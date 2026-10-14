/* =============================================================================
 * mathtext.js —— 迷你公式排版器
 * 把类 LaTeX 的记号转成 DOM（分式/上下标/根号/积分/矩阵），
 * 不依赖 KaTeX/MathJax，纯离线可用，且可被栅格化为画布纹理。
 * ========================================================================== */
import { rasterize } from './primitives.js';

import { esc } from './utils.js';

const SYM = {
  alpha: 'α', beta: 'β', gamma: 'γ', delta: 'δ', epsilon: 'ε', varepsilon: 'ε',
  zeta: 'ζ', eta: 'η', theta: 'θ', iota: 'ι', kappa: 'κ', lambda: 'λ', mu: 'μ',
  nu: 'ν', xi: 'ξ', pi: 'π', rho: 'ρ', sigma: 'σ', tau: 'τ', upsilon: 'υ',
  phi: 'φ', varphi: 'φ', chi: 'χ', psi: 'ψ', omega: 'ω',
  Gamma: 'Γ', Delta: 'Δ', Theta: 'Θ', Lambda: 'Λ', Xi: 'Ξ', Pi: 'Π',
  Sigma: 'Σ', Upsilon: 'Υ', Phi: 'Φ', Psi: 'Ψ', Omega: 'Ω',
  infty: '∞', partial: '∂', nabla: '∇', hbar: 'ℏ', ell: 'ℓ', propto: '∝',
  cdot: '·', cdots: '⋯', ldots: '…', times: '×', div: '÷', pm: '±', mp: '∓',
  leq: '≤', geq: '≥', neq: '≠', approx: '≈', equiv: '≡', sim: '∼',
  to: '→', rightarrow: '→', leftarrow: '←', Rightarrow: '⇒', Leftrightarrow: '⇔',
  mapsto: '↦', in: '∈', notin: '∉', subset: '⊂', forall: '∀', exists: '∃',
  empty: '∅', and: '∧', or: '∨', neg: '¬', angle: '∠', perp: '⊥',
  mathbb: '', mathcal: '',
};

/** 迷你 LaTeX 子集 -> HTML */
export function renderMath(src) {
  return parse(src.trim());
}

function parse(s) {
  let i = 0;
  const peek = () => s[i];
  const eat = () => s[i++];

  function group() {
    // 读取一个「原子」：{...} / \cmd / 单字符
    skipSpace();
    if (peek() === '{') {
      let depth = 0;
      const start = i;
      do {
        if (s[i] === '{') depth++;
        else if (s[i] === '}') depth--;
        i++;
      } while (i < s.length && depth > 0);
      return parse(s.slice(start + 1, i - 1));
    }
    if (peek() === '\\') return command();
    const ch = eat();
    return atom(ch);
  }

  function mandatoryArgs(n) {
    const out = [];
    for (let k = 0; k < n; k++) out.push(group());
    return out;
  }

  function command() {
    eat(); // backslash
    let name = '';
    if (/[a-zA-Z]/.test(peek() || '')) {
      while (i < s.length && /[a-zA-Z]/.test(s[i])) name += eat();
    } else {
      name = eat(); // \, \; \{ \} 等
    }
    switch (name) {
      case 'frac':
      case 'dfrac':
      case 'tfrac': {
        const [a, b] = mandatoryArgs(2);
        return `<span class="m-frac"><span class="m-num">${a}</span><span class="m-den">${b}</span></span>`;
      }
      case 'sqrt': {
        const [a] = mandatoryArgs(1);
        return `<span class="m-sqrt"><span class="m-radic">√</span><span class="m-rad">${a}</span></span>`;
      }
      case 'text':
      case 'mathrm': {
        const [a] = mandatoryArgs(1);
        return `<span class="m-text">${a}</span>`;
      }
      case 'vec': {
        const [a] = mandatoryArgs(1);
        return `<span class="m-vec">${a}</span>`;
      }
      case 'hat': {
        const [a] = mandatoryArgs(1);
        return `<span class="m-hat">${a}</span>`;
      }
      case 'dot': {
        const [a] = mandatoryArgs(1);
        return `<span class="m-dot">${a}</span>`;
      }
      case 'left':
      case 'right': {
        const ch = eat();
        return `<span class="m-delim">${esc(ch === '.' ? '' : ch)}</span>`;
      }
      case 'int':
        return `<span class="m-op m-int">∫</span>`;
      case 'iint':
        return `<span class="m-op m-int">∬</span>`;
      case 'oint':
        return `<span class="m-op m-int">∮</span>`;
      case 'sum':
        return `<span class="m-op m-big">∑</span>`;
      case 'prod':
        return `<span class="m-op m-big">∏</span>`;
      case 'lim':
        return `<span class="m-op">lim</span>`;
      case 'sin': case 'cos': case 'tan': case 'log': case 'ln': case 'exp':
      case 'max': case 'min': case 'det': case 'dim':
        return `<span class="m-text m-fn">${name}</span>`;
      case ',':
      case ';':
      case ' ':
        return '<span class="m-thin"></span>';
      case 'operatorname': {
        const [a] = mandatoryArgs(1);
        return `<span class="m-text m-fn">${a}</span>`;
      }
      default:
        if (SYM[name] !== undefined) return `<span class="m-sym">${SYM[name]}</span>`;
        return `<span class="m-text">${esc(name)}</span>`;
    }
  }

  function atom(ch) {
    if (ch === '^') return `<span class="m-sup">${group()}</span>`;
    if (ch === '_') return `<span class="m-sub">${group()}</span>`;
    if (ch === '{' || ch === '}') return '';
    if (/[0-9.]/.test(ch)) {
      let num = ch;
      while (i < s.length && /[0-9.]/.test(s[i])) num += eat();
      return `<span class="m-num-lit">${num}</span>`;
    }
    if (/[a-zA-Z]/.test(ch)) return `<i class="m-var">${ch}</i>`;
    if (ch === ' ') return '';
    return `<span class="m-punct">${esc(ch)}</span>`;
  }

  function skipSpace() {
    while (i < s.length && s[i] === ' ') i++;
  }

  // 顶层扫描：把原子 + 紧邻的 ^/_ 合并成 m-base
  let html = '';
  while (i < s.length) {
    const before = i;
    let base;
    if (peek() === '^' || peek() === '_') {
      base = '<span class="m-var"></span>';
    } else {
      base = group();
    }
    let sup = '';
    let sub = '';
    // eslint-disable-next-line no-constant-condition
    while (true) {
      skipSpace();
      if (peek() === '^') {
        eat();
        sup += group();
      } else if (peek() === '_') {
        eat();
        sub += group();
      } else break;
    }
    if (!sup && !sub) {
      html += base;
    } else {
      html += `<span class="m-base">${base}${sub ? `<span class="m-sub">${sub}</span>` : ''}${
        sup ? `<span class="m-sup">${sup}</span>` : ''
      }</span>`;
    }
    if (i === before) i++; // 安全阀
  }
  return html;
}

