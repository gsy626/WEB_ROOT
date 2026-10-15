<?php
/**
 * 提交新博客
 *
 * 全部用 PHP 完成：表单校验、预览、写入 data/posts/<slug>/main.qd、图片落盘。
 * 写入的格式和 qd/ 里的旧文件完全一致，因此能被同一套解析器读取。
 */
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

$form = [
    'title'    => '',
    'category' => 'math',
    'author'   => $_SESSION['author'] ?? '',
    'desc'     => '',
    'body'     => '',
];
$errors  = [];
$preview = null;
$done    = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $form['title']    = param('title', '', 'POST');
    $form['category'] = param('category', 'math', 'POST');
    $form['author']   = param('author', '', 'POST');
    $form['desc']     = param('desc', '', 'POST');
    $form['body']     = (string) ($_POST['body'] ?? '');
    $action           = param('action', 'publish', 'POST');

    if (!csrf_check()) {
        $errors[] = '表单已过期，请重新填写。';
    }
    if ($form['title'] === '') {
        $errors[] = '请填写标题。';
    } elseif (mb_strlen($form['title']) > 120) {
        $errors[] = '标题太长了（最多 120 字）。';
    }
    if (!isset(Config::CATEGORIES[$form['category']])) {
        $errors[] = '请选择分类。';
    }
    if ($form['author'] !== '' && mb_strlen($form['author']) > Config::MAX_NAME_LEN) {
        $errors[] = '作者名太长。';
    }
    if (trim($form['body']) === '') {
        $errors[] = '正文不能为空。';
    } elseif (strlen($form['body']) > Config::MAX_BODY_BYTES) {
        $errors[] = '正文过长（上限 ' . (int) (Config::MAX_BODY_BYTES / 1024) . ' KB）。';
    }

    if (!$errors && $action === 'preview') {
        $preview = Quarkdown::toHtml($form['body'], 'preview', Quarkdown::defaultMacros());
    }

    if (!$errors && $action === 'publish') {
        $slug = PostRepo::uniqueSlug(PostRepo::makeSlug($form['title']));
        $dir  = Config::submittedDir() . DIRECTORY_SEPARATOR . $slug;

        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            $errors[] = '无法创建文章目录，请检查 data/posts 是否可写。';
        }

        if (!$errors) {
            // 图片：保存到 <slug>/image/，正文里用 ![](image/文件名) 引用
            $uploaded = saveUploads($dir, $errors);

            $source = buildSource($form, $uploaded);
            if (@file_put_contents($dir . DIRECTORY_SEPARATOR . 'main.qd', $source) === false) {
                $errors[] = '写入 main.qd 失败，请检查目录权限。';
            } else {
                $_SESSION['author'] = $form['author'];
                flash_set('ok', '新博客已发布。');
                redirect(Config::url('post.php', ['p' => $slug]));
            }
        }
    }
}

/** 处理上传图片，返回「原始名 => 相对路径」 */
function saveUploads(string $dir, array &$errors): array
{
    $map = [];
    if (empty($_FILES['images']) || !is_array($_FILES['images']['name'])) {
        return $map;
    }
    $allowed = ['png' => 1, 'jpg' => 1, 'jpeg' => 1, 'gif' => 1, 'webp' => 1, 'pdf' => 1];

    foreach ($_FILES['images']['name'] as $i => $name) {
        if (($_FILES['images']['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if (($_FILES['images']['error'][$i] ?? 1) !== UPLOAD_ERR_OK) {
            $errors[] = '图片「' . e((string) $name) . '」上传失败。';
            continue;
        }
        $tmp  = (string) $_FILES['images']['tmp_name'][$i];
        $size = (int) ($_FILES['images']['size'][$i] ?? 0);
        $ext  = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));

        if (!isset($allowed[$ext])) {
            $errors[] = '不支持的图片格式：' . e((string) $name);
            continue;
        }
        if ($size > 8 * 1024 * 1024) {
            $errors[] = '图片超过 8MB：' . e((string) $name);
            continue;
        }
        if (!@getimagesize($tmp) && $ext !== 'pdf') {
            $errors[] = '文件不是有效图片：' . e((string) $name);
            continue;
        }

        $imgDir = $dir . DIRECTORY_SEPARATOR . 'image';
        if (!is_dir($imgDir)) {
            @mkdir($imgDir, 0775, true);
        }
        $base = pathinfo((string) $name, PATHINFO_FILENAME);
        $base = preg_replace('/[^\p{L}\p{N}_-]+/u', '-', $base) ?? 'image';
        $base = trim($base, '-') ?: 'image';
        $dest = $imgDir . DIRECTORY_SEPARATOR . $base . '.' . $ext;
        $n    = 2;
        while (is_file($dest)) {
            $dest = $imgDir . DIRECTORY_SEPARATOR . $base . '-' . $n++ . '.' . $ext;
        }
        if (!@move_uploaded_file($tmp, $dest)) {
            $errors[] = '保存图片失败：' . e((string) $name);
            continue;
        }
        $map[(string) $name] = 'image/' . basename($dest);
    }
    return $map;
}

