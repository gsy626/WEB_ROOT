<?php
/**
 * PostRepo —— 文章仓库
 *
 * 扫描两个来源，合并成一个有序列表：
 *   · qd/                       已有的博客（文件夹名多带日期前缀 YYYY-M-D=）
 *   · data/posts/<slug>/main.qd 通过 /submit.php 提交的新博客
 *
 * 分类（数理 / 休闲）的判定规则集中放在 data/categories.php，方便手工调整。
 */
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Quarkdown.php';
require_once __DIR__ . '/Post.php';

final class PostRepo
{
    private static ?array $cache = null;

    /** 分类配置 */
    public static function config(): array
    {
        static $cfg = null;
        if ($cfg === null) {
            $file = Config::path('data', 'categories.php');
            $cfg  = is_file($file) ? (array) require $file : [];
        }
        return $cfg;
    }

    /** 全部分类下的文章，按时间倒序 */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $cfg     = self::config();
        $hidden  = (array) ($cfg['hidden'] ?? []);
        $posts   = [];
        $used    = [];

        foreach ([Post::SOURCE_QD, Post::SOURCE_SUBMITTED] as $source) {
            foreach (self::scan($source) as $post) {
                if (in_array($post->slug, $hidden, true)) {
                    continue;
                }
                $slug = $post->slug;
                $n    = 2;
                while (isset($used[$slug])) {
                    $slug = $post->slug . '-' . $n++;
                }
                $used[$slug] = true;
                if ($slug !== $post->slug) {
                    $post = self::withSlug($post, $slug);
                }
                $posts[] = $post;
            }
        }

        usort($posts, static function (Post $a, Post $b) {
            return [$b->date, $a->title] <=> [$a->date, $b->title];
        });