/** 生成一个公式 DOM 节点 */
export function mathNode(tex, className = 'm') {
  const span = document.createElement('span');
  span.className = `math ${className}`;
  span.innerHTML = renderMath(tex);
  span.dataset.tex = tex;
  return span;
}

/** 批量把 [data-tex] 元素就地排版（保留容器自身的类名） */
export function typeset(root = document) {
  const list = root.querySelectorAll('[data-tex]');
  for (const el of list) {
    if (el.dataset.typeset === '1') continue;
    el.innerHTML = renderMath(el.dataset.tex);
    // 必须补上 .math 作用域，否则所有上下标/分式规则都不会匹配
    el.classList.add('math');
    el.dataset.typeset = '1';
  }
  return list.length;
}

/**
 * 公式的纯文本形式：当 SVG 栅格化不可用时的兜底显示
 */
export const PLAIN_FORMULAS = {
  'a^{2}+b^{2}=c^{2}': 'a² + b² = c²',
  '\\frac{a}{b}=\\frac{c}{d}': 'a/b = c/d',
  '\\sqrt{2}\\notin\\mathbb{Q}': '√2 ∉ Q',
  '\\pi r^{2}': 'πr²',
  'x=\\frac{-b\\pm\\sqrt{b^{2}-4ac}}{2a}': 'x = (−b ± √(b²−4ac)) / 2a',
  '\\int_{0}^{1}x^{2}\\,dx=\\frac{1}{3}': '∫₀¹ x² dx = 1/3',
  'e^{i\\pi}+1=0': 'e^{iπ} + 1 = 0',
  '\\sum_{n=1}^{\\infty}\\frac{1}{n^{2}}=\\frac{\\pi^{2}}{6}': 'Σ 1/n² = π²/6',
  '\\nabla\\cdot\\vec{E}=\\frac{\\rho}{\\varepsilon_{0}}': '∇·E = ρ/ε₀',
  'F=G\\frac{m_{1}m_{2}}{r^{2}}': 'F = G·m₁m₂/r²',
  'E=mc^{2}': 'E = mc²',
  'R_{\\mu\\nu}-\\frac{1}{2}Rg_{\\mu\\nu}=\\frac{8\\pi G}{c^{4}}T_{\\mu\\nu}': 'Rμν − ½Rgμν = 8πG/c⁴ · Tμν',
  'i\\hbar\\frac{\\partial}{\\partial t}\\Psi=\\hat{H}\\Psi': 'iℏ ∂Ψ/∂t = ĤΨ',
  '\\Delta x\\,\\Delta p\\geq\\frac{\\hbar}{2}': 'Δx·Δp ≥ ℏ/2',
  '\\oint_{C}\\vec{B}\\cdot d\\vec{l}=\\mu_{0}I': '∮ B·dl = μ₀I',
  '\\left[\\hat{x},\\hat{p}\\right]=i\\hbar': '[x̂, p̂] = iℏ',
};

