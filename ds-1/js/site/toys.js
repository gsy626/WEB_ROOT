/* =============================================================================
 * toys.js —— 可交互物理实验室（三个独立的小引擎，均为原生 Canvas 2D）
 *   FieldToy     向量场与流线
 *   PendulumToy  双摆（混沌 / 初值敏感）
 *   WaveToy      阻尼受迫谐振（共振 / 相图）
 * 每个玩具只在进入视口时运行，离开即暂停，节省算力。
 * ========================================================================== */
import { clamp, lerp, damp, TAU, makeRng, esc } from '../core/utils.js';
import { LAB_ITEMS } from '../data/sections.js';

export function renderLab(root = document.querySelector('#lab')) {
  if (!root) return null;
  const grid = root.querySelector('.lab__grid');
  grid.innerHTML = LAB_ITEMS.map(
    (item) => `
    <article class="lab-card" id="${item.id}" data-reveal>
      <header class="lab-card__head">
        <h3 class="lab-card__title">${esc(item.title)}</h3>
        <p class="lab-card__desc">${esc(item.desc)}</p>
      </header>
      <div class="lab-stage">
        <canvas class="lab-canvas"></canvas>
        <div class="lab-controls"></div>
      </div>
      <p class="lab-card__caption">${esc(item.caption)}</p>
    </article>`
  ).join('');

  const toys = [
    new FieldToy(grid.querySelector('#toy-field .lab-canvas')),
    new PendulumToy(grid.querySelector('#toy-pendulum .lab-canvas')),
    new WaveToy(grid.querySelector('#toy-wave .lab-canvas')),
  ];

  // 仅当卡片可见时才推进物理，离开视口即暂停
  const io = new IntersectionObserver(
    (entries) =>
      entries.forEach((e) => {
        const t = e.target.__toy;
        if (t) t.active = e.isIntersecting;
      }),
    { threshold: 0.05 }
  );
  toys.forEach((t) => {
    const card = t.canvas.closest('.lab-card');
    card.__toy = t;
    io.observe(card);
  });

  let raf = 0;
  let last = performance.now();
  const frame = (now) => {
    raf = requestAnimationFrame(frame);
    let dt = Math.min((now - last) / 1000, 1 / 20);
    last = now;
    for (const t of toys) if (t.active) t.frame(dt);
  };
  raf = requestAnimationFrame(frame);
  return { toys, stop: () => cancelAnimationFrame(raf) };
}

/* ---------------------------------------------------------------- 基类 ---- */
class Toy {
  constructor(canvas) {
    this.canvas = canvas;
    this.ctx = canvas.getContext('2d');
    this.active = false;
    this.w = 0;
    this.h = 0;
    this.dpr = Math.min(devicePixelRatio || 1, 2);
    this.pointer = { x: 0.5, y: 0.5, inside: false, down: false };
    this.controls = [];
    this.ro = new ResizeObserver(() => this.resize());
    this.ro.observe(canvas);
    this._bindPointer();
    this.resize();
  }
  get theme() {
    return getComputedStyle(document.documentElement);
  }
  _bindPointer() {
    const set = (e) => {
      const r = this.canvas.getBoundingClientRect();
      this.pointer.x = clamp((e.clientX - r.left) / r.width, 0, 1);
      this.pointer.y = clamp((e.clientY - r.top) / r.height, 0, 1);
      this.pointer.inside = true;
    };
    this.canvas.addEventListener('pointermove', set);
    this.canvas.addEventListener('pointerdown', (e) => {
      this.pointer.down = true;
      set(e);
      this.canvas.setPointerCapture?.(e.pointerId);
    });
    this.canvas.addEventListener('pointerup', () => (this.pointer.down = false));
    this.canvas.addEventListener('pointerleave', () => {
      this.pointer.inside = false;
      this.pointer.down = false;
    });
  }
  addControls(defs) {
    const host = this.canvas.parentElement.querySelector('.lab-controls');
    if (!host) return;
    host.innerHTML = defs
      .map(
        (d) => `
      <label class="ctrl">
        <span class="ctrl__name">${esc(d.name)}</span>
        <input type="range" min="${d.min}" max="${d.max}" step="${d.step}" value="${d.value}" data-key="${d.key}">
        <output class="ctrl__val">${d.value}${d.unit || ''}</output>
      </label>`
      )
      .join('');
    host.querySelectorAll('input[type=range]').forEach((input) => {
      input.addEventListener('input', () => {
        const key = input.dataset.key;
        this.params[key] = Number(input.value);
        const out = input.parentElement.querySelector('.ctrl__val');
        const def = defs.find((d) => d.key === key);
        if (out) out.textContent = `${input.value}${def?.unit || ''}`;
      });
    });
    this.controls = defs;
  }
  resize() {
    const r = this.canvas.getBoundingClientRect();
    this.w = Math.max(1, Math.round(r.width));
    this.h = Math.max(1, Math.round(r.height));
    this.canvas.width = Math.round(this.w * this.dpr);
    this.canvas.height = Math.round(this.h * this.dpr);
    this.ctx.setTransform(this.dpr, 0, 0, this.dpr, 0, 0);
    this.onResize?.();
  }
  clear(bg = '#050a18') {
    const { ctx } = this;
    ctx.setTransform(this.dpr, 0, 0, this.dpr, 0, 0);
    ctx.globalAlpha = 1;
    ctx.globalCompositeOperation = 'source-over';
    ctx.fillStyle = bg;
    ctx.fillRect(0, 0, this.w, this.h);
  }
  frame() {}
}

