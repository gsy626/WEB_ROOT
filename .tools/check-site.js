'use strict';
/* 站点健康检查：链接、素材、KaTeX 依赖、HTML 结构 */
const fs = require('fs');
const path = require('path');
const { posts } = require('./posts');

const ROOT = 'D:\\HOME\\WEB_ROOT';
const problems = [];
const checked = { files: 0, links: 0, assets: 0 };

function exists(p) { try { return fs.statSync(p).isFile(); } catch (e) { return false; } }

// 1. 首页与样式说明
['index.html', 'styles.html'].forEach((f) => {
  const p = path.join(ROOT, f);
  if (!exists(p)) problems.push('缺少 ' + f);
});

// 2. 全局依赖
['style/base.css', 'style/math.css', 'style/casual.css', 'style/gallery.css', 'style/blog.js',
  'style/vendor/katex/katex.min.css', 'style/vendor/katex/katex.min.js'].forEach((f) => {
  if (!exists(path.join(ROOT, f))) problems.push('缺少依赖 ' + f);
});

// 3. 每篇的链接与素材
posts.forEach((post) => {
  const dir = path.join(ROOT, 'content', post.slug);
  const file = path.join(dir, 'index.html');
  if (!exists(file)) { problems.push('缺少文章 ' + post.slug); return; }
  checked.files++;
  const html = fs.readFileSync(file, 'utf8');

  // 链接与素材都是根相对路径（以 WEB_ROOT 为文档根）
  const refs = [...html.matchAll(/(?:href|src)="([^"#?]+)"/g)].map((m) => m[1]);
  refs.forEach((r) => {
    if (/^(https?:|mailto:|data:|#)/.test(r)) return;
    checked.links++;
    const target = r.startsWith('/') ? path.resolve(ROOT, r.slice(1)) : path.resolve(dir, r);
    if (!exists(target)) problems.push(post.slug + ' 死链 → ' + r);
  });
  const imgs = [...html.matchAll(/<img[^>]+src="([^"]+)"/g)].map((m) => m[1]);
  imgs.forEach((r) => {
    checked.assets++;
    const target = r.startsWith('/') ? path.resolve(ROOT, r.slice(1)) : path.resolve(dir, r);
    if (!exists(target)) problems.push(post.slug + ' 缺图 → ' + r);
  });
  // 基本结构
  ['<html', '<head', '<body', 'post-head', 'post-body'].forEach((k) => {
    if (html.indexOf(k) < 0) problems.push(post.slug + ' 缺少结构 ' + k);
  });
  if (html.indexOf('style/base.css') < 0) problems.push(post.slug + ' 未引入 base.css');
  if (html.indexOf('style/' + post.style + '.css') < 0) problems.push(post.slug + ' 未引入 ' + post.style + '.css');
});

console.log('检查文件 %d 个，链接 %d 条，图片 %d 张', checked.files, checked.links, checked.assets);
if (problems.length) {
  console.log('发现 %d 个问题：', problems.length);
  problems.slice(0, 30).forEach((p) => console.log('  ✗ ' + p));
  process.exitCode = 1;
} else {
  console.log('全部通过：链接、素材、风格引用、页面结构均正常');
}
