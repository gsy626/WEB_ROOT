<?php
declare(strict_types=1);

/**
 * 到点结算。cron 或玩家访问都能触发；文件锁保证同一时刻只有一个结算进程。
 * @return array{tick_no:int,battles:int,battle_ids:array<int>,orders:int}|null
 */
function run_due_tick(bool $force = false): ?array
{
    $lockPath = game_db_path() . '.settle.lock';
    $lock = fopen($lockPath, 'c');
    if ($lock === false) {
        throw new RuntimeException('无法创建结算锁文件');
    }
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        return null;
    }

    try {
        $state = tick_state();
        if (!$force && now_utc() < $state['next_tick_at']) {
            return null;
        }
        $season = active_season();
        if (!$season) {
            return null;
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            // 提前获取 SQLite 写锁，避免结算期间与玩家行动发生快照冲突。
            $pdo->exec('UPDATE tick_state SET tick_no = tick_no WHERE id = 1');

            $tickNo = (int) $state['tick_no'] + 1;
            $orders = $pdo->query(
                "SELECT * FROM orders WHERE status = 'pending' ORDER BY node_id, id"
            )->fetchAll();

            $byNode = [];
            foreach ($orders as $order) {
                $byNode[(int) $order['node_id']][] = $order;
            }

            $balance = faction_balance_bonus();
            $battleCount = 0;
            $battleIds = [];

            $nodeStmt = $pdo->prepare('SELECT * FROM nodes WHERE id = ?');
            foreach ($byNode as $nodeId => $nodeOrders) {
                $nodeStmt->execute([$nodeId]);
                $node = $nodeStmt->fetch();
                if ($node === false) {
                    $pdo->prepare("UPDATE orders SET status = 'void', resolved_at = ? WHERE node_id = ? AND status = 'pending'")
                        ->execute([now_utc(), $nodeId]);
                    continue;
                }
                $battle = resolve_node_orders($pdo, $node, $nodeOrders, $season, $tickNo, $balance);
                if ($battle !== null) {
                    $battleCount++;
                    $battleIds[] = (int) $battle['id'];
                }
            }

            decay_garrisons($pdo);

            $now = now_utc();
            $pdo->prepare('UPDATE tick_state SET tick_no = ?, last_tick_at = ?, next_tick_at = ? WHERE id = 1')
                ->execute([$tickNo, $now, next_tick_boundary()]);
            $pdo->commit();

            return [
                'tick_no' => $tickNo,
                'battles' => $battleCount,
                'battle_ids' => $battleIds,
                'orders' => count($orders),
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * 结算一个节点上的所有待定行动。
 */
function resolve_node_orders(PDO $pdo, array $node, array $orders, array $season, int $tickNo, array $balance): ?array
{
    $ownerId = $node['owner_faction_id'] !== null ? (int) $node['owner_faction_id'] : null;
    $nodeId = (int) $node['id'];
    $garrison = (int) $node['garrison'];
    $cap = (int) $node['garrison_cap'];
    $fortify = (int) $node['fortify'];
    $now = now_utc();

    $defOrders = [];
    $sides = [];
    foreach ($orders as $order) {
        $fid = (int) $order['faction_id'];
        $action = $order['action'];
        if ($action === 'defend' || ($action === 'support' && $ownerId === $fid) || ($action === 'attack' && $ownerId === $fid)) {
            $defOrders[] = $order;
            continue;
        }
        $sides[$fid] ??= ['orders' => [], 'attack' => [], 'support' => [], 'raw' => 0];
        $sides[$fid]['orders'][] = $order;
        $sides[$fid]['raw'] += (int) $order['power'];
        if ($action === 'attack') {
            $sides[$fid]['attack'][] = $order;
        } else {
            $sides[$fid]['support'][] = $order;
        }
    }

    // 没有进攻主力：驻防行动直接转为驻军；纯支援行动只获得少量贡献。
    $hasAssault = false;
    foreach ($sides as $side) {
        if (count($side['attack']) > 0) {
            $hasAssault = true;
            break;
        }
    }

    if (!$hasAssault) {
        if ($defOrders) {
            $defRaw = array_sum(array_map(static fn ($o) => (int) $o['power'], $defOrders));
            $gain = (int) round($defRaw * 0.75);
            $newGarrison = min($cap, $garrison + $gain);
            $pdo->prepare('UPDATE nodes SET garrison = ? WHERE id = ?')->execute([$newGarrison, $nodeId]);

            $awards = [];
            $agg = [];
            foreach ($defOrders as $order) {
                $factor = $order['action'] === 'support' ? 0.35 : 0.50;
                $amount = max(1, (int) round((int) $order['power'] * $factor) + 2);
                $awards[(int) $order['id']] = [
                    'amount' => $amount,
                    'stat' => 'defense',
                    'refund' => $order['unit_code'] === 'logistics',
                ];
            }
            // 没有主力进攻时，纯支援部队转入策应，避免命令永久滞留。
            foreach ($sides as $side) {
                foreach ($side['orders'] as $order) {
                    $awards[(int) $order['id']] = [
                        'amount' => 1,
                        'stat' => 'attack',
                        'refund' => false,
                    ];
                }
            }
            settle_orders($pdo, $orders, null, $awards, $agg);
            apply_user_aggregate($pdo, $agg);
            return null;
        }

        // 只有策应部队，没有主力：保留行动记录与 1 点贡献。
        $awards = [];
        $agg = [];
        foreach ($orders as $order) {
            $awards[(int) $order['id']] = [
                'amount' => 1,
                'stat' => $order['faction_id'] === $ownerId ? 'defense' : 'attack',
                'refund' => false,
            ];
        }
        settle_orders($pdo, $orders, null, $awards, $agg);
        apply_user_aggregate($pdo, $agg);
        return null;
    }

    // 选择主攻方：必须包含至少一支进攻部队，战力最高者主攻。
    $primaryFid = null;
    $primaryPower = -1;
    foreach ($sides as $fid => $side) {
        if (count($side['attack']) === 0) {
            continue;
        }
        $power = $side['raw'] * (1 + ($balance['bonuses'][$fid] ?? 0));
        if ($power > $primaryPower) {
            $primaryPower = $power;
            $primaryFid = $fid;
        }
    }
    $primary = $sides[$primaryFid];
    $attacker = faction_by_id($primaryFid);
    $defender = $ownerId !== null ? faction_by_id($ownerId) : null;

    // 中立节点：另一方的部队作为侧翼牵制，增加守备力量。
    $interferencePower = 0;
    $interferenceFids = [];
    if ($ownerId === null) {
        foreach ($sides as $fid => $side) {
            if ($fid === $primaryFid) {
                continue;
            }
            $interferencePower += (int) round($side['raw'] * (1 + ($balance['bonuses'][$fid] ?? 0)));
            $interferenceFids[] = $fid;
        }
    }

    $atkMajority = majority_unit($primary['orders']);
    $defMajority = $ownerId !== null ? majority_unit($defOrders) : '';
    $counterMult = 1.0;
    if ($atkMajority !== '' && $defMajority !== '') {
        $counterMult = unit_counter_bonus($atkMajority, $defMajority);
    }

    $defRaw = array_sum(array_map(static fn ($o) => (int) $o['power'], $defOrders));
    $baseDefense = $garrison + $defRaw;
    $defensePower = (int) round($baseDefense * (1 + $fortify / 100));
    if ($ownerId === null) {
        $baseDefense = $garrison;
        $defensePower = $garrison + (int) round($interferencePower * 0.20);
    } else {
        $defensePower = (int) round(
            $defensePower * (1 + ($balance['bonuses'][$ownerId] ?? 0))
        );
    }

    $attackPower = (int) round(
        $primary['raw'] * $counterMult * (1 + ($balance['bonuses'][$primaryFid] ?? 0))
    );
    $attackPower = max(1, $attackPower);

    $captured = $attackPower > $defensePower;

    $attackerLosses = 0;
    $defenderLosses = 0;
    $newGarrison = $garrison;
    $newFortify = $fortify;

    if ($captured) {
        $remaining = $attackPower - $defensePower;
        $newGarrison = (int) clamp(40 + (int) round($remaining * 0.45), 40, $cap);
        $newFortify = 0;
        $attackerLosses = min($primary['raw'], (int) round($defensePower * 0.55));
        $defenderLosses = $baseDefense;

        $pdo->prepare(
            'UPDATE nodes SET owner_faction_id = ?, garrison = ?, fortify = 0,
                captured_at = ?, last_battle_at = ? WHERE id = ?'
        )->execute([$primaryFid, $newGarrison, $now, $now, $nodeId]);
    } else {
        if ($ownerId !== null) {
            $newGarrison = (int) clamp(
                $garrison + $defRaw - (int) round($attackPower * 0.90),
                max(30, (int) round((int) $node['base_defense'] * 0.5)),
                $cap
            );
            $newFortify = max(0, $fortify - 1);
            $defenderLosses = min($baseDefense, (int) round($attackPower * 0.60));
            $attackerLosses = (int) round($attackPower * 0.70);
            $pdo->prepare(
                'UPDATE nodes SET garrison = ?, fortify = ?, last_battle_at = ? WHERE id = ?'
            )->execute([$newGarrison, $newFortify, $now, $nodeId]);
        } else {
            $newGarrison = max(
                (int) $node['neutral_floor'],
                $garrison - (int) round($attackPower * 0.85)
            );
            $newGarrison = min($cap, $newGarrison);
            $defenderLosses = min($garrison, (int) round($attackPower * 0.85));
            $attackerLosses = (int) round($attackPower * 0.65);
            $pdo->prepare('UPDATE nodes SET garrison = ?, last_battle_at = ? WHERE id = ?')
                ->execute([$newGarrison, $now, $nodeId]);
        }
    }

    $winnerFactionId = $captured ? $primaryFid : $ownerId;
    $winnerShort = $captured
        ? ($attacker['short_name'] ?? '进攻方')
        : ($defender['short_name'] ?? '中立守备队');
    $headline = $captured
        ? (($attacker['short_name'] ?? '进攻方') . '攻陷' . $node['name'])
        : ($winnerShort . '守住' . $node['name']);

    $counterText = '双方均以混合编队投入战斗，未形成兵种克制。';
    if ($ownerId === null) {
        $counterText = '中立守备队不参与兵种克制。';
    } elseif (count($defOrders) === 0) {
        $counterText = '守方没有玩家部队参与，兵种克制未生效。';
    } elseif ($atkMajority !== '' && $defMajority !== '') {
        $atkUnitName = unit($atkMajority)['name'] ?? $atkMajority;
        $defUnitName = unit($defMajority)['name'] ?? $defMajority;
        if ($counterMult > 1.0) {
            $counterText = $atkUnitName . '克制' . $defUnitName . '，攻击方战力 ×1.2。';
        } elseif ($counterMult < 1.0) {
            $counterText = $defUnitName . '反制' . $atkUnitName . '，攻击方战力 ×0.8。';
        } else {
            $counterText = $atkUnitName . '与' . $defUnitName . '正面遭遇，兵种没有形成克制。';
        }
    }

    $balanceText = '';
    $bonus = (float) ($balance['bonuses'][$primaryFid] ?? 0);
    if ($bonus > 0.005) {
        $balanceText .= ' ' . ($attacker['short_name'] ?? '进攻方') . '在营人数较少，获得 +'
            . (int) round($bonus * 100) . '% 人数补正。';
    }
    if ($ownerId !== null) {
        $defBonus = (float) ($balance['bonuses'][$ownerId] ?? 0);
        if ($defBonus > 0.005) {
            $balanceText .= ' ' . ($defender['short_name'] ?? '防守方') . '获得 +'
                . (int) round($defBonus * 100) . '% 人数补正。';
        }
    }

    if ($defender === null) {
        $defenderLine = sprintf('该节点由中立守备队驻守，初始驻军 %d，无工事加成。', $garrison);
    } elseif (count($defOrders) > 0) {
        $defenderLine = sprintf(
            '%s以 %d 支部队（%s）迎战，节点驻军 %d，工事加成 +%d%%。',
            $defender['name'],
            count($defOrders),
            unit_names_summary($defOrders),
            $garrison,
            $fortify
        );
    } else {
        $defenderLine = sprintf(
            '%s没有派出驻防部队，仅靠常驻守军 %d 与 +%d%% 工事迎战。',
            $defender['name'],
            $garrison,
            $fortify
        );
    }

    $interferenceLine = '';
    if ($interferenceFids) {
        $names = [];
        foreach ($interferenceFids as $fid) {
            $f = faction_by_id($fid);
            $names[] = $f['short_name'] ?? '另一方';
        }
        $interferenceLine = implode('、', $names) . '的部队也在侧翼牵制，使守备力量增加了 '
            . (int) round($interferencePower * 0.20) . '。';
    }

    if ($captured) {
        $resultLine = sprintf(
            '防线被撕开，%s易主。%s战损 %d，%s战损 %d。新驻军 %d，工事清零。',
            $node['name'],
            $defender['short_name'] ?? '中立守备队',
            $defenderLosses,
            $attacker['short_name'] ?? '进攻方',
            $attackerLosses,
            $newGarrison
        );
    } elseif ($ownerId !== null) {
        $resultLine = sprintf(
            '%s稳住阵脚，%s的攻势被化解。%s战损 %d，%s损失 %d，%s驻军降至 %d，工事加成降至 +%d%%。',
            $winnerShort,
            $attacker['short_name'] ?? '进攻方',
            $attacker['short_name'] ?? '进攻方',
            $attackerLosses,
            $winnerShort,
            $defenderLosses,
            $node['name'],
            $newGarrison,
            $newFortify
        );
    } else {
        $resultLine = sprintf(
            '中立守备队挡住了进攻。%s战损 %d，节点驻军降至 %d，仍保持中立。',
            $attacker['short_name'] ?? '进攻方',
            $attackerLosses,
            $newGarrison
        );
    }

    $report = sprintf(
        "【%s · 第 %d 轮 · %s】\n%s集结 %d 支部队（%s），向「%s」发起进攻。\n%s%s\n攻击方战力 %d，防守方战力 %d。%s%s\n%s\n%s",
        $season['name'],
        $tickNo,
        fmt_time($now, 'm-d H:i'),
        $attacker['name'] ?? '进攻方',
        count($primary['orders']),
        unit_names_summary($primary['orders']),
        $node['name'],
        $defenderLine,
        $interferenceLine !== '' ? $interferenceLine : '',
        $attackPower,
        $defensePower,
        $counterText,
        $balanceText,
        $resultLine,
        random_flavor()
    );

    $stmt = $pdo->prepare(
        'INSERT INTO battles
            (season_id, node_id, tick_no, kind, attacker_faction_id, defender_faction_id,
             attack_power, defense_power, attacker_units, defender_units, attacker_losses,
             defender_losses, winner_faction_id, captured, garrison_after, headline, report, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        (int) $season['id'],
        $nodeId,
        $tickNo,
        $captured ? 'capture' : 'assault',
        $primaryFid,
        $ownerId,
        $attackPower,
        $defensePower,
        count($primary['orders']),
        count($defOrders),
        $attackerLosses,
        $defenderLosses,
        $winnerFactionId,
        $captured ? 1 : 0,
        $newGarrison,
        $headline,
        $report,
        $now,
    ]);
    $battleId = (int) $pdo->lastInsertId();

    // 贡献记录
    $awards = [];
    $agg = [];
    foreach ($primary['orders'] as $order) {
        $amount = max(1, (int) round((int) $order['power'] * ($captured ? 0.75 : 0.30)) + ($captured ? 12 : 0));
        $awards[(int) $order['id']] = [
            'amount' => $amount,
            'stat' => 'attack',
            'refund' => false,
        ];
    }
    foreach ($defOrders as $order) {
        $amount = $captured
            ? max(1, (int) round((int) $order['power'] * 0.22) + 2)
            : max(1, (int) round((int) $order['power'] * 0.55) + 8);
        $awards[(int) $order['id']] = [
            'amount' => $amount,
            'stat' => 'defense',
            'refund' => !$captured && $order['unit_code'] === 'logistics',
        ];
    }
    foreach ($sides as $fid => $side) {
        if ($fid === $primaryFid) {
            continue;
        }
        foreach ($side['orders'] as $order) {
            $awards[(int) $order['id']] = [
                'amount' => max(1, (int) round((int) $order['power'] * 0.15)),
                'stat' => 'attack',
                'refund' => false,
            ];
        }
    }

    settle_orders($pdo, $orders, $battleId, $awards, $agg);
    apply_user_aggregate($pdo, $agg);

    // 胜负与夺点统计（按玩家去重）
    $attackerUsers = [];
    foreach ($primary['orders'] as $order) {
        $attackerUsers[(int) $order['user_id']] = true;
    }
    $defenderUsers = [];
    foreach ($defOrders as $order) {
        $defenderUsers[(int) $order['user_id']] = true;
    }
    foreach ($attackerUsers as $uid => $_) {
        if ($captured) {
            $pdo->prepare('UPDATE users SET wins = wins + 1, season_captures = season_captures + 1 WHERE id = ?')
                ->execute([$uid]);
        } else {
            $pdo->prepare('UPDATE users SET losses = losses + 1 WHERE id = ?')->execute([$uid]);
        }
    }
    if (!$captured) {
        foreach ($defenderUsers as $uid => $_) {
            $pdo->prepare('UPDATE users SET wins = wins + 1 WHERE id = ?')->execute([$uid]);
        }
    } else {
        foreach ($defenderUsers as $uid => $_) {
            $pdo->prepare('UPDATE users SET losses = losses + 1 WHERE id = ?')->execute([$uid]);
        }
    }

    return ['id' => $battleId];
}

/**
 * @param array<int, array{amount:int,stat:string,refund:bool}> $awards
 * @param array<int, array{c:int,a:int,d:int,ap:int}> $agg
 */
function settle_orders(PDO $pdo, array $orders, ?int $battleId, array $awards, array &$agg): void
{
    $now = now_utc();
    $stmt = $pdo->prepare(
        "UPDATE orders
            SET status = 'resolved', battle_id = ?, contribution = ?, resolved_at = ?
          WHERE id = ? AND status = 'pending'"
    );
    foreach ($orders as $order) {
        $award = $awards[(int) $order['id']] ?? ['amount' => 0, 'stat' => 'none', 'refund' => false];
        $stmt->execute([$battleId, $award['amount'], $now, (int) $order['id']]);
        if ($stmt->rowCount() === 0) {
            continue;
        }
        $uid = (int) $order['user_id'];
        if (!isset($agg[$uid])) {
            $agg[$uid] = ['c' => 0, 'a' => 0, 'd' => 0, 'ap' => 0];
        }
        $agg[$uid]['c'] += $award['amount'];
        if ($award['stat'] === 'attack') {
            $agg[$uid]['a'] += $award['amount'];
        } elseif ($award['stat'] === 'defense') {
            $agg[$uid]['d'] += $award['amount'];
        }
        if ($award['refund']) {
            $agg[$uid]['ap'] += 1;
        }
    }
}

/**
 * @param array<int, array{c:int,a:int,d:int,ap:int}> $agg
 */
function apply_user_aggregate(PDO $pdo, array $agg): void
{
    if (!$agg) {
        return;
    }
    $stmt = $pdo->prepare(
        'UPDATE users
            SET contribution = contribution + ?,
                total_contribution = total_contribution + ?,
                season_attack = season_attack + ?,
                season_defense = season_defense + ?,
                ap = MIN(?, ap + ?)
          WHERE id = ?'
    );
    foreach ($agg as $uid => $delta) {
        $stmt->execute([
            $delta['c'],
            $delta['c'],
            $delta['a'],
            $delta['d'],
            AP_MAX,
            $delta['ap'],
            $uid,
        ]);
    }
}

function decay_garrisons(PDO $pdo): void
{
    $nodes = $pdo->query('SELECT * FROM nodes')->fetchAll();
    $stmt = $pdo->prepare('UPDATE nodes SET garrison = ? WHERE id = ?');
    $factor = (100 - GARRISON_DECAY_PCT) / 100;
    foreach ($nodes as $node) {
        $garrison = (int) $node['garrison'];
        $floor = $node['owner_faction_id'] !== null
            ? max(30, (int) round((int) $node['base_defense'] * 0.5))
            : (int) $node['neutral_floor'];
        $decayed = (int) floor($garrison * $factor);
        $new = max($floor, $decayed);
        if ($new < $garrison) {
            $stmt->execute([$new, (int) $node['id']]);
        }
    }
}
