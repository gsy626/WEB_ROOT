'use strict';
/* ==========================================================================
   tex.js —— LaTeX 源码 → 网页 HTML（针对本项目这些 .tex 的写作习惯做归一化）
   思路：
     1) 先把"行内/行间数学"整体抽出来存进仓库（converted-text.js 的字符串通道），
        避免后续的 LaTeX 命令清理误伤公式内部；
     2) 再把剩下的正文拉平成 Markdown；
     3) 最后交给 md.js 统一渲染，公式按原样还原。
   TikZ 独立图形源码走代码展板形式。
   ========================================================================== */

const md = require('./md');
const fs = require('fs');

/* --------------------------------------------------------------- 读取源文件 */
/**
 * 博文的 .tex 多为 Windows 记事本保存的 GBK/GB18030，
 * 这里先按 UTF-8 试读，出现替换字符则回退到 GB18030。
 */
function readTextFile(file) {
  const buf = fs.readFileSync(file);
  if (buf.length >= 3 && buf[0] === 0xEF && buf[1] === 0xBB && buf[2] === 0xBF) {
    return buf.slice(3).toString('utf8');
  }
  const utf8 = buf.toString('utf8');
  if (utf8.indexOf('\uFFFD') === -1) return utf8;
  try {
    return new TextDecoder('gb18030').decode(buf);
  } catch (e) {
    return utf8;
  }
}

/* ---------------------------------------------------------- 数学宏归一化 */
/* 单参数宏（用配对花括号读取，允许参数里再套花括号） */
const MATH_MACROS_1 = {
  '\\bvec': '\\overrightarrow{#1}',
  '\\D': '\\Delta',
  '\\R': '\\mathbb{R}',
  '\\geh': '\\sqrt{#1}',
  '\\xiab': '_{#1}',
  '\\l': '\\left|',
  '\\r': '\\right|'
};
/* 双参数宏 */
const MATH_MACROS_2 = {
  '\\hs': '#1(#2)',
  '\\p': '#1^{\\prime}(#2)',
  '\\pp': '#1^{\\prime\\prime}(#2)'
};

