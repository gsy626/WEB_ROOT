<?php
declare(strict_types=1);

// 供 PHP 内置服务器使用：阻止直接下载 SQLite、读取内部类库。
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$uri = rawurldecode($uri);

if (preg_match('#^/(storage|lib)(/|$)#', $uri)
    || preg_match('#^/(config|router)\.php$#', $uri)
    || str_contains($uri, '..')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo '403 · 内部文件不可直接访问';
    exit;
}

$path = __DIR__ . $uri;
if ($uri !== '/' && is_file($path)) {
    return false;
}

require __DIR__ . '/index.php';
