<?php
/**
 * Quarkdown —— 把 qd/ 目录里的 .qd 源文档转换成站内 HTML
 *
 * 为什么不用现成的 quarkdown 输出？因为那套 HTML 依赖 quarkdown.min.js 在浏览器里
 * 二次渲染，而且杂糅了整站主题。这里用 PHP 做一次干净的转换：
 *   · 结构（标题 / 段落 / 列表 / 图 / 提示框 / 公式块）由 PHP 解析
 *   · 公式本身仍然交给 KaTeX 渲染（KaTeX 只有 JS 版本），但只保留这一个 JS
 *
 * 只实现 qd/ 实际用到的语法子集，因此可读、可维护。
 */
declare(strict_types=1);

require_once __DIR__ . '/Config.php';

final class Quarkdown
{
    /** @var array<string,string> TeX 宏，来自 .texmacro 指令 */
    private array $macros;

    /** @var array<int,array{level:int,id:string,text:string}> */
    private array $headings = [];

    private bool $hasMath = false;
    private bool $wantsToc = false;
    private string $doctype = 'plain';
    private string $slug;
    private int $headingSeq = 0;

    private function __construct(string $slug, array $macros = [])
    {
        $this->slug   = $slug;
        $this->macros = $macros + self::defaultMacros();
    }

    /** KaTeX 默认宏：\bvec 是作者自定义的，其余为兜底 */
    public static function defaultMacros(): array
    {
        return [
            '\\bvec' => '\\overrightarrow{#1}',
        ];
    }

    /**
     * 读取文档元信息（不解析正文，列表页用它，速度更快）。
     *
     * @return array{name?:string,description?:string,category?:string,authors:string[],firstHeading?:string,doctype:string,macros:array}
     */
    public static function frontMatter(string $source): array
    {
        $src = self::normalize($source);

        $info = [
            'authors' => [],
            'doctype' => 'plain',
            'macros'  => [],
        ];

        if (preg_match('/\.docname\s*\{([^}]*)\}/u', $src, $m)) {
            $info['name'] = trim($m[1]);
        }
        if (preg_match('/\.docdescription\s*\{([^}]*)\}/u', $src, $m)) {
            $info['description'] = trim($m[1]);
        }
        if (preg_match('/\.doccategory\s*\{([^}]*)\}/u', $src, $m)) {
            $info['category'] = trim($m[1]);
        }
        if (preg_match('/\.doctype\s*\{([^}]*)\}/u', $src, $m)) {
            $info['doctype'] = trim($m[1]) ?: 'plain';
        }

        // 作者：.docauthors 后面的 - 列表
        if (preg_match('/\.docauthors\b[^\n]*\n((?:[ \t]*[-*]\s*[^\n]*\n?)+)/u', $src, $m)) {
            preg_match_all('/[-*]\s*([^\n]+)/u', $m[1], $mm);
            $info['authors'] = array_values(array_filter(array_map('trim', $mm[1])));
        }

        // TeX 宏：.texmacro {\bvec} {\overrightarrow{#1}}
        $offset = 0;
        while (($pos = strpos($src, '.texmacro', $offset)) !== false) {
            $offset = $pos + 9;
            $cur    = self::skipSpace($src, $offset);
            $name   = self::readBrace($src, $cur);
            if ($name === null) {
                continue;
            }
            $cur2 = self::skipSpace($src, $name[1]);
            $body = self::readBrace($src, $cur2);
            if ($body === null) {
                continue;
            }
            $key = '\\' . ltrim(trim($name[0]), '\\');
            if ($key !== '\\') {
                $info['macros'][$key] = $body[0];
            }
        }

        // 第一个「大标题」：只认 1~3 级，四级以下多是文内小标题
        // （比如 Elliptical 那篇只有 #### ，标题就该用 .docname）
        // 允许前置缩进：蝉鸣那篇的标题在 .center 里
        if (preg_match('/^[ \t]*#{1,3}!?[ \t]+(.+)$/mu', $src, $m)) {
            $text = preg_replace('/\$[^$]*\$/u', '', $m[1]) ?? $m[1];
            $info['firstHeading'] = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        }

        return $info;
    }