/** 单条公式清理：作者自定义命令 → KaTeX 可识别写法 */
function normalizeMath(tex) {
  let s = String(tex);
  s = s.replace(/\u0001M\d+\u0001/g, ' ');                    // 清理残留占位符
  s = s.replace(/\\\(/g, '');                                  // 作者 \renewcommand{\(}{\left(}，网页里多余
  s = s.replace(/\\\)/g, '');

  // 双参数宏
  s = s.replace(/\\(hs|pp|p)(?![a-zA-Z])/g, (m, name) => `\u0005${name}\u0005`);
  for (let guard = 0; guard < 40; guard++) {
    const m = s.match(/\u0005(hs|pp|p)\u0005\s*\{/);
    if (!m) break;
    const start = m.index + m[0].length - 1;
    const a = readBraced(s, start);
    if (!a) { s = s.replace(/\u0005(hs|pp|p)\u0005/, ''); continue; }
    let rest = s.slice(a.next);
    const bm = rest.match(/^\s*\{/);
    if (!bm) { s = s.replace(/\u0005(hs|pp|p)\u0005/, ''); continue; }
    const b = readBraced(rest, bm[0].length - 1);
    if (!b) { s = s.replace(/\u0005(hs|pp|p)\u0005/, ''); continue; }
    const tpl = MATH_MACROS_2['\\' + m[1]];
    const repl = tpl.replace('#1', a.content).replace('#2', b.content);
    s = s.slice(0, m.index) + repl + rest.slice(b.next);
  }
  // 求和宏：\sigm{下界}{上界} → \sum^{上界}_{下界}
  for (let guard = 0; guard < 40; guard++) {
    const m = s.match(/\\sigm(?![a-zA-Z])\s*\{/);
    if (!m) break;
    const start = m.index + m[0].length - 1;
    const a = readBraced(s, start);
    if (!a) { s = s.replace(/\\sigm(?![a-zA-Z])/, ''); continue; }
    const rest = s.slice(a.next);
    const bm = rest.match(/^\s*\{/);
    if (!bm) { s = s.replace(/\\sigm(?![a-zA-Z])/, ''); continue; }
    const b = readBraced(rest, bm[0].length - 1);
    if (!b) { s = s.replace(/\\sigm(?![a-zA-Z])/, ''); continue; }
    const repl = `\\sum^{${b.content}}_{${a.content}}`;
    s = s.slice(0, m.index) + repl + rest.slice(b.next);
  }
  // 单参数宏
  for (let guard = 0; guard < 80; guard++) {
    const m = s.match(/\\(bvec|D|R|geh|xiab|l|r)(?![a-zA-Z])\s*\{/);
    if (!m) break;
    const start = m.index + m[0].length - 1;
    const a = readBraced(s, start);
    if (!a) { s = s.replace(new RegExp('\\\\' + m[1] + '(?![a-zA-Z])'), ''); continue; }
    const tpl = MATH_MACROS_1['\\' + m[1]];
    const repl = tpl.replace('#1', a.content);
    s = s.slice(0, m.index) + repl + s.slice(a.next);
  }
  // 无参命令
  s = s.replace(/\\diff(?![a-zA-Z])/g, '\\mathrm{d}');
  s = s.replace(/\\lra(?![a-zA-Z])/g, '\\Leftrightarrow');
  s = s.replace(/\\so(?![a-zA-Z])/g, '\\therefore');
  s = s.replace(/\\be(?![a-zA-Z])/g, '\\because');
  // \l \r 是绝对值竖线（作者自定义），不带花括号参数
  s = s.replace(/\\l(?![a-zA-Z])/g, '\\left|');
  s = s.replace(/\\r(?![a-zA-Z])/g, '\\right|');
  // 交叉引用在网页里没有编号系统，直接写标签
  s = s.replace(/\\(?:eqref|ref)\{([^}]*)\}/g, '($1)');
  s = s.replace(/\u0005/g, '');

  // split 只能嵌在 equation 里，KaTeX 下换成等价的 aligned
  s = s.replace(/\\begin\{split\}/g, '\\begin{aligned}').replace(/\\end\{split\}/g, '\\end{aligned}');
  // cases 环境不支持 & 对齐符
  s = s.replace(/\\begin\{cases\}([\s\S]*?)\\end\{cases\}/g, (m, inner) => `\\begin{cases}${inner.replace(/&/g, '')}\\end{cases}`);
  // 没有任何环境包裹、却直接用了换行/对齐的公式，补一层 aligned
  if (!/\\begin\{/.test(s) && (/\\\\/.test(s) || /(^|[^\\])&/.test(s))) {
    s = `\\begin{aligned}${s}\\end{aligned}`;
  }

  s = s.replace(/\\textcircled\s*\{\s*\\?(\d)\s*\}/g, (m, d) => ({ 0: '⓪', 1: '①', 2: '②', 3: '③', 4: '④', 5: '⑤', 6: '⑥', 7: '⑦', 8: '⑧', 9: '⑨' }[d] || m));
  s = s.replace(/\\textcircled\s*\{\s*([①-⑳])\s*\}/g, '$1');
  s = s.replace(/\\textuparrow/g, '\\uparrow');
  s = s.replace(/\\textdownarrow/g, '\\downarrow');
  s = s.replace(/\\textrightarrow/g, '\\rightarrow');
  s = s.replace(/\\textperiodcentered/g, '\\cdot');
  s = s.replace(/\\(?:vspace|hspace|displaystyle|limits)\b\s*(\{[^}]*\})?/g, '');
  s = s.replace(/\\left\s*\./g, '.');
  s = s.replace(/\\right\s*\./g, '.');
  return s.trim();
}

/** 从 s[i] 处的 '{' 取出配对内容（允许内部嵌套花括号） */
function readBraced(s, i) {
  if (s[i] !== '{') return null;
  let depth = 0;
  for (let j = i; j < s.length; j++) {
    const ch = s[j];
    if (ch === '\\') { j++; continue; }
    if (ch === '{') depth++;
    else if (ch === '}') {
      depth--;
      if (depth === 0) return { content: s.slice(i + 1, j), next: j + 1 };
    }
  }
  return null;
}

function katexMacros() { return {}; }

/* ------------------------------------------------------------ 导言区识别 */
function splitPreamble(src) {
  const m = src.match(/\\begin\{document\}/);
  if (!m) return { preamble: '', body: src, standalone: false };
  const start = m.index + m[0].length;
  const endM = src.slice(start).match(/\\end\{document\}/);
  const end = endM ? start + endM.index : src.length;
  const preamble = src.slice(0, m.index);
  return { preamble, body: src.slice(start, end), standalone: /standalone/.test(preamble) };
}

function readMeta(preamble) {
  const meta = { standalone: /standalone/.test(preamble), cls: '', packages: [], title: '' };
  const cls = preamble.match(/\\documentclass(?:\[[^\]]*\])?\{([^}]*)\}/);
  if (cls) meta.cls = cls[1];
  (preamble.match(/\\usepackage(?:\[[^\]]*\])?\{([^}]*)\}/g) || []).forEach((p) => {
    const inner = p.match(/\{([^}]*)\}/);
    if (inner) meta.packages.push(inner[1]);
  });
  const t = preamble.match(/\\title\{([^}]*)\}/);
  if (t) meta.title = t[1];
  return meta;
}

