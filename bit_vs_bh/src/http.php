<?php
declare(strict_types=1);

function current_path(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $base = base_path();
    if ($base !== '' && str_starts_with($path, $base)) {
        $path = substr($path, strlen($base));
    }
    $path = '/' . trim($path, '/');
    return $path === '//' ? '/' : $path;
}

function render(string $view, array $data = []): void
{
    $pageTitle = $data['title'] ?? APP_NAME;
    unset($data['title']);

    $user = current_user();
    $season = active_season();
    $tick = tick_state();
    $flashes = take_flashes();
    $currentPath = current_path();

    extract($data, EXTR_SKIP);

    ob_start();
    require __DIR__ . '/views/' . $view . '.php';
    $content = ob_get_clean();
    require __DIR__ . '/views/layout.php';
    exit;
}

function render_error(int $status, string $title, string $message): void
{
    http_response_code($status);
    $pageTitle = $title;
    $user = null;
    $season = null;
    $tick = null;
    $flashes = [];
    $currentPath = current_path();
    $errorCode = $status;
    $errorTitle = $title;
    $errorMessage = $message;

    ob_start();
    require __DIR__ . '/views/error.php';
    $content = ob_get_clean();
    require __DIR__ . '/views/layout.php';
    exit;
}

function post_only(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        render_error(405, '方法不允许', '这个地址只接受表单 POST 请求。');
    }
}

function nav_active(string $path): string
{
    $current = current_path();
    if ($path === '/') {
        return $current === '/' ? 'is-active' : '';
    }
    return str_starts_with($current, $path) ? 'is-active' : '';
}
