/* =============================================================================
 * vortex.js —— 时空漩涡引擎
 * 结构：螺旋悬臂粒子 + 深度时空环 + 引力透镜弧 + 公式飞行物 + 中心奇点
 * 视角：沿隧道轴向前进，同时绕轴自旋 => 「时空被拖曳」的观感。
 * ========================================================================== */
import { TAU, clamp, lerp, damp, makeRng, withAlpha } from '../core/utils.js';
import { radialGlow, ringSprite, starFlare } from '../core/primitives.js';
import { fastSin, fastCos, Stars } from './stars.js';

export class Vortex {
  /**
   * @param {import('../core/renderer.js').Renderer} renderer
   * @param {object} cfg CFG.vortex
   * @param {{glow:()=>void, flares:()=>void}} fx
   */
  constructor(renderer, cfg, fx = {}) {
    this.r = renderer;
    this.cfg = cfg;
    this.fx = fx;
    this.view = { w: 0, h: 0, cx: 0, cy: 0, min: 0, focal: cfg.focal };
    this.time = 0;
    this.assembled = 1;      // 0 -> 1：漩涡的凝聚程度（开场演出用）
    this.warp = 1;           // 前进速度倍率
    this.spinBoost = 0;      // 额外自旋
    this.tunnelK = 1;        // 隧道口径倍率
    this.converge = cfg.converge;
    this.trail = cfg.trail;
    this.starAlpha = 0.95;
    this.brightness = 1;
    this.expansion = 0;      // 奇点爆发强度 0..1
    this.flash = 0;
    this.pulse = 0;
    this.glyphAlpha = 1;
    this.sinkGain = 1;   // 奇点/吸积盘层的整体增益（调试与降级用）
    this.mouse = { x: 0, y: 0, tx: 0, ty: 0, k: 0 };
    this.glyphs = [];
    this.glyphPool = null;
    this.ready = false;
    this.tier = 1;
    this._rng = makeRng(cfg.seed || 20240617);
    this._build();
  }

  /* ---------------------------------------------------------------- 构建 -- */
  _build() {
    const cfg = this.cfg;
    this.sprite = {
      dot: radialGlow(16, [
        [0, 'rgba(255,255,255,1)'],
        [0.3, 'rgba(210,240,255,0.55)'],
        [0.7, 'rgba(120,180,255,0.12)'],
        [1, 'rgba(60,110,220,0)'],
      ]),
      dust: radialGlow(96, [
        [0, 'rgba(190,230,255,0.30)'],
        [0.35, 'rgba(110,160,255,0.14)'],
        [0.7, 'rgba(80,90,220,0.05)'],
        [1, 'rgba(40,40,120,0)'],
      ]),
      // 隧道壁的薄雾：大而极淡，靠数量堆出「管壁」的体积感
      haze: radialGlow(128, [
        [0, 'rgba(150,200,255,0.16)'],
        [0.28, 'rgba(90,150,255,0.085)'],
        [0.6, 'rgba(70,80,210,0.03)'],
        [1, 'rgba(30,30,90,0)'],
      ]),
      halo: radialGlow(320, [
        [0, 'rgba(255,255,255,0.30)'],
        [0.05, 'rgba(195,235,255,0.20)'],
        [0.18, 'rgba(90,180,255,0.11)'],
        [0.5, 'rgba(70,90,240,0.045)'],
        [1, 'rgba(30,20,90,0)'],
      ]),
    };
    this.lensSprites = [
      ringSprite(512, { inner: 0.80, color: '#eaf6ff', width: 0.03, blur: 0.07, alpha: 0.85 }),
      ringSprite(512, { inner: 0.70, color: '#8fd8ff', width: 0.05, blur: 0.10, alpha: 0.6 }),
      ringSprite(512, { inner: 0.60, color: '#7b7bff', width: 0.06, blur: 0.12, alpha: 0.45 }),
    ];
    this.flareSprite = starFlare(256, '#dff4ff');
    this.ringSprites = [
      { c: '#a5e8ff', a: 0.5, s: ringSprite(256, { inner: 0.955, color: '#a5e8ff', width: 0.009, blur: 0.022, alpha: 0.5 }) },
      { c: '#22d3ee', a: 0.46, s: ringSprite(256, { inner: 0.95, color: '#22d3ee', width: 0.01, blur: 0.026, alpha: 0.46 }) },
      { c: '#8b5cf6', a: 0.38, s: ringSprite(256, { inner: 0.945, color: '#8b5cf6', width: 0.012, blur: 0.032, alpha: 0.38 }) },
    ];
    this._buildStreamers();
    this._buildRings();
    this._buildLens();
    this.stars = new Stars(this, { count: cfg.stars, seed: 7 });
  }