/* ------------------------------------------------------- 图片路径与宽度 */
function imageSrc(name) {
  const clean = String(name).trim().replace(/^\.\//, '').replace(/\\/g, '/').replace(/^\/+/, '');
  if (/\.(png|jpe?g|gif|webp|svg)$/i.test(clean)) return clean;
  return clean + '.png';
}
function parseWidth(opt) {
  if (!opt) return '';
  const m = opt.match(/([\d.]+)\s*\\(?:linewidth|textwidth)/);
  if (m) return Math.round(parseFloat(m[1]) * 100) + '%';
  const cm = opt.match(/([\d.]+)\s*cm/);
  if (cm) return Math.round(parseFloat(cm[1]) * 40) + 'px';
  const mm = opt.match(/([\d.]+)\s*mm/);
  if (mm) return Math.round(parseFloat(mm[1]) * 4) + 'px';
  return '';
}

/* --------------------------------------------------------- 正文预处理 */
/**
 * @param {string} body  document 环境内的内容
 * @returns {string} 拉平后的 Markdown（公式以内联标记 $$..$$ / $..$ 保留）
 */
function flattenTex(rawBody) {
  let s = String(rawBody).replace(/\r\n?/g, '\n');

  // 1. 注释（保留 \% \#）
  s = s.replace(/(^|[^\\])%[^\n]*/g, '$1');

  // 2. 图形
  s = s.replace(/\\begin\{figure\*?\}(?:\[[^\]]*\])?([\s\S]*?)\\end\{figure\*?\}/g, (m, inner) => {
    const img = inner.match(/\\includegraphics(?:\[([^\]]*)\])?\{([^}]*)\}/);
    if (!img) return '';
    const cap = ((inner.match(/\\caption\{([^}]*)\}/) || [])[1] || '').trim();
    return `\n\n!(${parseWidth(img[1] || '')})[${cap}](${imageSrc(img[2])})\n\n`;
  });
  s = s.replace(/\\includegraphics(?:\[([^\]]*)\])?\{([^}]*)\}/g,
    (m, opt, name) => `\n\n!(${parseWidth(opt || '')})[](${imageSrc(name)})\n\n`);

  // 3. 导言区性质的整行命令（宏包参数、字体名等）先整行丢掉，
  //    避免它们的参数内容被后面误当作正文
  s = s.replace(/(?<![a-zA-Z])\\(?:def|definecolor|tikzfading|pgfmathsetseed|pgfmathsetmacro|pgfmathdeclarefunction|usetikzlibrary|tikzset|titleformat|titlecontents|setlist|fancyhf|cfoot|headrulewidth|footrulewidth|setCJKmainfont|setCJKsansfont|setCJKfamilyfont|setmainfont|setmathfont|graphicspath|geometry|setcounter|addcontentsline|setlength|renewcommand|newcommand|providecommand|DeclareMathOperator)\b[^\n]*/g, ' ');

  // 4. LaTeX 行尾换行 \\[-16pt] / \\[2mm] 先收掉，否则会被当成 \[ 行间公式
  s = s.replace(/\\\\\[[^\]]*\]/g, ' ');
  // 5. \( \) 在多数文稿里其实就是"行内公式"的另一种写法（作者另有一篇把它
  //    重定义成普通圆括号，那种情况按普通括号还原）
  const rawParen = /\\\([\s\S]{0,200}?\\\)/g;
  const parenHits = s.match(rawParen) || [];
  // 判据：含 LaTeX 命令/数学符号，或"没有中文的短表达式"，都算公式
  const parenLooksMath = parenHits.filter((h) => {
    const inner = h.replace(/^\\\(|\\\)$/g, '');
    if (/[\u4e00-\u9fa5]/.test(inner)) return false;
    if (/\\[a-zA-Z]+|[\^_]\{|[=<>]|[+\-*/]|\d|[{}]/.test(inner)) return true;
    return inner.trim().split(/\s+/).length <= 5;      // 例如 \( f(x) \)、\( x \)
  }).length;
  if (parenHits.length && parenLooksMath >= parenHits.length * 0.8) {
    s = s.replace(/\\\(([\s\S]*?)\\\)/g, (m, inner) => `$${normalizeMath(inner)}$`);
  } else {
    s = s.replace(/\\\(/g, '(');
    s = s.replace(/\\\)/g, ')');
  }

  // 4. 数学：先抽走，避免被下面的命令清理破坏
  const mathStore = [];
  const keep = (tex, block) => {
    if (/^\u0001M\d+\u0001$/.test(String(tex))) return String(tex);   // 已抽取过，避免二次归一化
    let norm = normalizeMath(tex);
    if (!norm) return '';
    mathStore.push({ tex: norm, block: Boolean(block) });
    return `\u0001M${mathStore.length - 1}\u0001`;
  };
  s = s.replace(/\\begin\{(equation\*?|align\*?|gather(?:ed)?\*?|multline\*?|split|displaymath|eqnarray\*?|alignat\*?)\}([\s\S]*?)\\end\{\1\}/g,
    (m, env, inner) => {
      const tex = inner.replace(/\\label\{[^}]*\}/g, '').replace(/\\notag|\\nonumber/g, '');
      return '\n\n' + keep(tex, true) + '\n\n';
    });
  // \begin{subequations} 之类的分组外壳：网页里不需要
  s = s.replace(/\\(?:begin|end)\{subequations\}/g, '\n\n');
  s = s.replace(/\\\[([\s\S]*?)\\\]/g, (m, tex) => '\n\n' + keep(tex, true) + '\n\n');
  s = s.replace(/\\\(([\s\S]*?)\\\)/g, (m, tex) => keep(tex, false));
  s = s.replace(/\$\$([\s\S]*?)\$\$/g, (m, tex) => '\n\n' + keep(tex, true) + '\n\n');
  // 10. 数学之外的 \( \) 变成 $ $ 也要能被抽取（放在 $$ 之前处理）
  s = s.replace(/(?<!\\)\$([^$\n]+?)(?<!\\)\$/g, (m, tex) => keep(tex, false));


  // 4. 标题
  s = s.replace(/\\chapter\*?\{([^{}]*)\}/g, '\n\n## $1\n\n');
  s = s.replace(/\\section\*?\{([^{}]*)\}/g, '\n\n## $1\n\n');
  s = s.replace(/\\subsection\*?\{([^{}]*)\}/g, '\n\n### $1\n\n');
  s = s.replace(/\\subsubsection\*?\{([^{}]*)\}/g, '\n\n#### $1\n\n');

  // 5. 列表环境（在剥离 \item 之前先做，才能保留层级）
  s = convertListEnvs(s);

  // 6. 选项宏
  s = s.replace(/\\(?:choices|Choices)\s*\{([^{}]*)\}\s*\{([^{}]*)\}\s*\{([^{}]*)\}\s*\{([^{}]*)\}/g,
    (m, a, b, c, d) => `\n\n- **A.** ${a}\n- **B.** ${b}\n- **C.** ${c}\n- **D.** ${d}\n\n`);

  // 7. 版式命令：整行只有命令的（可能以花括号开头）一律丢弃
  s = s.split('\n').map((line) => {
    const t = line.trim();
    if (/^\{?\s*\\[a-zA-Z@]+\*?(\[[^\]]*\])?(\{[^{}]*\})*\\?(\[[^\]]*\])?\s*\}?\s*$/.test(t)) return '';
    return line;
  }).join('\n');

  // 8. 命令与符号清理
  s = s.replace(/\\underline\{\\hspace\{([^}]*)\}\}/g, '<span class="blank" style="display:inline-block;min-width:$1;border-bottom:1px solid currentColor"></span>');
  s = s.replace(/\\underline\{([^{}]*)\}/g, '<u>$1</u>');
  s = s.replace(/\\rule(?:\{[^{}]*\}){2}/g, ' ');
  s = s.replace(/\\(?:textbf|emph|textit|text|mbox|mathrm|operatorname)\{([^{}]*)\}/g, '$1');
  s = s.replace(/\{\\[a-zA-Z@]+\s*([^{}]*)\}/g, '$1');          // {\hei 文字} → 文字
  s = s.replace(/\{\s*\\[a-zA-Z@]+[^{}]*\}/g, ' ');
  s = s.replace(/\\(?:vspace|hspace|vskip|hskip|kern|zihao|fontsize|setlength|addtolength)\*?\s*(\[[^\]]*\])?\s*(\{[^{}]*\})?(\{[^{}]*\})?/g, ' ');
  s = s.replace(/\\(?:newpage|clearpage|pagebreak|linebreak|nobreak|noindent|centering|raggedright|raggedleft|maketitle|tableofcontents|thispagestyle|pagestyle|frontmatter|mainmatter|backmatter|twocolumn|vfill|hfill|par|smallskip|medskip|bigskip|selectfont|bfseries|itshape|rmfamily|sffamily|ttfamily|large|Large|LARGE|huge|Huge|small|footnotesize|tiny|normalsize|heiti|kaishu|songti|fangsong|hei|kai|quad|qquad|protect|relax|leavevmode|textwidth|linewidth|baselineskip|textheight|columnwidth|item)\b\s*(\[[^\]]*\])?/g, ' ');
  s = s.replace(/\\(?:ref|eqref|nameref|pageref)\{([^{}]*)\}/g, '($1)');
  s = s.replace(/\\label\{[^{}]*\}/g, ' ');
  s = s.replace(/\\(?:quad|qquad|,|;|:|!)(?![a-zA-Z])/g, ' ');
  // 注释行、行尾换行符
  s = s.replace(/\\\[-?[\d.]+pt\]/g, ' ');
  s = s.replace(/\\+/g, ' ');
  // 残留的 LaTeX 花括号：\{ \} 恢复成字面量，其余的去掉
  s = s.replace(/\\\{/g, '\u0003');
  s = s.replace(/\\\}/g, '\u0004');
  s = s.replace(/[{}]/g, ' ');
  s = s.replace(/\u0003/g, '{');
  s = s.replace(/\u0004/g, '}');

  // 9. 环境外壳
  s = s.replace(/\\begin\{center\}(?:\[[^\]]*\])?/g, '\n\n@@center@@\n');
  s = s.replace(/\\end\{center\}/g, '\n\n@@endcenter@@\n');
  s = s.replace(/\\begin\{(?:flushleft|flushright|quote|quotation|minipage|titlepage|abstract)\}(?:\[[^\]]*\])?(?:\{[^{}]*\})?/g, '\n\n');
  s = s.replace(/\\end\{(?:flushleft|flushright|quote|quotation|minipage|titlepage|abstract|document|itemize|enumerate)\}/g, '\n\n');

  // 10. 还原数学
  s = s.replace(/\u0001M(\d+)\u0001/g, (m, n) => {
    const item = mathStore[Number(n)];
    if (!item) return '';
    return item.block ? `$$${item.tex}$$` : `$${item.tex}$`;
  });

  // 11. 数学之外的 \\ 是 LaTeX 换行，换成行内换行记号（交给 md.js 变成 <br/>）
  s = s.replace(/\\\\/g, '\u0006');
  // 11.5 兜底修补：公式抽取漏掉的零星 LaTeX 语法，别让它以源码形式出现在页面上
  s = s.replace(/\$\$\$?/g, ' ');                       // 落单的 $ 定界符
  s = s.replace(/\\begin\{[a-zA-Z*]+\}(?:\[[^\]]*\])?/g, ' ');
  s = s.replace(/\\end\{[a-zA-Z*]+\}/g, ' ');
  s = s.replace(/\\[a-zA-Z@]+\s*(\[[^\]]*\])?/g, ' ');   // 其余命令名
  // 12. 收尾
  s = s.replace(/[ \t]+$/gm, '');
  s = s.replace(/[ \t]{2,}/g, ' ');
  s = s.replace(/\n{4,}/g, '\n\n\n');
  return s;
}

