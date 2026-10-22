/* =============================================================================
 * utils.js —— 纯函数工具集（无副作用、无 DOM 依赖）
 * ========================================================================== */
export const TAU = Math.PI * 2;

export const clamp = (v, a = 0, b = 1) => (v < a ? a : v > b ? b : v);
export const lerp = (a, b, t) => a + (b - a) * t;
export const invLerp = (a, b, v) => (b === a ? 0 : (v - a) / (b - a));
export const smoothstep = (t) => {
  t = clamp(t);
  return t * t * (3 - 2 * t);
};
export const smootherstep = (t) => {
  t = clamp(t);
  return t * t * t * (t * (t * 6 - 15) + 10);
};
export const mix = lerp;

export const easeOutCubic = (t) => 1 - Math.pow(1 - clamp(t), 3);
export const easeOutQuint = (t) => 1 - Math.pow(1 - clamp(t), 5);
export const easeInCubic = (t) => Math.pow(clamp(t), 3);
export const easeInOutCubic = (t) =>
  t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
export const easeOutElastic = (t) => {
  t = clamp(t);
  if (t === 0 || t === 1) return t;
  const c4 = TAU / 3;
  return Math.pow(2, -10 * t) * Math.sin((t * 10 - 0.75) * c4) + 1;
};

/** 确定性随机（mulberry32），保证每次加载形态一致 */
export function makeRng(seed = 20240617) {
  let a = seed >>> 0;
  return function rng() {
    a = (a + 0x6d2b79f5) >>> 0;
    let t = a;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

/** 环形包裹 */
export function wrap(v, min, max) {
  const span = max - min;
  return ((((v - min) % span) + span) % span) + min;
}

/** '#rrggbb' + alpha -> 'rgba(...)' */
export function withAlpha(hex, alpha) {
  const h = hex.replace('#', '');
  const n = parseInt(h.length === 3 ? h.replace(/(.)/g, '$1$1') : h, 16);
  const r = (n >> 16) & 255;
  const g = (n >> 8) & 255;
  const b = n & 255;
  return `rgba(${r},${g},${b},${alpha})`;
}

const ESC_MAP = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };

/** HTML 转义：所有插入 innerHTML 的文本都必须过这一层 */
export const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ESC_MAP[c]);

/** 两色线性插值（hex -> hex） */
export function mixHex(a, b, t) {
  const pa = parseInt(a.replace('#', ''), 16);
  const pb = parseInt(b.replace('#', ''), 16);
  const r = Math.round(lerp((pa >> 16) & 255, (pb >> 16) & 255, clamp(t)));
  const g = Math.round(lerp((pa >> 8) & 255, (pb >> 8) & 255, clamp(t)));
  const bl = Math.round(lerp(pa & 255, pb & 255, clamp(t)));
  return `rgb(${r},${g},${bl})`;
}

/** 帧率无关的指数趋近 */
export function damp(current, target, rate, dt) {
  return lerp(current, target, 1 - Math.exp(-rate * dt));
}

export const prefersReducedMotion = () =>
  typeof matchMedia === 'function' &&
  matchMedia('(prefers-reduced-motion: reduce)').matches;

/** 设备性能粗判：决定粒子规模 */
export function perfTier() {
  const cores = navigator.hardwareConcurrency || 4;
  const mem = navigator.deviceMemory || 4;
  const small = Math.min(innerWidth, innerHeight) < 700;
  if (cores <= 4 || mem <= 4 || small) return 0.62;
  if (cores >= 12) return 1;
  return 0.85;
}