  setTier(tier) {
    this.tier = tier;
    this._buildStreamers();
    this.stars.reset(Math.round(this.cfg.stars * tier), 7);
  }

  /**
   * 把粒子分成两组：
   *   wall —— 螺旋墙（管壁），是「时空漩涡」的主体结构，量大而暗
   *   disc —— 旋臂平面，负责明亮细丝与节点高光
   * 记录格式（每点 6 个浮点数）：[band, z, spread, sizeMul, isNode, isWall]
   */
  _buildStreamers() {
    const cfg = this.cfg;
    const total = Math.max(360, Math.round(cfg.streamers * this.tier));
    const wallCount = Math.round(total * 0.62);
    const n = total;
    const rng = makeRng(4242);
    const depth = cfg.depth;
    const arms = cfg.arms;
    const pts = new Float32Array(n * 6);
    for (let i = 0; i < n; i++) {
      const o = i * 6;
      const isWall = i < wallCount;
      const band = i % arms;
      const z = isWall
        ? Math.pow(rng(), 0.72) * depth          // 管壁偏向近处，保证隧道可读
        : rng() * depth;
      const spread = isWall ? (rng() - 0.5) * 0.055 : (rng() - 0.5) * 0.3;
      pts[o] = band;
      pts[o + 1] = z;
      pts[o + 2] = spread;
      pts[o + 3] = 0.45 + rng() * 0.8;                     // 大小倍率
      pts[o + 4] = !isWall && rng() < 0.2 ? 1 : 0;         // 明亮节点
      pts[o + 5] = isWall ? 1 : 0;
    }
    this.stream = pts;
    this.streamCount = n;
    this.wallCount = wallCount;
  }

  _buildRings() {
    const cfg = this.cfg;
    const n = Math.max(14, Math.round(cfg.rings * Math.max(0.7, this.tier)));
    this.rings = new Float32Array(n * 2);
    for (let i = 0; i < n; i++) {
      this.rings[i * 2] = (i / n) * cfg.depth;
      this.rings[i * 2 + 1] = this._rng();
    }
    this.ringCount = n;
  }

  _buildLens() {
    const n = Math.round(this.cfg.lensArcs * 2);
    this.lens = new Float32Array(n * 3);
    for (let i = 0; i < n; i++) {
      this.lens[i * 3] = this._rng() * TAU;
      this.lens[i * 3 + 1] = this._rng();
      this.lens[i * 3 + 2] = this._rng() * TAU;
    }
    this.lensCount = n;
  }

  /** 注入已烘焙好的公式纹理 */
  setGlyphs(canvases) {
    this.glyphPool = canvases;
    this.glyphs = canvases.map((canvas, i) => ({
      canvas,
      a: (i / canvases.length) * TAU * 2 + this._rng() * 0.5,
      z: this._rng() * this.cfg.depth,
      sp: 0.5 + this._rng() * 0.7,
      ex: 0.55 + this._rng() * 0.9,
      rot: (this._rng() - 0.5) * 0.6,
      wob: this._rng() * TAU,
      scale: 0.7 + this._rng() * 0.75,
      dead: 0,
    }));
    this.ready = true;
  }

  /* ------------------------------------------------------------ 生命周期 -- */
  resize() {
    const { w, h, cx, cy, min } = this.r;
    this.view = { w, h, cx, cy, min, focal: this.cfg.focal * (min / 900 + 0.35) };
    this.spriteScale = clamp(min / 900, 0.5, 1.6);
    this.stars.resize();
  }

