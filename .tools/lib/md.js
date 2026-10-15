'use strict';
/* ==========================================================================
   md.js —— 极简 Markdown / Quarkdown 转换器（零依赖，纯 Node）
   支持：
     · 标题 # ## ### ####
     · 段落 / 硬换行（行尾两空格 或 行尾 \）
     · **粗体** *斜体* `行内代码` ~~删除~~
     · --- 分隔线、> 引用
     · - / * / + 与 1. 列表（含嵌套）
     · ``` 代码围栏（可带语言）
     · | 表格 |
     · [文字](链接) 与 ![alt](图片)
     · $...$ 行内公式、$$...$$ 行间公式（交给 KaTeX，浏览器端渲染）
     · Quarkdown 扩展：.box{标题} type:{tip|note|warning}、.eq{标签}{内容}、
       .center、.row/.column、!(宽度)[说明](图片)、.pagebreak、.tableofcontents
   输出为 HTML 片段字符串；数学公式以 <span class="math-inline|math-block"> 承载。
   ========================================================================== */

const IMG_EXT = /\.(png|jpe?g|gif|webp|svg|bmp|avif)$/i;

/* ------------------------------------------------------------------ 转义 */
function escapeHtml(s) {
  return String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}
function escapeAttr(s) { return escapeHtml(s).replace(/'/g, '&#39;'); }

/* -------------------------------------------------------------- 花括号解析 */
/** 从 s[i] 处的 '{' 开始，取出配对内容，返回 {content, next}；不平衡时返回 null */
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

/** 在文本中定位配对的花括号，容忍 .eq { a } { $ x $ } 这种空格写法 */
function findBrace(s, from) {
  const idx = s.indexOf('{', from);
  if (idx < 0) return null;
  return readBraced(s, idx);
}

/* --------------------------------------------------- 数学与图片的行内处理 */
/**
 * 找出文本里所有数学定界符（$$$ / $$ / $），按出现顺序两两配对。
 * 用"先配对、再渲染"代替"逐个向前找收尾"，
 * 可以正确处理 "x $…$ y $$…$$" 这种单双 $ 混排、以及落单的 $。
 * @returns {Array<{start:number,end:number,tex:string,block:boolean,ref:string,stray:boolean}>}
 */
function findMathSpans(text) {
  const delims = [];
  for (let i = 0; i < text.length; i++) {
    if (text[i] !== '$') continue;
    if (text[i - 1] === '\\') continue;                 // \$ 是转义的美元符号
    let n = 1;
    while (text[i + n] === '$') n++;
    if (n >= 3) delims.push({ pos: i, len: 3, block: true });
    else if (n === 2) delims.push({ pos: i, len: 2, block: true });
    else delims.push({ pos: i, len: 1, block: false });
    i += n - 1;
  }

  // 坏配对判定：左右紧挨 $、跨段落、又长又混着中文/图片语法、括号不配对
  const isBad = (open, close, inner) => {
    if (text[open.pos - 1] === '$' || text[close.pos + close.len] === '$') return true;
    if (!open.block && !close.block) {
      const nl = (inner.match(/\n/g) || []).length;
      if (nl >= 2 && inner.length > 120) return true;
      if (inner.length > 300 && /[\u4e00-\u9fa5]|\]\(/.test(inner)) return true;
      if (/\]\(/.test(inner) && /[\u4e00-\u9fa5]/.test(inner)) return true;
      const ob = (inner.match(/\{/g) || []).length;
      const cb = (inner.match(/\}/g) || []).length;
      if (ob !== cb && inner.length < 60) return true;
    }
    return false;
  };

  // 反复配对 → 剔除坏配对 → 再配对，直到稳定
  let work = delims.slice();
  let pairs = [];
  for (let round = 0; round < 6; round++) {
    pairs = [];
    const drop = new Set();
    if (work.length % 2 === 1) drop.add(work.length - 1);   // 落单的最后一个
    for (let k = 0; k + 1 < work.length; k += 2) {
      if (drop.has(k) || drop.has(k + 1)) continue;
      const open = work[k], close = work[k + 1];
      const inner = text.slice(open.pos + open.len, close.pos);
      if (isBad(open, close, inner)) { drop.add(k); drop.add(k + 1); continue; }
      pairs.push({ open, close, inner });
    }
    if (!drop.size) break;
    work = work.filter((_, idx) => !drop.has(idx));
  }

  const spans = pairs.map(({ open, close, inner }) => {
    const refMatch = inner.match(/^\s*\{#([^}]+)\}\s*/);
    const body = refMatch ? inner.slice(refMatch[0].length) : inner;
    return {
      start: open.pos,
      end: close.pos + close.len,
      tex: body.trim(),
      block: open.block && close.block,
      ref: refMatch ? refMatch[1] : '',
      stray: false
    };
  }).sort((a, b) => a.start - b.start);

  // 没配上对的定界符：原样保留（宁可显示成普通文本，也不要吞掉正文）
  delims.forEach((d) => {
    if (!spans.some((s) => s.start === d.pos)) {
      spans.push({ start: d.pos, end: d.pos + d.len, tex: null, block: d.block, ref: '', stray: true, keepDelims: true });
    }
  });
  return spans.sort((a, b) => a.start - b.start);
}

/** 行内公式被换行拆开时，把续行并回上一行（QD 里常见）。
    不跨空行、不碰行间公式围栏行，量比较保守。 */
function mergeInlineMathLines(text) {
  const lines = text.split('\n');
  const out = [];
  let carry = null;
  for (const line of lines) {
    if (carry !== null) {
      if (!line.trim()) { out.push(carry); carry = null; out.push(line); continue; }
      carry += ' ' + line.trim();
      if ((carry.match(/\$/g) || []).length % 2 === 0) { out.push(carry); carry = null; }
      continue;
    }
    const stripped = line.replace(/\\\\/g, '');
    const n = (stripped.match(/\$/g) || []).length;
    // 行间公式围栏行、以及本行含 $$ 的，都不要并
    if (n % 2 === 1 && n === 1 && !/\$\$/.test(stripped)) { carry = line; continue; }
    out.push(line);
  }
  if (carry !== null) out.push(carry);
  return out.join('\n');
}

/** 预先抽出 Quarkdown 的 .eq {标签} {公式} 卡片（花括号可跨行） */
function extractEqCards(text, store, stats) {
  const lines = text.split('\n');
  const out = [];
  for (let i = 0; i < lines.length; i++) {
    const tl = lines[i].replace(/^[ \t]+/, '');
    if (!/^\.eq\b/.test(tl)) { out.push(lines[i]); continue; }
    let buf = tl;
    let done = false;
    for (let guard = 0; guard < 400; guard++) {
      const kw = buf.search(/\.eq\b/);
      if (kw < 0) break;
      let bs = kw + 3;
      while (buf[bs] === ' ' || buf[bs] === '\t') bs++;
      const first = findBrace(buf, bs);
      const second = first ? findBrace(buf, first.next) : null;
      if (second) {
        const mm = second.content.match(/\$[^$]+\$/);   // 花括号里混着参数时只取公式
        const body = (mm ? mm[0] : `$$${second.content}$$`).trim();
        let label = first.content.trim();
        // 标签里带公式（作者常写 .eq {$ S^2 $} {公式}）：标签本身若含 $ 就当没标签
        if (/\$/.test(label)) label = '';
        if (stats) stats.eqCards++;
        const lab = label ? `<span class="eq-label">${escapeHtml(label)}</span>` : '';
        const inner = [];
        const body2 = protectMath(body, (h) => { inner.push(h); return h; });
        store.push(`<div class="eq-card">${lab}${inner.length ? inner.join('') : escapeHtml(body2)}</div>`);
        out.push(`\u0004E${store.length - 1}\u0004`);
        done = true;
        break;
      }
      if (i + 1 >= lines.length) break;
      i++;
      buf += '\n' + lines[i];
    }
    if (!done) out.push(lines[i]);
  }
  return out.join('\n');
}

/** 预先抽出 $$ / $$$ 行间公式，换成占位符（\u0004B<n>\u0004）
    行级扫描：整行只有 $$$（可带 {#标签}）才算围栏；收尾必须用同样长度的围栏，
    否则会把后面的行内 $ 当成收尾，把整段正文吞进公式里。 */
function extractBlockMath(text, store, stats) {
  const lines = text.split('\n');
  const fenceRe = /^[ \t]*(\$\$+)[ \t]*(\{#([^}]*)\})?[ \t]*$/;
  const fenceOf = (l) => {
    const m = l.match(fenceRe);
    return m ? { len: m[1].length, ref: m[3] || '' } : null;
  };
  const out = [];
  for (let i = 0; i < lines.length; i++) {
    const open = fenceOf(lines[i]);
    if (!open || open.len < 2) { out.push(lines[i]); continue; }
    // 往后找"同样长度"的收尾围栏
    let close = -1;
    for (let j = i + 1; j < lines.length; j++) {
      const f = fenceOf(lines[j]);
      if (f && f.len === open.len) { close = j; break; }
    }
    if (close < 0) { out.push(lines[i]); continue; }
    const body = lines.slice(i + 1, close).join('\n').trim();
    const looksMath = body.length < 400 && (/\\[a-zA-Z]|\^|_\{|[=<>]|\d/.test(body) || /^[\x00-\x7F\s]*$/.test(body));
    if (!body || !looksMath) { out.push(lines[i]); continue; }
    if (stats) stats.mathBlock++;
    const ref = open.ref ? ` data-ref="${escapeAttr(open.ref)}"` : '';
    store.push(`<span class="math-block"${ref}>${escapeHtml(body)}</span>`);
    out.push(`\u0004B${store.length - 1}\u0004`);
    i = close;
  }
  return out.join('\n');
}

/** 把落单的 $ 定义符换成占位符，内容交给 KaTeX；返回已转义的 HTML */
function protectMath(text, store) {
  const spans = findMathSpans(text);
  if (!spans.length) return text;
  let out = '';
  let cursor = 0;
  for (const sp of spans) {
    out += escapeHtml(text.slice(cursor, sp.start));
    if (sp.tex) {
      // 先把公式存进仓库，再把占位符当普通文本输出（占位符不含 $，不会被再次扫描）
      const attr = sp.ref ? ` data-ref="${escapeAttr(sp.ref)}"` : '';
      const cls = sp.block ? 'math-block' : 'math-inline';
      out += store(`<span class="${cls}"${attr}>${escapeHtml(sp.tex)}</span>`);
    }
    // 没配上对的定界符：保留原样，避免吞掉后面的正文
    if (sp.keepDelims) out += escapeHtml(text.slice(sp.start, sp.end));
    cursor = sp.end;
  }
  out += escapeHtml(text.slice(cursor));
  return out;
}

/** 行内 Markdown → HTML */
function inline(text, ctx) {
  let s = String(text);
  // 0. LaTeX 换行记号 → <br/>，先换成占位符，避免被转义
  s = s.replace(/\u0006/g, () => ctx.push('<br/>'));
  // 1. 先取出公式，避免内部字符被后续规则改写
  s = protectMath(s, ctx.push);

  // 2. 代码片段
  s = s.replace(/`([^`]+)`/g, (m, code) => `<code>${escapeHtml(code)}</code>`);

  // 3. 图片 ![alt](src) —— 注意与 QD 的 !(width)[alt](src) 区分
  s = s.replace(/!\[([^\]]*)\]\(([^)\s]+)(?:\s+"([^"]*)")?\)/g, (m, alt, src, title) => {
    return ctx.push(ctx.image(src, alt || title || '', null));
  });

  // 4. 链接
  s = s.replace(/\[([^\]]+)\]\(([^)\s]+)(?:\s+"([^"]*)")?\)/g, (m, label, href, title) => {
    const ext = /^https?:\/\//i.test(href);
    const t = title ? ` title="${escapeAttr(title)}"` : '';
    const rel = ext ? ' target="_blank" rel="noopener noreferrer"' : '';
    return `<a href="${escapeAttr(href)}"${t}${rel}>${inline(label, ctx)}</a>`;
  });

  // 5. 其余文本转义（占位符是 \u0000N\u0000 形式，不受影响）
  s = escapeHtml(s);

  // 6. 强调
  s = s.replace(/\*\*\*([^*]+)\*\*\*/g, '<strong><em>$1</em></strong>');
  s = s.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
  s = s.replace(/(^|[^*\w])\*([^*\n]+)\*/g, '$1<em>$2</em>');
  s = s.replace(/~~([^~]+)~~/g, '<del>$1</del>');

  // 7. 还原公式占位符
  return ctx.restore(s);
}

/** 收尾清理：修掉少数因源码写法怪异而产生的嵌套/畸形公式容器 */
function tidyMathHtml(html) {
  const re = /<span class="math-(inline|block)"( data-ref="[^"]*")?>/g;
  const cuts = [];
  let m;
  while ((m = re.exec(html)) !== null) {
    const openStart = m.index;
    const contentStart = openStart + m[0].length;
    // 用深度扫描找到配对的收尾标签
    let depth = 1;
    let p = contentStart;
    let closeStart = -1;
    while (p < html.length) {
      const nextOpen = html.indexOf('<span', p);
      const nextClose = html.indexOf('</span>', p);
      if (nextClose < 0) break;
      if (nextOpen >= 0 && nextOpen < nextClose) { depth++; p = nextOpen + 5; continue; }
      depth--;
      if (depth === 0) { closeStart = nextClose; break; }
      p = nextClose + 7;
    }
    if (closeStart < 0) continue;
    const inner = html.slice(contentStart, closeStart);
    // 只拆"明显包错"的容器：里面有块级元素，或公式内容里还留着 $ 定界符
    if (/\$/.test(inner) || /<div\b|<p\b|<figure\b|<ul\b|<h[1-4]\b/.test(inner)) {
      cuts.push({ openStart, contentStart, closeStart });
    }
  }
  for (let i = cuts.length - 1; i >= 0; i--) {
    const c = cuts[i];
    // 去掉这一层的开、收标签，只保留内容
    html = html.slice(0, c.openStart) + html.slice(c.contentStart, c.closeStart) + html.slice(c.closeStart + 7);
  }
  // 清洗掉个别只剩环境名的畸形公式容器
  html = html.replace(/<span class="math-(?:inline|block)"[^>]*>\s*(?:aligned|gathered|cases|split|array|matrix)\s*<\/span>/g, '');
  // 收尾修正：公式内容里不该再有 $ 定界符
  html = html.replace(/(<span class="math-(?:inline|block)"[^>]*>)([^<]*)(<\/span>)/g,
    (mm, a, inner, b) => a + inner.replace(/\$/g, '') + b);
  // 最后清一遍"漏在正文里的 LaTeX 残渣"（公式容器与代码展板内部不动）
  html = cleanTexResidue(html);
  return dropUnmatchedClosers(html, ['span', 'div', 'p', 'figure', 'li', 'ul', 'ol', 'td', 'tr', 'table', 'blockquote', 'h2', 'h3', 'h4']);
}

/** 把漏在正文里的零散 LaTeX 语法清掉（只处理非公式、非代码的文本片段） */
function cleanTexResidue(html) {
  const segments = html.split(/(<span class="math-(?:inline|block)"[\s\S]*?<\/span>|<pre\b[\s\S]*?<\/pre>|<code\b[\s\S]*?<\/code>)/);
  let res = segments.map((seg, i) => {
    if (i % 2 === 1) return seg;                        // 奇数下标是受保护片段
    return seg
      .replace(/\\\\/g, ' ')                            // LaTeX 换行残留
      .replace(/\\[a-zA-Z@]+\s*(\[[^\]]*\])?/g, ' ')    // 命令名
      .replace(/[{}]/g, '')                             // 残留花括号
      .replace(/\$/g, ' ')                              // 漏在正文里的定界符（真公式已被抽成 span）
      .replace(/[ \t]{2,}/g, ' ');
  }).join('');
  // 清掉只剩标点/空白的段落
  res = res.replace(/<p>[\s&;]*<\/p>/g, '')
    .replace(/<p>\s*(?:&[a-z]+;|[.,、，。；:：\s])*\s*<\/p>/g, '');
  return res;
}

/** 丢掉没有对应开标签的收尾标签（只处理行内/块级白名单） */
function dropUnmatchedClosers(html, tags) {
  const stack = [];
  const out = [];
  const re = /<(\/?)([a-zA-Z][\w-]*)([^>]*)>/g;
  let last = 0;
  let m;
  while ((m = re.exec(html)) !== null) {
    const closing = m[1] === '/';
    const name = m[2].toLowerCase();
    if (tags.indexOf(name) < 0) continue;
    out.push(html.slice(last, m.index));
    if (!closing) {
      if (!/\/$/.test(m[3])) stack.push(name);
      out.push(m[0]);
    } else {
      const at = stack.lastIndexOf(name);
      if (at >= 0) { stack.splice(at, 1); out.push(m[0]); }
      // 找不到对应开标签 → 直接丢弃这个收尾标签
    }
    last = m.index + m[0].length;
  }
  out.push(html.slice(last));
  return out.join('');
}

/* ------------------------------------------------------------------ 上下文 */
function createContext(opts) {
  const store = [];
  return {
    opts,
    image: opts.image || ((src, alt) => `<img src="${escapeAttr(src)}" alt="${escapeAttr(alt)}"/>`),
    push(html) {
      store.push(html);
      return `\u0000${store.length - 1}\u0000`;
    },
    restore(s) {
      return s.replace(/\u0000(\d+)\u0000/g, (m, n) => store[Number(n)] || '');
    }
  };
}

/* ------------------------------------------------------------- 块级：表格 */
function renderTable(rows, ctx) {
  const sep = rows[1];
  if (!sep || !/^\s*\|?[\s:|-]+\|?\s*$/.test(sep)) return null;
  const split = (line) => line.replace(/^\s*\|/, '').replace(/\|\s*$/, '').split('|').map((c) => c.trim());
  const head = split(rows[0]);
  const body = rows.slice(2).map(split);
  const aligns = split(sep).map((c) => (/^:-+:$/.test(c) ? 'center' : /-+:$/.test(c) ? 'right' : /^:-+/.test(c) ? 'left' : ''));
  const th = head.map((c, i) => `<th${aligns[i] ? ` style="text-align:${aligns[i]}"` : ''}>${inline(c, ctx)}</th>`).join('');
  const trs = body.map((cells) => '<tr>' + head.map((_, i) => `<td${aligns[i] ? ` style="text-align:${aligns[i]}"` : ''}>${inline(cells[i] || '', ctx)}</td>`).join('') + '</tr>').join('\n');
  return `<div class="table-scroll"><table>\n<thead><tr>${th}</tr></thead>\n<tbody>\n${trs}\n</tbody></table></div>`;
}

/* --------------------------------------------------- 块级：列表（含嵌套） */
function listItemIndent(line) {
  const m = line.match(/^(\s*)(?:[-*+]|\d+[.)])\s+/);
  return m ? m[1].replace(/\t/g, '    ').length : -1;
}

function renderList(items, ctx) {
  // items: [{indent, ordered, marker, text, children:[]}] 已构成树
  const tag = items[0].ordered ? 'ol' : 'ul';
  const startAttr = items[0].ordered && items[0].start && items[0].start !== 1 ? ` start="${items[0].start}"` : '';
  const lis = items.map((it) => {
    let html = inline(it.text, ctx);
    if (it.children && it.children.length) html += '\n' + renderList(it.children, ctx);
    return `<li>${html}</li>`;
  }).join('\n');
  return `<${tag}${startAttr}>\n${lis}\n</${tag}>`;
}

/* ------------------------------------------------------------- 主转换函数 */
/**
 * @param {string} text  源文本
 * @param {object} opts  { image(src, alt, widthPct), assetPrefix }
 * @returns {{html:string, headings:Array<{level:number,id:string,text:string}>, stats:object}}
 */
function convert(text, opts = {}) {
  const ctx = createContext(opts);
  const headings = [];
  const stats = { mathInline: 0, mathBlock: 0, images: 0, codeBlocks: 0, callouts: 0, eqCards: 0 };
  let slugCount = {};

  // ---- 统计并生成锚点 ----
  const originalImage = ctx.image;
  ctx.image = (src, alt, widthPct) => {
    stats.images++;
    return originalImage(src, alt, widthPct);
  };

  // ---- 先把行间公式（$$ / $$$ 成对围栏）整体抽走，避免与行内 $ 配对互相干扰 ----
  const blockStore = [];
  const raw0 = String(text).replace(/\r\n?/g, '\n')
    .replace(/<!---[\s\S]*?-->/g, '')      // Quarkdown 的三横线注释
    .replace(/<!---[\s\S]*$/gm, '')       // 未闭合的残留
    .replace(/<![^\n]*$/gm, '')           // <!------ 之类的长注释行
    .replace(/^\s*-{2,}>\s*$/gm, '')      // 与之配对的 -------> 行
    .replace(/^\s*<!-+.*$/gm, '');
  // ---- 行内公式跨行时并成一行，避免换行把配对关系打乱 ----
  const merged = mergeInlineMathLines(String(text).replace(/\r\n?/g, '\n'));
  const withoutEq = extractBlockMath(merged, blockStore, stats);
  const extracted = extractEqCards(withoutEq, blockStore, stats);
  const lines = extracted
    .replace(/<!--[\s\S]*?-->/g, '')      // HTML 注释（QD 里用来注释掉整段内容）
    .replace(/!?-{2,}>?/g, '')            // QD 里 <!--- ... ---> 这种不完整注释的残留
    .split('\n');
  const out = [];
  let i = 0;
  let paragraph = [];
  let inCode = false;
  let codeLang = '';
  let codeLines = [];
  let openStack = [];   // 容器：{kind:'callout'|'center'|'row'|'column'|'eq', html}

  const flushParagraph = () => {
    if (!paragraph.length) return;
    const raw = paragraph.join('\n');
    paragraph = [];
    out.push(`<p>${inline(raw, ctx)}</p>`);
  };

  /** 去掉缩进：保留一个前导空格，避免中文与行内公式被粘连 */
  const dedent = (s) => (opts.keepIndent ? String(s) : String(s).replace(/^[ \t]+/, (m) => (m.length ? ' ' : '')));

  const closeContainersTo = (targetDepth) => {
    while (openStack.length > targetDepth) {
      const c = openStack.pop();
      out.push(c.close);
    }
  };

  const slugify = (s) => {
    const base = String(s).toLowerCase()
      .replace(/<[^>]+>/g, '')
      .replace(/[^\w\u4e00-\u9fa5]+/g, '-')
      .replace(/^-+|-+$/g, '') || 'section';
    slugCount[base] = (slugCount[base] || 0) + 1;
    return slugCount[base] > 1 ? `${base}-${slugCount[base]}` : base;
  };

  const pushHeading = (level, rawText) => {
    const id = slugify(rawText);
    const txt = inline(rawText, ctx);
    headings.push({ level, id, text: rawText.replace(/[*`]/g, '') });
    out.push(`<h${level} id="${id}">${txt}</h${level}>`);
  };

  /* ---------------- QD 指令识别 ---------------- */
  const QD_BOX = /^\.box\b(.*)$/;
  const QD_IMAGE = /^!\(([^)]*)\)\[([^\]]*)\]\(([^)]+)\)\s*$/;
  const QD_IMAGE2 = /^!\[([^\]]*)\]\(([^)]+)\)\s*$/;
  const QD_IMAGE3 = /^\.image\s*\{([^}]*)\}(.*)$/;
  const QD_CENTER = /^\.center\s*$/;
  const QD_ROW = /^\.row\s*$/;
  const QD_COLUMN = /^\.column\s*$/;
  const QD_PAGEBREAK = /^\.pagebreak\s*$/;
  const QD_TOC = /^\.tableofcontents\b/;
  const QD_FUNC = /^\.(function|texmacro|docname|docdescription|doctype|doclang|theme|docauthors|pagemargin|paragraphstyle|container|pageformat|include|var)\b/;
  const QD_PARAMS = /^\t[a-zA-Z][\w-]*:/;

  const boxTypeClass = (t) => {
    switch ((t || '').toLowerCase()) {
      case 'tip': case 'hint': return 'callout-tip';
      case 'note': case 'info': return 'callout-note';
      case 'warning': case 'warn': case 'caution': return 'callout-warning';
      case 'quote': return 'callout-quote';
      default: return 'callout-note';
    }
  };

  const openCallout = (title, type) => {
    stats.callouts++;
    const t = title && title.trim() ? `<span class="callout-title">${inline(title.trim(), ctx)}</span>` : '';
    const cls = `callout ${boxTypeClass(type)}`;
    out.push(`<div class="${cls}">${t}`);
    openStack.push({ kind: 'callout', close: '</div>' });
  };

  const emitEq = (label, bodyRaw) => {
    stats.eqCards++;
    const body = String(bodyRaw).trim();
    // 已有 $...$ / $$...$$ 的内容原样交给行内解析；否则当作行间公式
    const hasDelims = /^\$\$[\s\S]*\$\$$/.test(body) || /^\$[^$][\s\S]*\$$/.test(body);
    const inner = inline(hasDelims ? body : `$$${body}$$`, ctx);
    const lab = label && label.trim() ? `<span class="eq-label">${inline(label.trim(), ctx)}</span>` : '';
    out.push(`<div class="eq-card">${lab}${inner}</div>`);
  };

  const emitImage = (src, alt, width) => {
    const raw = width ? String(width).replace(/[^0-9.%]/g, '') : '';
    // 支持 0.2（linewidth 比例）、20%、320px
    let style = '';
    if (/^\d*\.?\d+%$/.test(raw)) style = ` style="width:${parseFloat(raw)}%"`;
    else if (/^\d*\.?\d+$/.test(raw)) {
      const n = parseFloat(raw);
      style = n <= 1 ? ` style="width:${Math.round(n * 100)}%"` : ` style="width:${n}px"`;
    }
    const cap = alt && alt.trim() && !/^\d+(\.\d+)?%?$/.test(alt.trim()) ? `<figcaption>${inline(alt.trim(), ctx)}</figcaption>` : '';
    out.push(`<figure class="post-figure"${style}>${ctx.image(src, alt || '', raw)}${cap}</figure>`);
  };

  /* ---------------------------- 主循环 ---------------------------- */
  while (i < lines.length) {
    let line = lines[i];
    // 指令行允许有缩进（QD 里常用缩进表示层级）
    const tl = line.replace(/^[ \t]+/, '');

    /* 代码围栏 */
    const fence = line.match(/^\s*```(\S*)\s*$/);
    if (fence) {
      if (!inCode) {
        flushParagraph();
        inCode = true; codeLang = fence[1] || ''; codeLines = [];
      } else {
        inCode = false;
        stats.codeBlocks++;
        const body = escapeHtml(codeLines.join('\n'));
        const head = `<div class="code-head"><span>${escapeHtml(codeLang || 'text')}</span>` +
          `<button class="code-copy" type="button">复制</button></div>`;
        out.push(`<div class="code-panel">${head}<pre><code>${body}</code></pre></div>`);
      }
      i++; continue;
    }
    if (inCode) { codeLines.push(line); i++; continue; }

    /* 空行：结束段落，但不关闭容器（Quarkdown 用缩进表达层级） */
    if (/^\s*$/.test(line)) { flushParagraph(); i++; continue; }

    /* 预先抽出的行间公式占位符：独占一行，直接输出 */
    const bp = line.match(/^\s*\u0004B(\d+)\u0004\s*$/);
    if (bp) {
      flushParagraph();
      out.push(blockStore[Number(bp[1])] || '');
      i++; continue;
    }
    /* 行间公式与正文同行（QD 里式子后面直接跟说明文字） */
    if (/\u0004B\d+\u0004/.test(line)) {
      flushParagraph();
      line.split(/(\u0004B\d+\u0004)/).forEach((seg) => {
        const mm = seg.match(/^\u0004B(\d+)\u0004$/);
        if (mm) out.push(blockStore[Number(mm[1])] || '');
        else if (seg.trim()) out.push(`<p>${inline(seg.trim(), ctx)}</p>`);
      });
      i++; continue;
    }
    /* 预先抽出的 .eq 公式卡 */
    const ep = line.match(/^\s*\u0004E(\d+)\u0004\s*$/);
    if (ep) {
      flushParagraph();
      out.push(blockStore[Number(ep[1])] || '');
      i++; continue;
    }
    /* 公式卡与正文同行的情况：只处理当前行，不要把后面的行一起吞掉 */
    if (/\u0004E\d+\u0004/.test(line)) {
      flushParagraph();
      const m2 = line.match(/^\s*([^\n]*?)\s*\u0004E(\d+)\u0004\s*([^\n]*?)\s*$/);
      if (m2) {
        if (m2[1]) out.push(`<p>${inline(m2[1], ctx)}</p>`);
        out.push(blockStore[Number(m2[2])] || '');
        if (m2[3]) out.push(`<p>${inline(m2[3], ctx)}</p>`);
        i++; continue;
      }
    }

    /* 标题 */
    const h = line.match(/^(#{1,6})\s*(.+?)\s*#*\s*$/);
    if (h) {
      flushParagraph();
      const lvl = h[1].length;
      const txt = h[2].replace(/^#+\s*/, '');
      // '#! 标题' / '####! 标题' 这些 Quarkdown 变体：! 表示居中/强调，直接忽略
      pushHeading(Math.min(Math.max(lvl, 1), 4), txt.replace(/^!\s*/, ''));
      i++; continue;
    }

    /* 分隔线 */
    if (/^\s*([-*_])\s*\1\s*\1[\s\1]*$/.test(line)) { flushParagraph(); out.push('<hr/>'); i++; continue; }

    /* LaTeX 转换器留下的居中块标记 */
    if (/^\s*@@center@@\s*$/.test(line)) {
      flushParagraph();
      if (openStack.length && openStack[openStack.length - 1].kind === 'center') {
        out.push(openStack.pop().close);
      } else {
        out.push('<div class="center">');
        openStack.push({ kind: 'center', close: '</div>' });
      }
      i++; continue;
    }
    if (/^\s*@@endcenter@@\s*$/.test(line)) {
      flushParagraph();
      if (openStack.length && openStack[openStack.length - 1].kind === 'center') out.push(openStack.pop().close);
      i++; continue;
    }

    /* QD：目录 / 分页 */
    if (QD_TOC.test(tl)) { flushParagraph(); out.push('<!--TOC-->'); i++; continue; }
    if (QD_PAGEBREAK.test(tl)) { flushParagraph(); out.push('<hr class="page-break"/>'); i++; continue; }

    /* QD：容器开合（靠缩进判断） */
    if (QD_CENTER.test(tl)) { flushParagraph(); out.push('<div class="center">'); openStack.push({ kind: 'center', close: '</div>' }); i++; continue; }
    if (QD_ROW.test(tl)) { flushParagraph(); out.push('<div class="img-row">'); openStack.push({ kind: 'row', close: '</div>' }); i++; continue; }
    if (QD_COLUMN.test(tl)) { flushParagraph(); out.push('<div class="column">'); openStack.push({ kind: 'column', close: '</div>' }); i++; continue; }

    /* QD：.box 提示框（标题与 type:{...} 的先后、有无都要兼容） */
    const box = tl.match(QD_BOX);
    if (box) {
      flushParagraph();
      let rest = box[1] || '';
      let title = '', type = 'note';
      for (let guard = 0; guard < 4; guard++) {
        const m = rest.match(/^\s*(?:\{([^}]*)\}|type:\{([^}]*)\})/);
        if (!m) break;
        if (m[1] !== undefined) { if (m[1].trim()) title = m[1]; }
        else type = m[2];
        rest = rest.slice(m[0].length);
      }
      // .box{ } type:{tip} 后面紧跟的正文（有时同一行，有时下一行）
      const wrapped = rest.match(/^\s*([\s\S]*?)\s*$/);
      const tail = wrapped ? wrapped[1] : '';
      if (tail && !/^type:/.test(tail)) {
        title = title ? title : tail;
      } else {
        // 同一行没内容时，取下一行的正文当默认标题（Quarkdown 的常见写法）
        const next = lines[i + 1] || '';
        const pm = next.match(/^\s*(?:\d+[.)]\s+)?([^*|>#\-+.]{2,24})\s*$/);
        if (pm && !/^\s*\./.test(next)) { title = pm[1].trim(); i++; }
      }
      openCallout(title, type);
      i++; continue;
    }

    /* QD：.eq 公式卡（花括号可跨行，内容里带 LaTeX 花括号也能配对） */
    if (/^\.eq\b/.test(tl)) {
      flushParagraph();
      let buf = tl;
      let guard = 0;
      for (;;) {
        const first = findBrace(buf, 0);
        if (first) {
          const second = findBrace(buf, first.next);
          if (second) {
            // 花括号里可能既包着公式又混着参数写法，只取第一段完整公式；没有则整段当公式
            const inner = second.content;
            const mm = inner.match(/\$[^$]+\$/);
            const body = mm ? mm[0] : inner;
            emitEq(first.content, body);
            i++;
            break;
          }
        }
        i++;
        if (i >= lines.length || guard++ > 400) { i++; break; }
        buf += '\n' + lines[i];
      }
      continue;
    }

    /* QD：图片变体 */
    const qdImg = tl.match(QD_IMAGE);
    if (qdImg) {
      flushParagraph();
      emitImage(qdImg[3], qdImg[2], qdImg[1]);
      i++; continue;
    }
    if (QD_IMAGE2.test(tl)) {
      const m2 = tl.match(QD_IMAGE2);
      flushParagraph();
      emitImage(m2[2], m2[1], '');
      i++; continue;
    }
    if (QD_IMAGE3.test(tl)) {
      const m3 = tl.match(QD_IMAGE3);
      flushParagraph();
      const extra = m3[2] || '';
      const wm = extra.match(/width:\{?([^}]*)\}?/) || (line.match(/width:\{?([^}]*)\}?/) || []);
      const lm = extra.match(/label:\{([^}]*)\}/);
      emitImage(m3[1].trim(), lm ? lm[1] : '', wm[1] || '');
      i++; continue;
    }

    /* QD：元数据指令 —— 跳过（同时跳过其缩进参数行与紧随的列表块） */
    if (QD_FUNC.test(tl)) {
      i++;
      while (i < lines.length && (QD_PARAMS.test(lines[i]) || /^\s+\S/.test(lines[i]) || /^\t/.test(lines[i]) || /^\s*$/.test(lines[i]))) {
        // 空行后面若不再紧跟缩进内容，则停止吞并
        if (/^\s*$/.test(lines[i])) {
          const nxt = lines[i + 1] || '';
          if (!/^\s+\S/.test(nxt)) break;
        }
        i++;
      }
      continue;
    }

    /* QD：参数行残留 */
    if (QD_PARAMS.test(tl)) { i++; continue; }

    /* QD：.docauthors 的列表项 '-' 已在列表规则里处理；.function 体里的裸文本忽略 */

    /* 单独占一行的 $$ / $$$ 围栏：成对时按行间公式处理（QD 教程里的空 $$$） */
    if (/^\s*\$\$\$?\s*$/.test(line)) {
      const fence = line.trim();
      let close = -1;
      for (let j = i + 1; j < lines.length; j++) {
        if (lines[j].trim() === fence) { close = j; break; }
      }
      if (close > i) {
        flushParagraph();
        const inner = lines.slice(i + 1, close).join('\n').trim();
        if (inner) {
          out.push(`<span class="math-block">${escapeHtml(inner.replace(/^\s*\{#([^}]+)\}\s*/, ''))}</span>`);
        }
        i = close + 1;
        continue;
      }
      flushParagraph();
      i++;
      continue;
    }

    /* 表格 */
    if (line.includes('|') && lines[i + 1] && /^\s*\|?[\s:|-]+\|?\s*$/.test(lines[i + 1]) && lines[i + 1].includes('-')) {
      flushParagraph();
      const rows = [line];
      let j = i + 1;
      while (j < lines.length && lines[j].includes('|') && !/^\s*$/.test(lines[j])) { rows.push(lines[j]); j++; }
      const html = renderTable(rows, ctx);
      if (html) { out.push(html); i = j; continue; }
    }

    /* 引用 */
    const bq = line.match(/^>\s?(.*)$/);
    if (bq) {
      flushParagraph();
      const buf = [bq[1]];
      let j = i + 1;
      while (j < lines.length && /^>\s?/.test(lines[j])) { buf.push(lines[j].replace(/^>\s?/, '')); j++; }
      out.push(`<blockquote><p>${inline(buf.join('\n'), ctx)}</p></blockquote>`);
      i = j; continue;
    }

    /* 列表 */
    if (listItemIndent(line) >= 0) {
      flushParagraph();
      const flat = [];
      let j = i;
      while (j < lines.length) {
        const cur = lines[j];
        const ind = listItemIndent(cur);
        if (ind < 0) {
          // 列表项内部的续行：缩进且不是新的列表项
          if (/^\s+\S/.test(cur) && flat.length && listItemIndent(cur) < 0) {
            const prev = flat[flat.length - 1];
            prev.text += '\n' + cur.trim();
            j++; continue;
          }
          break;
        }
        const m = cur.match(/^(\s*)(?:([-*+])|(\d+)[.)])\s+(.*)$/);
        flat.push({
          indent: m[1].replace(/\t/g, '    ').length,
          ordered: Boolean(m[3]),
          start: m[3] ? Number(m[3]) : 1,
          text: m[4]
        });
        j++;
      }
      // 构造树
      const roots = [];
      const stack = [];
      flat.forEach((it) => {
        const node = Object.assign({ children: [] }, it);
        while (stack.length && stack[stack.length - 1].indent >= it.indent) stack.pop();
        if (!stack.length) roots.push(node);
        else stack[stack.length - 1].children.push(node);
        stack.push(node);
      });
      if (roots.length) out.push(renderList(roots, ctx));
      i = j; continue;
    }

    /* 普通段落 */
    paragraph.push(dedent(line));
    i++;
  }

  if (inCode && codeLines.length) {
    out.push(`<pre><code>${escapeHtml(codeLines.join('\n'))}</code></pre>`);
  }
  flushParagraph();
  closeContainersTo(0);

  // 还原预先抽出的行间公式与公式卡
  let html = out.join('\n');
  if (blockStore.length) {
    html = html.replace(/\u0004[BE](\d+)\u0004/g, (m, n) => blockStore[Number(n)] || '');
  }
  // 清掉只有占位符的空段落
  html = html.replace(/<p>\s*<\/p>/g, '');
  html = tidyMathHtml(html);
  // 兜底：万一还有没还原的内部占位符，就地清掉，避免泄漏到页面
  html = html.replace(/\u0000\d*\u0000?/g, '').replace(/[\u0001-\u0006]/g, '');

  // 统计公式
  stats.mathBlock = (html.match(/class="math-block"/g) || []).length;
  stats.mathInline = (html.match(/class="math-inline"/g) || []).length;

  return { html, headings, stats };
}

module.exports = { convert, inline, escapeHtml, escapeAttr, protectMath, readBraced, findBrace };
