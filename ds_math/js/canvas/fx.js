/* =============================================================================
 * fx.js —— 后处理：泛光(Bloom) / 色散(Aberration) / 胶片颗粒 / 扫描线 / 暗角
 * 思路：把主画布降采样到低分辨率缓冲再模糊，代价极小却能显著提升质感。
 * ========================================================================== */
import { clamp, makeRng } from '../core/utils.js';

export class FX {
  constructor(renderer, vortex) {
    this.r = renderer;
    this.vortex = vortex;
    this.quality = 1;      // 0 = 极简, 1 = 标准, 2 = 全效
    this.bloomGain = 1;    // 泛光强度倍率（调试 / 降级用）
    this.time = 0;
    this._lastGrain = 0;
    this._grainAcc = 0;
    this._buildGrain();
    this._buildScanlines();
  }

  setQuality(q) {
    this.quality = q;
  }

  _buildGrain() {
    const size = 180;
    const c = document.createElement('canvas');
    c.width = c.height = size;
    const ctx = c.getContext('2d');
    const img = ctx.createImageData(size, size);
    const rng = makeRng(31337);
    for (let i = 0; i < img.data.length; i += 4) {
      const v = 120 + rng() * 135;
      img.data[i] = v;
      img.data[i + 1] = v;
      img.data[i + 2] = v;
      img.data[i + 3] = rng() < 0.75 ? 18 + rng() * 26 : 0;
    }
    ctx.putImageData(img, 0, 0);
    this.grain = c;
  }

  _buildScanlines() {
    const c = document.createElement('canvas');
    c.width = 1;
    c.height = 4;
    const ctx = c.getContext('2d');
    const g = ctx.createLinearGradient(0, 0, 0, 4);
    g.addColorStop(0, 'rgba(0,0,0,0)');
    g.addColorStop(0.5, 'rgba(0,0,0,0.16)');
    g.addColorStop(1, 'rgba(0,0,0,0)');
    ctx.fillStyle = g;
    ctx.fillRect(0, 0, 1, 4);
    this.scan = c;
  }

  resize() {
    this._vignette = null; // 尺寸变化后重建
  }

  _vignetteSprite() {
    if (this._vignette) return this._vignette;
    const r = this.r;
    const c = document.createElement('canvas');
    c.width = Math.max(2, Math.round(r.w));
    c.height = Math.max(2, Math.round(r.h));
    const ctx = c.getContext('2d');
    const g = ctx.createRadialGradient(
      c.width / 2, c.height / 2, Math.min(c.width, c.height) * 0.18,
      c.width / 2, c.height / 2, Math.max(c.width, c.height) * 0.78
    );
    g.addColorStop(0, 'rgba(0,0,0,0)');
    g.addColorStop(0.55, 'rgba(2,4,12,0.22)');
    g.addColorStop(1, 'rgba(1,2,8,0.72)');
    ctx.fillStyle = g;
    ctx.fillRect(0, 0, c.width, c.height);
    // 顶部与底部的水平渐隐，模拟镜头遮光
    const top = ctx.createLinearGradient(0, 0, 0, c.height * 0.18);
    top.addColorStop(0, 'rgba(1,2,8,0.55)');
    top.addColorStop(1, 'rgba(0,0,0,0)');
    ctx.fillStyle = top;
    ctx.fillRect(0, 0, c.width, c.height * 0.18);
    const bot = ctx.createLinearGradient(0, c.height, 0, c.height * 0.72);
    bot.addColorStop(0, 'rgba(1,2,8,0.6)');
    bot.addColorStop(1, 'rgba(0,0,0,0)');
    ctx.fillStyle = bot;
    ctx.fillRect(0, c.height * 0.72, c.width, c.height * 0.28);
    this._vignette = c;
    return c;
  }

  compose(dt) {
    this.time += dt;
    const r = this.r;
    const v = this.vortex;
    const bright = v ? v.brightness : 1;
    if (this.quality >= 1 && bright > 0.05) {
      this._bloom();
      this._aberration();
    }
    if (this.quality >= 2) {
      this._scan();
      this._grain(dt);
    }
    this._vignettePass();
  }

  /** 泛光：降采样 -> 模糊 -> 叠加 */
  _bloom() {
    const r = this.r;
    const q = this.quality >= 2 ? 0.26 : 0.2;
    const small = r.buffer('fx-small', { ratio: q });
    if (!small || !r.canvas.width) return;
    // 主画布（物理像素）等比缩放进缓冲
    const k = small.canvas.width / r.canvas.width;
    const dw = small.canvas.width;
    const dh = r.canvas.height * k;
    const sctx = small.ctx;
    sctx.setTransform(1, 0, 0, 1, 0, 0);
    sctx.globalCompositeOperation = 'source-over';
    sctx.globalAlpha = 1;
    sctx.clearRect(0, 0, small.canvas.width, small.canvas.height);
    // contrast 抬高阈值：只有真正的高光才进入泛光，避免整体发灰
    sctx.filter = 'blur(5px) saturate(1.15) contrast(1.6) brightness(0.55)';
    sctx.drawImage(r.canvas, 0, 0, dw, dh);
    sctx.filter = 'none';

    const ctx = r.reset();
    ctx.globalCompositeOperation = 'lighter';
    ctx.globalAlpha = clamp(0.34 * this.bloomGain * (this.vortex?.brightness ?? 1), 0, 1);
    ctx.imageSmoothingEnabled = true;
    ctx.drawImage(small.canvas, 0, 0, r.w, r.h);
    ctx.globalAlpha = 1;
    ctx.globalCompositeOperation = 'source-over';
  }