  /** 让 mouse 在全局指针事件中被调用 */
  pointer(nx, ny) {
    this.mouse.tx = nx;
    this.mouse.ty = ny;
  }

  /** 触发一次奇点爆发（时间轴推入奇点 / 开场高潮） */
  burst(strength = 1) {
    this.expansion = Math.max(this.expansion, strength);
    this.flash = Math.max(this.flash, 0.8 * strength);
  }

  /* ---------------------------------------------------------- 每帧更新 -- */
  update(dt) {
    this.time += dt;
    const cfg = this.cfg;
    const m = this.mouse;
    m.x = damp(m.x, m.tx, 3.2, dt);
    m.y = damp(m.y, m.ty, 3.2, dt);
    m.k = damp(m.k, 1, 1.6, dt);

    // 隧道轴随指针轻微摆动（重物附近的测地线偏移）
    const drift = 1 - Math.exp(-1.4 * dt);
    this.drift = this.drift || { x: 0, y: 0 };
    this.drift.x = lerp(this.drift.x, m.x * 0.055, drift);
    this.drift.y = lerp(this.drift.y, m.y * 0.045, drift);

    // 奇点爆发衰减
    this.expansion = Math.max(0, this.expansion - dt * (0.45 + this.expansion * 0.9));
    this.flash = Math.max(0, this.flash - dt * 2.6);
    this.pulse = (this.pulse + dt * 0.9) % TAU;

    // 悬臂粒子前进
    const speed = cfg.speed * this.warp * lerp(0.55, 1.15, this.assembled);
    const n = this.streamCount;
    const depth = cfg.depth;
    const pts = this.stream;
    for (let i = 0; i < n; i++) {
      const o = i * 6;
      let z = pts[o + 1] - speed * dt;
      if (z < 6) z += depth;
      pts[o + 1] = z;
    }

    // 深度环前进
    for (let i = 0; i < this.ringCount; i++) {
      const o = i * 2;
      let z = this.rings[o] - speed * 0.92 * dt;
      if (z < 8) z += depth;
      this.rings[o] = z;
    }
    this.stars.update(dt);

    // 公式飞行物：沿螺旋向内（趋近奇点后爆发重生）
    if (this.glyphs.length) {
      const maxR = Math.max(this.view.w, this.view.h) * 0.78;
      for (const g of this.glyphs) {
        g.z -= speed * g.sp * dt;
        g.a += (0.22 + 260 / (g.z + 300)) * dt * (1 + this.spinBoost);
        g.wob += dt * 1.2;
        const persp = this.view.focal / (this.view.focal + g.z);
        const rad = g.z * this.converge * persp * this.tunnelK * 0.92;
        if (rad < this.view.min * 0.022 && !g.dead) {
          g.dead = 1;
          this.glyphSink = { x: this.view.cx, y: this.view.cy, t: 1 };
          this.flash = Math.min(0.55, this.flash + 0.16);
        }
        if (g.z < 4 || rad > maxR * 3.4) {
          g.z = cfg.depth * (0.9 + Math.random() * 0.2);
          g.a = Math.random() * TAU;
          g.dead = 0;
        }
      }
    }
    if (this.glyphSink) {
      this.glyphSink.t -= dt * 2.2;
      if (this.glyphSink.t <= 0) this.glyphSink = null;
    }
  }

  /* ------------------------------------------------------------ 绘制 ---- */
  draw() {
    const r = this.r;
    const ctx = r.reset();
    r.fadeTrail(this.trail * (1 - this.flash * 0.6), '#02040c');
    ctx.save();
    ctx.translate(this.drift.x * this.view.min, this.drift.y * this.view.min);
    this._drawNebula(ctx);
    this._drawFilaments(ctx);
    this._drawArms(ctx);
    this._drawStars(ctx);
    this._drawRings(ctx);
    this._drawGlyphs(ctx);
    this._drawLens(ctx);
    this._drawSingularity(ctx);
    this._drawFlash(ctx);
    ctx.restore();
    ctx.globalCompositeOperation = 'source-over';
    ctx.globalAlpha = 1;
  }

