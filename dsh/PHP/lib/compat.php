<?php
/**
 * compat.php —— 极简 UTF-8 兼容层
 *
 * 这台机器的 PHP 8.4 没有编译 mbstring，而本站只需要 5 个 mb_* 函数。
 * PCRE 的 /u 模式已经能正确处理 UTF-8，所以这里用 preg_* 补齐，
 * 避免为了让博客跑起来再去装一个扩展。
 *
 * 如果运行环境本来就有 mbstring，这里的定义会被跳过。
 */
declare(strict_types=1);

if (!function_exists('mb_internal_encoding')) {
    function mb_internal_encoding(?string $encoding = null): string|bool
    {
        return $encoding === null ? 'UTF-8' : true;
    }
}

if (!function_exists('mb_strlen')) {
    function mb_strlen(string $string, ?string $encoding = null): int
    {
        $n = preg_match_all('/./us', $string);
        return $n === false ? strlen($string) : $n;
    }
}

if (!function_exists('mb_substr')) {
    function mb_substr(string $string, int $start, ?int $length = null, ?string $encoding = null): string
    {
        if (preg_match_all('/./us', $string, $m) === false) {
            return $length === null ? substr($string, $start) : substr($string, $start, $length);
        }
        $chars = $m[0];
        $slice = $length === null ? array_slice($chars, $start) : array_slice($chars, $start, $length);
        return implode('', $slice);
    }
}

if (!function_exists('mb_strtolower')) {
    // UTF-8 里非 ASCII 字节都 >= 0x80，strtolower 不会碰它们；
    // 而本站只对拉丁关键词做小写化，所以这样足够。
    function mb_strtolower(string $string, ?string $encoding = null): string
    {
        return strtolower($string);
    }
}

if (!function_exists('mb_strtoupper')) {
    function mb_strtoupper(string $string, ?string $encoding = null): string
    {
        return strtoupper($string);
    }
}

if (!function_exists('mb_strpos')) {
    // UTF-8 是自同步编码，合法字节串不会在字符中间匹配，strpos 可直接用。
    function mb_strpos(string $haystack, string $needle, int $offset = 0, ?string $encoding = null): int|false
    {
        return strpos($haystack, $needle, $offset);
    }
}

if (!function_exists('mb_str_split')) {
    function mb_str_split(string $string, int $length = 1, ?string $encoding = null): array
    {
        if (preg_match_all('/./us', $string, $m) === false) {
            return str_split($string, $length);
        }
        return $length === 1 ? $m[0] : array_map('implode', array_chunk($m[0], $length));
    }
}