/* ------------------------------------------------------------ 向量场 ------ */
class FieldToy extends Toy {
  constructor(canvas) {
    super(canvas);
    this.params = { strength: 1, swirl: 0.75 };
    this.addControls([
      { key: 'strength', name: '场强', min: 0.2, max: 2.2, step: 0.05, value: 1, unit: '×' },
      { key: 'swirl', name: '旋度', min: -1.4, max: 1.4, step: 0.05, value: 0.75 },
    ]);
    this.rng = makeRng(4711);
    this.particles = Array.from({ length: 900 }, () => ({
      x: this.rng(),
      y: this.rng(),
      life: 0.2 + this.rng() * 0.8,
      speed: 0.6 + this.rng() * 0.9,
      hue: this.rng(),
    }));
    this.attractors = [
      { x: 0.3, y: 0.55, q: 1 },
      { x: 0.72, y: 0.38, q: -0.65 },
    ];
    this.time = 0;
  }

  /** 场函数：三个电荷的叠加（含旋转分量） + 均匀背景场 */
  field(x, y) {
    const k = this.params.strength;
    let vx = 0.28 * k;
    let vy = 0.1 * k * Math.sin(this.time * 0.25);
    /** @type {Array<{x:number,y:number,q:number}>} */
    const all = this._attrs || this.attractors;
    for (const a of all) {
      const dx = x - a.x;
      const dy = y - a.y;
      const d2 = dx * dx + dy * dy + 0.0035;
      const inv = a.q * k / d2;
      vx += dx * inv;
      vy += dy * inv;
      // 旋度（磁场式涡旋）
      const s = this.params.swirl * a.q * k / Math.sqrt(d2);
      vx += -dy * s * 0.35;
      vy += dx * s * 0.35;
    }
    const m = Math.hypot(vx, vy) || 1;
    const cap = 2.4;
    const sc = m > cap ? cap / m : 1;
    return [vx * sc, vy * sc, Math.min(1, m)];
  }

