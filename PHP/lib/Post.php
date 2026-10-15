<?php
/**
 * Post —— 一篇文章的值对象
 *
 * 数据来源有两种：
 *   · qd/      目录里的旧博客（Quarkdown 源文件 main.qd）
 *   · data/posts/<slug>/main.qd   通过网页提交的新博客
 * 两者的目录约定一致：文章目录下可以有 image/ 之类的相对资源。
 */
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Quarkdown.php';

final class Post
{
    public const SOURCE_QD        = 'qd';
    public const SOURCE_SUBMITTED = 'submitted';

    /** @var array|null 渲染结果缓存 */
    private ?array $rendered = null;

    public function __construct(
        public readonly string $slug,
        public readonly string $title,
        public readonly string $description,
        public readonly array $authors,
        public readonly string $date,          // Y-m-d
        public readonly string $category,      // math | leisure
        public readonly string $dir,           // 文章目录（绝对路径）
        public readonly string $file,          // main.qd 绝对路径
        public readonly string $source,        // Post::SOURCE_*
    ) {
    }

    public function categoryName(): string
    {
        return Config::CATEGORIES[$this->category] ?? $this->category;
    }

    /** body 上的主题类，决定加载哪一份 CSS */
    public function themeClass(): string
    {
        return 'theme-' . $this->category;
    }

    public function cssFile(): string
    {
        return Config::CATEGORY_CSS[$this->category] ?? 'base.css';
    }

    public function url(): string
    {
        return Config::url('post.php', ['p' => $this->slug]);
    }

    public function dateText(): string
    {
        $ts = strtotime($this->date);
        return $ts ? date('Y 年 n 月 j 日', $ts) : $this->date;
    }

    public function authorText(): string
    {
        return $this->authors ? implode('、', $this->authors) : '佚名';
    }

    /** 源文件是否可读 */
    public function exists(): bool
    {
        return is_file($this->file);
    }

    public function sourceText(): string
    {
        $src = @file_get_contents($this->file);
        return $src === false ? '' : $src;
    }

    /** 渲染正文（带磁盘缓存） */
    public function render(): array
    {
        if ($this->rendered !== null) {
            return $this->rendered;
        }

        // 缓存键里带上站点根路径：正文里的图片链接是按当前部署路径生成的
        $key = md5(
            $this->slug . '|' . @filemtime($this->file) . '|' . @filesize($this->file)
            . '|' . QUARKDOWN_PARSER_VERSION . '|' . Config::baseUrl()
        );
        $cacheFile = Config::cacheDir() . DIRECTORY_SEPARATOR . md5($this->slug) . '.json';

        if (is_file($cacheFile)) {
            $cached = json_decode((string) @file_get_contents($cacheFile), true);
            if (is_array($cached) && ($cached['key'] ?? '') === $key) {
                return $this->rendered = $cached;
            }
        }

        $meta = Quarkdown::frontMatter($this->sourceText());
        $out  = Quarkdown::toHtml($this->sourceText(), $this->slug, $meta['macros'] ?? []);

        $result = [
            'key'      => $key,
            'html'     => $out['html'],
            'hasMath'  => $out['hasMath'],
            'headings' => $out['headings'],
            'macros'   => $out['macros'],
            'doctype'  => $out['doctype'],
        ];

        $dir = Config::cacheDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($cacheFile, json_encode($result, JSON_UNESCAPED_UNICODE));

        return $this->rendered = $result;
    }

    public function html(): string
    {
        return $this->render()['html'];
    }

    public function hasMath(): bool
    {
        return (bool) $this->render()['hasMath'];
    }

    public function macros(): array
    {
        return $this->render()['macros'] ?? [];
    }

    public function doctype(): string
    {
        return $this->render()['doctype'] ?? 'plain';
    }

    /** .docdescription 里有不少是作者随手写的内部标记，过滤掉 */
    public function descriptionText(): string
    {
        $d = trim($this->description);
        if ($d === '' || mb_strlen($d) < 4) {
            return '';
        }
        if (preg_match('/^[0-9\s\.\-\/]+$/', $d)) {
            return '';
        }
        if (preg_match('/(practice|practive|preactice|chanming|untitled|todo|demo|test)/i', $d)) {
            return '';
        }
        return $d;
    }

    /** 把正文 HTML 压成纯文本（公式替换成「公式」占位） */
    private function toText(string $html): string
    {
        // 先去掉公式元素里的原始 TeX 回退文本，再整体替换成占位符
        $html = preg_replace('/<span class="qd-tex-src">.*?<\/span>/s', ' ', $html) ?? $html;
        $html = preg_replace('#<(span|div) class="qd-math[^"]*"[^>]*>\s*</(?:span|div)>#s', ' 公式 ', $html) ?? $html;
        $html = preg_replace('#<(figure|nav)[^>]*>.*?</\1>#s', ' ', $html) ?? $html;
        $html = preg_replace('#<figcaption>.*?</figcaption>#s', ' ', $html) ?? $html;
        $html = preg_replace('#<span class="qd-eq-label">(.*?)</span>#s', ' ', $html) ?? $html;
        // 块级标签之间补空格，避免 strip_tags 之后文字黏在一起
        $html = preg_replace('#</(p|div|li|ul|ol|h[1-6]|figure|figcaption|nav|blockquote)>#i', ' ', $html) ?? $html;

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    /** 纯文本（整个正文） */
    public function plainText(): string
    {
        return $this->toText($this->html());
    }

    /** 第一段像样的段落，用作摘要更自然 */
    private function firstParagraph(): string
    {
        if (!preg_match_all('#<p>(.*?)</p>#s', $this->html(), $m)) {
            return '';
        }
        foreach ($m[1] as $fragment) {
            $text = $this->toText($fragment);
            $plain = str_replace('公式', '', $text);
            if (mb_strlen($plain) >= 40) {     // 太短的（页眉、作者简介）不算摘要
                return $text;
            }
        }
        return '';
    }

    /** 卡片摘要 */
    public function excerpt(int $length = 110): string
    {
        $text = $this->firstParagraph();
        if ($text === '') {
            $text = $this->plainText();
        }

        // 去掉开头与标题重复的部分
        if ($this->title !== '' && str_starts_with($text, $this->title)) {
            $text = trim(mb_substr($text, mb_strlen($this->title)));
        }
        $text = trim($text, " \t\n。，,.;；");

        if ($text === '' && $this->descriptionText() !== '') {
            $text = $this->descriptionText();
        }
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, $length), " \t\n。，,.;；") . '…';
    }

    public function readingMinutes(): int
    {
        $len = mb_strlen($this->plainText());
        return max(1, (int) ceil($len / 400));
    }
}
