<?php
/**
 * media.php —— 文章图片/附件的统一出口
 *
 *   media.php?p=<文章 slug>&f=image/fig1.png
 *
 * 为什么不直接把 qd/ 暴露成静态目录？
 *   1. qd/ 在站点根目录之外，Web 服务器看不到；
 *   2. 通过这里可以校验路径、限定扩展名，避免目录穿越。
 * 这里刻意不引入 session，避免图片请求被 session 锁串行化。
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/compat.php';
require_once __DIR__ . '/lib/Config.php';
require_once __DIR__ . '/lib/Quarkdown.php';
require_once __DIR__ . '/lib/Post.php';
require_once __DIR__ . '/lib/PostRepo.php';

$slug = isset($_GET['p']) && is_string($_GET['p']) ? trim($_GET['p']) : '';
$file = isset($_GET['f']) && is_string($_GET['f']) ? trim($_GET['f']) : '';

$post = $slug === '' ? null : PostRepo::find($slug);
if ($post === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('not found');
}

// 统一分隔符，去掉开头的 ./
$rel = str_replace('\\', '/', $file);
$rel = preg_replace('#^\./#', '', $rel) ?? $rel;

$types = [
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'avif' => 'image/avif',
    'pdf'  => 'application/pdf',
];

$ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));

$rootReal = realpath($post->dir);
$target   = ($rel === '' || str_contains($rel, "\0") || !isset($types[$ext]))
    ? false
    : realpath($post->dir . DIRECTORY_SEPARATOR . $rel);

if ($rootReal === false || $target === false || !is_file($target)
    || !str_starts_with($target, $rootReal . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('not found');
}

$mtime = (int) filemtime($target);
$size  = (int) filesize($target);
$etag  = '"' . md5($target . '|' . $mtime . '|' . $size) . '"';

header('Content-Type: ' . $types[$ext]);
header('Content-Length: ' . $size);
header('Cache-Control: public, max-age=604800');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
header('ETag: ' . $etag);

$ifNone = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
if ($ifNone !== '' && trim($ifNone) === $etag) {
    http_response_code(304);
    exit;
}

// PDF 用附件下载，图片内联
if ($ext === 'pdf') {
    header('Content-Disposition: inline; filename="' . rawurlencode(basename($target)) . '"');
}

while (ob_get_level() > 0) {
    ob_end_clean();
}
readfile($target);
