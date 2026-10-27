<?php
/**
 * CommentRepo —— 最简单的评论系统（SQLite + PHP）
 *
 * 只做三件事：按文章列出评论、新增评论、统计每篇评论数。
 * 没有嵌套、没有登录，长度与空值在写入前校验。
 */
declare(strict_types=1);

final class CommentRepo
{
    /** 单篇评论，最早的在前 */
    public static function forPost(string $slug): array
    {
        if (!Database::available()) {
            return [];
        }
        return Database::all(
            'SELECT id, author, body, created_at FROM comments WHERE post_slug = ? ORDER BY id ASC',
            [$slug]
        );
    }

    /** 所有文章的评论数： slug => count */
    public static function counts(): array
    {
        if (!Database::available()) {
            return [];
        }
        $rows = Database::all('SELECT post_slug, COUNT(*) AS n FROM comments GROUP BY post_slug');
        $out  = [];
        foreach ($rows as $row) {
            $out[$row['post_slug']] = (int) $row['n'];
        }
        return $out;
    }

    public static function countFor(string $slug): int
    {
        return count(self::forPost($slug));
    }

    /**
     * 新增评论。
     * @return array{ok:bool,error?:string}
     */
    public static function add(string $slug, string $author, string $body): array
    {
        $author = trim(preg_replace('/\s+/u', ' ', $author) ?? '');
        $body   = trim(str_replace("\r\n", "\n", $body));

        if ($author === '') {
            $author = '匿名';
        }
        if (mb_strlen($author) > Config::MAX_NAME_LEN) {
            return ['ok' => false, 'error' => '昵称太长（最多 ' . Config::MAX_NAME_LEN . ' 字）。'];
        }
        if ($body === '') {
            return ['ok' => false, 'error' => '评论内容不能为空。'];
        }
        if (mb_strlen($body) > Config::MAX_COMMENT_LEN) {
            return ['ok' => false, 'error' => '评论太长（最多 ' . Config::MAX_COMMENT_LEN . ' 字）。'];
        }
        if (!Database::available()) {
            return ['ok' => false, 'error' => Database::error() ?? '数据库不可用。'];
        }

        Database::run(
            'INSERT INTO comments (post_slug, author, body, created_at, ip_hash) VALUES (?, ?, ?, ?, ?)',
            [
                $slug,
                $author,
                $body,
                date('Y-m-d H:i:s'),
                substr(hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|blog-salt'), 0, 16),
            ]
        );
        return ['ok' => true];
    }
}
