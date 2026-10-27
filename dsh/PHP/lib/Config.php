<?php
/**
 * Config —— 全站配置与路径解析
 *
 * 所有路径都从这里取，避免各页面各自拼路径。
 */
declare(strict_types=1);

final class Config
{
    /** 站点名称 */
    public const SITE_NAME = '郭的博客';
    public const SITE_SUBTITLE = '数理 · 休闲';

    /** 两类内容的标识与中文名 */
    public const CATEGORIES = [
        'math'    => '数理',
        'leisure' => '休闲',
    ];

    /** 每类内容对应的独立样式表（两种内容两种 CSS） */
    public const CATEGORY_CSS = [
        'math'    => 'math.css',
        'leisure' => 'leisure.css',
    ];

    /** PHP 站点根目录 */
    public static function root(): string
    {
        return dirname(__DIR__);
    }

    public static function path(string ...$parts): string
    {
        return self::root() . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts);
    }

    /** 站点根 URL（用于生成资源链接，自动适配子目录部署） */
    public static function baseUrl(): string
    {
        static $base = null;
        if ($base === null) {
            $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
            // 命令行下没有 SCRIPT_NAME（php -r 会给 "-"），此时按站点根处理
            if (PHP_SAPI === 'cli' || $script === '' || $script[0] !== '/') {
                $base = '';
            } else {
                $dir  = rtrim(str_replace('\\', '/', dirname($script)), '/');
                $base = $dir;
            }
        }
        return $base;
    }

    public static function url(string $file, array $query = []): string
    {
        $url = self::baseUrl() . '/' . ltrim($file, '/');
        if ($query) {
            $url .= '?' . http_build_query($query);
        }
        return $url;
    }

    /** 已有博客内容目录（qd/ 中的旧站点） */
    public static function contentDir(): string
    {
        return dirname(self::root()) . DIRECTORY_SEPARATOR . 'qd';
    }

    /** 用户通过网页提交的新 blog */
    public static function submittedDir(): string
    {
        return self::path('data', 'posts');
    }

    /** SQLite 数据库文件 */
    public static function databaseFile(): string
    {
        return self::path('data', 'blog.sqlite');
    }

    /** 解析结果缓存目录 */
    public static function cacheDir(): string
    {
        return self::path('data', 'cache');
    }

    /** 提交新 blog 时允许的正文长度 */
    public const MAX_BODY_BYTES = 512000;
    public const MAX_COMMENT_LEN = 2000;
    public const MAX_NAME_LEN = 40;
}
