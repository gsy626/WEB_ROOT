<?php
/**
 * 整站骨架：<head> + 导航栏 + 页脚
 *
 * 变量来自 View::page()：$title $nav $bodyClass $styles $content $math $macros $description
 */
/** @var string $title */
/** @var string $nav */
/** @var string $bodyClass */
/** @var array $styles */
/** @var string $content */
/** @var bool $math */
/** @var array $macros */
/** @var string $description */

$asset = static function (string $rel): string {
    $abs = Config::path($rel);
    $v   = is_file($abs) ? (string) filemtime($abs) : '1';
    return Config::url($rel, ['v' => $v]);
};
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e(Config::SITE_NAME) ?></title>
<meta name="description" content="<?= e(mb_substr($description, 0, 150)) ?>">
<meta name="generator" content="PHP + SQLite + KaTeX">
<link rel="stylesheet" href="<?= e($asset('assets/css/base.css')) ?>">
<?php foreach ($styles as $sheet): ?>
<link rel="stylesheet" href="<?= e($asset('assets/css/' . $sheet)) ?>">
<?php endforeach; ?>
<?php if ($math): ?>
<link rel="stylesheet" href="<?= e($asset('assets/katex/katex.min.css')) ?>">
<?php endif; ?>
</head>
<body class="<?= e($bodyClass) ?>">

<a class="skip-link" href="#main">跳到正文</a>

<header class="site-header">
  <div class="header-inner">
    <a class="brand" href="<?= e(Config::url('index.php')) ?>">
      <span class="brand-mark">郭</span>
      <span class="brand-text">
        <span class="brand-name"><?= e(Config::SITE_NAME) ?></span>
        <span class="brand-sub"><?= e(Config::SITE_SUBTITLE) ?></span>
      </span>
    </a>
    <?php include Config::path('templates', 'nav.php'); ?>
  </div>
</header>

<main class="site-main" id="main">
<?= $content ?>
</main>

<footer class="site-footer">
  <div class="footer-inner">
    <p>
      <?= e(Config::SITE_NAME) ?> ·
      数理 <strong><?= (int) PostRepo::countByCategory()['math'] ?></strong> 篇 ·
      休闲 <strong><?= (int) PostRepo::countByCategory()['leisure'] ?></strong> 篇
    </p>
    <p class="footer-links">
      <a href="<?= e(Config::url('index.php')) ?>">首页</a>
      <a href="<?= e(Config::url('category.php')) ?>">分类</a>
      <a href="<?= e(Config::url('about.php')) ?>">关于</a>
      <a href="<?= e(Config::url('submit.php')) ?>">提交新博客</a>
    </p>
    <p class="footer-note">由 PHP + SQLite 驱动，公式使用本地 KaTeX 渲染。</p>
  </div>
</footer>

<?php if ($math): ?>
<script>window.QD_KATEX_MACROS = <?= json_encode($macros, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="<?= e($asset('assets/katex/katex.min.js')) ?>"></script>
<script src="<?= e($asset('assets/js/katex-render.js')) ?>"></script>
<?php endif; ?>
</body>
</html>
