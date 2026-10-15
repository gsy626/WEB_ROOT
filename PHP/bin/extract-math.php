#!/usr/bin/env php
<?php
/**
 * 把站内所有文章的公式抽出来，写到 data/cache/math-audit.json，
 * 供 bin/check-katex.js 用本地 KaTeX 逐条试渲染。
 *
 *   php bin/extract-math.php
 */
declare(strict_types=1);

require __DIR__ . '/../lib/compat.php';

const QUARKDOWN_PARSER_VERSION = '3';

require __DIR__ . '/../lib/Config.php';
require __DIR__ . '/../lib/Quarkdown.php';
require __DIR__ . '/../lib/Post.php';
require __DIR__ . '/../lib/PostRepo.php';

$pattern = '/class="qd-math[^"]*"\s+data-tex="(.*?)"\s+data-display="(\d)"/s';

$all = [];
foreach (PostRepo::all() as $post) {
    $rendered = $post->render();
    $items    = [];
    if (preg_match_all($pattern, $rendered['html'], $m, PREG_SET_ORDER)) {
        foreach ($m as $one) {
            $items[] = [
                'tex'     => html_entity_decode($one[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'display' => (int) $one[2],
            ];
        }
    }
    $all[] = [
        'slug'   => $post->slug,
        'title'  => $post->title,
        'macros' => $rendered['macros'],
        'items'  => $items,
    ];
}

$out = Config::cacheDir() . DIRECTORY_SEPARATOR . 'math-audit.json';
if (!is_dir(dirname($out))) {
    mkdir(dirname($out), 0775, true);
}
file_put_contents($out, json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$n = array_sum(array_map(static fn(array $a): int => count($a['items']), $all));
printf("已导出 %d 条公式（%d 篇文章）→ %s\n", $n, count($all), $out);
