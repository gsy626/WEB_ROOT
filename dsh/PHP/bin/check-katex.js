/**
 * 用站点自带的 KaTeX 逐条试渲染 bin/extract-math.php 导出的公式。
 * 任何一条渲染失败都会以非 0 退出码结束，方便放进 CI 或提交前自检。
 *
 *   node bin/check-katex.js
 */
'use strict';

const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const katex = require(path.join(root, 'assets/katex/katex.min.js'));
const auditFile = path.join(root, 'data/cache/math-audit.json');

if (!fs.existsSync(auditFile)) {
  console.error('找不到 ' + auditFile + '，请先运行：php bin/extract-math.php');
  process.exit(2);
}

const data = JSON.parse(fs.readFileSync(auditFile, 'utf8'));

let total = 0;
let failed = 0;
let warned = 0;
const failures = [];
const warnings = [];

for (const post of data) {
  const macros = Object.assign({}, post.macros);
  for (const item of post.items) {
    total++;
    const base = {
      displayMode: item.display === 1,
      macros: macros,
      strict: false,
      trust: false,
    };
    try {
      katex.renderToString(item.tex, Object.assign({ throwOnError: true }, base));
    } catch (err) {
      failed++;
      failures.push({ post: post.title, tex: item.tex, error: String(err.message).slice(0, 200) });
      continue;
    }
    const collected = [];
    katex.renderToString(item.tex, Object.assign({}, base, {
      throwOnError: false,
      strict: function (code, msg) { collected.push(code + ': ' + msg); return 'ignore'; },
    }));
    if (collected.length) {
      warned++;
      warnings.push({ post: post.title, tex: item.tex, warns: collected });
    }
  }
}

console.log('KaTeX 版本   ' + katex.version);
console.log('公式总数     ' + total);
console.log('渲染失败     ' + failed);
console.log('有警告       ' + warned);

for (const w of warnings) {
  console.log('  [警告] ' + w.post + ' :: ' + w.tex.replace(/\s+/g, ' ').slice(0, 80));
  console.log('         ' + w.warns.join(' | ').slice(0, 150));
}

if (failures.length) {
  console.log('\n--- 失败明细 ---');
  for (const f of failures.slice(0, 40)) {
    console.log('[' + f.post + '] ' + f.tex.replace(/\s+/g, ' ').slice(0, 150));
    console.log('   => ' + f.error);
  }
  process.exit(1);
}

console.log('\n全部公式渲染通过。');
