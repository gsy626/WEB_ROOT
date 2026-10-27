<?php
declare(strict_types=1);

/**
 * 确保存在进行中的赛季；跨周时自动结算旧赛季、重置地图并开启新赛季。
 */
function ensure_season(): ?array
{
    static $checked = false;
    if ($checked) {
        return active_season();
    }
    $checked = true;

    $season = active_season();
    if (!$season) {
        return create_season();
    }

    if (now_utc() >= $season['ends_at']) {
        $stmt = db()->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'");
        if ((int) $stmt->fetchColumn() > 0) {
            run_due_tick(true);
        }
        finalize_season($season);
        $season = create_season();
    }
    return $season;
}

function create_season(): array
{
    $pdo = db();
    $bounds = season_bounds_for(new DateTimeImmutable('now', tz()));
    $number = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM seasons')->fetchColumn();
    $pdo->prepare('INSERT INTO seasons (name, started_at, ends_at, status) VALUES (?, ?, ?, ?)')
        ->execute(['第 ' . $number . ' 赛季', $bounds['start'], $bounds['end'], 'active']);
    $id = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE seasons SET name = ? WHERE id = ?')
        ->execute([season_name_from_bounds($number, $bounds), $id]);
    $pdo->prepare('UPDATE tick_state SET next_tick_at = ? WHERE id = 1')
        ->execute([next_tick_boundary()]);
    return active_season() ?? [];
}

