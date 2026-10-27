#!/usr/bin/env php
<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$pdo = db();

$existing = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_demo = 1')->fetchColumn();
if ($existing > 0) {
    echo "演示数据已存在（{$existing} 个演示账号），本次跳过。\n";
    echo "如需重新灌入：php bin/init_db.php --fresh && php bin/demo_seed.php\n";
    exit(0);
}

$factionIds = [];
foreach (factions() as $faction) {
    $factionIds[$faction['code']] = (int) $faction['id'];
}

$now = now_utc();
$threeDaysAgo = gmdate('Y-m-d H:i:s', time() - 3 * 86400);

$teamDefs = [
    '良乡夜班工兵' => 'bit',
    '学院路信号塔' => 'bit',
    '沙河试飞队' => 'buaa',
    '风洞守夜人' => 'buaa',
];

$teamIds = [];
foreach ($teamDefs as $name => $code) {
    $pdo->prepare('INSERT OR IGNORE INTO teams (name, faction_id, created_at) VALUES (?, ?, ?)')
        ->execute([$name, $factionIds[$code], $now]);
    $stmt = $pdo->prepare('SELECT id FROM teams WHERE name = ?');
    $stmt->execute([$name]);
    $teamIds[$name] = (int) $stmt->fetchColumn();
}

$userDefs = [
    // username, faction, team, demo password (null = random)
    ['夜班工兵', 'bit', '良乡夜班工兵', null],
    ['良乡搬砖侠', 'bit', '良乡夜班工兵', null],
    ['图书馆钉子户', 'bit', '学院路信号塔', null],
    ['南操场巡逻员', 'bit', '学院路信号塔', null],
    ['工程训练中心常客', 'bit', '良乡夜班工兵', null],
    ['沙河试飞员', 'buaa', '沙河试飞队', null],
    ['风洞守夜人', 'buaa', '风洞守夜人', null],
    ['机库管理员', 'buaa', '沙河试飞队', null],
    ['校车站调度', 'buaa', '沙河试飞队', null],
    ['天桥观察员', 'buaa', '风洞守夜人', null],
    ['北理演示号', 'bit', null, 'demo12345'],
    ['北航演示号', 'buaa', null, 'demo12345'],
];

