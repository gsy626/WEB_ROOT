<?php
/**
 * 评论提交（纯 PHP，POST → 302 重定向，避免刷新重复提交）
 */
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect(Config::url('index.php'));
}

$slug = param('p', '', 'POST');
$post = $slug === '' ? null : PostRepo::find($slug);

if ($post === null) {
    not_found('找不到这篇文章：' . $slug);
}

// 蜜罐字段：真人不会填
if (param('url', '', 'POST') !== '') {
    flash_set('error', '提交被判定为垃圾评论。');
    redirect($post->url() . '#comments');
}

if (!csrf_check()) {
    flash_set('error', '表单已过期，请重新提交。');
    redirect($post->url() . '#comments');
}

$result = CommentRepo::add($slug, param('author', '', 'POST'), param('body', '', 'POST'));

if ($result['ok']) {
    flash_set('ok', '评论已发布，感谢留言。');
    redirect($post->url() . '#comments');
}

flash_set('error', $result['error'] ?? '提交失败。');
redirect($post->url() . '#comments');
