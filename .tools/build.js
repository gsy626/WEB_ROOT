'use strict';
/* ==========================================================================
   build.js —— 生成博客静态站点
   用法：node build.js
   产物：
     WEB_ROOT/index.html                    首页（全部博文，可按风格筛选）
     WEB_ROOT/content/<slug>/index.html     每篇博文一个文件夹
     WEB_ROOT/content/<slug>/assets/...     该篇的图片/PDF 素材
     WEB_ROOT/style/*.css + blog.js         风格与交互（已单独维护，不覆盖）
   ========================================================================== */

const fs = require('fs');
const path = require('path');

const md = require('./lib/md');
const tex = require('./lib/tex');
const { posts, styles } = require('./posts');

const WEB_ROOT = 'D:\\HOME\\WEB_ROOT';
const CONTENT = path.join(WEB_ROOT, 'content');
const RENDER_OUT = path.join(WEB_ROOT, '.tools', 'render-out');
const KATEX = 'style/vendor/katex';

const onlySlug = process.argv[2] && !process.argv[2].startsWith('-') ? process.argv[2] : '';
const quiet = process.argv.includes('--quiet');

const log = (...a) => { if (!quiet) console.log(...a); };

/* ------------------------------------------------------------------ 工具 */
function ensureDir(dir) { fs.mkdirSync(dir, { recursive: true }); }

function copyFile(src, dest) {
  ensureDir(path.dirname(dest));
  fs.copyFileSync(src, dest);
}

function copyAsset(src, dest) {
  if (!fs.existsSync(src)) { console.warn('  ! 素材缺失：' + src); return false; }
  copyFile(src, dest);
  return true;
}

function readText(file) {
  if (!fs.existsSync(file)) return null;
  return tex.readTextFile(file);
}

function escapeHtml(s) { return md.escapeHtml(s); }

function rel(fromDir, target) {
  let r = path.relative(fromDir, target).replace(/\\/g, '/');
  if (!r.startsWith('.')) r = './' + r;
  return r;
}

/* ------------------------------------------------------------ HTML 骨架 */
/** 站点以 WEB_ROOT 为文档根，统一用根相对路径，避免相对层级出错 */
function layout(opts) {
  const { title, description, styleName, body, activeStyle } = opts;
  return `<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<title>${escapeHtml(title)}</title>
<meta name="description" content="${escapeHtml(description || '')}"/>
<meta name="generator" content="PERSONAL_PROJECTS → WEB_ROOT 静态博客生成器"/>
<link rel="stylesheet" href="/style/base.css"/>
<link rel="stylesheet" href="/style/${styleName}.css"/>
<link rel="stylesheet" href="/${KATEX}/katex.min.css"/>
</head>
<body data-style="${styleName}">
<nav class="site-nav">
  <a class="brand" href="/index.html">PERSONAL&nbsp;PROJECTS</a>
  <div class="nav-right">
    <a class="nav-link" href="/index.html">全部博文</a>
    ${styles.filter((s) => s.name !== styleName).map((s) => `<a class="nav-link" href="/index.html?style=${s.name}">${s.label}</a>`).join('\n    ')}
  </div>
</nav>
${body}
<footer class="site-foot">
  <div>由 <code>PERSONAL_PROJECTS/tex</code> 与 <code>PERSONAL_PROJECTS/qd</code> 的原始文稿转换生成 · 公式由 KaTeX 渲染</div>
</footer>
<button class="to-top" type="button" aria-label="返回顶部">↑</button>
<script src="/${KATEX}/katex.min.js"></script>
<script src="/style/blog.js"></script>
</body>
</html>
`;
}

/* ---------------------------------------------------------------- 目录 */
function buildToc(headings) {
  const items = headings.filter((h) => h.level >= 2 && h.level <= 3);
  if (items.length < 2) return '';
  return [
    '<nav class="toc"><div class="toc-title">目录</div><ol>',
    items.map((h) => `<li class="toc-${h.level}"><a href="#${h.id}">${escapeHtml(h.text)}</a></li>`).join('\n'),
    '</ol></nav>'
  ].join('\n');
}

