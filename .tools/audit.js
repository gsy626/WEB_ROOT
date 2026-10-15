'use strict';
/* 逐篇检查：公式、图片、结构、以及正文里是否还有 LaTeX 残渣 */
const fs = require('fs');
const path = require('path');
const { posts } = require('./posts');

const ROOT = 'D:\\HOME\\WEB_ROOT';
let totalMath = 0, totalDollars = 0, totalTex = 0;
console.log('slug'.padEnd(42) + '公式   图片  $残渣  LaTeX残渣');
console.log('-'.repeat(78));
posts.forEach((p) => {
  const h = fs.readFileSync(path.join(ROOT, 'content', p.slug, 'index.html'), 'utf8');
  const body = (h.match(/<article class="post-body">([\s\S]*?)<\/article>/) || [, ''])[1];
  const plain = body
    .replace(/<span class="math-(?:inline|block)"[^>]*>[\s\S]*?<\/span>/g, ' ')
    .replace(/<pre><code>[\s\S]*?<\/code><\/pre>/g, ' ')
    .replace(/<[^>]+>/g, ' ');
  const math = (body.match(/class="math-(inline|block)"/g) || []).length;
  const imgs = (body.match(/<img\b/g) || []).length;
  const dollars = (plain.match(/\$/g) || []).length;
  const texRes = (plain.match(/\\[a-zA-Z]{2,}|\\begin\{|\\end\{/g) || []).length;
  totalMath += math; totalDollars += dollars; totalTex += texRes;
  const flag = dollars + texRes > 0 ? '  ←' : '';
  console.log(p.slug.padEnd(42) + String(math).padStart(4) + String(imgs).padStart(7) +
    String(dollars).padStart(7) + String(texRes).padStart(9) + flag);
});
console.log('-'.repeat(78));
console.log('合计：公式 ' + totalMath + '，$ 残渣 ' + totalDollars + '，LaTeX 残渣 ' + totalTex);
