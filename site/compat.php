<?php
// UTF-8 fallbacks for PHP builds without the mbstring extension.
// Prefer the native extension (sudo apt install php8.4-mbstring); these
// polyfills only exist so the site still runs when it is missing.

if (!function_exists('mb_strlen')) {
    function mb_strlen($string, $encoding = null)
    {
        $string = (string)$string;
        if ($string === '') {
            return 0;
        }
        $count = preg_match_all('/./us', $string);
        return $count === false ? strlen($string) : $count;
    }
}

if (!function_exists('mb_substr')) {
    function mb_substr($string, $start, $length = null, $encoding = null)
    {
        $string = (string)$string;
        $chars = preg_split('//u', $string, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            return $length === null
                ? substr($string, $start)
                : substr($string, $start, $length);
        }
        $slice = $length === null
            ? array_slice($chars, $start)
            : array_slice($chars, $start, $length);
        return implode('', $slice);
    }
}