/** 剥离 LaTeX 记号的粗糙兜底 */
const stripTex = (tex) =>
  tex
    .replace(/\\[a-zA-Z]+/g, ' ')
    .replace(/[{}$]/g, '')
    .replace(/\s+/g, ' ')
    .trim();

/**
 * 把公式元素烘焙为画布纹理（供开场漩涡使用）
 * 双通道：优先 SVG/foreignObject 栅格化（保留分式与根号），
 * 失败时退回纯文本绘制，保证演出不会缺元素。
 * 返回 Promise<{tex, canvas, fallback}>
 */
export function bakeFormulas(formulas, host, { fontSize = 30 } = {}) {
  return Promise.all(
    formulas.map(
      (tex) =>
        new Promise((resolve) => {
          const el = mathNode(tex, 'm m-bake');
          el.style.fontSize = `${fontSize}px`;
          el.dataset.plain = PLAIN_FORMULAS[tex] || stripTex(tex);
          host.appendChild(el);

          const finish = () => {
            const rect = el.getBoundingClientRect();
            const pad = 30;
            const raster = rasterize(el, Math.ceil(rect.width), Math.ceil(rect.height));
            const canvas = document.createElement('canvas');
            canvas.width = Math.ceil(rect.width) + pad * 2;
            canvas.height = Math.ceil(rect.height) + pad * 2;
            const ctx = canvas.getContext('2d');
            // 有些浏览器对 SVG-in-<img> 的外链字体有限制，因此用主画布可用的字体栈直接重绘
            const cs = getComputedStyle(el);
            const paintText = () => {
              ctx.clearRect(0, 0, canvas.width, canvas.height);
              ctx.font = `${cs.fontStyle} ${cs.fontWeight} ${cs.fontSize} ${cs.fontFamily}`;
              ctx.fillStyle = cs.color || '#dff1ff';
              ctx.textBaseline = 'middle';
              ctx.textAlign = 'center';
              ctx.globalAlpha = 1;
              const plain = el.dataset.plain || el.textContent || '';
              ctx.fillText(plain, canvas.width / 2, canvas.height / 2);
            };
            const paint = () => {
              ctx.clearRect(0, 0, canvas.width, canvas.height);
              ctx.globalCompositeOperation = 'source-over';
              ctx.filter = 'none';
              ctx.globalAlpha = 1;
              ctx.drawImage(raster, pad, pad);
              // 冷色光晕，使纹理在深色背景上「发光」
              ctx.globalCompositeOperation = 'lighter';
              ctx.filter = 'blur(5px)';
              ctx.globalAlpha = 0.6;
              ctx.drawImage(raster, pad, pad);
              ctx.filter = 'none';
              ctx.globalAlpha = 1;
              ctx.globalCompositeOperation = 'source-over';
            };
            const settle = () => {
              // 若栅格化失败（跨文档渲染受限），退回纯文本绘制，保证演出不缺元素
              const test = document.createElement('canvas');
              test.width = test.height = 8;
              const tc = test.getContext('2d');
              tc.drawImage(raster, 0, 0, 8, 8);
              const d = tc.getImageData(0, 0, 8, 8).data;
              let sum = 0;
              for (let i = 3; i < d.length; i += 4) sum += d[i];
              if (sum < 6) {
                paintText();
                canvas.__fallback = true;
              } else {
                paint();
              }
              el.remove();
              resolve({ tex, canvas, fallback: !!canvas.__fallback });
            };
            if (raster.__pending) {
              const timer = setInterval(() => {
                if (!raster.__pending) {
                  clearInterval(timer);
                  settle();
                }
              }, 40);
              setTimeout(() => {
                clearInterval(timer);
                settle();
              }, 2500);
            } else {
              settle();
            }
          };

          // 等字体与布局稳定
          if (document.fonts?.ready) document.fonts.ready.then(() => setTimeout(finish, 20));
          else setTimeout(finish, 60);
        })
    )
  );
}

