<?php
/**
 * 分类页
 *
 *   category.php            两类总览
 *   category.php?c=math     数理（加载 math.css）
 *   category.php?c=leisure  休闲（加载 leisure.css）
 */
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

$key = param('c');

if (!isset(Config::CATEGORIES[$key])) {
    // ---------- 分类总览 ----------
    $counts   = PostRepo::countByCategory();
    $comments = CommentRepo::counts();

    ob_start();
    ?>
    <section class="panel">
      <h1 class="section-title section-title-lg">分类</h1>
      <p class="section-intro">
        全站内容分成两类。两类各自使用独立的样式表：
        <code>assets/css/math.css</code> 与 <code>assets/css/leisure.css</code>。
      </p>
      <div class="cat-grid">
      <?php foreach (Config::CATEGORIES as $k => $name): ?>
        <?php $posts = PostRepo::byCategory($k); ?>
        <section class="cat-block <?= e('theme-' . $k) ?>">
          <header class="cat-head">
            <h2><?= e($name) ?></h2>
            <span class="cat-count"><?= (int) $counts[$k] ?> 篇</span>
          </header>
          <p class="cat-desc">
            <?= $k === 'math'
                ? '数学与物理的推导、证明和计算，公式用 KaTeX 渲染。'
                : '小说、随笔一类，慢慢读的文字。' ?>
          </p>
          <ol class="cat-list">
          <?php foreach (array_slice($posts, 0, 6) as $post): ?>
            <li>
              <a href="<?= e($post->url()) ?>"><?= e($post->title) ?></a>
              <time datetime="<?= e($post->date) ?>"><?= e($post->date) ?></time>
              <span class="cat-comments"><?= (int) ($comments[$post->slug] ?? 0) ?> 评</span>
            </li>
          <?php endforeach; ?>
          <?php if (!$posts): ?>
            <li class="empty">暂无文章</li>
          <?php endif; ?>
          </ol>
          <p><a class="btn btn-small" href="<?= e(Config::url('category.php', ['c' => $k])) ?>">
            进入「<?= e($name) ?>」分类
          </a></p>
        </section>
      <?php endforeach; ?>
      </div>
    </section>
    <?php
    $content = (string) ob_get_clean();

    View::page([
        'title'     => '分类',
        'nav'       => 'category',
        'bodyClass' => 'theme-neutral',
        'styles'    => ['math.css', 'leisure.css'],
        'content'   => $content,
    ]);
    exit;
}

// ---------- 单个分类 ----------
$name     = Config::CATEGORIES[$key];
$posts    = PostRepo::byCategory($key);
$comments = CommentRepo::counts();

ob_start();
?>
<section class="panel theme-<?= e($key) ?>">
  <header class="cat-page-head">
    <span class="badge badge-<?= e($key) ?>"><?= e($name) ?></span>
    <h1 class="section-title section-title-lg"><?= e($name) ?></h1>
    <p class="section-intro">
      <?= $key === 'math'
          ? '数学与物理的推导、证明和计算，公式由 KaTeX 排版。'
          : '小说、随笔一类，慢慢读的文字。' ?>
      共 <?= count($posts) ?> 篇。
    </p>
  </header>

  <?php if (!$posts): ?>
    <p class="empty">这个分类下还没有文章。</p>
  <?php else: ?>
    <div class="card-list">
    <?php foreach ($posts as $post): ?>
      <?= View::render('post-card', [
          'post'         => $post,
          'commentCount' => $comments[$post->slug] ?? 0,
      ]) ?>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <p class="back-link">
    <a href="<?= e(Config::url('category.php')) ?>">← 返回分类总览</a>
  </p>
</section>
<?php
$content = (string) ob_get_clean();

View::page([
    'title'     => $name,
    'nav'       => 'category',
    'bodyClass' => 'theme-' . $key,
    'styles'    => [Config::CATEGORY_CSS[$key]],
    'content'   => $content,
    'description' => $name . '类文章列表',
]);
