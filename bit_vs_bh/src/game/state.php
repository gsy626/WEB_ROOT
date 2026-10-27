<?php
declare(strict_types=1);

/**
 * 每日行动点：跨天后补满到上限。返回更新后的用户行。
 */
function ensure_daily_ap(array $user): array
{
    $today = local_date();
    $ap = (int) $user['ap'];

    if (($user['ap_day'] ?? '') === $today) {
        if ($ap > AP_MAX) {
            db()->prepare('UPDATE users SET ap = ? WHERE id = ?')->execute([AP_MAX, (int) $user['id']]);
            $user['ap'] = AP_MAX;
        }
        return $user;
    }

    $newAp = AP_MAX;
    db()->prepare('UPDATE users SET ap = ?, ap_day = ? WHERE id = ?')
        ->execute([$newAp, $today, (int) $user['id']]);
    $user['ap'] = $newAp;
    $user['ap_day'] = $today;
    return $user;
}

function maybe_run_tick(): ?array
{
    $state = tick_state();
    if (now_utc() < $state['next_tick_at']) {
        return null;
    }
    return run_due_tick(false);
}

function next_tick_boundary(): string
{
    $interval = TICK_MINUTES * 60;
    $next = (int) (ceil((time() + 1) / $interval) * $interval);
    return gmdate('Y-m-d H:i:s', $next);
}

function node_by_id(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM nodes WHERE id = ?');
    $stmt->execute([$id]);
    $node = $stmt->fetch();
    return $node === false ? null : $node;
}

function node_by_code(string $code): ?array
{
    $stmt = db()->prepare('SELECT * FROM nodes WHERE code = ?');
    $stmt->execute([$code]);
    $node = $stmt->fetch();
    return $node === false ? null : $node;
}

function nodes(): array
{
    return db()->query('SELECT * FROM nodes ORDER BY id')->fetchAll();
}

/**
 * 节点上待结算行动汇总。
 */
function pending_summary_for_node(int $nodeId): array
{
    $stmt = db()->prepare(
        "SELECT faction_id, action, COUNT(*) AS c, COALESCE(SUM(power), 0) AS p
           FROM orders
          WHERE node_id = ? AND status = 'pending'
          GROUP BY faction_id, action"
    );
    $stmt->execute([$nodeId]);
    $summary = ['by_faction' => [], 'total_count' => 0];
    foreach ($stmt->fetchAll() as $row) {
        $fid = (int) $row['faction_id'];
        $summary['by_faction'][$fid] ??= [
            'count' => 0,
            'power' => 0,
            'attack_power' => 0,
            'defense_power' => 0,
            'actions' => [],
        ];
        $count = (int) $row['c'];
        $power = (int) $row['p'];
        $summary['by_faction'][$fid]['count'] += $count;
        $summary['by_faction'][$fid]['power'] += $power;
        $summary['by_faction'][$fid]['actions'][$row['action']] = $count;
        if (in_array($row['action'], ['attack', 'support'], true)) {
            $summary['by_faction'][$fid]['attack_power'] += $power;
        }
        if ($row['action'] === 'defend') {
            $summary['by_faction'][$fid]['defense_power'] += $power;
        }
        $summary['total_count'] += $count;
    }
    return $summary;
}

function enemy_pending_power(int $nodeId, int $factionId): int
{
    $stmt = db()->prepare(
        "SELECT COALESCE(SUM(power), 0)
           FROM orders
          WHERE node_id = ? AND faction_id <> ? AND status = 'pending'"
    );
    $stmt->execute([$nodeId, $factionId]);
    return (int) $stmt->fetchColumn();
}

function user_pending_orders(int $userId): array
{
    $stmt = db()->prepare(
        "SELECT o.*, n.name AS node_name, n.zone AS node_zone
           FROM orders o
           JOIN nodes n ON n.id = o.node_id
          WHERE o.user_id = ? AND o.status = 'pending'
          ORDER BY o.id DESC"
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function latest_intel_for(int $nodeId, int $factionId): ?array
{
    $stmt = db()->prepare(
        'SELECT i.*, u.username
           FROM intel i
           JOIN users u ON u.id = i.user_id
          WHERE i.node_id = ? AND i.faction_id = ?
          ORDER BY i.id DESC LIMIT 1'
    );
    $stmt->execute([$nodeId, $factionId]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function node_battles(int $nodeId, int $limit = 8): array
{
    $stmt = db()->prepare(
        'SELECT * FROM battles WHERE node_id = ? ORDER BY id DESC LIMIT ' . (int) $limit
    );
    $stmt->execute([$nodeId]);
    return $stmt->fetchAll();
}

function node_orders_pending_detail(int $nodeId, int $factionId): array
{
    $stmt = db()->prepare(
        "SELECT o.*, u.username
           FROM orders o
           JOIN users u ON u.id = o.user_id
          WHERE o.node_id = ? AND o.faction_id = ? AND o.status = 'pending'
          ORDER BY o.id DESC"
    );
    $stmt->execute([$nodeId, $factionId]);
    return $stmt->fetchAll();
}
