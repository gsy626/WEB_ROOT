/* =============================================================================
 * primitives.js —— 预渲染精灵（离屏画布）
 * 把渐变/光晕/公式文字一次性烘焙成纹理，主循环里只做 drawImage，
 * 这是「高帧率 + 电影质感」的关键。
 * ========================================================================== */
import { withAlpha } from './utils.js';

/** 环形调色：由内向外的能量色阶 */
export const RING_STOPS = ['#ffffff', '#a5e8ff', '#22d3ee', '#4f7dfb', '#8b5cf6', '#e879f9'];

/** 径向光晕精灵（黑洞吸积 / 星云团块 / 星点） */
export function radialGlow(size, stops, { core = 0 } = {}) {
  const s = Math.max(2, Math.round(size));
  const c = document.createElement('canvas');
  c.width = c.height = s;
  const ctx = c.getContext('2d');
  const r = s / 2;
  const g = ctx.createRadialGradient(r, r, r * core, r, r, r);
  for (const [offset, color] of stops) g.addColorStop(offset, color);
  ctx.fillStyle = g;
  ctx.beginPath();
  ctx.arc(r, r, r, 0, Math.PI * 2);
  ctx.fill();
  return c;
}

/** 环状渐变精灵：用于时空隧道的深度环 / 引力透镜弧 */
export function ringSprite(size, { inner = 0.62, color = '#22d3ee', alpha = 0.9, width = 0.06, blur = 0.1 } = {}) {
  const s = Math.max(4, Math.round(size));
  const c = document.createElement('canvas');
  c.width = c.height = s;
  const ctx = c.getContext('2d');
  const r = s / 2;
  const g = ctx.createRadialGradient(r, r, r * inner, r, r, r * (inner + width + blur));
  g.addColorStop(0, 'rgba(0,0,0,0)');
  g.addColorStop(Math.max(0, (width * 0.5) / (width + blur)), withAlpha(color, alpha));
  g.addColorStop(1, 'rgba(0,0,0,0)');
  ctx.fillStyle = g;
  ctx.beginPath();
  ctx.arc(r, r, r, 0, Math.PI * 2);
  ctx.fill();
  return c;
}

/** 4 角星芒（高光、奇点闪烁） */
export function starFlare(size, color = '#ffffff') {
  const s = Math.max(8, Math.round(size));
  const c = document.createElement('canvas');
  c.width = c.height = s;
  const ctx = c.getContext('2d');
  const r = s / 2;
  ctx.globalCompositeOperation = 'lighter';
  const g = ctx.createRadialGradient(r, r, 0, r, r, r);
  g.addColorStop(0, withAlpha(color, 1));
  g.addColorStop(0.18, withAlpha(color, 0.5));
  g.addColorStop(0.55, withAlpha(color, 0.08));
  g.addColorStop(1, 'rgba(0,0,0,0)');
  ctx.fillStyle = g;
  ctx.fillRect(0, 0, s, s);
  // 十字光条
  ctx.translate(r, r);
  for (let i = 0; i < 4; i++) {
    ctx.save();
    ctx.rotate((Math.PI / 2) * i);
    const lg = ctx.createLinearGradient(-r, 0, r, 0);
    lg.addColorStop(0, 'rgba(0,0,0,0)');
    lg.addColorStop(0.5, withAlpha(color, 0.55));
    lg.addColorStop(1, 'rgba(0,0,0,0)');
    ctx.fillStyle = lg;
    ctx.fillRect(-r, -s * 0.012, s, s * 0.024);
    ctx.restore();
  }
  return c;
}

/**
 * 把节点渲染为纹理（公式 / 年份 / 标签）
 * @param {HTMLElement} node
 * @param {{padding?:number, scale?:number, force?:boolean}} opts
 */
