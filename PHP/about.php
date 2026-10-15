<?php
/**
 * 关于页
 */
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

$counts = PostRepo::countByCategory();
$total  = array_sum($counts);
$comments = CommentRepo::counts();

ob_start();
?>
<section class="panel about-page">
  <h1 class="section-title section-title-lg">关于</h1>
  <p class="section-intro">
    这是一个用最基本的 HTML / PHP / 一点点 JS 搭起来的小站：
    没有框架、没有构建步骤，内容来自磁盘上的 <code>.qd</code> 源文件，
    评论存在一个 SQLite 文件里。
  </p>

  <div class="about-grid">
    <section class="about-card theme-math">
      <h2>数理</h2>
      <p><?= (int) $counts['math'] ?> 篇。数学与物理的推导、证明，公式用 KaTeX 排版。</p>
      <p><a class="btn btn-small" href="<?= e(Config::url('category.php', ['c' => 'math'])) ?>">进入分类</a></p>
    </section>
    <section class="about-card theme-leisure">
      <h2>休闲</h2>
      <p><?= (int) $counts['leisure'] ?> 篇。小说与随笔，慢慢读的文字。</p>
      <p><a class="btn btn-small" href="<?= e(Config::url('category.php', ['c' => 'leisure'])) ?>">进入分类</a></p>
    </section>
  </div>

  <h2 class="section-title" id="comments">评论与数据库</h2>
  <p>
    评论系统是最简单的那种：一张表、一个表单、一次 POST 之后重定向。
    数据存在 <code>data/blog.sqlite</code>，建表语句在 <code>lib/Database.php</code> 里，
    第一次访问时自动执行。
  </p>
  <pre class="code-block"><code>CREATE TABLE comments (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    post_slug  TEXT NOT NULL,   -- 属于哪篇文章
    author     TEXT NOT NULL,   -- 昵称
    body       TEXT NOT NULL,   -- 内容
    created_at TEXT NOT NULL,   -- 时间
    ip_hash    TEXT NOT NULL    -- 仅存哈希，用来简单去重
);</code></pre>
  <p class="hint">
    目前共有 <?= (int) array_sum($comments) ?> 条评论，分布在
    <?= count($comments) ?> 篇文章下。
  </p>

  <h2 class="section-title" id="structure">站点结构</h2>
  <pre class="code-block"><code>PHP/
├── index.php          首页
├── category.php       分类（?c=math / ?c=leisure）
├── post.php           文章 + 评论
├── about.php          关于
├── submit.php         提交新博客
├── comment.php        评论写入
├── media.php          文章图片出口
├── lib/               PHP 类库（配置 / 数据库 / 解析器 / 仓库 / 视图）
├── templates/         页面骨架、导航栏、评论、卡片
├── assets/
│   ├── css/base.css   公共样式
│   ├── css/math.css   数理专用样式
│   ├── css/leisure.css 休闲专用样式
│   ├── js/katex-render.js  唯一的一段 JS：把公式交给 KaTeX
│   └── katex/         本地 KaTeX 0.16.44（含字体）
└── data/
    ├── categories.php 分类规则
    ├── posts/         网页提交的新博客
    ├── cache/         正文解析缓存
    └── blog.sqlite    评论数据库</code></pre>

  <h2 class="section-title">技术取舍</h2>
  <ul class="plain-list">
    <li><strong>能用 PHP 就不用 JS。</strong>导航栏用原生 <code>&lt;details&gt;</code>
      实现点击展开，零 JS；评论、提交、分页全在 PHP 里完成。</li>
    <li><strong>为什么要 JS？</strong>只为一个原因：KaTeX 只有 JavaScript 实现，
      没有 PHP 版本。所以公式渲染是本页唯一的 JS，
      且资源全部来自本地 <code>assets/katex/</code>，不依赖 CDN。</li>
    <li><strong>正文解析。</strong><code>qd/</code> 里的源文件由
      <code>lib/Quarkdown.php</code> 转成 HTML，不做任何浏览器端二次渲染，
      解析结果缓存在 <code>data/cache/</code>。</li>
    <li><strong>两种内容、两份 CSS。</strong>数理页只加载 <code>math.css</code>，
      休闲页只加载 <code>leisure.css</code>，两份文件互不依赖。</li>
  </ul>

  <p class="back-link">
    <a href="<?= e(Config::url('submit.php')) ?>">去提交一篇新博客 →</a>
  </p>
</section>
<?php
$content = (string) ob_get_clean();

View::page([
    'title'     => '关于',
    'nav'       => 'about',
    'bodyClass' => 'theme-neutral',
    'styles'    => ['math.css', 'leisure.css'],
    'content'   => $content,
    'description' => '关于本站、评论系统与站点结构。',
]);