/* ------------------------------------------------------------ 来源信息 */
function sourceBlock(post) {
  const lines = post.sources.map((s) => {
    const relSrc = path.relative('D:\\HOME', s.path).replace(/\\/g, '/');
    return `<div>· ${escapeHtml(s.label)} &nbsp;<span class="muted">PERSONAL_PROJECTS/${escapeHtml(relSrc.replace(/^PERSONAL_PROJECTS\//, ''))}</span></div>`;
  }).join('\n');
  return `<div class="post-source">
  <div>原始文稿（${post.sources.length} 份）</div>
  ${lines}
</div>`;
}

function metaLine(post) {
  const styleObj = styles.find((s) => s.name === post.style) || { label: post.style };
  const dateLabel = `${post.date}${post.dateNote && /^inferred/.test(post.dateNote) ? '（推断）' : ''}`;
  return `<div class="post-meta">
    <span class="chip style-${post.style}">${escapeHtml(styleObj.label)}</span>
    <time datetime="${post.date}">${dateLabel}</time>
    ${(post.tags || []).map((t) => `<span>#${escapeHtml(t)}</span>`).join('')}
  </div>`;
}

/* --------------------------------------------------------- 自定义构建器 */
/** 图鉴类：多件作品 + 全部源码 */
function buildGalleryPost(post, ctx) {
  const parts = [];
  const items = post.items || [];
  parts.push(`<div class="gallery-grid">`);
  items.forEach((it) => {
    const img = `/content/${post.slug}/assets/preview/${it.name}.png`;
    parts.push(`<figure>
      <img src="${img}" alt="${escapeHtml(it.label)}" loading="lazy"/>
      <figcaption>${escapeHtml(it.label)} · ${escapeHtml(it.paper || '')}</figcaption>
    </figure>`);
  });
  parts.push('</div>');

  parts.push('<h2 id="sources">全部源码</h2>');
  parts.push('<p class="muted small">点开任意一条即可查看完整 LaTeX 源码，右上角可一键复制。</p>');
  items.forEach((it, idx) => {
    const code = readText(it.src);
    if (code == null) return;
    parts.push(`<details class="code-drawer">
      <summary><span class="drawer-title">${escapeHtml(it.label)}</span><span class="drawer-meta">${escapeHtml(path.basename(it.src))}</span></summary>
      <div class="code-panel">
        <div class="code-head"><span>LaTeX · TikZ</span><button class="code-copy" type="button">复制</button></div>
        <pre><code>${escapeHtml(code.trim())}</code></pre>
      </div>
    </details>`);
  });
  return { html: parts.join('\n'), headings: [{ level: 2, id: 'sources', text: '全部源码' }] };
}

/** 海报类：成品图 + 组成素材 + 源码 */
function buildPosterPost(post, ctx) {
  const parts = [];
  const items = post.items || [];
  items.forEach((it) => {
    const img = `/content/${post.slug}/assets/preview/${it.name}.png`;
    parts.push(`<figure class="poster-figure" data-zoom="1">
      <img src="${img}" alt="${escapeHtml(it.label)}" loading="lazy"/>
      <figcaption>${escapeHtml(it.label)} · ${escapeHtml(it.paper || '')}</figcaption>
    </figure>`);
  });
  if (post.figures && post.figures.length) {
    parts.push('<h2 id="figures">海报里的六幅插图</h2>');
    parts.push('<div class="gallery-grid">');
    post.figures.forEach((f) => {
      parts.push(`<figure><img src="/content/${post.slug}/assets/${f.as}" alt="${escapeHtml(f.label)}" loading="lazy"/><figcaption>${escapeHtml(f.label)}</figcaption></figure>`);
    });
    parts.push('</div>');
  }
  parts.push('<h2 id="sources">源码</h2>');
  items.forEach((it) => {
    const code = readText(it.src);
    if (code == null) return;
    parts.push(`<details class="code-drawer">
      <summary><span class="drawer-title">${escapeHtml(it.label)}</span><span class="drawer-meta">${escapeHtml(path.basename(it.src))}</span></summary>
      <div class="code-panel">
        <div class="code-head"><span>LaTeX · TikZ</span><button class="code-copy" type="button">复制</button></div>
        <pre><code>${escapeHtml(code.trim())}</code></pre>
      </div>
    </details>`);
  });
  return { html: parts.join('\n'), headings: [{ level: 2, id: 'figures', text: '海报里的六幅插图' }, { level: 2, id: 'sources', text: '源码' }] };
}

/** 蝉鸣：Quarkdown 定稿 + LaTeX 初稿合刊 */
function buildBundlePost(post, ctx) {
  const qdSrc = readText(post.sources[0].path);
  const texSrc = readText(post.sources[1].path);
  const parts = [];
  const asset = (name) => `/content/${post.slug}/assets/${name}`;

  parts.push(`<div class="img-row">
    <figure style="flex:1 1 20rem"><img src="${asset('cover.jpg')}" alt="蝉的封面图"/></figure>
    <figure style="flex:0 1 12rem"><img src="${asset('author.jpg')}" alt="作者近照"/><figcaption>清苑一中优秀毕业生 · 郭子奥</figcaption></figure>
  </div>`);

  if (qdSrc) {
    const r = md.convert(qdSrc, {
      image: (src, alt, w) => {
        const clean = String(src).replace(/^\.\/?/, '');
        const file = clean.split('/').pop();
        const mapped = file === 'chan2.jpg' ? 'cover.jpg' : file === 'guoziao.jpg' ? 'author.jpg' : file;
        const style = w ? ` style="width:${String(w).replace(/[^0-9.]/g, '')}%"` : '';
        return `<img src="${asset(mapped)}" alt="${escapeHtml(alt)}"${style}/>`;
      }
    });
    // 去掉 Quarkdown 的 .docauthors 残留列表
    const html = r.html.replace(/<ul>\s*<li>guosh<\/li>\s*<\/ul>/g, '');
    parts.push('<h2 id="final">定稿 · Quarkdown 版</h2>');
    parts.push('<p class="muted small">2026.3.2 初稿，2026.6.11 修改；撰稿人：郭子奥，审稿人：郭绍宇。</p>');
    parts.push(html);
    parts.push('<hr/>');
  }

  if (texSrc) {
    const r = tex.convert(texSrc, { image: (src) => `<img src="${asset(src.replace(/\\/g, '/'))}" alt=""/>` });
    parts.push('<h2 id="draft">初稿 · LaTeX 四回本</h2>');
    parts.push('<p class="muted small">同一故事的早期版本，以"第一回～第四回"分回，与定稿在字句上有出入，可对照阅读。</p>');
    parts.push(`<div class="draft-panel">${r.html}</div>`);
  }

  return {
    html: parts.join('\n'),
    headings: [{ level: 2, id: 'final', text: '定稿 · Quarkdown 版' }, { level: 2, id: 'draft', text: '初稿 · LaTeX 四回本' }]
  };
}

/* ------------------------------------------------------------ 单篇构建 */
function buildPost(post, styleList) {
  const outDir = path.join(CONTENT, post.slug);
  const assetsDir = path.join(outDir, 'assets');
  ensureDir(outDir);

  log(`\n▶ ${post.slug}  [${post.style}]  ${post.title}`);
  const ctx = { prefix: '/', outDir, assetsDir };

  // 素材
  let copied = 0;
  (post.assets || []).forEach((a) => {
    if (copyAsset(a.src, path.join(assetsDir, a.as))) copied++;
  });
  if (post.manifest === 'render-out' && post.items) {
    post.items.forEach((it) => {
      const srcPng = path.join(RENDER_OUT, it.preview);
      if (copyAsset(srcPng, path.join(assetsDir, 'preview', it.name + '.png'))) copied++;
    });
  }
  if (post.figures) {
    post.figures.forEach((f) => { if (copyAsset(f.src, path.join(assetsDir, f.as))) copied++; });
  }
  log(`  素材 ${copied} 个`);

  // 正文
  let built;
  if (post.kind === 'gallery') built = buildGalleryPost(post, ctx);
  else if (post.kind === 'poster') built = buildPosterPost(post, ctx);
  else if (post.kind === 'bundle') built = buildBundlePost(post, ctx);
  else built = buildArticle(post, ctx);

  // 清掉指向不存在素材的图片与空图注（例如 Quarkdown 自带的水印 logo）
  built.html = built.html
    .replace(/<img[^>]*data-missing="1"[^>]*\/?>/g, '')
    .replace(/<figure[^>]*>\s*<\/figure>/g, '')
    .replace(/<p>\s*<\/p>/g, '');

  const headings = built.headings || [];
  const toc = buildToc(headings);
  const body = `
<main class="post">
  <header class="post-head wrap">
    <p class="kicker">${escapeHtml(post.subtitle || '')}</p>
    <h1>${escapeHtml(post.title)}</h1>
    ${post.summary ? `<p class="subtitle">${escapeHtml(post.summary)}</p>` : ''}
    ${metaLine(post)}
  </header>
  <div class="wrap">
    ${toc}
    <article class="post-body">
${built.html}
    </article>
    ${sourceBlock(post)}
    ${postNav(post, styleList)}
  </div>
</main>
`;

  const html = layout({
    title: `${post.title} · 个人项目博客`,
    description: post.summary || post.title,
    styleName: post.style,
    body,
    prefix: '/',
    homeHref: '/index.html'
  });
  fs.writeFileSync(path.join(outDir, 'index.html'), html, 'utf8');

  // 校验报告
  const stat = checkOutput(html);
  log(`  ✓ index.html  ${(html.length / 1024).toFixed(1)} KB  ${stat}`);
  return { post, headings, bytes: html.length, stat };
}

/** 普通文章：把 sources[0] 作为正文，其余作为附注 */
function buildArticle(post, ctx) {
  const primary = post.sources[0];
  const src = readText(primary.path);
  if (src == null) throw new Error('找不到正文源文件：' + primary.path);

  const imageResolver = (srcPath, alt, width) => {
    const clean = String(srcPath).replace(/^\.\//, '').replace(/\\/g, '/');
    // 优先用拷贝进来的素材
    const candidates = collectAssets(post);
    const hit = candidates.find((c) => c.toLowerCase().endsWith('/' + clean.toLowerCase()) || c.toLowerCase() === clean.toLowerCase());
    const file = hit ? hit : clean;
    const style = width ? ` style="width:${String(width).replace(/[^0-9.a-z%]/gi, '')}"` : '';
    // 素材不存在时标记出来，构建结束后连图一起清掉（例如 Quarkdown 自带的水印 logo）
    if (!fs.existsSync(path.join(ctx.assetsDir, file))) {
      return `<img data-missing="1" src="/content/${post.slug}/assets/${file}" alt="${escapeHtml(alt || '')}"${style}/>`;
    }
    return `<img src="/content/${post.slug}/assets/${file}" alt="${escapeHtml(alt || '')}"${style} loading="lazy"/>`;
  };

  const isQd = /\.qd$/i.test(primary.path);
  const result = isQd
    ? md.convert(src, { image: imageResolver })
    : tex.convert(src, { image: imageResolver });

  const parts = [result.html];

  // 附加源（例如同一主题的另一版本）
  for (let i = 1; i < post.sources.length; i++) {
    const extra = post.sources[i];
    const extraSrc = readText(extra.path);
    if (extraSrc == null) continue;
    const isQdExtra = /\.qd$/i.test(extra.path);
    const r = isQdExtra ? md.convert(extraSrc, { image: imageResolver }) : tex.convert(extraSrc, { image: imageResolver });
    parts.push(`<h2 id="extra-${i}">附：${escapeHtml(extra.label)}</h2>`);
    parts.push(r.html);
    result.headings = result.headings.concat([{ level: 2, id: `extra-${i}`, text: `附：${extra.label}` }], r.headings);
  }

  // PDF 附件入口
  if ((post.assets || []).some((a) => /\.pdf$/i.test(a.as))) {
    const pdfs = post.assets.filter((a) => /\.pdf$/i.test(a.as)).map((a) => {
      const name = a.as.split('/').pop();
      return `<li><a href="/content/${post.slug}/assets/${a.as}">${escapeHtml(name)}</a></li>`;
    }).join('');
    parts.push(`<h2 id="printable">可打印原件</h2>\n<ul class="file-list">${pdfs}</ul>`);
    result.headings.push({ level: 2, id: 'printable', text: '可打印原件' });
  }

  return { html: parts.join('\n'), headings: result.headings, stats: result.stats };
}

function collectAssets(post) {
  const list = [];
  (post.assets || []).forEach((a) => list.push(a.as));
  if (post.figures) post.figures.forEach((f) => list.push(f.as));
  if (post.items) post.items.forEach((it) => list.push('preview/' + it.name + '.png'));
  return list;
}

/* ------------------------------------------------------------ 输出校验 */
/** 校验时把公式与代码面板里的内容剔除，只在"正文文本"里找残留 */
function checkOutput(html) {
  const issues = [];
  const text = html
    .replace(/<span class="math-(?:inline|block)"[^>]*>[\s\S]*?<\/span>/g, ' ')
    .replace(/<div class="code-panel">[\s\S]*?<\/div>\s*<\/div>/g, ' ')
    .replace(/<pre><code>[\s\S]*?<\/code><\/pre>/g, ' ')
    .replace(/<script[\s\S]*?<\/script>/g, ' ')
    .replace(/<style[\s\S]*?<\/style>/g, ' ');

  const leftovers = [
    [/\\begin\{/, '未转换的 \\begin{}'],
    [/\\end\{/, '未转换的 \\end{}'],
    [/\\includegraphics/, '未转换的 \\includegraphics'],
    [/\\[a-zA-Z]{2,}\{/, '未转换的 LaTeX 命令'],
    [/\u0001|\u0002|\u0003|\u0000/, '残留占位符'],
    [/@@center@@|@@CHOICES4@@|@@CENTER@@/, '残留内部标记'],
    [/\$\$/, '未渲染的 $$ 定界符']
  ];
  leftovers.forEach(([re, msg]) => { if (re.test(text)) issues.push(msg); });

  // 标签配对（粗略，按原始 HTML 统计，避免被剔除逻辑干扰）
  const pairs = [['div', /<div\b/g, /<\/div>/g], ['figure', /<figure\b/g, /<\/figure>/g], ['span', /<span\b/g, /<\/span>/g], ['p', /<p\b/g, /<\/p>/g]];
  pairs.forEach(([name, o, c]) => {
    const a = (html.match(o) || []).length;
    const b = (html.match(c) || []).length;
    if (a !== b) issues.push(`<${name}> 不平衡 ${a}/${b}`);
  });
  return issues.length ? '⚠ ' + issues.join('；') : 'ok';
}

/* ---------------------------------------------------------- 上一/下一篇 */
function postNav(post, styleList) {
  const idx = styleList.findIndex((p) => p.slug === post.slug);
  const prev = styleList[idx - 1];
  const next = styleList[idx + 1];
  const link = (p, dir) => (p ? `<a href="/content/${p.slug}/index.html">${dir} ${escapeHtml(p.title)}</a>` : '<span></span>');
  return `<div class="post-nav">${link(prev, '← 上一篇')}${link(next, '下一篇 →')}</div>`;
}

/* ------------------------------------------------------------ 首页构建 */
function buildIndex(all) {
  const cards = all.map((post) => {
    const styleObj = styles.find((s) => s.name === post.style) || { label: post.style };
    return `<li class="card" data-style="${post.style}">
  <a class="card-main" href="content/${post.slug}/index.html">
    <p class="card-title">${escapeHtml(post.title)}</p>
    <p class="card-sub">${escapeHtml(post.summary || post.subtitle || '')}</p>
  </a>
  <div class="card-meta">
    <div class="card-date">${post.date}${post.dateNote && /^inferred/.test(post.dateNote) ? '（推断）' : ''}</div>
    <div class="chip style-${post.style}">${escapeHtml(styleObj.label)}</div>
  </div>
</li>`;
  }).join('\n');

  const counts = styles.map((s) => {
    const n = all.filter((p) => p.style === s.name).length;
    return `${s.label} ${n} 篇`;
  }).join(' · ');

  const body = `<main>
  <div class="wrap">
    <section class="hero">
      <p class="kicker">PERSONAL PROJECTS · BLOG</p>
      <h1>个人项目博文</h1>
      <p class="lede">这里收录 <code>PERSONAL_PROJECTS/tex</code>（LaTeX）与 <code>PERSONAL_PROJECTS/qd</code>（Quarkdown）里的全部文稿，按内容自动归入三种版式风格。共 ${all.length} 篇：${counts}。</p>
    </section>

    <section>
      <h2 class="section-title">风格说明</h2>
      <div class="style-cards">
        ${styles.map((s) => `<article class="style-card style-card-${s.name}">
          <h3>${escapeHtml(s.label)}<code>${escapeHtml(s.file)}</code></h3>
          <p>${escapeHtml(s.desc)}</p>
          <p class="muted small">${escapeHtml(s.rule)}</p>
          <p class="small"><a href="index.html?style=${s.name}">只看这一类 →</a></p>
        </article>`).join('\n        ')}
      </div>
    </section>

    <section>
      <h2 class="section-title">全部博文 <span class="filter-count"></span></h2>
      <div class="filter-bar" data-initial="all">
        <button type="button" data-filter="all" aria-pressed="true">全部</button>
        ${styles.map((s) => `<button type="button" data-filter="${s.name}" aria-pressed="false">${escapeHtml(s.label)}</button>`).join('\n        ')}
      </div>
      <ul class="card-list">
${cards}
      </ul>
    </section>
  </div>
</main>
`;

  const html = layout({
    title: '个人项目博文 · 全部文章',
    description: 'LaTeX 与 Quarkdown 文稿的网页版，三种版式风格。',
    styleName: 'casual',
    body,
    prefix: '/',
    homeHref: '/index.html'
  });
  fs.writeFileSync(path.join(WEB_ROOT, 'index.html'), html, 'utf8');
  log(`\n✓ 首页 WEB_ROOT/index.html  ${(html.length / 1024).toFixed(1)} KB  ${checkOutput(html)}`);
}

/* ------------------------------------------------------------- 样式说明页 */
function buildStyleDoc() {
  const body = `<main class="wrap">
  <header class="post-head">
    <p class="kicker">STYLE GUIDE</p>
    <h1>风格说明</h1>
    <p class="subtitle">每种风格一个 CSS 文件，放在 <code>WEB_ROOT/style/</code>，博文页面只引用 <code>base.css</code> + 自己的风格文件。</p>
  </header>
  <article class="post-body">
    <h2 id="how">怎么选风格</h2>
    <p>转换脚本按正文内容自动判定：只要正文里出现 LaTeX 数学（行内 <code>$…$</code> 或行间 <code>$$…$$</code>），就归入 <strong>数理风格</strong>；没有公式的叙事性文本归入 <strong>休闲随笔风格</strong>；以图像、海报或纯绘图代码为主体的作品归入 <strong>图鉴风格</strong>。</p>
    <h2 id="list">三种风格</h2>
    ${styles.map((s) => `<div class="callout callout-tip">
      <span class="callout-title">${escapeHtml(s.label)} · ${escapeHtml(s.file)}</span>
      <p>${escapeHtml(s.desc)}</p>
      <p class="muted small">${escapeHtml(s.rule)}</p>
    </div>`).join('\n    ')}
    <h2 id="tokens">公共变量</h2>
    <p>三套风格共用 <code>style/base.css</code> 里的设计变量（纸色、墨色、强调色、字体、版心宽度），风格文件只覆盖变量与少量组件样式，因此新增风格只需再写一个 CSS 文件。</p>
  </article>
</main>
`;
  const html = layout({
    title: '风格说明 · 个人项目博客',
    styleName: 'casual',
    body,
    prefix: '/',
    homeHref: '/index.html'
  });
  fs.writeFileSync(path.join(WEB_ROOT, 'styles.html'), html, 'utf8');
  log(`✓ 风格说明 WEB_ROOT/styles.html  ${(html.length / 1024).toFixed(1)} KB`);
}

/* ---------------------------------------------------------------- 主流程 */
function main() {
  ensureDir(CONTENT);
  log('构建开始：' + new Date().toLocaleString());

  const list = onlySlug ? posts.filter((p) => p.slug === onlySlug) : posts;
  if (onlySlug && !list.length) { console.error('没有匹配的 slug：' + onlySlug); process.exit(1); }

  const results = [];
  list.forEach((post) => {
    try {
      results.push(buildPost(post, posts));
    } catch (err) {
      console.error(`  ✗ ${post.slug} 构建失败：${err.message}`);
      results.push({ post, error: err.message });
    }
  });

  if (!onlySlug) {
    buildIndex(posts);
    buildStyleDoc();
  }

  const failed = results.filter((r) => r.error);
  console.log(`\n完成：${results.length - failed.length}/${results.length} 篇`);
  if (failed.length) {
    failed.forEach((f) => console.log('  ✗ ' + f.post.slug + '：' + f.error));
    process.exitCode = 1;
  }
  results.forEach((r) => {
    if (r.stat && r.stat !== 'ok') console.log('  ⚠ ' + r.post.slug + '：' + r.stat);
  });
}

if (require.main === module) main();

module.exports = { layout, buildPost, posts };
