<?php
/**
 * 分类规则
 *
 * 判定顺序（见 lib/PostRepo.php）：
 *   1. 文章自己写的 .doccategory {数理} / {休闲}
 *   2. 本文件的 overrides（按目录名精确指定）
 *   3. 本文件的 keywords（目录名 + 标题 + 摘要里出现的关键词）
 *   4. 都没有 → 归入「数理」
 */
return [
    // 目录名 => 分类（math | leisure），优先级高于关键词
    'overrides' => [
        '2026-6-13=chanming' => 'leisure',
    ],

    // 想改标题的话写在这里：目录名 => 标题
    'titles' => [
        // 'emoji' => 'Quarkdown 试手',
    ],

    // 不想出现在列表里的目录
    'hidden' => [
        // 'emoji',
    ],

    // 关键词兜底
    'keywords' => [
        'leisure' => [
            'chanming', '蝉', '小说', '散文', '随笔', '诗歌', '故事', '书评', '游记', '休闲',
        ],
        'math' => [
            'math', 'normal-distribution', 'fermat', 'extremum', 'optical',
            'charged', 'distance', 'distribution', '数理', '数学', '物理', '证明',
        ],
    ],
];