  _drawNebula(ctx) {
    const { cx, cy, min } = this.view;
    const s = min * 2.4 * (1 + this.expansion * 0.6);
    ctx.globalCompositeOperation = 'lighter';
    ctx.globalAlpha = clamp(0.16 * this.brightness * (0.35 + 0.65 * this.assembled), 0, 1);
    ctx.drawImage(this.sprite.halo, cx - s / 2, cy - s / 2, s, s);
    ctx.globalAlpha = 1;
    ctx.globalCompositeOperation = 'source-over';
  }

  _drawArms(ctx) {
    const { cx, cy, min, focal } = this.view;
    const cfg = this.cfg;
    const unit = clamp(min / 900, 0.5, 1.6) * this.spriteScale;
    // 曝光预算：管壁（大量、极暗）与旋臂（少量、明亮）分开配平，
    // 否则中心会在几秒内累积到纯白。
    const S = {
      wall: { img: this.sprite.haze, base: 74 },
      dot: { img: this.sprite.dot, base: 4.6 },
      node: { img: this.sprite.dot, base: 10 },
      dust: { img: this.sprite.dust, base: 40 },
    };
    const expo = this.brightness * (0.3 + 0.7 * this.assembled) * (1 - this.flash * 0.5);
    const aWall = clamp(0.06 * expo, 0, 1);
    const aArm = clamp(0.062 * expo, 0, 1);
    ctx.globalCompositeOperation = 'lighter';
    const pts = this.stream;
    for (let i = 0; i < this.streamCount; i++) {
      const o = i * 6;
      const band = pts[o];
      const z = pts[o + 1];
      const spread = pts[o + 2];
      const sizeMul = pts[o + 3];
      const bright = pts[o + 4];
      const isWall = pts[o + 5] === 1;
      const persp = focal / (focal + z);
      if (persp < 0.07) continue;
      const rad = z * this.converge * persp * this.tunnelK * (1 + spread * this.assembled);
      const ang =
        band * (TAU / cfg.arms) +
        z * 0.00118 * cfg.twist +
        this.time * (cfg.spin + this.spinBoost) +
        spread * 6.0;
      const x = cx + fastCos(ang) * rad;
      const y = cy + fastSin(ang) * rad * 0.93;
      const sp = isWall ? S.wall : bright ? S.node : rad > min * 0.62 ? S.dust : S.dot;
      const size = sp.base * unit * sizeMul * persp * (bright ? 1 + (1 - persp) * 1.6 : 1);
      const radial = Math.pow(persp, isWall ? 0.9 : 1.5);
      let a;
      if (isWall) {
        a = aWall * radial * (rad < min * 0.2 ? 0.5 : 0.9);
      } else if (bright) {
        a = clamp(Math.min(aArm * radial * 2.2, 0.42), 0, 1);
      } else {
        // 中景最亮、近场显著收暗：避免中心堆成白斑
        const midBoost = rad < min * 0.16 ? 0.34 : rad < min * 0.55 ? 1.15 : 0.8;
        a = aArm * radial * midBoost;
      }
      // alpha 必须在 continue 之前写回，否则会「漏」到下一个粒子
      ctx.globalAlpha = clamp(a, 0, 1);
      if (size < 0.4) continue;
      ctx.drawImage(sp.img, x - size / 2, y - size / 2, size, size);
    }
    ctx.globalAlpha = 1;
    ctx.globalCompositeOperation = 'source-over';
  }

  _drawStars(ctx) {
    this.stars.draw(ctx);
  }

