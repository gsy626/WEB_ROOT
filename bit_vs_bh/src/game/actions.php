<?php
declare(strict_types=1);

/**
 * 估算 / 生成一次行动的战力快照。
 * 随机浮动 ±8%，结算时再叠加兵种克制与人数补正。
 */
function calculate_order_power(string $unitCode, string $action, array $node, array $faction): int
{
    $unit = unit($unitCode);
    if (!$unit) {
        return 0;
    }
    if (!in_array($action, ['attack', 'defend', 'support'], true)) {
        return 0;
    }

    $base = in_array($action, ['attack', 'support'], true) ? (int) $unit['atk'] : (int) $unit['def'];
    $mult = 1.0;

    if ($unitCode === 'academic' && in_array($node['kind'], LANDMARK_KINDS, true)) {
        $mult *= 1.30;
    }
    if ($action === 'support') {
        $mult *= 0.50;
    }
    if ($faction['code'] === 'bit' && $action === 'defend') {
        $mult *= 1.10;
    }
    if ($faction['code'] === 'buaa' && $action === 'attack') {
        $mult *= 1.08;
    }

    $mult *= 0.92 + mt_rand(0, 160) / 1000;
    return max(1, (int) round($base * $mult));
}

/**
 * @return array{ok: bool, message: string, type: string}
 */
