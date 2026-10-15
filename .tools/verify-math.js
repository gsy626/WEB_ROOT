'use strict';
/* 用 KaTeX（Node 版）把生成页面里的每条公式预渲染一遍，找出真正的语法错误。
   浏览器端仍然用同一份 KaTeX 渲染，这里只是提前校验。 */
const fs = require('fs');
const path = require('path');
const { posts } = require('./posts');

global.document = {
  createElement: () => ({ style: {}, setAttribute() {}, appendChild() {} }),
  createTextNode: () => ({}),
  querySelector: () => null,
  head: { appendChild() {} },
  body: { appendChild() {} }
};

const katexPath = path.join('D:\\HOME\\WEB_ROOT', 'style', 'vendor', 'katex', 'katex.min.js');
// eslint-disable-next-line import/no-dynamic-require
const katex = require(katexPath);

const CONTENT = 'D:\\HOME\\WEB_ROOT\\content';
let total = 0, fails = 0;
const report = [];

for (const post of posts) {
  const file = path.join(CONTENT, post.slug, 'index.html');
  if (!fs.existsSync(file)) continue;
  const html = fs.readFileSync(file, 'utf8');
  const m = html.match(/<article class="post-body">([\s\S]*?)<\/article>/);
  const body = m ? m[1] : '';
  const blocks = body.match(/<span class="math-(?:inline|block)"[^>]*>[\s\S]*?<\/span>/g) || [];
  post.__fails = [];
  blocks.forEach((blk) => {
    const cls = /math-block/.test(blk) ? 'block' : 'inline';
    const inner = blk.replace(/^<span[^>]*>/, '').replace(/<\/span>$/, '');
    const tex = inner
      .replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"')
      .replace(/&#39;/g, "'").replace(/&amp;/g, '&');
    total++;
    try {
      katex.renderToString(tex, {
        displayMode: cls === 'block',
        throwOnError: true,
        strict: false,
        trust: false,
        macros: { '\\bvec': '\\overrightarrow{#1}', '\\D': '\\Delta', '\\R': '\\mathbb{R}' }
      });
    } catch (err) {
      fails++;
      post.__fails.push({ tex: tex.slice(0, 150).replace(/\s+/g, ' '), msg: String(err.message).slice(0, 120) });
    }
  });
  if (post.__fails.length) report.push(post);
}

console.log(`公式总数 ${total}，渲染失败 ${fails}`);
report.forEach((post) => {
  console.log('\n===== ' + post.slug + '  失败 ' + post.__fails.length + ' / 共 ' + (post.__fails.length + 1) + '+');
  const seen = new Set();
  post.__fails.slice(0, 8).forEach((f) => {
    if (seen.has(f.tex)) return;
    seen.add(f.tex);
    console.log('  tex: ' + f.tex);
    console.log('  err: ' + f.msg);
  });
});