  /**
   * 螺旋细丝：沿对数螺旋描出明亮的连续曲线，
   * 这是「漩涡」能被一眼认出的关键结构（粒子只负责体积感）。
   */
  _drawFilaments(ctx) {
    const { cx, cy, min, focal } = this.view;
    const cfg = this.cfg;
    const expo = this.brightness * (0.35 + 0.65 * this.assembled) * (0.5 + 0.5 * Math.min(1, this.time * 0.5));
    if (expo <= 0.02) return;
    const T = this.time * (cfg.spin + this.spinBoost) * 1.6;
    const segments = 84;
    ctx.globalCompositeOperation = 'lighter';
    ctx.lineCap = 'round';
    for (let arm = 0; arm < cfg.arms; arm++) {
      for (let k = 0; k < 2; k++) {
        const off = arm * (TAU / cfg.arms) + k * 0.7;
        ctx.beginPath();
        let started = false;
        let alphaPeak = 0;
        for (let s = 0; s <= segments; s++) {
          const z = 30 + (s / segments) * (cfg.depth * 0.82);
          const persp = focal / (focal + z);
          const rad = z * this.converge * persp * this.tunnelK * (1 + k * 0.22);
          if (rad < 2) continue;
          const ang = off + z * 0.00118 * cfg.twist + T;
          const x = cx + fastCos(ang) * rad;
          const y = cy + fastSin(ang) * rad * 0.93;
          if (!started) {
            ctx.moveTo(x, y);
            started = true;
          } else {
            ctx.lineTo(x, y);
          }
          alphaPeak = Math.max(alphaPeak, persp);
        }
        if (!started) continue;
        // 近端更亮，远端隐入深处
        const g = ctx.createRadialGradient(cx, cy, 0, cx, cy, min * 0.85);
        g.addColorStop(0, `rgba(200,240,255,${clamp(0.26 * expo, 0, 1)})`);
        g.addColorStop(0.28, `rgba(130,195,255,${clamp(0.18 * expo, 0, 1)})`);
        g.addColorStop(0.65, `rgba(120,130,255,${clamp(0.11 * expo, 0, 1)})`);
        g.addColorStop(1, `rgba(120,120,255,${clamp(0.04 * expo, 0, 1)})`);
        ctx.strokeStyle = g;
        ctx.lineWidth = 1.15 * clamp(min / 900, 0.6, 1.5);
        ctx.stroke();
        void alphaPeak;
      }
    }
    ctx.globalCompositeOperation = 'source-over';
  }

  _drawRings(ctx) {
    const { cx, cy, min, focal } = this.view;
    const cfg = this.cfg;
    const n = this.ringCount;
    ctx.globalCompositeOperation = 'lighter';
    for (let i = 0; i < n; i++) {
      const o = i * 2;
      const z = this.rings[o];
      const seedv = this.rings[o + 1];
      const persp = focal / (focal + z);
      if (persp < 0.06) continue;
      const rad = z * this.converge * persp * this.tunnelK * (1 + seedv * 0.28);
      // 环带由精灵内部的透明间隙决定，故整体绘制尺寸需略大于直径
      const size = rad * 2.06;
      if (size < 12 || rad < 3) continue;
      const sp = this.ringSprites[(seedv * this.ringSprites.length) | 0];
      const ang = this.time * (cfg.spin * 0.6 + this.spinBoost * 0.5) + seedv * TAU;
      // 靠近中心的位置环带被压亮（引力透镜方向）
      const lensBoost = clamp(1 - Math.abs(rad - min * 0.22) / (min * 0.2), 0, 1);
      const a = clamp(0.13 * sp.a * Math.pow(persp, 1.55) * (0.45 + 0.55 * this.assembled), 0, 1);
      ctx.globalAlpha = clamp(a * (1 + lensBoost * 0.7) * this.brightness, 0, 1);
      ctx.save();
      ctx.translate(cx, cy);
      ctx.rotate(ang);
      ctx.scale(1, 0.93);
      ctx.drawImage(sp.s, -size / 2, -size / 2, size, size);
      ctx.restore();
    }
    ctx.globalAlpha = 1;
    ctx.globalCompositeOperation = 'source-over';
  }

