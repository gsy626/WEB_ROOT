<?php
/**
 * bootstrap.php —— 所有页面的统一入口
 *
 * 页面只写：require __DIR__ . '/lib/bootstrap.php';
 */
declare(strict_types=1);

require_once __DIR__ . '/compat.php';   // 本机 PHP 没有 mbstring，先补上 UTF-8 函数

/** 解析器版本：改动 Quarkdown.php 后 +1 可让缓存失效 */
const QUARKDOWN_PARSER_VERSION = '3';

mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Shanghai');

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/CommentRepo.php';
require_once __DIR__ . '/Quarkdown.php';
require_once __DIR__ . '/Post.php';
require_once __DIR__ . '/PostRepo.php';
require_once __DIR__ . '/View.php';

// session 放到站点自己的 data 目录，不依赖系统 session 目录的写权限
$sessionDir = Config::path('data', 'sessions');
if (!is_dir($sessionDir)) {
    @mkdir($sessionDir, 0775, true);
}
if (is_dir($sessionDir) && is_writable($sessionDir)) {
    session_save_path($sessionDir);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

/** HTML 转义 */
function e(?string $text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** 取 GET/POST 的字符串参数 */
function param(string $key, string $default = '', string $method = 'GET'): string
{
    $bag = $method === 'POST' ? $_POST : $_GET;
    $v   = $bag[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

/** 一次性提示消息 */
function flash_set(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flash_get(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return is_array($f) ? $f : null;
}

/** 302 跳转并结束 */
function redirect(string $url): never
{
    header('Location: ' . $url, true, 302);
    exit;
}

/** 简易 CSRF 令牌 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_check(): bool
{
    $sent = $_POST['csrf'] ?? '';
    return is_string($sent) && $sent !== '' && hash_equals($_SESSION['csrf'] ?? '', $sent);
}

/** 404 */
function not_found(string $message = '页面不存在'): never
{
    http_response_code(404);
    View::page([
        'title'     => '404',
        'nav'       => '',
        'bodyClass' => 'theme-neutral',
        'styles'    => [],
        'content'   => '<section class="panel"><h1>404</h1><p>' . e($message)
            . '</p><p><a class="btn" href="' . e(Config::url('index.php')) . '">回到首页</a></p></section>',
    ]);
    exit;
}