$pdo->beginTransaction();
try {
    $insertUser = $pdo->prepare(
        'INSERT INTO users
            (username, password_hash, faction_id, team_id, ap, ap_day, created_at, last_seen_at, last_action_at, is_demo)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
    );
    foreach ($userDefs as [$username, $code, $teamName, $password]) {
        $plain = $password ?? bin2hex(random_bytes(16));
        $insertUser->execute([
            $username,
            password_hash($plain, PASSWORD_DEFAULT),
            $factionIds[$code],
            $teamName !== null ? $teamIds[$teamName] : null,
            12,
            local_date(),
            $threeDaysAgo,
            $now,
            gmdate('Y-m-d H:i:s', time() - 180),
        ]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

$userRows = [];
foreach ($pdo->query('SELECT id, username, faction_id FROM users WHERE is_demo = 1')->fetchAll() as $row) {
    $userRows[$row['username']] = $row;
}
$nodeRows = [];
foreach ($pdo->query('SELECT id, code FROM nodes')->fetchAll() as $row) {
    $nodeRows[$row['code']] = (int) $row['id'];
}

function demo_queue(PDO $pdo, array $userRows, array $nodeRows, array $orders, array $actions): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO orders
            (user_id, faction_id, node_id, unit_code, action, ap_cost, power, status, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?)"
    );
    foreach ($orders as [$username, $nodeCode, $unit, $action, $power]) {
        $user = $userRows[$username];
        if ($action === 'support') {
            $power = max(1, (int) round($power * 0.5));
        }
        $stmt->execute([
            (int) $user['id'],
            (int) $user['faction_id'],
            $nodeRows[$nodeCode],
            $unit,
            $action,
            $actions[$action]['ap'],
            $power,
            now_utc(),
        ]);
    }
}

$rounds = [
    [
        ['夜班工兵', 'academy-library', 'air', 'attack', 30],
        ['良乡搬砖侠', 'academy-library', 'air', 'attack', 28],
        ['图书馆钉子户', 'academy-library', 'academic', 'attack', 26],
        ['南操场巡逻员', 'academy-library', 'signal', 'support', 10],
        ['工程训练中心常客', 'lx-lab', 'engineer', 'defend', 13],
        ['南操场巡逻员', 'lx-lab', 'engineer', 'defend', 12],
        ['沙河试飞员', 'zc-canteen', 'air', 'attack', 30],
        ['风洞守夜人', 'zc-canteen', 'air', 'attack', 32],
        ['机库管理员', 'zc-canteen', 'air', 'attack', 29],
        ['校车站调度', 'zc-canteen', 'signal', 'support', 11],
        ['天桥观察员', 'academy-station', 'air', 'attack', 30],
        ['沙河试飞员', 'academy-station', 'air', 'attack', 26],
        ['校车站调度', 'academy-station', 'signal', 'support', 8],
        ['机库管理员', 'sh-hangar', 'engineer', 'defend', 13],
        ['北理演示号', 'academy-library', 'air', 'attack', 29],
        ['北航演示号', 'zc-canteen', 'air', 'attack', 31],
    ],
    [
        ['南操场巡逻员', 'zc-canteen', 'air', 'attack', 31],
        ['图书馆钉子户', 'zc-canteen', 'academic', 'attack', 27],
        ['工程训练中心常客', 'zc-canteen', 'air', 'attack', 29],
        ['夜班工兵', 'zc-canteen', 'signal', 'support', 10],
        ['良乡搬砖侠', 'academy-station', 'air', 'attack', 30],
        ['南操场巡逻员', 'academy-station', 'air', 'attack', 32],
        ['工程训练中心常客', 'academy-station', 'academic', 'attack', 27],
        ['夜班工兵', 'academy-plaza', 'engineer', 'defend', 13],
        ['沙河试飞员', 'academy-library', 'air', 'attack', 30],
        ['风洞守夜人', 'academy-library', 'air', 'attack', 33],
        ['天桥观察员', 'academy-library', 'air', 'attack', 28],
        ['校车站调度', 'academy-library', 'signal', 'support', 12],
        ['机库管理员', 'zc-bridge', 'air', 'attack', 31],
        ['沙河试飞员', 'zc-bridge', 'air', 'attack', 29],
        ['天桥观察员', 'zc-bridge', 'academic', 'attack', 26],
        ['风洞守夜人', 'sh-hangar', 'engineer', 'defend', 13],
        ['北航演示号', 'sh-hangar', 'engineer', 'defend', 12],
    ],
    [
        ['夜班工兵', 'academy-library', 'air', 'attack', 31],
        ['良乡搬砖侠', 'academy-library', 'air', 'attack', 30],
        ['图书馆钉子户', 'academy-library', 'air', 'attack', 32],
        ['南操场巡逻员', 'academy-library', 'signal', 'support', 10],
        ['工程训练中心常客', 'sh-station', 'air', 'attack', 34],
        ['夜班工兵', 'sh-station', 'air', 'attack', 34],
        ['良乡搬砖侠', 'sh-station', 'air', 'attack', 33],
        ['南操场巡逻员', 'sh-station', 'air', 'attack', 33],
        ['图书馆钉子户', 'sh-station', 'signal', 'support', 10],
        ['沙河试飞员', 'academy-station', 'air', 'attack', 32],
        ['风洞守夜人', 'academy-station', 'air', 'attack', 30],
        ['机库管理员', 'academy-station', 'air', 'attack', 31],
        ['沙河试飞员', 'lx-field', 'air', 'attack', 33],
        ['风洞守夜人', 'lx-field', 'air', 'attack', 32],
        ['天桥观察员', 'lx-field', 'air', 'attack', 33],
        ['机库管理员', 'lx-field', 'air', 'attack', 30],
        ['校车站调度', 'lx-field', 'signal', 'support', 10],
        ['工程训练中心常客', 'lx-field', 'engineer', 'defend', 13],
        ['图书馆钉子户', 'lx-field', 'engineer', 'defend', 12],
        ['北理演示号', 'sh-station', 'air', 'attack', 33],
    ],
];

foreach ($rounds as $index => $round) {
    demo_queue($pdo, $userRows, $nodeRows, $round, ACTIONS);
    $result = run_due_tick(true);
    echo '第 ' . ($index + 1) . ' 轮：' . ($result['battles'] ?? 0) . " 场战斗\n";
}

$pending = [
    ['夜班工兵', 'academy-station', 'air', 'attack', 30],
    ['良乡搬砖侠', 'academy-station', 'air', 'attack', 29],
    ['南操场巡逻员', 'academy-station', 'signal', 'support', 9],
    ['工程训练中心常客', 'zc-canteen', 'engineer', 'defend', 14],
    ['图书馆钉子户', 'zc-canteen', 'logistics', 'defend', 8],
    ['沙河试飞员', 'sh-station', 'air', 'attack', 30],
    ['风洞守夜人', 'sh-station', 'air', 'attack', 31],
    ['机库管理员', 'sh-station', 'signal', 'support', 10],
    ['天桥观察员', 'academy-library', 'air', 'attack', 29],
    ['校车站调度', 'academy-library', 'signal', 'support', 9],
    ['风洞守夜人', 'lx-field', 'engineer', 'defend', 14],
    ['机库管理员', 'lx-field', 'engineer', 'defend', 13],
    ['北理演示号', 'academy-station', 'air', 'attack', 28],
    ['北航演示号', 'sh-station', 'air', 'attack', 29],
];
demo_queue($pdo, $userRows, $nodeRows, $pending, ACTIONS);

// 为下一轮准备两条共享情报，顺便展示侦察面板。
$tick = tick_state();
$intel = $pdo->prepare(
    'INSERT INTO intel (node_id, faction_id, user_id, power_min, power_max, accuracy, tick_at, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
$enemyAtStation = (int) $pdo->query(
    "SELECT COALESCE(SUM(power), 0) FROM orders
      WHERE node_id = {$nodeRows['sh-station']} AND faction_id <> {$factionIds['bit']} AND status = 'pending'"
)->fetchColumn();
$enemyAtAcademy = (int) $pdo->query(
    "SELECT COALESCE(SUM(power), 0) FROM orders
      WHERE node_id = {$nodeRows['academy-station']} AND faction_id <> {$factionIds['buaa']} AND status = 'pending'"
)->fetchColumn();
$intel->execute([
    $nodeRows['sh-station'], $factionIds['bit'], (int) $userRows['夜班工兵']['id'],
    max(0, (int) floor($enemyAtStation * 0.95)), (int) ceil($enemyAtStation * 1.05), 95,
    $tick['next_tick_at'], now_utc(),
]);
$intel->execute([
    $nodeRows['academy-station'], $factionIds['buaa'], (int) $userRows['天桥观察员']['id'],
    max(0, (int) floor($enemyAtAcademy * 0.95)), (int) ceil($enemyAtAcademy * 1.05), 95,
    $tick['next_tick_at'], now_utc(),
]);

// 普通演示号的行动点设置得低一些，给人可以直接接手的感觉。
$pdo->exec("UPDATE users SET ap = 8 + (id % 7), last_seen_at = datetime('now') WHERE is_demo = 1");

$playerCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$battleCount = (int) $pdo->query('SELECT COUNT(*) FROM battles')->fetchColumn();
$pendingCount = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn();

echo "\n演示数据生成完成。\n";
echo "玩家：{$playerCount} 人；战斗：{$battleCount} 场；待结算命令：{$pendingCount} 条。\n";
echo "演示登录：北理演示号 / demo12345；北航演示号 / demo12345\n\n";
foreach (faction_standings() as $row) {
    echo "  {$row['name']}：{$row['score']} 分，占 {$row['node_count']} 点，夺点 {$row['captures']}，胜场 {$row['wins']}\n";
}
