#!/usr/bin/env php
<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$fresh = in_array('--fresh', $argv, true);
$path = game_db_path();

if ($fresh) {
    if ($path === '' || $path === '/' || str_contains($path, '..')) {
        fwrite(STDERR, "拒绝删除可疑路径：{$path}\n");
        exit(1);
    }
    foreach ([$path, $path . '-wal', $path . '-shm', $path . '.install.lock', $path . '.settle.lock'] as $file) {
        if (is_file($file)) {
            unlink($file);
            echo "removed {$file}\n";
        }
    }
}

db();
$season = ensure_season();
$tick = tick_state();
$nodeCount = (int) db()->query('SELECT COUNT(*) FROM nodes')->fetchColumn();

echo "数据库：" . $path . "\n";
echo "赛季：" . ($season['name'] ?? '—') . "（" . ($season['started_at'] ?? '') . " → " . ($season['ends_at'] ?? '') . " UTC）\n";
echo "节点：" . $nodeCount . " 个\n";
echo "下一轮结算：" . $tick['next_tick_at'] . " UTC\n";
echo "提示：运行 php bin/demo_seed.php 可灌入演示战况。\n";