/* ------------------------------------------------- enumerate / itemize 转换 */
function convertListEnvs(s) {
  const envRe = /\\begin\{(enumerate|itemize)\}(?:\[[^\]]*\])?([\s\S]*?)\\end\{\1\}/;
  for (let guard = 0; guard < 200; guard++) {
    const m = s.match(envRe);
    if (process.env.TEX_DEBUG) {
      console.log('[env] pass', guard, m ? JSON.stringify(m[2].slice(0, 50)) : 'NO MATCH');
      if (!m) break;
    }
    if (!m) break;
    const ordered = m[1] === 'enumerate';
    const items = m[2].split(/\\item(?:\[[^\]]*\])?/).slice(1);
    const rendered = items.map((it) => {
      const clean = it.trim().replace(/\n{2,}/g, '\n').replace(/\n[ \t]+/g, '\n ');
      const lines = clean.split('\n');
      return (ordered ? '1. ' : '- ') + [lines[0]].concat(lines.slice(1).map((l) => '  ' + l)).join('\n');
    }).join('\n');
    s = s.slice(0, m.index) + '\n\n' + rendered + '\n\n' + s.slice(m.index + m[0].length);
  }
  return s;
}

/* -------------------------------------------------------------- 主转换器 */
/** 判断正文是否"基本就是一张 TikZ 图"（纯图形作品） */
function isTikzOnly(body) {
  if (!/\\begin\{tikzpicture\}|\\tikz\b/.test(body)) return false;
  const proseLines = String(body).split('\n').filter((line) => {
    const t = line.replace(/(^|[^\\])%.*$/, '$1').trim();
    if (!t) return false;
    if (t.indexOf('\\') >= 0) return false;                 // 含 LaTeX 命令的行视为代码
    return (t.match(/[\u4e00-\u9fa5]|[A-Za-z]{3,}/g) || []).join('').length >= 8;
  });
  return proseLines.length < 6;
}