  frame(dt) {
    this.time += dt;
    // 鼠标拖拽生成临时电荷
    if (this.pointer.down && this.pointer.inside) {
      this.live = { x: this.pointer.x, y: 1 - this.pointer.y, q: 0.9 };
    } else if (this.live) {
      this.live = null;
    }
    const attrs = this.live ? [...this.attractors, this.live] : this.attractors;
    this._attrs = attrs;
    const { ctx, w, h } = this;
    // 拖尾
    ctx.globalCompositeOperation = 'source-over';
    ctx.fillStyle = 'rgba(5,10,24,0.16)';
    ctx.fillRect(0, 0, w, h);

    // 静态箭头网格
    ctx.globalCompositeOperation = 'lighter';
    if (!this._grid) this._grid = this._buildGrid();
    this._drawGrid();

    // 粒子沿场流动
    ctx.lineCap = 'round';
    for (const p of this.particles) {
      const [fx, fy, mag] = this.field(p.x, p.y);
      const step = 0.0034 * p.speed * (0.5 + mag);
      const nx = p.x + fx * step;
      const ny = p.y + fy * step;
      const px = p.x * w;
      const py = (1 - p.y) * h;
      p.x = nx > 1.02 ? nx - 1.04 : nx < -0.02 ? nx + 1.04 : nx;
      p.y = ny > 1.02 ? ny - 1.04 : ny < -0.02 ? ny + 1.04 : ny;
      const qx = p.x * w;
      const qy = (1 - p.y) * h;
      p.life -= dt * 0.22;
      if (p.life <= 0) {
        // 从电荷附近重生，让流线自然收束
        const a = attrs[(this.rng() * attrs.length) | 0];
        const ang = this.rng() * TAU;
        const rad = 0.06 + this.rng() * 0.22;
        p.x = clamp(a.x + Math.cos(ang) * rad, 0, 1);
        p.y = clamp(a.y + Math.sin(ang) * rad, 0, 1);
        p.life = 0.7 + this.rng() * 0.7;
      }
      const alpha = clamp(p.life * 1.15, 0, 0.75) * (0.4 + mag * 0.6);
      ctx.strokeStyle = mag > 0.75
        ? `rgba(165,232,255,${alpha})`
        : mag > 0.35
        ? `rgba(120,190,255,${alpha * 0.85})`
        : `rgba(139,92,246,${alpha * 0.7})`;
      ctx.lineWidth = 1.05;
      ctx.beginPath();
      ctx.moveTo(px, py);
      ctx.lineTo(qx, qy);
      ctx.stroke();
    }

    // 电荷标记
    for (const a of attrs) {
      const x = a.x * w;
      const y = (1 - a.y) * h;
      const pos = a.q > 0;
      const g = ctx.createRadialGradient(x, y, 0, x, y, 26);
      g.addColorStop(0, pos ? 'rgba(255,255,255,0.95)' : 'rgba(220,200,255,0.9)');
      g.addColorStop(0.4, pos ? 'rgba(34,211,238,0.4)' : 'rgba(139,92,246,0.35)');
      g.addColorStop(1, 'rgba(0,0,0,0)');
      ctx.fillStyle = g;
      ctx.beginPath();
      ctx.arc(x, y, 26, 0, TAU);
      ctx.fill();
    }
    ctx.globalCompositeOperation = 'source-over';
  }

  _buildGrid() {
    const step = 46;
    const pts = [];
    for (let x = step * 0.6; x < this.w; x += step) {
      for (let y = step * 0.6; y < this.h; y += step) {
        pts.push([(x / this.w), 1 - y / this.h, x, y]);
      }
    }
    return pts;
  }

  _drawGrid() {
    const { ctx } = this;
    const L = 13;
    for (const [nx, ny, x, y] of this._grid) {
      const [fx, fy, mag] = this.field(nx, ny);
      const m = Math.hypot(fx, fy) || 1;
      const ux = fx / m;
      const uy = -fy / m;
      const a = 0.05 + mag * 0.28;
      ctx.strokeStyle = `rgba(150,200,255,${a})`;
      ctx.lineWidth = 1;
      ctx.beginPath();
      ctx.moveTo(x - ux * L * 0.5, y - uy * L * 0.5);
      ctx.lineTo(x + ux * L * 0.5, y + uy * L * 0.5);
      ctx.stroke();
      ctx.fillStyle = `rgba(200,235,255,${a * 1.1})`;
      ctx.beginPath();
      ctx.arc(x + ux * L * 0.5, y + uy * L * 0.5, 1.15, 0, TAU);
      ctx.fill();
    }
  }
}

