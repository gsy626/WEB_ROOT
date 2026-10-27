<?php
/**
 * 首页 —— 站点介绍 + 最新文章 + 全部文章
 *
 * 可选 ?c=math|leisure 只看某一类。
 */
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

$filter = param('c');
$filter = isset(Config::CATEGORIES[$filter]) ? $filter : '';

$all       = PostRepo::all();
$counts    = PostRepo::countByCategory();
$comments  = CommentRepo::counts();
$recent    = array_slice($all, 0, 3);
$listing   = $filter === '' ? $all : PostRepo::byCategory($filter);

/** 卡片列表 HTML */
$renderCards = static function (array $posts) use ($comments): string {
    if (!$posts) {
        return '<p class="empty">这里还没有文章。</p>';
    }
    $html = '<div class="card-list">';
    foreach ($posts as $post) {
        $html .= View::render('post-card', [
            'post'         => $post,
            'commentCount' => $comments[$post->slug] ?? 0,
        ]);
    }
    return $html . '</div>';
};

$filterBanner = $filter === ''
    ? ''
    : '<p class="filter-banner">正在查看：<strong>' . e(Config::CATEGORIES[$filter])
      . '</strong>（' . count($listing) . ' 篇）<a href="' . e(Config::url('index.php')) . '">显示全部</a></p>';

ob_start();
?>
<section class="hero">
  <p class="hero-kicker">PHP · SQLite · KaTeX</p>
  <h1 class="hero-title"><?= e(Config::SITE_NAME) ?></h1>
  <p class="hero-lead">
    这里放着两类东西：<strong>数理</strong>是推导与证明，公式由 KaTeX 排版；
    <strong>休闲</strong>是小说与随笔，读起来轻松一点。
    每篇下面都可以评论，也可以自己提交一篇新的。
  </p>
  <div class="hero-actions">
    <a class="btn btn-primary" href="<?= e(Config::url('category.php')) ?>">按分类浏览</a>
    <a class="btn" href="<?= e(Config::url('submit.php')) ?>">提交新博客</a>
  </div>
  <dl class="hero-stats">
    <div><dt>数理</dt><dd><?= (int) $counts['math'] ?></dd></div>
    <div><dt>休闲</dt><dd><?= (int) $counts['leisure'] ?></dd></div>
    <div><dt>评论</dt><dd><?= (int) array_sum($comments) ?></dd></div>
  </dl>
</section>

<section class="panel">
  <h2 class="section-title">最新文章</h2>
  <?= $renderCards($recent) ?>
</section>

<section class="panel" id="all">
  <h2 class="section-title">全部文章 <span class="count"><?= count($listing) ?></span></h2>
  <p class="section-tabs">
    <a class="<?= $filter === '' ? 'is-on' : '' ?>" href="<?= e(Config::url('index.php')) ?>">全部</a>
    <?php foreach (Config::CATEGORIES as $key => $name): ?>
      <a class="<?= $filter === $key ? 'is-on' : '' ?>"
         href="<?= e(Config::url('index.php', ['c' => $key])) ?>"><?= e($name) ?></a>
    <?php endforeach; ?>
  </p>
  <?= $filterBanner ?>
  <?= $renderCards($listing) ?>
</section>
<?php
$content = (string) ob_get_clean();

View::page([
    'title'       => '首页',
    'nav'         => 'home',
    'bodyClass'   => 'theme-neutral',
    'styles'      => ['math.css', 'leisure.css'],
    'content'     => $content,
    'description' => '数理与休闲两类内容：数学物理推导，以及小说随笔。',
]);