function convert(src, opts = {}) {
  const { preamble, body, standalone } = splitPreamble(src);
  const meta = readMeta(preamble);

  if (opts.mode === 'tikz' || (standalone && /tikzpicture/.test(body)) || isTikzOnly(body)) {
    return tikzArticle(src, meta, opts);
  }

  const flat = flattenTex(body);
  const r = md.convert(flat, Object.assign({ keepIndent: true }, opts));
  const stats = Object.assign({ headings: r.headings.length }, r.stats);
  return { html: r.html, headings: r.headings, stats, meta, preamble, standalone, flattened: flat };
}

/* -------------------------------------------------------- TikZ 图形文章 */
function tikzArticle(src, meta, opts = {}) {
  const code = src.trim();
  const note = opts.tikzNote || '下方即生成该图形的完整 LaTeX（TikZ）源码，可直接用 <code>xelatex</code> 或 <code>lualatex</code> 编译得到矢量图。';
  const html = [
    `<p class="muted small">${note}</p>`,
    '<div class="code-panel">',
    '<div class="code-head"><span>LaTeX · TikZ</span><button class="code-copy" type="button">复制</button></div>',
    '<pre><code>' + md.escapeHtml(code) + '</code></pre>',
    '</div>'
  ].join('\n');
  return {
    html, headings: [], meta,
    stats: { mathInline: 0, mathBlock: 0, images: 0, codeBlocks: 1, callouts: 0, eqCards: 0, headings: 0 }
  };
}

module.exports = { convert, normalizeMath, katexMacros, splitPreamble, readMeta, imageSrc, parseWidth, flattenTex, isTikzOnly, readTextFile };