        return self::$cache = $posts;
    }

    private static function withSlug(Post $p, string $slug): Post
    {
        return new Post(
            $slug,
            $p->title,
            $p->description,
            $p->authors,
            $p->date,
            $p->category,
            $p->dir,
            $p->file,
            $p->source
        );
    }

    /** @return Post[] */
    public static function scan(string $source): array
    {
        $root = $source === Post::SOURCE_QD ? Config::contentDir() : Config::submittedDir();
        if (!is_dir($root)) {
            return [];
        }

        $dirs = glob($root . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];
        sort($dirs);

        $out = [];
        foreach ($dirs as $dir) {
            $file = $dir . DIRECTORY_SEPARATOR . 'main.qd';
            if (!is_file($file)) {
                continue;
            }
            $post = self::build(basename($dir), $dir, $file, $source);
            if ($post !== null) {
                $out[] = $post;
            }
        }
        return $out;
    }

    private static function build(string $folder, string $dir, string $file, string $source): ?Post
    {
        $src = @file_get_contents($file);
        if ($src === false) {
            return null;
        }

        $meta    = Quarkdown::frontMatter($src);
        $cfg     = self::config();
        $titles  = (array) ($cfg['titles'] ?? []);
        $over    = (array) ($cfg['overrides'] ?? []);
        $folderTitle = self::folderTitle($folder);

        // 标题优先级：配置覆盖 > 正文第一个标题 > .docname > 文件夹名
        $title = $titles[$folder] ?? null;
        if ($title === null || $title === '') {
            $title = self::pickTitle(
                $meta['firstHeading'] ?? '',
                $meta['name'] ?? '',
                $folderTitle
            );
        }

        $category = self::resolveCategory(
            $meta['category'] ?? '',
            $folder,
            ($meta['name'] ?? '') . ' ' . $title . ' ' . ($meta['description'] ?? ''),
            $over
        );

        return new Post(
            slug:        $folder,
            title:       $title,
            description: (string) ($meta['description'] ?? ''),
            authors:     (array) ($meta['authors'] ?? []),
            date:        self::resolveDate($folder, $file),
            category:    $category,
            dir:         $dir,
            file:        $file,
            source:      $source,
        );
    }

    /** 标题候选里挑一个像样的：太短或纯数字的都不要 */
    private static function pickTitle(string $heading, string $docname, string $folderTitle): string
    {
        foreach ([$heading, $docname, $folderTitle] as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '' || mb_strlen($candidate) < 2) {
                continue;
            }
            if (preg_match('/^[0-9\s\.\-\/]+$/', $candidate)) {
                continue;                       // 纯数字或日期，不是标题
            }
            if (preg_match('/^(practice|preactice|practive|chanming)\d*$/i', $candidate)) {
                continue;                       // 作者随手写的练习文件名
            }
            return $candidate;
        }
        return $folderTitle !== '' ? $folderTitle : '未命名';
    }

    /** "2026-6-15=normal-distribution" → "normal-distribution" */
    private static function folderTitle(string $folder): string
    {
        $pos = strpos($folder, '=');
        $t   = $pos === false ? $folder : substr($folder, $pos + 1);
        return trim(str_replace(['_', '-'], ' ', $t));
    }

    private static function resolveDate(string $folder, string $file): string
    {
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $folder, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
        }
        $ts = @filemtime($file) ?: time();
        return date('Y-m-d', $ts);
    }

    private static function resolveCategory(string $declared, string $folder, string $haystack, array $overrides): string
    {
        // 1. 文章自己声明 .doccategory {数理|休闲|math|leisure}
        $declared = self::normalizeCategory($declared);
        if ($declared !== null) {
            return $declared;
        }
        // 2. 配置里的显式覆盖
        if (isset($overrides[$folder])) {
            $c = self::normalizeCategory((string) $overrides[$folder]);
            if ($c !== null) {
                return $c;
            }
        }
        // 3. 关键词
        $cfg  = self::config();
        $keys = (array) ($cfg['keywords'] ?? []);
        $text = mb_strtolower($folder . ' ' . $haystack);
        foreach (['leisure', 'math'] as $cat) {
            foreach ((array) ($keys[$cat] ?? []) as $kw) {
                if ($kw !== '' && mb_strpos($text, mb_strtolower((string) $kw)) !== false) {
                    return $cat;
                }
            }
        }
        // 4. 默认：数理
        return 'math';
    }

    private static function normalizeCategory(string $value): ?string
    {
        $v = mb_strtolower(trim($value));
        return match ($v) {
            'math', '数理', '数学', '物理', '理科' => 'math',
            'leisure', '休闲', '文学', '随笔', '小说' => 'leisure',
            default => null,
        };
    }

    public static function find(string $slug): ?Post
    {
        foreach (self::all() as $post) {
            if ($post->slug === $slug) {
                return $post;
            }
        }
        return null;
    }

    /** @return Post[] */
    public static function byCategory(string $category): array
    {
        return array_values(array_filter(self::all(), static fn(Post $p) => $p->category === $category));
    }

    /** @return Post[] */
    public static function recent(int $limit = 5): array
    {
        return array_slice(self::all(), 0, $limit);
    }

    public static function countByCategory(): array
    {
        $out = array_fill_keys(array_keys(Config::CATEGORIES), 0);
        foreach (self::all() as $post) {
            $out[$post->category] = ($out[$post->category] ?? 0) + 1;
        }
        return $out;
    }

    /** 新提交的文章目录 */
    public static function makeSlug(string $title): string
    {
        $slug = mb_strtolower(trim($title));
        $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        $slug = mb_substr($slug, 0, 60);
        if ($slug === '') {
            $slug = 'post';
        }
        return date('Y-m-d') . '-' . $slug;
    }

    /** 生成一个未占用的 slug */
    public static function uniqueSlug(string $base): string
    {
        $existing = array_map(static fn(Post $p) => $p->slug, self::all());
        $slug     = $base;
        $n        = 2;
        while (in_array($slug, $existing, true) || is_dir(Config::submittedDir() . DIRECTORY_SEPARATOR . $slug)) {
            $slug = $base . '-' . $n++;
        }
        return $slug;
    }
}