export function textSprite(node, { padding = 26, scale = 1, glow = true, glowBlur = 8 } = {}) {
  const rect = node.getBoundingClientRect();
  const w = Math.ceil(rect.width * scale) + padding * 2;
  const h = Math.ceil(rect.height * scale) + padding * 2;
  const canvas = document.createElement('canvas');
  canvas.width = Math.max(2, w);
  canvas.height = Math.max(2, h);
  const ctx = canvas.getContext('2d');
  const raster = rasterize(node, Math.ceil(rect.width), Math.ceil(rect.height));

  const paint = () => {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.save();
    ctx.translate(padding, padding);
    ctx.scale(scale, scale);
    ctx.drawImage(raster, 0, 0);
    if (glow) {
      ctx.globalCompositeOperation = 'lighter';
      ctx.filter = `blur(${glowBlur}px)`;
      ctx.globalAlpha = 0.7;
      ctx.drawImage(raster, 0, 0);
      ctx.filter = 'none';
      ctx.globalAlpha = 1;
      ctx.globalCompositeOperation = 'source-over';
    }
    ctx.restore();
  };
  paint();
  canvas.__pending = true;
  const watch = setInterval(() => {
    if (!raster.__pending) {
      clearInterval(watch);
      paint();
      canvas.__pending = false;
    }
  }, 40);
  setTimeout(() => clearInterval(watch), 2500);
  return canvas;
}

/**
 * 用 foreignObject 把任意 HTML（含 MathML / 分式 / 根号）栅格化。
 * 内联样式必须全部写死，因为 SVG 是独立文档，不继承页面 CSS。
 */
export function rasterize(node, w, h, { background = 'transparent' } = {}) {
  const clone = node.cloneNode(true);
  inlineStyles(node, clone);
  const html = `
    <div xmlns="http://www.w3.org/1999/xhtml" style="
      width:${w}px;height:${h}px;display:flex;align-items:center;justify-content:center;
      background:${background};">
      ${new XMLSerializer().serializeToString(clone)}
    </div>`;
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}">
    <foreignObject width="100%" height="100%">${html}</foreignObject>
  </svg>`;
  const img = new Image();
  const url = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
  img.src = url;
  // 同步接口：多数浏览器对 data URI 的 SVG 需要异步解码，
  // 因此此处返回一个「稍后绘制」的占位，由 drawSpriteAsync 补齐。
  const canvas = document.createElement('canvas');
  canvas.width = Math.max(2, Math.round(w));
  canvas.height = Math.max(2, Math.round(h));
  const ctx = canvas.getContext('2d');
  canvas.__pending = true;
  img.onload = () => {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
    canvas.__pending = false;
    canvas.__image = img;
  };
  img.onerror = () => {
    canvas.__pending = false;
    canvas.__error = true;
  };
  return canvas;
}

/** 递归把计算样式写成内联样式（只保留影响排版/色彩的属性） */
const STYLE_KEYS = [
  'font-family', 'font-size', 'font-weight', 'font-style', 'line-height',
  'letter-spacing', 'color', 'text-shadow', 'background', 'background-color',
  'background-image', 'border', 'border-radius', 'padding', 'margin',
  'display', 'flex-direction', 'align-items', 'justify-content', 'gap',
  'width', 'height', 'opacity', 'text-align', 'vertical-align',
  'box-shadow', 'filter', 'transform', 'white-space', 'border-top',
  'border-bottom', 'min-width', 'min-height', 'position', 'top', 'left',
];
function inlineStyles(src, dst) {
  const cs = getComputedStyle(src);
  let css = '';
  for (const k of STYLE_KEYS) {
    const v = cs.getPropertyValue(k);
    if (v && v !== 'none' && v !== 'normal' && v !== 'auto' && v !== 'rgba(0, 0, 0, 0)') {
      css += `${k}:${v};`;
    }
  }
  if (css) dst.setAttribute('style', css);
  const sChildren = src.children;
  const dChildren = dst.children;
  for (let i = 0; i < sChildren.length && i < dChildren.length; i++) {
    inlineStyles(sChildren[i], dChildren[i]);
  }
}
