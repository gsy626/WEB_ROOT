<?php
/**
 * 初始化 data/player.db。
 *
 * 在 GreatBIT/ 目录执行：
 *   php server/init-db.php
 *
 * 有 pdo_sqlite 时创建 SQLite 数据库；没有时创建同名的 JSON 数据库。
 * 已存在数据库时不会清空数据。
 */
declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/storage.php';

$store = bit_store();
$store->write(static function (array &$state): bool {
    return true;
});

echo 'player.db 已就绪，格式：' . $store->format() . PHP_EOL;
echo '路径：' . BIT_DB_FILE . PHP_EOL;
