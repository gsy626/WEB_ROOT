/* =============================================================================
 * renderer.js —— 画布管理：DPR 适配、尺寸自适应、离屏缓冲、运动残影
 * ========================================================================== */
import { clamp } from './utils.js';

export class Renderer {
  /**
   * @param {HTMLCanvasElement} canvas
   * @param {{dprMax?:number, alphaFade?:number|null, onResize?:(w:number,h:number,ctx:CanvasRenderingContext2D)=>void}} opts
   */
  constructor(canvas, opts = {}) {
    this.canvas = canvas;
    this.ctx = canvas.getContext('2d', { alpha: true, desynchronized: true });
    this.dprMax = opts.dprMax ?? 1.85;
    this.alphaFade = opts.alphaFade ?? null; // 非 null 时用半透明填充代替清屏（拖尾）
    this.onResize = opts.onResize;
    this.w = 0;
    this.h = 0;
    this.cx = 0;
    this.cy = 0;
    this.min = 0;
    this.max = 0;
    this.buffers = new Map();
    this._ro = new ResizeObserver(() => this.resize());
    this._ro.observe(canvas);
    window.addEventListener('orientationchange', () => setTimeout(() => this.resize(), 120));
    this.resize();
  }

  get dpr() {
    return Math.min(window.devicePixelRatio || 1, this.dprMax);
  }

  resize() {
    const rect = this.canvas.getBoundingClientRect();
    const w = Math.max(1, Math.round(rect.width || window.innerWidth));
    const h = Math.max(1, Math.round(rect.height || window.innerHeight));
    const dpr = this.dpr;
    this.w = w;
    this.h = h;
    this.cx = w * 0.5;
    this.cy = h * 0.5;
    this.min = Math.min(w, h);
    this.max = Math.max(w, h);
    this.scale = clamp(this.min / 900, 0.55, 1.5);
    this.dprReal = dpr;
    this.canvas.width = Math.round(w * dpr);
    this.canvas.height = Math.round(h * dpr);
    this.ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    // 离屏缓冲随主画布一起重建
    for (const [key, buf] of this.buffers) {
      const ratio = buf.ratio ?? 1;
      buf.canvas.width = Math.max(1, Math.round(w * dpr * ratio));
      buf.canvas.height = Math.max(1, Math.round(h * dpr * ratio));
      buf.ctx.setTransform(dpr * ratio, 0, 0, dpr * ratio, 0, 0);
    }
    this.onResize?.(w, h, this.ctx);
  }

  /** 获取（或创建）一张与视口同尺寸的离屏画布，用于后处理 */
  buffer(name, { ratio = 1, filter = 'none' } = {}) {
    let buf = this.buffers.get(name);
    if (!buf) {
      const c = document.createElement('canvas');
      const ctx = c.getContext('2d');
      buf = { canvas: c, ctx, ratio, filter };
      this.buffers.set(name, buf);
      const dpr = this.dprReal ?? this.dpr;
      c.width = Math.max(1, Math.round(this.w * dpr * ratio));
      c.height = Math.max(1, Math.round(this.h * dpr * ratio));
      ctx.setTransform(dpr * ratio, 0, 0, dpr * ratio, 0, 0);
      ctx.filter = filter;
    }
    return buf;
  }

  clear(color) {
    const { ctx } = this;
    ctx.setTransform(this.dprReal, 0, 0, this.dprReal, 0, 0);
    ctx.globalCompositeOperation = 'source-over';
    ctx.globalAlpha = 1;
    if (color) {
      ctx.fillStyle = color;
      ctx.fillRect(0, 0, this.w, this.h);
    } else {
      ctx.clearRect(0, 0, this.w, this.h);
    }
  }

  /** 关键技巧：半透明填充 = 廉价且稳定的运动拖尾 */
  fadeTrail(alpha, color = '#03060f') {
    const { ctx } = this;
    ctx.setTransform(this.dprReal, 0, 0, this.dprReal, 0, 0);
    ctx.globalCompositeOperation = 'source-over';
    ctx.globalAlpha = 1;
    ctx.fillStyle = color;
    ctx.globalAlpha = clamp(alpha, 0, 1);
    ctx.fillRect(0, 0, this.w, this.h);
    ctx.globalAlpha = 1;
  }

  reset() {
    const { ctx } = this;
    ctx.setTransform(this.dprReal, 0, 0, this.dprReal, 0, 0);
    ctx.globalAlpha = 1;
    ctx.globalCompositeOperation = 'source-over';
    ctx.shadowBlur = 0;
    ctx.lineWidth = 1;
    return ctx;
  }

  destroy() {
    this._ro.disconnect();
    this.buffers.clear();
  }
}