function finalize_season(array $season): array
{
    $pdo = db();
    $seasonId = (int) $season['id'];
    $number = $seasonId;
    $standings = array_values(faction_standings());
    $first = $standings[0] ?? null;
    $second = $standings[1] ?? null;

    $winner = null;
    if ($first !== null) {
        if ($second === null
            || $first['score'] > $second['score']
            || ($first['score'] === $second['score'] && $first['node_count'] > $second['node_count'])
        ) {
            $winner = $first;
        }
    }

    $mvp = $pdo->query(
        'SELECT * FROM users WHERE contribution > 0
          ORDER BY contribution DESC, season_captures DESC, wins DESC LIMIT 1'
    )->fetch() ?: null;
    $topAttack = $pdo->query(
        'SELECT * FROM users WHERE season_attack > 0 ORDER BY season_attack DESC, contribution DESC LIMIT 1'
    )->fetch() ?: null;
    $topDefense = $pdo->query(
        'SELECT * FROM users WHERE season_defense > 0 ORDER BY season_defense DESC, contribution DESC LIMIT 1'
    )->fetch() ?: null;
    $topScout = $pdo->query(
        'SELECT * FROM users WHERE season_scout > 0 ORDER BY season_scout DESC, contribution DESC LIMIT 1'
    )->fetch() ?: null;

    $lines = [];
    $lines[] = '【' . $season['name'] . ' 结算】';
    if ($winner !== null) {
        $lines[] = '冠军：' . $winner['name'] . '（' . $winner['score'] . ' 分）';
        if ($second !== null && (int) $second['id'] !== (int) $winner['id']) {
            $lines[] = '亚军：' . $second['name'] . '（' . $second['score'] . ' 分）';
        }
    } else {
        $lines[] = '冠军：平局（双方积分与节点数相同）';
    }
    if ($mvp !== null) {
        $lines[] = '赛季 MVP：' . $mvp['username'] . '（贡献 ' . $mvp['contribution'] . '）';
    }
    if ($topAttack !== null) {
        $lines[] = '最佳进攻：' . $topAttack['username'] . '（进攻贡献 ' . $topAttack['season_attack'] . '）';
    }
    if ($topDefense !== null) {
        $lines[] = '最佳防守：' . $topDefense['username'] . '（防守贡献 ' . $topDefense['season_defense'] . '）';
    }
    if ($topScout !== null) {
        $lines[] = '最佳侦察：' . $topScout['username'] . '（侦察 ' . $topScout['season_scout'] . ' 次）';
    }
    if ($winner === null && $mvp === null) {
        $lines[] = '这个赛季没有产生有效战果，地图保持原样。';
    }
    $lines[] = '';
    $lines[] = '新赛季已开启：地图、驻军、行动点与贡献榜全部重置。';
    $summary = implode("\n", $lines);

    $pdo->beginTransaction();
    try {
        $pdo->exec('UPDATE tick_state SET tick_no = tick_no WHERE id = 1');

        if ($winner !== null) {
            $pdo->prepare('UPDATE users SET title = ? WHERE faction_id = ?')
                ->execute(['第' . $number . '赛季·冠军', (int) $winner['id']]);
        }
        if ($topScout !== null) {
            $pdo->prepare('UPDATE users SET title = ? WHERE id = ?')
                ->execute(['第' . $number . '赛季·学院路情报官', (int) $topScout['id']]);
        }
        if ($topDefense !== null) {
            $pdo->prepare('UPDATE users SET title = ? WHERE id = ?')
                ->execute(['第' . $number . '赛季·良乡守门员', (int) $topDefense['id']]);
        }
        if ($topAttack !== null) {
            $pdo->prepare('UPDATE users SET title = ? WHERE id = ?')
                ->execute(['第' . $number . '赛季·沙河突袭者', (int) $topAttack['id']]);
        }
        if ($mvp !== null) {
            $pdo->prepare('UPDATE users SET title = ? WHERE id = ?')
                ->execute(['第' . $number . '赛季·MVP', (int) $mvp['id']]);
        }

        $pdo->prepare(
            'UPDATE seasons SET status = ?, winner_faction_id = ?, summary = ?, finalized_at = ? WHERE id = ?'
        )->execute([
            'finished',
            $winner['id'] ?? null,
            $summary,
            now_utc(),
            $seasonId,
        ]);

        // 作废旧赛季残余行动并返还行动点
        $pending = $pdo->query("SELECT * FROM orders WHERE status = 'pending'")->fetchAll();
        foreach ($pending as $order) {
            $pdo->prepare("UPDATE orders SET status = 'void', resolved_at = ? WHERE id = ?")
                ->execute([now_utc(), (int) $order['id']]);
            $pdo->prepare('UPDATE users SET ap = MIN(?, ap + ?) WHERE id = ?')
                ->execute([AP_MAX, (int) $order['ap_cost'], (int) $order['user_id']]);
        }

        reset_map_for_new_season($pdo);
        $pdo->prepare('DELETE FROM intel')->execute();
        $pdo->prepare(
            'UPDATE users
                SET contribution = 0, season_attack = 0, season_defense = 0,
                    season_scout = 0, season_captures = 0,
                    ap = ?, ap_day = ?, last_action_at = NULL, last_seen_at = ?'
        )->execute([AP_MAX, local_date(), now_utc()]);
        $pdo->prepare('UPDATE tick_state SET next_tick_at = ?, last_tick_at = ? WHERE id = 1')
            ->execute([next_tick_boundary(), now_utc()]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return ['summary' => $summary];
}

function reset_map_for_new_season(PDO $pdo): void
{
    $factionIds = [];
    foreach (factions() as $faction) {
        $factionIds[$faction['code']] = (int) $faction['id'];
    }
    $stmt = $pdo->prepare(
        'UPDATE nodes
            SET owner_faction_id = ?, garrison = ?, base_defense = ?, neutral_floor = ?,
                garrison_cap = ?, value = ?, fortify = 0, captured_at = ?, last_battle_at = NULL
          WHERE code = ?'
    );
    foreach (NODE_SEED as $seed) {
        $ownerId = $seed['owner'] !== null ? $factionIds[$seed['owner']] : null;
        $garrison = $seed['base_defense'] + ($ownerId !== null ? 25 : 0);
        $stmt->execute([
            $ownerId,
            $garrison,
            $seed['base_defense'],
            (int) round($seed['base_defense'] * 0.9),
            $seed['garrison_cap'],
            $seed['value'],
            $ownerId !== null ? now_utc() : null,
            $seed['code'],
        ]);
    }
}
