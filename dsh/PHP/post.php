<?php
/**
 * 文章页 —— 正文 + 评论
 *
 * 正文由 lib/Quarkdown.php 把 .qd 源文件转成 HTML；公式交给 KaTeX。
 * 页面按文章分类加载对应的独立样式表（math.css / leisure.css）。
 */
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

$slug = param('p');
$post = $slug === '' ? null : PostRepo::find($slug);

if ($post === null) {
    not_found('找不到这篇文章：' . $slug);
}

$rendered  = $post->render();
$comments  = CommentRepo::forPost($post->slug);
$flash     = flash_get();

// 同分类内的上一篇 / 下一篇
$siblings = PostRepo::byCategory($post->category);
$index    = null;
foreach ($siblings as $i => $p) {
    if ($p->slug === $post->slug) {
        $index = $i;
        break;
    }
}
$prev = $index !== null ? ($siblings[$index - 1] ?? null) : null;
$next = $index !== null ? ($siblings[$index + 1] ?? null) : null;

ob_start();
?>
<article class="post <?= e($post->themeClass()) ?>" data-doctype="<?= e($post->doctype()) ?>">

  <header class="post-header">
    <p class="post-meta">
      <span class="badge badge-<?= e($post->category) ?>"><?= e($post->categoryName()) ?></span>
      <time datetime="<?= e($post->date) ?>"><?= e($post->dateText()) ?></time>
      <span class="dot">·</span>
      <span><?= e($post->authorText()) ?></span>
      <span class="dot">·</span>
      <span>约 <?= (int) $post->readingMinutes() ?> 分钟</span>
    </p>
    <h1 class="post-title"><?= e($post->title) ?></h1>
    <?php if ($post->descriptionText() !== ''): ?>
      <p class="post-desc"><?= e($post->descriptionText()) ?></p>
    <?php endif; ?>
    <p class="post-source">
      来源：
      <?php if ($post->source === Post::SOURCE_SUBMITTED): ?>
        网页提交（<code>data/posts/<?= e(basename($post->dir)) ?>/main.qd</code>）
      <?php else: ?>
        <code>qd/<?= e(basename($post->dir)) ?>/main.qd</code>
      <?php endif; ?>
    </p>
  </header>

  <div class="post-body">
    <?= $rendered['html'] ?>
  </div>

  <footer class="post-footer">
    <nav class="post-nav">
      <?php if ($prev): ?>
        <a class="post-nav-prev" href="<?= e($prev->url()) ?>">
          <span>上一篇</span><?= e($prev->title) ?>
        </a>
      <?php else: ?><span></span><?php endif; ?>
      <?php if ($next): ?>
        <a class="post-nav-next" href="<?= e($next->url()) ?>">
          <span>下一篇</span><?= e($next->title) ?>
        </a>
      <?php else: ?><span></span><?php endif; ?>
    </nav>
    <p class="back-link">
      <a href="<?= e(Config::url('category.php', ['c' => $post->category])) ?>">
        ← 更多「<?= e($post->categoryName()) ?>」文章
      </a>
    </p>
  </footer>
</article>

<?= View::render('comments', [
    'post'     => $post,
    'comments' => $comments,
    'error'    => $flash && $flash['type'] === 'error' ? $flash['message'] : null,
    'ok'       => $flash && $flash['type'] === 'ok' ? $flash['message'] : null,
]) ?>
<?php
$content = (string) ob_get_clean();

View::page([
    'title'       => $post->title,
    'nav'         => 'home',
    'bodyClass'   => $post->themeClass(),
    'styles'      => [$post->cssFile()],
    'content'     => $content,
    'math'        => (bool) $rendered['hasMath'],
    'macros'      => $rendered['macros'] ?? [],
    'description' => $post->descriptionText() !== '' ? $post->descriptionText() : $post->excerpt(120),
]);