/* -------------------------------------------------------------- 双摆 ------ */
class PendulumToy extends Toy {
  constructor(canvas) {
    super(canvas);
    this.params = { gravity: 1.6, radius: 1 };
    this.addControls([
      { key: 'gravity', name: '重力', min: 0.35, max: 2.6, step: 0.05, value: 1.6, unit: 'g' },
      { key: 'radius', name: '摆长', min: 0.5, max: 1.6, step: 0.05, value: 1, unit: 'L' },
    ]);
    this.reset();
  }
  reset() {
    this.a = { t1: 2.2, t2: 2.6, w1: 0, w2: 0 };
    this.b = { t1: 2.2 + 0.0025, t2: 2.6, w1: 0, w2: 0 }; // 极微小差异
    this.trail = [];
    this.trailB = [];
  }
  /** 双摆运动方程（四阶龙格库塔） */
  _step(s, dt, g) {
    const { t1, t2, w1, w2 } = s;
    const d = t1 - t2;
    const den = 2 - Math.cos(2 * d);
    const a1 = (-g * (2 * Math.sin(t1) + Math.sin(t1 - 2 * t2)) - 2 * Math.sin(d) * (w2 * w2 + w1 * w1 * Math.cos(d))) / den;
    const a2 = (2 * Math.sin(d) * (w1 * w1 * 2 + g * Math.cos(t1) + w2 * w2 * Math.cos(d))) / den;
    return { a1, a2 };
  }
  _integrate(s, dt, g) {
    const k1 = this._step(s, dt, g);
    const s2 = { t1: s.t1 + (k1.a1 * dt) / 2, t2: s.t2 + (k1.a2 * dt) / 2, w1: s.w1 + (k1.a1 * dt) / 2, w2: s.w2 + (k1.a2 * dt) / 2 };
    const k2 = this._step(s2, dt, g);
    const s3 = { t1: s.t1 + (k2.a1 * dt) / 2, t2: s.t2 + (k2.a2 * dt) / 2, w1: s.w1 + (k2.a1 * dt) / 2, w2: s.w2 + (k2.a2 * dt) / 2 };
    const k3 = this._step(s3, dt, g);
    const s4 = { t1: s.t1 + k3.a1 * dt, t2: s.t2 + k3.a2 * dt, w1: s.w1 + k3.a1 * dt, w2: s.w2 + k3.a2 * dt };
    const k4 = this._step(s4, dt, g);
    s.w1 += (dt / 6) * (k1.a1 + 2 * k2.a1 + 2 * k3.a1 + k4.a1);
    s.w2 += (dt / 6) * (k1.a2 + 2 * k2.a2 + 2 * k3.a2 + k4.a2);
    s.t1 += s.w1 * dt;
    s.t2 += s.w2 * dt;
  }
  frame(dt) {
    if (this.pointer.down && this.pointer.inside) {
      this.a.t1 = this.pointer.x * Math.PI * 1.9 - Math.PI * 0.95;
      this.a.t2 = this.pointer.y * Math.PI * 1.9 - Math.PI * 0.95;
      this.a.w1 = this.a.w2 = 0;
      this.b.t1 = this.a.t1 + 0.0025;
      this.b.t2 = this.a.t2;
      this.b.w1 = this.b.w2 = 0;
      this.trail.length = 0;
      this.trailB.length = 0;
      this.clear();
    }
    const g = 9.81 * this.params.gravity;
    const L = this.params.radius;
    const sub = 4;
    for (let i = 0; i < sub; i++) {
      this._integrate(this.a, dt / sub, g);
      this._integrate(this.b, dt / sub, g);
    }
    const { ctx, w, h } = this;
    this.clear('#050a18');

    const cx = w / 2;
    const cy = h * 0.42;
    const scale = Math.min(w, h) * 0.24 * L;
    const pos = (s) => {
      const x1 = cx + Math.sin(s.t1) * scale;
      const y1 = cy + Math.cos(s.t1) * scale;
      const x2 = x1 + Math.sin(s.t2) * scale;
      const y2 = y1 + Math.cos(s.t2) * scale;
      return [x1, y1, x2, y2];
    };
    const A = pos(this.a);
    const B = pos(this.b);

    this.trail.push([A[2], A[3]]);
    this.trailB.push([B[2], B[3]]);
    if (this.trail.length > 420) this.trail.shift();
    if (this.trailB.length > 420) this.trailB.shift();

    // 背景网格
    ctx.strokeStyle = 'rgba(120,170,255,0.07)';
    ctx.lineWidth = 1;
    for (let r = scale; r < Math.max(w, h); r += scale) {
      ctx.beginPath();
      ctx.arc(cx, cy, r, 0, TAU);
      ctx.stroke();
    }

    // 轨迹（两个几乎相同的初值）
    const drawTrail = (trail, color) => {
      ctx.lineWidth = 1.1;
      for (let i = 1; i < trail.length; i++) {
        const a = i / trail.length;
        ctx.strokeStyle = color(a);
        ctx.beginPath();
        ctx.moveTo(trail[i - 1][0], trail[i - 1][1]);
        ctx.lineTo(trail[i][0], trail[i][1]);
        ctx.stroke();
      }
    };
    ctx.globalCompositeOperation = 'lighter';
    drawTrail(this.trail, (a) => `rgba(34,211,238,${a * 0.55})`);
    drawTrail(this.trailB, (a) => `rgba(232,121,249,${a * 0.5})`);

    // 摆杆
    const drawRod = (P, c1, c2, alpha) => {
      ctx.lineCap = 'round';
      ctx.strokeStyle = c1.replace('ALPHA', alpha);
      ctx.lineWidth = 3.4;
      ctx.beginPath();
      ctx.moveTo(cx, cy);
      ctx.lineTo(P[0], P[1]);
      ctx.stroke();
      ctx.strokeStyle = c2.replace('ALPHA', alpha);
      ctx.lineWidth = 3;
      ctx.beginPath();
      ctx.moveTo(P[0], P[1]);
      ctx.lineTo(P[2], P[3]);
      ctx.stroke();
    };
    drawRod(B, 'rgba(232,121,249,ALPHA)', 'rgba(200,140,255,ALPHA)', 0.4);
    drawRod(A, 'rgba(165,232,255,ALPHA)', 'rgba(34,211,238,ALPHA)', 1);

    // 质点
    const dot = (x, y, r, color) => {
      const grd = ctx.createRadialGradient(x, y, 0, x, y, r * 3.2);
      grd.addColorStop(0, 'rgba(255,255,255,0.95)');
      grd.addColorStop(0.35, color);
      grd.addColorStop(1, 'rgba(0,0,0,0)');
      ctx.fillStyle = grd;
      ctx.beginPath();
      ctx.arc(x, y, r * 3.2, 0, TAU);
      ctx.fill();
    };
    dot(B[2], B[3], 4, 'rgba(232,121,249,0.5)');
    dot(A[2], A[3], 5.5, 'rgba(34,211,238,0.55)');
    ctx.globalCompositeOperation = 'source-over';

    if (this.pointer.down) {
      ctx.fillStyle = 'rgba(232,241,255,0.7)';
      ctx.font = '500 12px ui-monospace, monospace';
      ctx.fillText('拖动以重新设定初值', 12, h - 14);
    }
  }
}

