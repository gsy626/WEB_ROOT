<?php
/**
 * 合成清苑一中 · 排行榜后端配置
 *
 * 这个目录只需要 PHP 7.4+。数据库优先使用 SQLite（player.db），
 * 如果主机没有 pdo_sqlite，会自动退化为同名的 JSON 文件数据库。
 */
declare(strict_types=1);

if (!defined('QYYZ_ROOT')) {
    define('QYYZ_ROOT', dirname(__DIR__));
}

define('QYYZ_APP_VERSION', '1.0.0');
define('QYYZ_SCHEMA_VERSION', 1);

// 运行数据目录。默认放在项目 data/ 下；线上建议指向部署目录之外，
// 例如在 nginx 的 PHP location 里加：
//   fastcgi_param QYYZ_DATA_DIR /var/www/guosyzamz.top/shared/create_qyyz/data;
// 也可以在 PHP-FPM 的 env[QYYZ_DATA_DIR] 里设置。
$qyyzDataDir = getenv('QYYZ_DATA_DIR');
if (!is_string($qyyzDataDir) || trim($qyyzDataDir) === '') {
    $qyyzDataDir = isset($_SERVER['QYYZ_DATA_DIR']) ? (string) $_SERVER['QYYZ_DATA_DIR'] : '';
}
if (trim($qyyzDataDir) === '') {
    $qyyzDataDir = QYYZ_ROOT . DIRECTORY_SEPARATOR . 'data';
}
$qyyzDataDir = rtrim($qyyzDataDir, "/\\");
if ($qyyzDataDir === '') {
    $qyyzDataDir = QYYZ_ROOT . DIRECTORY_SEPARATOR . 'data';
}

define('QYYZ_DATA_DIR', $qyyzDataDir);
define('QYYZ_DB_FILE', QYYZ_DATA_DIR . DIRECTORY_SEPARATOR . 'player.db');
define('QYYZ_LOCK_FILE', QYYZ_DATA_DIR . DIRECTORY_SEPARATOR . 'player.lock');

define('QYYZ_SESSION_COOKIE', 'qyyz_session');
define('QYYZ_SESSION_TTL', 30 * 86400);
define('QYYZ_RUN_TTL', 6 * 3600);
define('QYYZ_MIN_RUN_SECONDS', 10);
define('QYYZ_MAX_SCORE', 10000000);
define('QYYZ_MAX_SCORE_BASE', 5000);
define('QYYZ_MAX_SCORE_PER_SECOND', 4000);
define('QYYZ_MAX_LEVEL', 16);
define('QYYZ_LEADERBOARD_LIMIT', 50);
define('QYYZ_MAX_PLAYERS', 50000);
define('QYYZ_IP_SALT', 'qyyz-leaderboard-v1');

define('QYYZ_TIMEZONE', 'Asia/Shanghai');
define('QYYZ_DEBUG', getenv('QYYZ_DEBUG') === '1');

date_default_timezone_set(QYYZ_TIMEZONE);
error_reporting(E_ALL);
ini_set('display_errors', QYYZ_DEBUG ? '1' : '0');
ini_set('log_errors', '1');