  _drawGlyphs(ctx) {
    if (!this.glyphs.length || this.glyphAlpha <= 0.01) return;
    const { cx, cy, focal } = this.view;
    const unit = this.spriteScale;
    ctx.globalCompositeOperation = 'lighter';
    for (const g of this.glyphs) {
      const z = g.z;
      const persp = focal / (focal + z);
      if (persp < 0.08) continue;
      const rad = z * this.converge * persp * this.tunnelK * g.ex * 0.9;
      const ang = g.a + z * 0.0011 * this.cfg.twist + Math.sin(g.wob * 0.6) * 0.35;
      const x = cx + fastCos(ang) * rad;
      const y = cy + fastSin(ang) * rad * 0.93 + Math.sin(g.wob) * 6;
      const scale = persp * g.scale * unit * 1.35;
      if (scale < 0.07) continue;
      const cw = g.canvas.width * scale;
      const ch = g.canvas.height * scale;
      // 越靠近奇点越亮，随后在中心被吞没
      const near = clamp(1.25 - rad / (this.view.min * 0.6), 0, 1);
      const a = clamp(
        this.glyphAlpha * (0.22 + 0.78 * Math.pow(persp, 1.5)) * (0.6 + near * 0.85) * (g.dead ? 0.25 : 1),
        0,
        1
      );
      ctx.globalAlpha = a;
      ctx.save();
      ctx.translate(x, y);
      ctx.rotate(g.rot * 0.18 + Math.sin(g.wob * 0.7) * 0.05);
      ctx.drawImage(g.canvas, -cw / 2, -ch / 2, cw, ch);
      ctx.restore();
    }
    ctx.globalAlpha = 1;
    ctx.globalCompositeOperation = 'source-over';
  }

  _drawLens(ctx) {
    const { cx, cy, focal } = this.view;
    ctx.globalCompositeOperation = 'lighter';
    for (let i = 0; i < this.lensCount; i++) {
      const o = i * 3;
      const base = this.lens[o];
      const seedv = this.lens[o + 1];
      const phase = this.lens[o + 2];
      const cycle = (this.time * 0.16 + seedv) % 1;      // 由远及近的周期
      const z = (1 - cycle) * this.cfg.depth * 0.9 + 20;
      const persp = focal / (focal + z);
      const rad = z * this.converge * persp * this.tunnelK * 0.72;
      const size = rad * 2.4;
      if (size < 10) continue;
      const sp = this.lensSprites[i % this.lensSprites.length];
      const a = Math.sin(cycle * Math.PI) ** 1.6;
      ctx.globalAlpha = clamp(a * 0.26 * this.brightness * (1 - this.flash), 0, 1);
      ctx.save();
      ctx.translate(cx, cy);
      ctx.rotate(base + this.time * 0.22 + phase);
      ctx.scale(1, 0.94);
      ctx.drawImage(sp, -size / 2, -size / 2, size, size);
      ctx.restore();
    }
    ctx.globalAlpha = 1;
    ctx.globalCompositeOperation = 'source-over';
  }

