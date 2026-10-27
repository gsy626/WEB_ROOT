<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Shanghai');
mb_internal_encoding('UTF-8');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/game/state.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/game/actions.php';
require_once __DIR__ . '/game/combat.php';
require_once __DIR__ . '/game/ranking.php';
require_once __DIR__ . '/game/season.php';
require_once __DIR__ . '/http.php';

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    // 本地环境的系统 session 目录可能只读，默认放到项目的 data/sessions。
    $sessionDir = getenv('SESSION_SAVE_PATH');
    if (!is_string($sessionDir) || $sessionDir === '') {
        $sessionDir = data_path() . '/sessions';
    }
    if (!is_dir($sessionDir)) {
        @mkdir($sessionDir, 0770, true);
    }
    if (is_dir($sessionDir) && is_writable($sessionDir)) {
        session_save_path($sessionDir);
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

if (PHP_SAPI !== 'cli') {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; base-uri 'self'; form-action 'self'");
}

// 页面访问时顺带完成“到点结算”，没有 cron 也能运转。
if (PHP_SAPI !== 'cli') {
    try {
        ensure_season();
        $tickSummary = maybe_run_tick();
        if ($tickSummary !== null && $tickSummary['battles'] > 0) {
            flash(
                'tick',
                '第 ' . $tickSummary['tick_no'] . ' 轮结算完成，爆发 '
                . $tickSummary['battles'] . ' 场战斗。'
            );
        }
    } catch (Throwable $e) {
        error_log('[tick] ' . $e->getMessage());
    }
}
