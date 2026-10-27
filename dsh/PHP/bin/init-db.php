#!/usr/bin/env php
<?php
/**
 * 初始化 SQLite 评论库（可重复执行）
 *
 *   php bin/init-db.php
 *   bin/serve.sh 会自动处理扩展问题；单独跑这个脚本时若报缺少扩展，
 *   请用：php -d extension=vendor/php-ext/usr/lib/php/<API>/pdo_sqlite.so bin/init-db.php
 */
declare(strict_types=1);

require __DIR__ . '/../lib/compat.php';
require __DIR__ . '/../lib/Config.php';
require __DIR__ . '/../lib/Database.php';

if (!Database::available()) {
    fwrite(STDERR, "无法使用 SQLite：" . (Database::error() ?? '未知') . "\n");
    fwrite(STDERR, "提示：直接运行 bin/serve.sh 启动站点，它会自动载入随站点附带的扩展。\n");
    exit(1);
}

foreach (['data', 'data/posts', 'data/cache'] as $dir) {
    $path = Config::path(...explode('/', $dir));
    if (!is_dir($path) && !mkdir($path, 0775, true)) {
        fwrite(STDERR, "无法创建目录：$path\n");
        exit(1);
    }
    echo "目录就绪  $path\n";
}

// Database::available() 已经建过表，这里再确认一次
Database::run('SELECT 1');

echo "数据库就绪  " . Config::databaseFile() . "\n";
echo "当前评论数  " . count(Database::all('SELECT id FROM comments')) . "\n";
