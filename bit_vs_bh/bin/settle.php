#!/usr/bin/env php
<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$force = in_array('--force', $argv, true);
$season = ensure_season();
$tick = tick_state();

if (!$force && now_utc() < $tick['next_tick_at']) {
    echo "未到结算时间。下一轮：" . fmt_time($tick['next_tick_at'], 'Y-m-d H:i') . "（Asia/Shanghai）\n";
    echo "如需立即结算，使用 php bin/settle.php --force\n";
    exit(0);
}

$result = run_due_tick($force);
if ($result === null) {
    echo "结算被跳过（可能另一个进程正在结算）。\n";
    exit(0);
}

echo "第 {$result['tick_no']} 轮结算完成。\n";
echo "处理待定行动：{$result['orders']} 条；发生战斗：{$result['battles']} 场。\n";
echo "赛季：" . ($season['name'] ?? '—') . "\n";
$next = tick_state();
echo "下一轮：" . fmt_time($next['next_tick_at'], 'Y-m-d H:i') . "（Asia/Shanghai）\n";