    /**
     * 完整渲染。
     *
     * @return array{html:string,headings:array,macros:array,hasMath:bool,doctype:string}
     */
    public static function toHtml(string $source, string $slug, array $macros = []): array
    {
        $parser = new self($slug, $macros);
        $src    = self::normalize($source);
        $lines  = explode("\n", $src);
        $i      = 0;
        $html   = $parser->blocks($lines, $i, 0);

        if ($parser->wantsToc) {
            $html = str_replace('<!--QD_TOC-->', $parser->renderToc(), $html);
        }

        return [
            'html'     => $html,
            'headings' => $parser->headings,
            'macros'   => $parser->macros,
            'hasMath'  => $parser->hasMath,
            'doctype'  => $parser->doctype,
        ];
    }

    // ---------------------------------------------------------------- 规范化

    private static function normalize(string $src): string
    {
        $src = str_replace(["\r\n", "\r"], "\n", $src);
        $src = str_replace("\t", '    ', $src);              // 缩进统一成空格
        // 去掉 <!-- --> / <!--- ---> 注释（作者用它注释掉草稿公式）
        $src = preg_replace('/<!-{2,}.*?-{2,}>/s', '', $src) ?? $src;
        return $src;
    }

    private static function skipSpace(string $s, int $pos): int
    {
        $len = strlen($s);
        while ($pos < $len && ($s[$pos] === ' ' || $s[$pos] === "\n")) {
            $pos++;
        }
        return $pos;
    }