  /** 色散：RGB 两次位移采样，营造镜头色差 */
  _aberration() {
    const r = this.r;
    if (!this.canvasCopy) return;
    const ctx = r.reset();
    const d = 1.35 + (this.vortex ? this.vortex.expansion * 5 : 0);
    ctx.globalCompositeOperation = 'lighter';
    ctx.save();
    ctx.globalAlpha = 0.075;
    ctx.translate(-d, 0);
    ctx.filter = 'sepia(1) saturate(6) hue-rotate(-50deg)';
    ctx.drawImage(this.canvasCopy, 0, 0, r.w, r.h);
    ctx.restore();
    ctx.save();
    ctx.globalAlpha = 0.06;
    ctx.translate(d * 0.7, -d * 0.4);
    ctx.filter = 'sepia(1) saturate(6) hue-rotate(150deg)';
    ctx.drawImage(this.canvasCopy, 0, 0, r.w, r.h);
    ctx.restore();
    ctx.filter = 'none';
    ctx.globalAlpha = 1;
    ctx.globalCompositeOperation = 'source-over';
  }

  /** 每帧开始前抓取一份画面副本，供色散 / 泛光采样，避免自采样拖影 */
  captureFrame() {
    if (this.quality < 1) return;
    const r = this.r;
    const q = 0.5;
    const buf = r.buffer('fx-copy', { ratio: q });
    if (!buf || !r.canvas.width) return;
    const k = buf.canvas.width / r.canvas.width;
    const bctx = buf.ctx;
    bctx.setTransform(1, 0, 0, 1, 0, 0);
    bctx.globalCompositeOperation = 'source-over';
    bctx.globalAlpha = 1;
    bctx.clearRect(0, 0, buf.canvas.width, buf.canvas.height);
    bctx.drawImage(r.canvas, 0, 0, buf.canvas.width, r.canvas.height * k);
    this.canvasCopy = buf.canvas;
  }

  _scan() {
    const r = this.r;
    const ctx = r.reset();
    ctx.globalCompositeOperation = 'source-over';
    ctx.globalAlpha = 0.5;
    const pattern = ctx.createPattern(this.scan, 'repeat');
    if (pattern) {
      ctx.fillStyle = pattern;
      ctx.fillRect(0, 0, r.w, r.h);
    }
    // 缓慢下移的高光带
    const y = ((this.time * 46) % (r.h + 400)) - 200;
    const g = ctx.createLinearGradient(0, y, 0, y + 220);
    g.addColorStop(0, 'rgba(160,220,255,0)');
    g.addColorStop(0.5, 'rgba(160,220,255,0.045)');
    g.addColorStop(1, 'rgba(160,220,255,0)');
    ctx.globalAlpha = 1;
    ctx.fillStyle = g;
    ctx.fillRect(0, y, r.w, 220);
    ctx.globalAlpha = 1;
  }

  _grain(dt) {
    this._grainAcc += dt;
    if (this._grainAcc < 1 / 18) return; // 颗粒无需满帧刷新
    this._grainAcc = 0;
    const r = this.r;
    const ctx = r.reset();
    ctx.globalCompositeOperation = 'overlay';
    const tile = this.grain;
    const ox = -(Math.random() * tile.width) | 0;
    const oy = -(Math.random() * tile.height) | 0;
    ctx.globalAlpha = 0.55;
    for (let x = ox; x < r.w; x += tile.width) {
      for (let y = oy; y < r.h; y += tile.height) {
        ctx.drawImage(tile, x, y);
      }
    }
    ctx.globalAlpha = 1;
    ctx.globalCompositeOperation = 'source-over';
  }

  _vignettePass() {
    const r = this.r;
    const ctx = r.reset();
    ctx.globalCompositeOperation = 'source-over';
    ctx.globalAlpha = 1;
    ctx.drawImage(this._vignetteSprite(), 0, 0, r.w, r.h);
  }

  /** 开场大闪光（叠加在一切之上） */
  _flashPass(strength) {
    if (strength <= 0.001) return;
    const r = this.r;
    const ctx = r.reset();
    ctx.globalCompositeOperation = 'lighter';
    const g = ctx.createRadialGradient(r.cx, r.cy, 0, r.cx, r.cy, r.max * 0.9);
    g.addColorStop(0, `rgba(255,255,255,${clamp(strength, 0, 1)})`);
    g.addColorStop(0.35, `rgba(180,225,255,${clamp(strength * 0.5, 0, 1)})`);
    g.addColorStop(1, 'rgba(80,120,255,0)');
    ctx.fillStyle = g;
    ctx.fillRect(0, 0, r.w, r.h);
    ctx.globalCompositeOperation = 'source-over';
  }

  /** 由 intro 调用的收尾：白闪 + 暗场 */
  finishFlash(strength) {
    this._flashPass(strength);
  }
}
