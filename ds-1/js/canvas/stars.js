/* =============================================================================
 * stars.js —— 星场 / 星际尘埃
 * 圆柱体 3D 点云，沿隧道轴向前进，差速自旋 = 时空拖曳感。
 * 为避免每帧上万次 Math.sin，用查找表 + 线性插值。
 * ========================================================================== */
import { TAU, makeRng, clamp } from '../core/utils.js';
import { radialGlow } from '../core/primitives.js';

const LAT = 2048;
const SIN = new Float32Array(LAT + 1);
for (let i = 0; i <= LAT; i++) SIN[i] = Math.sin((i / LAT) * TAU);

/** 快速 sin，输入任意弧度（查找表 + 线性插值） */
export function fastSin(x) {
  let t = (x % TAU) / TAU;
  if (t < 0) t += 1;
  const f = t * LAT;
  const i = f | 0;
  const frac = f - i;
  const a = SIN[i];
  const b = i >= LAT ? SIN[0] : SIN[i + 1];
  return a + (b - a) * frac;
}
export const fastCos = (x) => fastSin(x + TAU * 0.25);

export class Stars {
  constructor(vortex, { count = 700, seed = 991 } = {}) {
    this.vortex = vortex;
    this.sprites = [
      radialGlow(24, [[0, 'rgba(255,255,255,1)'], [0.35, 'rgba(190,225,255,0.45)'], [1, 'rgba(140,190,255,0)']]),
      radialGlow(24, [[0, 'rgba(255,255,255,1)'], [0.35, 'rgba(180,200,255,0.4)'], [1, 'rgba(120,140,255,0)']]),
      radialGlow(24, [[0, 'rgba(255,255,255,1)'], [0.3, 'rgba(255,230,200,0.42)'], [1, 'rgba(255,190,120,0)']]),
      radialGlow(24, [[0, 'rgba(255,255,255,1)'], [0.3, 'rgba(220,190,255,0.42)'], [1, 'rgba(160,120,255,0)']]),
    ];
    this.p = [];
    this.reset(count, seed);
  }

  reset(count, seed = 991) {
    const rng = makeRng(seed);
    const V = this.vortex;
    this.p = [];
    for (let i = 0; i < count; i++) {
      const r = Math.pow(rng(), 0.62) * V.depth * 0.96;
      this.p.push({
        r,
        a: rng() * TAU,
        z: rng() * V.depth,
        s: 0.3 + rng() * 1.2,
        sp: 0.5 + rng() * 1.0,
        tw: rng() * TAU,
        sprite: this.sprites[(rng() * this.sprites.length) | 0],
        dust: rng() < 0.42,
      });
    }
  }

  resize() {
    /* 位置以世界单位表示，随视口自动缩放，无需重建 */
  }

  update(dt) {
    const V = this.vortex;
    const v = V.speed;
    for (const s of this.p) {
      s.z -= v * s.sp * dt * V.warp;
      s.a += (V.spin * (0.16 + 900 / (s.r + 320)) + V.spinBoost) * dt;
      s.tw += dt * (1.4 + s.sp);
      if (s.z < 2) {
        s.z += V.depth;
        s.r = Math.pow(Math.random(), 0.62) * V.depth * 0.96;
        s.a = Math.random() * TAU;
      }
    }
  }

  draw(ctx) {
    const V = this.vortex;
    const alpha = V.starAlpha;
    if (alpha <= 0.003) return;
    const { cx, cy, min, focal } = V.view;
    const unit = min / 900;
    ctx.globalCompositeOperation = 'lighter';
    for (const s of this.p) {
      const z = s.z;
      if (z <= 12) continue;
      const persp = focal / (focal + z);
      if (persp < 0.06) continue;
      const rr = s.r * persp * V.tunnelK;
      const ang = s.a + z * 0.00022 * V.twist;
      const x = cx + fastCos(ang) * rr;
      const y = cy + fastSin(ang) * rr * 0.94;
      const twinkle = 0.55 + 0.45 * Math.sin(s.tw * 2.2);
      const size = s.s * (s.dust ? 9 : 3.2) * unit * persp * (s.dust ? 1 : 1 + (1 - persp) * 1.4);
      if (size < 0.35) continue;
      ctx.globalAlpha = clamp(alpha * twinkle * Math.pow(persp, 1.15) * (s.dust ? 0.28 : 0.95), 0, 1);
      ctx.drawImage(s.sprite, x - size / 2, y - size / 2, size, size);
    }
    ctx.globalAlpha = 1;
    ctx.globalCompositeOperation = 'source-over';
  }
}
