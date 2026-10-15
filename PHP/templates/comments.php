<?php
/**
 * 评论区：列表 + 表单（纯 PHP 提交，无 JavaScript）
 *
 * 变量：$post (Post)、$comments (array)、$error (?string)、$ok (?string)
 */
/** @var Post $post */
$error = $error ?? null;
$ok    = $ok ?? null;
?>
<section class="comments" id="comments">
  <h2 class="section-title">评论 <span class="count"><?= count($comments) ?></span></h2>

  <?php if (!Database::available()): ?>
    <p class="notice notice-warn">
      评论功能当前不可用：<?= e(Database::error() ?? '未知原因') ?><br>
      请用 <code>bin/serve.sh</code> 启动站点（它会载入随站点附带的 SQLite 扩展），
      或为 PHP 安装 <code>pdo_sqlite</code>。
    </p>
  <?php endif; ?>

  <?php if ($error !== null): ?>
    <p class="notice notice-error"><?= e($error) ?></p>
  <?php endif; ?>
  <?php if ($ok !== null): ?>
    <p class="notice notice-ok"><?= e($ok) ?></p>
  <?php endif; ?>

  <?php if (!$comments): ?>
    <p class="empty">还没有评论，来说两句吧。</p>
  <?php else: ?>
    <ol class="comment-list">
    <?php foreach ($comments as $c): ?>
      <li class="comment" id="c<?= (int) $c['id'] ?>">
        <div class="comment-head">
          <span class="comment-author"><?= e((string) $c['author']) ?></span>
          <time datetime="<?= e((string) $c['created_at']) ?>"><?= e((string) $c['created_at']) ?></time>
        </div>
        <div class="comment-body"><?= nl2br(e((string) $c['body'])) ?></div>
      </li>
    <?php endforeach; ?>
    </ol>
  <?php endif; ?>

  <?php if (Database::available()): ?>
  <form class="comment-form" method="post" action="<?= e(Config::url('comment.php')) ?>">
    <h3 class="form-title">发表评论</h3>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="p" value="<?= e($post->slug) ?>">
    <p class="field">
      <label for="comment-author">昵称</label>
      <input id="comment-author" type="text" name="author" maxlength="<?= (int) Config::MAX_NAME_LEN ?>"
             placeholder="匿名" autocomplete="nickname">
    </p>
    <p class="field">
      <label for="comment-body">内容</label>
      <textarea id="comment-body" name="body" rows="5" required
                maxlength="<?= (int) Config::MAX_COMMENT_LEN ?>"
                placeholder="支持纯文本，换行会保留。"></textarea>
    </p>
    <p class="field field-hp" aria-hidden="true">
      <label for="comment-url">网址</label>
      <input id="comment-url" type="text" name="url" tabindex="-1" autocomplete="off">
    </p>
    <button class="btn btn-primary" type="submit">提交评论</button>
  </form>
  <?php endif; ?>
</section>
