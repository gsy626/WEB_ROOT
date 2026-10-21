<?php
/**
 * 合成北理工 · 排行榜后端配置
 *
 * 这个目录只需要 PHP 7.4+。数据库优先使用 SQLite（player.db），
 * 如果主机没有 pdo_sqlite，会自动退化为同名的 JSON 文件数据库。
 */
declare(strict_types=1);

if (!defined('BIT_ROOT')) {
    define('BIT_ROOT', dirname(__DIR__));
}

define('BIT_APP_VERSION', '1.0.0');
define('BIT_SCHEMA_VERSION', 1);

define('BIT_DATA_DIR', BIT_ROOT . DIRECTORY_SEPARATOR . 'data');
define('BIT_DB_FILE', BIT_DATA_DIR . DIRECTORY_SEPARATOR . 'player.db');
define('BIT_LOCK_FILE', BIT_DATA_DIR . DIRECTORY_SEPARATOR . 'player.lock');

define('BIT_SESSION_COOKIE', 'greatbit_session');
define('BIT_SESSION_TTL', 30 * 86400);
define('BIT_RUN_TTL', 6 * 3600);
define('BIT_MIN_RUN_SECONDS', 10);
define('BIT_MAX_SCORE', 10000000);
define('BIT_MAX_SCORE_BASE', 5000);
define('BIT_MAX_SCORE_PER_SECOND', 4000);
define('BIT_MAX_LEVEL', 8);
define('BIT_LEADERBOARD_LIMIT', 50);
define('BIT_MAX_PLAYERS', 50000);
define('BIT_IP_SALT', 'greatbit-leaderboard-v1');

define('BIT_TIMEZONE', 'Asia/Shanghai');
define('BIT_DEBUG', getenv('BIT_DEBUG') === '1');

date_default_timezone_set(BIT_TIMEZONE);
error_reporting(E_ALL);
ini_set('display_errors', BIT_DEBUG ? '1' : '0');
ini_set('log_errors', '1');