/** 组装 .qd 源文件 */
function buildSource(array $form, array $uploads): string
{
    $author  = trim($form['author']) !== '' ? trim($form['author']) : '匿名';
    $authors = '';
    foreach (preg_split('/[、,，\/]+/u', $author) ?: [] as $one) {
        $one = trim($one);
        if ($one !== '') {
            $authors .= '  - ' . $one . "\n";
        }
    }

    $head  = ".docname {" . trim($form['title']) . "}\n";
    if (trim($form['desc']) !== '') {
        $head .= ".docdescription {" . trim($form['desc']) . "}\n";
    }
    $head .= ".doccategory {" . Config::CATEGORIES[$form['category']] . "}\n";
    $head .= ".doclang {Chinese}\n";
    $head .= ".doctype {plain}\n";
    $head .= ".theme {paperwhite} layout:{latex}\n\n";
    $head .= ".docauthors\n" . $authors . "\n";
    $head .= "# " . trim($form['title']) . "\n\n";

    $body = $form['body'];
    if ($uploads) {
        $body .= "\n\n<!-- 已上传的图片：\n";
        foreach ($uploads as $orig => $rel) {
            $body .= "  " . $orig . "  =>  ![](" . $rel . ")\n";
        }
        $body .= "-->\n";
    }
    return $head . $body . "\n";
}

$previewHtml = $preview['html'] ?? '';
$previewMath = (bool) ($preview['hasMath'] ?? false);

/** @var string $slugUnused */
ob_start();
?>
<section class="panel submit-page">
  <h1 class="section-title section-title-lg">提交新博客</h1>
  <p class="section-intro">
    正文使用站点自己的 Quarkdown 语法，完全由 PHP 解析：
    行内公式写 <code>$ ... $</code>，行间公式写单独一行的 <code>$$$ ... $$$</code>，
    标题用 <code>#</code>，图片用 <code>![](image/文件名)</code>，提示框用
    <code>.box {标题} type:{tip}</code>。公式由 KaTeX 渲染，支持
    <code>\frac</code>、<code>\int</code>、<code>\begin{cases}</code> 等常用命令。
  </p>

  <?php if ($errors): ?>
    <div class="notice notice-error">
      <ul><?php foreach ($errors as $err): ?><li><?= $err ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <form class="post-form" method="post" action="<?= e(Config::url('submit.php')) ?>"
        enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

    <p class="field">
      <label for="f-title">标题 <span class="req">必填</span></label>
      <input id="f-title" type="text" name="title" maxlength="120"
             value="<?= e($form['title']) ?>" required>
    </p>

    <fieldset class="field">
      <legend>分类 <span class="req">必填</span></legend>
      <div class="radio-row">
      <?php foreach (Config::CATEGORIES as $key => $name): ?>
        <label class="radio theme-<?= e($key) ?>">
          <input type="radio" name="category" value="<?= e($key) ?>"
                 <?= $form['category'] === $key ? 'checked' : '' ?>>
          <span><?= e($name) ?></span>
        </label>
      <?php endforeach; ?>
      </div>
      <p class="hint">分类决定文章使用哪一份独立样式表（math.css / leisure.css）。</p>
    </fieldset>

    <p class="field">
      <label for="f-author">作者</label>
      <input id="f-author" type="text" name="author" maxlength="<?= (int) Config::MAX_NAME_LEN ?>"
             value="<?= e($form['author']) ?>" placeholder="多个作者用、分隔">
    </p>

    <p class="field">
      <label for="f-desc">一句话摘要</label>
      <input id="f-desc" type="text" name="desc" maxlength="200" value="<?= e($form['desc']) ?>">
    </p>

    <p class="field">
      <label for="f-body">正文 <span class="req">必填</span></label>
      <textarea id="f-body" name="body" rows="20" required><?= e($form['body']) ?></textarea>
    </p>

    <p class="field">
      <label for="f-images">图片（可多选）</label>
      <input id="f-images" type="file" name="images[]" multiple
             accept=".png,.jpg,.jpeg,.gif,.webp,.pdf">
      <span class="hint">上传后可用 <code>![](image/文件名)</code> 引用。</span>
    </p>

    <div class="form-actions">
      <button class="btn btn-primary" type="submit" name="action" value="publish">发布</button>
      <button class="btn" type="submit" name="action" value="preview">预览</button>
      <a class="btn btn-ghost" href="<?= e(Config::url('index.php')) ?>">取消</a>
    </div>
  </form>

  <?php if ($preview !== null): ?>
    <section class="panel preview-panel">
      <h2 class="section-title">预览</h2>
      <article class="post theme-<?= e($form['category']) ?>" data-doctype="<?= e($preview['doctype'] ?? 'plain') ?>">
        <header class="post-header">
          <p class="post-meta">
            <span class="badge badge-<?= e($form['category']) ?>">
              <?= e(Config::CATEGORIES[$form['category']]) ?>
            </span>
            <span>预览</span>
          </p>
          <h1 class="post-title"><?= e($form['title'] !== '' ? $form['title'] : '未命名') ?></h1>
        </header>
        <div class="post-body"><?= $previewHtml ?></div>
      </article>
    </section>
  <?php endif; ?>
</section>
<?php
$content = (string) ob_get_clean();

View::page([
    'title'       => '提交新博客',
    'nav'         => 'about',
    'bodyClass'   => 'theme-neutral',
    'styles'      => ['math.css', 'leisure.css'],
    'content'     => $content,
    'math'        => true,                        // 预览可能含公式
    'macros'      => Quarkdown::defaultMacros(),
    'description' => '提交一篇新的博客文章。',
]);
