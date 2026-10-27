<?php
/**
 * 全站统一导航栏
 *
 * 三个主项（首页 / 分类 / 关于）都是 <details>，点击即向下展开子菜单 ——
 * 用原生 HTML 元素实现，不需要任何 JavaScript。
 */
/** @var string $nav 当前激活的主项 */

$navItems = [
    'home' => [
        'label' => '首页',
        'items' => [
            ['最新文章', Config::url('index.php')],
            ['全部文章', Config::url('index.php') . '#all'],
            ['数理最新', Config::url('index.php', ['c' => 'math'])],
            ['休闲最新', Config::url('index.php', ['c' => 'leisure'])],
        ],
    ],
    'category' => [
        'label' => '分类',
        'items' => [
            ['分类总览', Config::url('category.php')],
            ['数理 · 推导与证明', Config::url('category.php', ['c' => 'math'])],
            ['休闲 · 小说随笔', Config::url('category.php', ['c' => 'leisure'])],
        ],
    ],
    'about' => [
        'label' => '关于',
        'items' => [
            ['关于本站', Config::url('about.php')],
            ['提交新博客', Config::url('submit.php')],
            ['评论与数据库', Config::url('about.php') . '#comments'],
            ['站点结构', Config::url('about.php') . '#structure'],
        ],
    ],
];
?>
<nav class="site-nav" aria-label="主导航">
<?php foreach ($navItems as $key => $item): ?>
  <details class="nav-item<?= $nav === $key ? ' is-active' : '' ?>">
    <summary class="nav-toggle">
      <span class="nav-label"><?= e($item['label']) ?></span>
      <span class="nav-caret" aria-hidden="true"></span>
    </summary>
    <ul class="nav-menu">
    <?php foreach ($item['items'] as [$label, $href]): ?>
      <li><a href="<?= e($href) ?>"><?= e($label) ?></a></li>
    <?php endforeach; ?>
    </ul>
  </details>
<?php endforeach; ?>
</nav>