function submit_order(array $user, int $nodeId, string $unitCode, string $action): array
{
    $node = node_by_id($nodeId);
    if (!$node) {
        return ['ok' => false, 'message' => '目标节点不存在。', 'type' => 'error'];
    }
    $unit = unit($unitCode);
    if (!$unit) {
        return ['ok' => false, 'message' => '未知兵种。', 'type' => 'error'];
    }
    if (!isset(ACTIONS[$action])) {
        return ['ok' => false, 'message' => '未知行动。', 'type' => 'error'];
    }

    $faction = faction_by_id((int) $user['faction_id']);
    if (!$faction) {
        return ['ok' => false, 'message' => '阵营数据异常。', 'type' => 'error'];
    }
    $myFactionId = (int) $faction['id'];
    $ownerId = $node['owner_faction_id'] !== null ? (int) $node['owner_faction_id'] : null;
    $isOwner = $ownerId === $myFactionId;

    // 新玩家保护期
    $created = strtotime($user['created_at'] . ' UTC');
    $isNewPlayer = $created > time() - NEW_PLAYER_HOURS * 3600;
    if ($action === 'attack' && $isNewPlayer) {
        return [
            'ok' => false,
            'message' => '新玩家保护期内（注册 24 小时内）只能驻防、支援、侦察或修复。',
            'type' => 'error',
        ];
    }

    // 行动合法性
    if ($action === 'attack' && $isOwner) {
        return ['ok' => false, 'message' => '不能进攻本方节点，请选择驻防。', 'type' => 'error'];
    }
    if ($action === 'defend' && !$isOwner) {
        return ['ok' => false, 'message' => '只能在本方节点驻防。', 'type' => 'error'];
    }
    if ($action === 'repair' && !$isOwner) {
        return ['ok' => false, 'message' => '只能修复本方节点。', 'type' => 'error'];
    }
    if ($action === 'repair' && $unitCode !== 'engineer') {
        return ['ok' => false, 'message' => '只有工程兵能执行修复行动。', 'type' => 'error'];
    }

    $apCost = ap_cost($action);
    $isInstant = in_array($action, ['scout', 'repair'], true);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // 先取得写锁，避免与结算事务的 SQLite 快照冲突。
        $pdo->prepare('UPDATE users SET ap = ap WHERE id = ?')->execute([(int) $user['id']]);

        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([(int) $user['id']]);
        $fresh = $stmt->fetch();
        if ($fresh === false) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => '登录状态已失效。', 'type' => 'error'];
        }

        if ((int) $fresh['ap'] < $apCost) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => '行动点不足，今天先歇一歇。', 'type' => 'error'];
        }

        if ($fresh['last_action_at']) {
            $remain = ACTION_INTERVAL_SECONDS - (time() - strtotime($fresh['last_action_at'] . ' UTC'));
            if ($remain > 0) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => '操作太快了，请等待 ' . $remain . ' 秒后再行动。', 'type' => 'error'];
            }
        }

        if (!$isInstant) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE user_id = ? AND status = 'pending'");
            $stmt->execute([(int) $fresh['id']]);
            if ((int) $stmt->fetchColumn() >= MAX_PENDING_ORDERS) {
                $pdo->rollBack();
                return [
                    'ok' => false,
                    'message' => '待结算行动已达 ' . MAX_PENDING_ORDERS . ' 个上限，等本轮结算后再派兵。',
                    'type' => 'error',
                ];
            }
        }

        $now = now_utc();
        $message = '';
        $power = 0;

        if ($action === 'scout') {
            $enemyPower = enemy_pending_power((int) $node['id'], $myFactionId);
            $accuracy = (float) $unit['scout'];
            $noise = (1 - $accuracy) * 0.60;
            $powerMin = max(0, (int) floor($enemyPower * (1 - $noise)));
            $powerMax = (int) ceil($enemyPower * (1 + $noise));
            $tick = tick_state();
            $stmt = $pdo->prepare(
                'INSERT INTO intel (node_id, faction_id, user_id, power_min, power_max, accuracy, tick_at, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                (int) $node['id'],
                $myFactionId,
                (int) $fresh['id'],
                $powerMin,
                $powerMax,
                (int) round($accuracy * 100),
                $tick['next_tick_at'],
                $now,
            ]);
            $contribution = 2;
            $stmt = $pdo->prepare(
                "INSERT INTO orders
                    (user_id, faction_id, node_id, unit_code, action, ap_cost, power, status, contribution, created_at, resolved_at)
                 VALUES (?, ?, ?, ?, 'scout', ?, 0, 'resolved', ?, ?, ?)"
            );
            $stmt->execute([
                (int) $fresh['id'], $myFactionId, (int) $node['id'], $unitCode, $apCost, $contribution, $now, $now,
            ]);
            $pdo->prepare(
                'UPDATE users SET ap = ap - ?, last_action_at = ?, contribution = contribution + ?,
                    total_contribution = total_contribution + ?, season_scout = season_scout + 1
                  WHERE id = ?'
            )->execute([$apCost, $now, $contribution, $contribution, (int) $fresh['id']]);

            $range = $powerMin === $powerMax ? (string) $powerMin : $powerMin . '–' . $powerMax;
            $message = '侦察完成：' . $node['name'] . ' 的敌方待结算战力约为 ' . $range
                . '（' . $unit['name'] . '，精度 ' . (int) round($accuracy * 100) . '%）。情报已同步给本阵营。';
        } elseif ($action === 'repair') {
            $gain = 16;
            $fortifyGain = 2;
            if ((int) $node['garrison'] >= (int) $node['garrison_cap'] && (int) $node['fortify'] >= FORTIFY_CAP) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => '该节点驻军与工事都已达到上限。', 'type' => 'error'];
            }
            $newGarrison = min((int) $node['garrison_cap'], (int) $node['garrison'] + $gain);
            $newFortify = min(FORTIFY_CAP, (int) $node['fortify'] + $fortifyGain);
            $actualGain = $newGarrison - (int) $node['garrison'];
            $actualFortify = $newFortify - (int) $node['fortify'];
            $pdo->prepare('UPDATE nodes SET garrison = ?, fortify = ? WHERE id = ?')
                ->execute([$newGarrison, $newFortify, (int) $node['id']]);

            $contribution = 5;
            $stmt = $pdo->prepare(
                "INSERT INTO orders
                    (user_id, faction_id, node_id, unit_code, action, ap_cost, power, status, contribution, created_at, resolved_at)
                 VALUES (?, ?, ?, 'engineer', 'repair', ?, ?, 'resolved', ?, ?, ?)"
            );
            $stmt->execute([
                (int) $fresh['id'], $myFactionId, (int) $node['id'], $apCost, $actualGain, $contribution, $now, $now,
            ]);
            $pdo->prepare(
                'UPDATE users SET ap = ap - ?, last_action_at = ?, contribution = contribution + ?,
                    total_contribution = total_contribution + ?, season_defense = season_defense + ?
                  WHERE id = ?'
            )->execute([$apCost, $now, $contribution, $contribution, $contribution, (int) $fresh['id']]);

            $message = '修复完成：' . $node['name'] . ' 驻军 +' . $actualGain
                . '，防御加成 +' . $actualFortify . '%。';
        } else {
            $power = calculate_order_power($unitCode, $action, $node, $faction);
            $stmt = $pdo->prepare(
                "INSERT INTO orders
                    (user_id, faction_id, node_id, unit_code, action, ap_cost, power, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?)"
            );
            $stmt->execute([
                (int) $fresh['id'], $myFactionId, (int) $node['id'], $unitCode, $action, $apCost, $power, $now,
            ]);
            $pdo->prepare('UPDATE users SET ap = ap - ?, last_action_at = ? WHERE id = ?')
                ->execute([$apCost, $now, (int) $fresh['id']]);

            $tick = tick_state();
            $message = '命令已下达：' . $unit['name'] . '执行「' . ACTIONS[$action]['name'] . '」，战力 '
                . $power . '，将在 ' . fmt_time($tick['next_tick_at'], 'H:i') . ' 前后的结算中生效。';
        }

        $pdo->commit();
        return ['ok' => true, 'message' => $message, 'type' => 'success'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * @return array{ok: bool, message: string, type: string}
 */
function cancel_order(array $user, int $orderId): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE users SET ap = ap WHERE id = ?')->execute([(int) $user['id']]);

        $stmt = $pdo->prepare(
            "SELECT * FROM orders WHERE id = ? AND user_id = ? AND status = 'pending'"
        );
        $stmt->execute([$orderId, (int) $user['id']]);
        $order = $stmt->fetch();
        if ($order === false) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => '这条行动已经结算或不存在。', 'type' => 'error'];
        }
        $stmt = $pdo->prepare("UPDATE orders SET status = 'void', resolved_at = ? WHERE id = ?");
        $stmt->execute([now_utc(), $orderId]);
        $pdo->prepare('UPDATE users SET ap = MIN(?, ap + ?) WHERE id = ?')
            ->execute([AP_MAX, (int) $order['ap_cost'], (int) $user['id']]);
        $pdo->commit();
        return [
            'ok' => true,
            'message' => '已撤回行动，返还 ' . (int) $order['ap_cost'] . ' 行动点。',
            'type' => 'info',
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * 不考虑随机浮动的战力基准值，用于界面预估与 JS 展示。
 */
function calculate_base_power(string $unitCode, string $action, array $node, array $faction): int
{
    $unit = unit($unitCode);
    if (!$unit) {
        return 0;
    }
    if (!in_array($action, ['attack', 'defend', 'support'], true)) {
        return 0;
    }
    $base = in_array($action, ['attack', 'support'], true) ? (int) $unit['atk'] : (int) $unit['def'];
    $mult = 1.0;
    if ($unitCode === 'academic' && in_array($node['kind'], LANDMARK_KINDS, true)) {
        $mult *= 1.30;
    }
    if ($action === 'support') {
        $mult *= 0.50;
    }
    if (($faction['code'] ?? '') === 'bit' && $action === 'defend') {
        $mult *= 1.10;
    }
    if (($faction['code'] ?? '') === 'buaa' && $action === 'attack') {
        $mult *= 1.08;
    }
    return max(1, (int) round($base * $mult));
}