  /** 中心奇点：光子环 + 吸积盘 + 事件视界 + 相对论喷流 */
  _drawSingularity(ctx) {
    const { cx, cy, min, focal } = this.view;
    if (this.sinkGain <= 0.01) return;
    const puls = 1 + Math.sin(this.pulse) * 0.035;
    const ex = this.expansion;
    const coreR = min * 0.03 * puls * (1 + ex * 1.6);
    const haloR = min * 0.95 * (1 + ex * 1.2);

    ctx.globalCompositeOperation = 'lighter';

    // 喷流：沿隧道轴的两束（简化为上下方向的细长渐变）
    const jet = ctx.createLinearGradient(cx, cy - haloR * 0.9, cx, cy + haloR * 0.9);
    const ja = clamp(0.07 * (0.4 + 0.6 * this.assembled) * this.brightness, 0, 1);
    jet.addColorStop(0, 'rgba(120,180,255,0)');
    jet.addColorStop(0.5, withAlpha('#cfe9ff', ja));
    jet.addColorStop(1, 'rgba(120,180,255,0)');
    ctx.globalAlpha = 0.32;
    ctx.fillStyle = jet;
    ctx.fillRect(cx - coreR * 0.16, cy - haloR * 0.85, coreR * 0.32, haloR * 1.7);

    // 外光晕
    ctx.globalAlpha = clamp(0.12 * this.assembled * this.brightness, 0, 1);
    ctx.drawImage(this.sprite.halo, cx - haloR / 2, cy - haloR / 2, haloR, haloR);

    // 吸积盘（椭圆环，随时间旋转）
    ctx.save();
    ctx.translate(cx, cy);
    ctx.rotate(this.time * 0.35);
    ctx.scale(1, 0.34);
    const diskR = coreR * 5.2 * (1 + ex);
    const dg = ctx.createRadialGradient(0, 0, coreR * 0.9, 0, 0, diskR);
    dg.addColorStop(0, withAlpha('#ffffff', 0.36));
    dg.addColorStop(0.18, withAlpha('#a5e8ff', 0.26));
    dg.addColorStop(0.45, withAlpha('#f0c060', 0.2));
    dg.addColorStop(0.72, withAlpha('#8b5cf6', 0.12));
    dg.addColorStop(1, 'rgba(20,10,60,0)');
    ctx.globalAlpha = clamp(0.3 * this.brightness, 0, 1);
    ctx.fillStyle = dg;
    ctx.beginPath();
    ctx.arc(0, 0, diskR, 0, TAU);
    ctx.fill();
    ctx.restore();

    // 光子环 / 透镜环
    for (let i = 0; i < this.lensSprites.length; i++) {
      const size = coreR * (5.4 + i * 2.6) * (1 + ex * 0.8);
      ctx.globalAlpha = clamp((0.17 - i * 0.04) * (0.5 + 0.5 * this.assembled) * this.brightness, 0, 1);
      ctx.drawImage(this.lensSprites[i], cx - size / 2, cy - size / 2, size, size);
    }

    // 事件视界（真正的黑）
    ctx.globalCompositeOperation = 'source-over';
    const eh = ctx.createRadialGradient(cx, cy, 0, cx, cy, coreR * 2.4);
    eh.addColorStop(0, 'rgba(0,0,0,1)');
    eh.addColorStop(0.45, 'rgba(0,0,4,0.98)');
    eh.addColorStop(0.78, 'rgba(1,2,8,0.72)');
    eh.addColorStop(1, 'rgba(0,0,0,0)');
    ctx.globalAlpha = 1;
    ctx.fillStyle = eh;
    ctx.beginPath();
    ctx.arc(cx, cy, coreR * 2.4, 0, TAU);
    ctx.fill();

    // 内环亮线（光子球）
    ctx.globalCompositeOperation = 'lighter';
    ctx.globalAlpha = clamp(0.6 * this.brightness, 0, 1);
    const ringR = coreR * 2.15;
    const rg = ctx.createRadialGradient(cx, cy, ringR * 0.86, cx, cy, ringR * 1.14);
    rg.addColorStop(0, 'rgba(120,180,255,0)');
    rg.addColorStop(0.5, withAlpha('#eaf6ff', 0.45));
    rg.addColorStop(1, 'rgba(120,180,255,0)');
    ctx.fillStyle = rg;
    ctx.beginPath();
    ctx.arc(cx, cy, ringR * 1.14, 0, TAU);
    ctx.fill();

    // 星芒
    const flareSize = coreR * 12 * (1 + ex * 1.4);
    ctx.globalAlpha = clamp(0.22 * this.brightness * (0.55 + 0.45 * this.assembled), 0, 1);
    ctx.drawImage(this.flareSprite, cx - flareSize / 2, cy - flareSize / 2, flareSize, flareSize);

    // 公式沉没时的中心闪光
    if (this.glyphSink) {
      const t = clamp(this.glyphSink.t);
      const s = coreR * (2 + (1 - t) * 6);
      ctx.globalAlpha = t * 0.5;
      ctx.drawImage(this.flareSprite, cx - s / 2, cy - s / 2, s, s);
    }

    ctx.globalAlpha = 1;
    ctx.globalCompositeOperation = 'source-over';
    void focal;
  }

  _drawFlash(ctx) {
    if (this.flash <= 0.001) return;
    const { cx, cy, min } = this.view;
    const s = min * (0.9 + this.flash * 3.2);
    ctx.globalCompositeOperation = 'lighter';
    ctx.globalAlpha = clamp(this.flash * 0.9, 0, 1);
    ctx.drawImage(this.flareSprite, cx - s / 2, cy - s / 2, s, s);
    ctx.globalAlpha = 1;
    ctx.globalCompositeOperation = 'source-over';
  }
}