/* ---------------------------------------------------- 阻尼受迫谐振子 ------ */
class WaveToy extends Toy {
  constructor(canvas) {
    super(canvas);
    this.params = { zeta: 0.08, drive: 1.0, omega0: 1.0 };
    this.addControls([
      { key: 'zeta', name: '阻尼 ζ', min: 0, max: 0.6, step: 0.005, value: 0.08 },
      { key: 'drive', name: '驱动 ω', min: 0, max: 3, step: 0.02, value: 1.0, unit: 'ω₀' },
      { key: 'omega0', name: '固有 ω₀', min: 0.5, max: 2, step: 0.02, value: 1.0 },
    ]);
    this.reset();
  }
  reset() {
    this.x = 0.9;
    this.v = 0;
    this.t = 0;
    this.trail = [];
    this.phase = [];
  }
  frame(dt) {
    if (this.pointer.down && this.pointer.inside) {
      this.x = (this.pointer.x - 0.5) * 2.6;
      this.v = 0;
      this.trail.length = 0;
      this.phase.length = 0;
    }
    const { zeta, drive, omega0 } = this.params;
    const w0 = omega0 * 4.6;
    const wd = drive * 4.6;
    const steps = 6;
    for (let i = 0; i < steps; i++) {
      const h = dt / steps;
      const acc = -2 * zeta * w0 * this.v - w0 * w0 * this.x + Math.cos(this.t * wd) * (drive > 0 ? 2.2 : 0);
      this.v += acc * h;
      this.x += this.v * h;
      this.t += h;
    }
    const { ctx, w, h } = this;
    this.clear('#050a18');

    const axisY = h * 0.5;
    const midX = w * 0.5;
    const amp = Math.min(w, h) * 0.3;
    const scaleX = Math.min(w, h) * 0.42;

    // 坐标轴
    ctx.strokeStyle = 'rgba(120,170,255,0.16)';
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(0, axisY);
    ctx.lineTo(w, axisY);
    ctx.stroke();
    ctx.beginPath();
    ctx.moveTo(midX, 0);
    ctx.lineTo(midX, h);
    ctx.stroke();
    ctx.fillStyle = 'rgba(150,190,255,0.35)';
    ctx.font = '500 11px ui-monospace, monospace';
    ctx.fillText('x', w - 14, axisY - 8);
    ctx.fillText('t', midX + 8, 14);

    // 位形轨迹
    this.trail.push([this.t, this.x]);
    if (this.trail.length > 900) this.trail.shift();
    ctx.globalCompositeOperation = 'lighter';
    ctx.lineWidth = 2;
    ctx.strokeStyle = 'rgba(34,211,238,0.85)';
    ctx.beginPath();
    for (let i = 0; i < this.trail.length; i++) {
      const [tt, xx] = this.trail[i];
      const px = midX + (tt - this.t) * scaleX * 0.5;
      const py = axisY - xx * amp * 0.62;
      if (i === 0) ctx.moveTo(px, py);
      else ctx.lineTo(px, py);
    }
    ctx.stroke();

    // 共振响应曲线（振幅-频率）
    const gx = w * 0.06;
    const gy = h * 0.94;
    const gw = w * 0.3;
    const gh = h * 0.34;
    ctx.strokeStyle = 'rgba(120,170,255,0.18)';
    ctx.lineWidth = 1;
    ctx.strokeRect(gx, gy - gh, gw, gh);
    ctx.beginPath();
    for (let i = 0; i <= 60; i++) {
      const r = (i / 60) * 3;
      const denom = Math.sqrt(Math.pow(1 - r * r, 2) + Math.pow(2 * zeta * r, 2)) || 1e-4;
      const A = 1 / denom;
      const px = gx + (i / 60) * gw;
      const py = gy - clamp(A / 6, 0, 1) * gh;
      if (i === 0) ctx.moveTo(px, py);
      else ctx.lineTo(px, py);
    }
    ctx.strokeStyle = 'rgba(139,92,246,0.85)';
    ctx.lineWidth = 1.6;
    ctx.stroke();
    // 当前频率指示
    const cxg = gx + clamp(drive / 3, 0, 1) * gw;
    ctx.strokeStyle = 'rgba(240,192,96,0.9)';
    ctx.beginPath();
    ctx.moveTo(cxg, gy - gh);
    ctx.lineTo(cxg, gy);
    ctx.stroke();
    ctx.fillStyle = 'rgba(150,190,255,0.45)';
    ctx.fillText('共振曲线', gx, gy - gh - 6);
    ctx.globalCompositeOperation = 'source-over';

    // 振子本体（弹簧-质量）
    const bx = w * 0.78;
    const by = h * 0.32;
    const len = 70;
    ctx.strokeStyle = 'rgba(165,232,255,0.5)';
    ctx.lineWidth = 1.4;
    ctx.beginPath();
    const coils = 12;
    for (let i = 0; i <= coils * 4; i++) {
      const u = i / (coils * 4);
      const y = by - len + u * len * 1.2;
      const dir = i % 2 === 0 ? 1 : -1;
      ctx.lineTo(bx + dir * 9 * Math.sin(u * Math.PI), y);
    }
    ctx.stroke();
    const my = by + clamp(-this.x * 0.42, -0.5, 0.5) * len * 1.6 + 12;
    const g2 = ctx.createRadialGradient(bx, my, 0, bx, my, 26);
    g2.addColorStop(0, 'rgba(255,255,255,0.95)');
    g2.addColorStop(0.4, 'rgba(34,211,238,0.55)');
    g2.addColorStop(1, 'rgba(0,0,0,0)');
    ctx.fillStyle = g2;
    ctx.beginPath();
    ctx.arc(bx, my, 26, 0, TAU);
    ctx.fill();
  }
}