    /**
     * 从 $pos（必须指向 '{'）读取成对花括号内容，支持嵌套。
     * @return array{0:string,1:int}|null [内容, 结束位置]
     */
    private static function readBrace(string $s, int $pos): ?array
    {
        if (($s[$pos] ?? '') !== '{') {
            return null;
        }
        $depth = 0;
        $out   = '';
        $len   = strlen($s);
        for ($k = $pos; $k < $len; $k++) {
            $ch = $s[$k];
            if ($ch === '{') {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return [$out, $k + 1];
                }
            }
            $out .= $ch;
        }
        return null;
    }

    // ------------------------------------------------------------- 块级解析

    private function indentOf(string $line): int
    {
        return strlen($line) - strlen(ltrim($line, ' '));
    }

    /** 主循环：解析 $minIndent 及更深缩进的块 */
    private function blocks(array $lines, int &$i, int $minIndent): string
    {
        $out = '';
        $n   = count($lines);

        while ($i < $n) {
            if (trim($lines[$i]) === '') {
                $i++;
                continue;
            }
            $indent = $this->indentOf($lines[$i]);
            if ($indent < $minIndent) {
                break;
            }
            $text = trim($lines[$i]);

            // 1. $$$ 公式块
            if (str_starts_with($text, '$$$')) {
                $out .= $this->mathFence($lines, $i);
                continue;
            }
            // 2. 标题
            if (preg_match('/^(#{1,6})(!?)[ \t]+(.*)$/u', $text, $m)) {
                $out .= $this->heading((int) strlen($m[1]), $m[3]);
                $i++;
                continue;
            }
            // 3. 独占一行的公式（Quarkdown 里 $...$ 单独成行 = 行间公式）
            if ($this->isStandaloneMath($text)) {
                $out .= $this->mathBlock(substr($text, 1, -1));
                $i++;
                continue;
            }
            // 4. 图片独占一行
            $img = $this->imageHtml($text);
            if ($img !== null) {
                $out .= $img;
                $i++;
                continue;
            }
            // 5. 列表
            if (preg_match('/^[-*+][ \t]+/u', $text)) {
                $out .= $this->listBlock($lines, $i, $indent);
                continue;
            }
            // 6. 指令
            if ($text[0] === '.') {
                $out .= $this->directive($lines, $i, $indent);
                continue;
            }
            // 7. 段落
            $out .= $this->paragraph($lines, $i, $indent);
        }
        return $out;
    }

    private function isStandaloneMath(string $text): bool
    {
        return strlen($text) > 2
            && $text[0] === '$'
            && str_ends_with($text, '$')
            && substr_count($text, '$') === 2;
    }

    private function paragraph(array $lines, int &$i, int $minIndent): string
    {
        $buf = [];
        $n   = count($lines);

        while ($i < $n) {
            $raw = $lines[$i];
            if (trim($raw) === '') {
                break;
            }
            if ($this->indentOf($raw) < $minIndent) {
                break;
            }
            $t = trim($raw);
            if ($this->startsBlock($t)) {
                break;
            }
            $buf[] = $t;
            $i++;
        }

        if (!$buf) {                       // 保险，避免死循环
            $i++;
            return '';
        }
        return '<p>' . $this->inline(implode("\n", $buf)) . '</p>';
    }

    private function startsBlock(string $t): bool
    {
        if (str_starts_with($t, '$$$')) {
            return true;
        }
        if (preg_match('/^#{1,6}(!?)[ \t]/u', $t)) {
            return true;
        }
        if (preg_match('/^[-*+][ \t]/u', $t)) {
            return true;
        }
        if (preg_match('/^\.[A-Za-z]/u', $t)) {
            return true;
        }
        if ($this->isStandaloneMath($t)) {
            return true;
        }
        return $this->imageHtml($t) !== null;
    }

    private function mathFence(array $lines, int &$i): string
    {
        $i++;                              // 跳过开栅栏
        $tex = [];
        $n   = count($lines);
        while ($i < $n && !str_starts_with(trim($lines[$i]), '$$$')) {
            $tex[] = trim($lines[$i]);
            $i++;
        }
        if ($i < $n) {
            $i++;                          // 跳过闭栅栏
        }
        return $this->mathBlock(implode("\n", $tex));
    }

    private function heading(int $level, string $text): string
    {
        $level = max(1, min(6, $level));
        $text  = trim($text);
        $plain = trim(preg_replace('/\s+/u', ' ', strip_tags($this->inline($text))) ?? '');
        $id    = 'h' . (++$this->headingSeq) . '-' . self::slugify($plain);

        $this->headings[] = ['level' => $level, 'id' => $id, 'text' => $plain];

        return sprintf('<h%d id="%s">%s</h%d>', $level, $id, $this->inline($text), $level);
    }

    private static function slugify(string $text): string
    {
        $s = preg_replace('/[^\p{L}\p{N}]+/u', '-', $text) ?? '';
        $s = trim($s, '-');
        $s = mb_substr($s, 0, 40);
        return $s === '' ? 'section' : $s;
    }

    private function listBlock(array $lines, int &$i, int $indent): string
    {
        $items = [];
        $n     = count($lines);

        while ($i < $n) {
            $raw = $lines[$i];
            if (trim($raw) === '') {
                break;
            }
            if ($this->indentOf($raw) !== $indent) {
                break;
            }
            $t = trim($raw);
            if (!preg_match('/^[-*+][ \t]+(.*)$/u', $t, $m)) {
                break;
            }
            $i++;
            $parts = [$m[1]];

            // 吸收缩进更深的续行，以及同级“懒续行”（Markdown 风格）
            while ($i < $n) {
                $next = $lines[$i];
                if (trim($next) === '') {
                    break;
                }
                $ni = $this->indentOf($next);
                $nt = trim($next);
                if ($ni > $indent) {
                    $parts[] = $nt;
                    $i++;
                    continue;
                }
                if ($ni === $indent && !preg_match('/^[-*+][ \t]/u', $nt) && !$this->startsBlock($nt)) {
                    $parts[] = $nt;
                    $i++;
                    continue;
                }
                break;
            }
            $items[] = '<li>' . $this->inline(implode("\n", $parts)) . '</li>';
        }

        return '<ul>' . implode('', $items) . '</ul>';
    }

    // ------------------------------------------------------------------ 指令

    /** 解析 .name{...} key:{...} ... */
    private function directive(array $lines, int &$i, int $indent): string
    {
        $buffer = trim($lines[$i]);
        $i++;
        // 花括号可能跨行闭合
        while ($this->braceBalance($buffer) > 0 && $i < count($lines)) {
            $buffer .= "\n" . $lines[$i];
            $i++;
        }
        $buffer = trim($buffer);

        if (!preg_match('/^\.([A-Za-z][A-Za-z0-9_-]*)/', $buffer, $m)) {
            return '';
        }
        $name = strtolower($m[1]);
        [$positional, $named] = $this->parseArgs(substr($buffer, strlen($m[0])));

        switch ($name) {
            // ---- 元信息：只记录，不渲染 ----
            case 'docname':
            case 'docdescription':
            case 'doccategory':
            case 'doclang':
                $this->consumeRawBody($lines, $i, $indent);
                return '';
            case 'docauthors':
                // 有的文件里作者列表没有缩进（如 shift-of-the-extremum-point）
                $this->consumeRawBody($lines, $i, $indent, true);
                return '';
            case 'theme':
            case 'paragraphstyle':
            case 'texmacro':
            case 'pagemargin':
                if ($name === 'pagemargin') {
                    $this->consumeRawBody($lines, $i, $indent);
                }
                return '';
            case 'doctype':
                $this->doctype = trim($positional[0] ?? 'plain') ?: 'plain';
                return '';
            case 'function':
                // 用户自定义函数（本转换器不实现函数展开），整体丢弃
                $this->consumeRawBody($lines, $i, $indent);
                return '';

            // ---- 结构 ----
            case 'pagebreak':
                return '<div class="qd-pagebreak" role="separator"></div>';
            case 'tableofcontents':
                $this->wantsToc = true;
                return '<!--QD_TOC-->';
            case 'center':
                return '<div class="qd-center">' . $this->body($lines, $i, $indent) . '</div>';
            case 'container':
                return '<div class="qd-container">' . $this->body($lines, $i, $indent) . '</div>';
            case 'row':
                return '<div class="qd-row">' . $this->body($lines, $i, $indent) . '</div>';
            case 'column':
                return '<div class="qd-column">' . $this->body($lines, $i, $indent) . '</div>';
            case 'box':
                return $this->callout($positional[0] ?? '', $named['type'] ?? 'note', $lines, $i, $indent);
            case 'eq':
                return $this->equation($positional, $lines, $i, $indent);
            case 'image':
                return $this->figure(
                    $positional[0] ?? '',
                    $named['label'] ?? '',
                    $named['width'] ?? ''
                );
            case 'currentpage':
                return '';
            default:
                // 未知指令：连它的缩进正文一起丢掉，避免漏出源码
                if ($this->hasIndentedBody($lines, $i, $indent)) {
                    $this->consumeRawBody($lines, $i, $indent);
                }
                return '';
        }
    }

    private function braceBalance(string $s): int
    {
        return substr_count($s, '{') - substr_count($s, '}');
    }

    private function hasIndentedBody(array $lines, int $i, int $indent): bool
    {
        return $i < count($lines)
            && trim($lines[$i]) !== ''
            && $this->indentOf($lines[$i]) > $indent;
    }

    /** 取出缩进正文，作为 HTML */
    private function body(array $lines, int &$i, int $indent): string
    {
        if ($this->hasIndentedBody($lines, $i, $indent)) {
            $bodyIndent = $this->indentOf($lines[$i]);
            return $this->blocks($lines, $i, $bodyIndent);
        }
        // 没有缩进正文：把紧接着的那一个元素当作正文
        if ($i < count($lines) && trim($lines[$i]) !== '') {
            $one = [];
            $one[] = $lines[$i];
            $j = 0;
            $k = $i + 1;
            $tmp = $one;
            $html = $this->blocks($tmp, $j, 0);
            if ($html !== '' && trim($html) !== '') {
                $i = $k;
                return $html;
            }
        }
        return '';
    }

    /** 取出缩进正文，但返回原始文本（用于元信息指令） */
    private function consumeRawBody(array $lines, int &$i, int $indent, bool $allowLooseList = false): string
    {
        $raw = [];
        $n   = count($lines);

        // 没有缩进、但紧接着一个同级列表的情况（.docauthors 的另一种写法）
        if ($allowLooseList) {
            $j = $i;
            while ($j < $n && trim($lines[$j]) === '') {
                $j++;
            }
            if ($j < $n && $this->indentOf($lines[$j]) <= $indent
                && preg_match('/^[-*+][ \t]+/u', trim($lines[$j]))) {
                while ($i < $n && trim($lines[$i]) !== ''
                    && preg_match('/^[-*+][ \t]+/u', trim($lines[$i]))) {
                    $raw[] = trim($lines[$i]);
                    $i++;
                }
                return implode("\n", $raw);
            }
        }

        while ($i < $n) {
            if (trim($lines[$i]) === '') {
                // 允许正文中间的空行
                if (isset($lines[$i + 1]) && trim($lines[$i + 1]) !== ''
                    && $this->indentOf($lines[$i + 1]) > $indent) {
                    $raw[] = '';
                    $i++;
                    continue;
                }
                break;
            }
            if ($this->indentOf($lines[$i]) <= $indent) {
                break;
            }
            $raw[] = trim($lines[$i]);
            $i++;
        }
        return implode("\n", $raw);
    }

    /** @return array{0:string[],1:array<string,string>} */
    private function parseArgs(string $s): array
    {
        $positional = [];
        $named      = [];
        $len        = strlen($s);
        $k          = 0;

        while ($k < $len) {
            while ($k < $len && ctype_space($s[$k])) {
                $k++;
            }
            if ($k >= $len) {
                break;
            }
            if ($s[$k] === '{') {
                $r = self::readBrace($s, $k);
                if ($r === null) {
                    break;
                }
                $positional[] = $r[0];
                $k = $r[1];
                continue;
            }
            // 读一个词
            $word = '';
            while ($k < $len && !ctype_space($s[$k]) && $s[$k] !== '{' && $s[$k] !== ':') {
                $word .= $s[$k];
                $k++;
            }
            $j = $k;
            while ($j < $len && ctype_space($s[$j])) {
                $j++;
            }
            if ($j < $len && $s[$j] === ':') {
                $j++;
                while ($j < $len && ctype_space($s[$j])) {
                    $j++;
                }
                if ($j < $len && $s[$j] === '{') {
                    $r = self::readBrace($s, $j);
                    if ($r !== null) {
                        $named[$word] = $r[0];
                        $k = $r[1];
                        continue;
                    }
                }
                // key:value（无花括号）
                $val = '';
                while ($j < $len && !ctype_space($s[$j])) {
                    $val .= $s[$j];
                    $j++;
                }
                $named[$word] = $val;
                $k = $j;
                continue;
            }
            if ($word !== '') {
                $positional[] = $word;
            }
            $k = $j;
        }

        return [$positional, $named];
    }

    private function callout(string $title, string $type, array $lines, int &$i, int $indent): string
    {
        $type  = preg_replace('/[^a-z]/', '', strtolower($type)) ?: 'note';
        $inner = $this->body($lines, $i, $indent);
        $head  = trim($title) === ''
            ? ''
            : '<div class="qd-box-title">' . $this->inline($title) . '</div>';
        return sprintf(
            '<div class="qd-box qd-box-%s">%s<div class="qd-box-body">%s</div></div>',
            $type,
            $head,
            $inner
        );
    }

    /**
     * .eq —— 作者在源文件里自定义的「公式卡片」
     *   .eq{标题}{公式}  → 带标题的公式卡
     *   .eq{公式}        → 直接居中显示
     */
    private function equation(array $args, array $lines, int &$i, int $indent): string
    {
        $args = array_values(array_filter($args, static fn($a) => trim($a) !== ''));
        if (!$args) {
            $this->consumeRawBody($lines, $i, $indent);
            return '';
        }
        if (count($args) === 1) {
            return '<div class="qd-eq">' . $this->richBlock($args[0]) . '</div>';
        }
        return '<div class="qd-eq qd-eq-labeled">'
            . '<div class="qd-eq-label">' . $this->inline($args[0]) . '</div>'
            . '<div class="qd-eq-body">' . $this->richBlock($args[1]) . '</div>'
            . '</div>';
    }

    /**
     * 用于指令参数内的多行内容：行间公式按公式块渲染，其余按段落。
     */
    private function richBlock(string $content): string
    {
        $out = '';
        $buf = [];
        foreach (explode("\n", $content) as $line) {
            $t = trim($line);
            if ($t === '') {
                if ($buf) {
                    $out .= '<p>' . $this->inline(implode("\n", $buf)) . '</p>';
                    $buf = [];
                }
                continue;
            }
            if ($this->isStandaloneMath($t)) {
                if ($buf) {
                    $out .= '<p>' . $this->inline(implode("\n", $buf)) . '</p>';
                    $buf = [];
                }
                $out .= $this->mathBlock(substr($t, 1, -1));
                continue;
            }
            if (str_starts_with($t, '$$$')) {
                continue;
            }
            $buf[] = $t;
        }
        if ($buf) {
            $out .= '<p>' . $this->inline(implode("\n", $buf)) . '</p>';
        }
        return $out;
    }

    // -------------------------------------------------------------- 行内格式

    private function inline(string $text): string
    {
        $store = [];
        $keep  = function (string $html) use (&$store): string {
            $key          = "\x01QD" . count($store) . "\x02";
            $store[$key]  = $html;
            return $key;
        };

        // 1. 图片 !(宽)[说明](路径) / ![说明](路径)
        $text = preg_replace_callback(
            '/!\(([^)]*)\)\[([^\]]*)\]\(([^)]+)\)/u',
            fn($m) => $keep($this->figure($m[3], $m[2], $m[1])),
            $text
        ) ?? $text;
        $text = preg_replace_callback(
            '/!\[([^\]]*)\]\(([^)]+)\)/u',
            function ($m) use ($keep) {
                $alt = $m[1];
                if (preg_match('/^[0-9.]+%$/', trim($alt))) {
                    return $keep($this->figure($m[2], '', $alt));
                }
                return $keep($this->figure($m[2], $alt, ''));
            },
            $text
        ) ?? $text;

        // 2. 行内公式 $...$
        $text = preg_replace_callback(
            '/\$(?!\$)([^$]+?)\$(?!\$)/su',
            fn($m) => $keep($this->mathSpan($m[1])),
            $text
        ) ?? $text;

        // 3. 链接 [文字](地址)
        $text = preg_replace_callback(
            '/\[([^\]]+)\]\(([^)\s]+)\)/u',
            function ($m) use ($keep) {
                $href = $m[2];
                if (!preg_match('#^(https?://|mailto:|/|\#)#i', $href)) {
                    return $m[0];
                }
                return $keep('<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8')
                    . '" rel="noopener">' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . '</a>');
            },
            $text
        ) ?? $text;

        // 4. 转义剩余文本
        $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // 5. 粗体 / 斜体 / 行内代码
        $text = preg_replace('/`([^`]+)`/u', '<code>$1</code>', $text) ?? $text;
        $text = preg_replace('/\*\*(?!\s)(.+?)(?<!\s)\*\*/su', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/(?<![\*\w])\*(?!\s)([^\*]+?)(?<!\s)\*(?!\*)/su', '<em>$1</em>', $text) ?? $text;

        // 6. 还原占位
        return strtr($text, $store);
    }

    private function mathSpan(string $tex): string
    {
        return $this->math($tex, false);
    }

    private function mathBlock(string $tex): string
    {
        return $this->math($tex, true);
    }

    private function math(string $tex, bool $display): string
    {
        $tex = trim($tex);
        if ($tex === '') {
            return '';
        }
        $this->hasMath = true;

        // 作者有时把中文直接写在数学模式里（例如「即」「（C_i可以是…）」），
        // KaTeX 会报警告且字体难看；这里把裸露的中文包进 \text{}，
        // 已经写在 \text{} 里的保持原样。
        $texAttr = self::wrapCjk($tex);

        $tag     = $display ? 'div' : 'span';
        $class   = 'qd-math ' . ($display ? 'qd-math-block' : 'qd-math-inline');
        $attr    = htmlspecialchars($texAttr, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $raw     = htmlspecialchars(($display ? '$$' : '$') . $tex . ($display ? '$$' : '$'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return sprintf(
            '<%1$s class="%2$s" data-tex="%3$s" data-display="%4$d"><span class="qd-tex-src">%5$s</span></%1$s>',
            $tag,
            $class,
            $attr,
            $display ? 1 : 0,
            $raw
        );
    }

    /** 裸写的中日韩字符包一层 \text{} */
    private static function wrapCjk(string $tex): string
    {
        if (!preg_match('/[\p{Han}\x{3000}-\x{303F}\x{FF01}-\x{FF5E}]/u', $tex)) {
            return $tex;
        }
        $chars = preg_split('//u', $tex, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            return $tex;
        }
        $n   = count($chars);
        $out = '';
        $i   = 0;
        $keepCommands = ['text', 'textrm', 'textit', 'textbf', 'mathrm', 'mbox', 'operatorname'];

        while ($i < $n) {
            if ($chars[$i] === '\\') {
                $j   = $i + 1;
                $cmd = '';
                while ($j < $n && ctype_alpha($chars[$j])) {
                    $cmd .= $chars[$j];
                    $j++;
                }
                if (in_array($cmd, $keepCommands, true)) {
                    $k = $j;
                    while ($k < $n && ctype_space($chars[$k])) {
                        $k++;
                    }
                    if ($k < $n && $chars[$k] === '{') {
                        $depth = 0;
                        $end   = $n - 1;
                        for ($p = $k; $p < $n; $p++) {
                            if ($chars[$p] === '{') {
                                $depth++;
                            } elseif ($chars[$p] === '}') {
                                $depth--;
                                if ($depth === 0) {
                                    $end = $p;
                                    break;
                                }
                            }
                        }
                        $out .= implode('', array_slice($chars, $i, $end - $i + 1));
                        $i = $end + 1;
                        continue;
                    }
                }
                $take = max(1, $j - $i);
                $out .= implode('', array_slice($chars, $i, $take));
                $i += $take;
                continue;
            }

            if (self::isCjkChar($chars[$i])) {
                $run = '';
                while ($i < $n && self::isCjkChar($chars[$i])) {
                    $run .= $chars[$i];
                    $i++;
                }
                $out .= '\\text{' . $run . '}';
                continue;
            }

            $out .= $chars[$i];
            $i++;
        }
        return $out;
    }

    private static function isCjkChar(string $ch): bool
    {
        return (bool) preg_match('/^[\p{Han}\x{3000}-\x{303F}\x{FF01}-\x{FF5E}]$/u', $ch);
    }

    // ------------------------------------------------------------------ 图片

    /** 整行就是一个图片时返回 HTML，否则 null */
    private function imageHtml(string $text): ?string
    {
        if (!str_starts_with($text, '!')) {
            return null;
        }
        if (preg_match('/^!\(([^)]*)\)\[([^\]]*)\]\(([^)]+)\)$/u', $text, $m)) {
            return $this->figure($m[3], $m[2], $m[1]);
        }
        if (preg_match('/^!\[([^\]]*)\]\(([^)]+)\)$/u', $text, $m)) {
            if (preg_match('/^[0-9.]+%$/', trim($m[1]))) {
                return $this->figure($m[2], '', $m[1]);
            }
            return $this->figure($m[2], $m[1], '');
        }
        return null;
    }

    private function figure(string $path, string $alt, string $width): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        $src   = $this->mediaUrl($path);
        $alt   = trim($alt);
        $width = trim($width);
        $style = '';
        if ($width !== '') {
            // 带 % 的按百分比；纯数字按像素（如 !(200)[...]）
            if (ctype_digit($width)) {
                $width .= 'px';
            }
            if (preg_match('/^[0-9.]+(px|%|em|rem|cm|mm)$/', $width)) {
                $style = ' style="max-width:' . $width . '"';
            }
        }
        $cap = ($alt !== '' && !preg_match('/^[0-9.]+%$/', $alt))
            ? '<figcaption>' . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . '</figcaption>'
            : '';

        return '<figure class="qd-figure"' . $style . '>'
            . '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="'
            . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . '" loading="lazy">'
            . $cap . '</figure>';
    }

    /** 图片走 media.php，不直接暴露磁盘路径 */
    private function mediaUrl(string $path): string
    {
        $p = str_replace('\\', '/', trim($path));
        $p = preg_replace('#^\./#', '', $p) ?? $p;
        return Config::url('media.php', ['p' => $this->slug, 'f' => $p]);
    }

    // ------------------------------------------------------------------ 目录

    private function renderToc(): string
    {
        $items = array_filter($this->headings, static fn($h) => $h['level'] <= 3);
        if (!$items) {
            $items = $this->headings;
        }
        if (!$items) {
            return '';
        }
        $html = '<nav class="qd-toc"><div class="qd-toc-title">目录</div><ol>';
        foreach ($items as $h) {
            $html .= sprintf(
                '<li class="qd-toc-l%d"><a href="#%s">%s</a></li>',
                $h['level'],
                htmlspecialchars($h['id'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($h['text'], ENT_QUOTES, 'UTF-8')
            );
        }
        return $html . '</ol></nav>';
    }
}
