<?php
/**
 * 文章卡片。变量：$post (Post)、$commentCount (int, 可选)
 */
/** @var Post $post */
$commentCount = $commentCount ?? 0;
?>
<article class="card <?= e($post->themeClass()) ?>">
  <a class="card-hit" href="<?= e($post->url()) ?>">
    <div class="card-meta">
      <span class="badge badge-<?= e($post->category) ?>"><?= e($post->categoryName()) ?></span>
      <time datetime="<?= e($post->date) ?>"><?= e($post->date) ?></time>
    </div>
    <h3 class="card-title"><?= e($post->title) ?></h3>
    <p class="card-excerpt"><?= e($post->excerpt()) ?></p>
    <div class="card-foot">
      <span><?= e($post->authorText()) ?></span>
      <span class="dot">·</span>
      <span>约 <?= (int) $post->readingMinutes() ?> 分钟</span>
      <span class="dot">·</span>
      <span><?= (int) $commentCount ?> 条评论</span>
    </div>
  </a>
</article>
