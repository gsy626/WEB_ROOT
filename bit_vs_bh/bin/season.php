#!/usr/bin/env php
<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$finalize = in_array('--finalize', $argv, true);
$yes = in_array('--yes', $argv, true);

ensure_season();
$season = active_season();
$standings = faction_standings();

echo "当前赛季：" . ($season['name'] ?? '—') . "\n";
echo "结束时间：" . fmt_time($season['ends_at'] ?? null, 'Y-m-d H:i') . "（Asia/Shanghai）\n";
foreach ($standings as $row) {
    echo "  {$row['name']}：{$row['score']} 分，占 {$row['node_count']} 点，夺点 {$row['captures']}，胜场 {$row['wins']}\n";
}

if (!$finalize) {
    echo "查看结算预览后可运行：php bin/season.php --finalize\n";
    exit(0);
}

if ($season && now_utc() < $season['ends_at'] && !$yes) {
    echo "\n赛季尚未结束。强制结算会重置地图、贡献与行动点。\n";
    echo "确认请加 --yes：php bin/season.php --finalize --yes\n";
    exit(1);
}

$result = run_due_tick(true);
if ($result !== null) {
    echo "\n先补跑了第 {$result['tick_no']} 轮，处理 {$result['orders']} 条行动。\n";
}

$out = finalize_season($season);
echo "\n赛季结算完成：\n" . ($out['summary'] ?? '') . "\n";
$newSeason = create_season();
echo "\n新赛季：" . ($newSeason['name'] ?? '—') . "\n";
