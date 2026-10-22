/* =============================================================================
 * event-bus.js —— 极简发布订阅 + rAF 主循环
 * 模块之间只通过事件通信，保证松耦合、可单独替换。
 * ========================================================================== */
export class EventBus {
  constructor() {
    this.map = new Map();
  }
  on(type, fn) {
    if (!this.map.has(type)) this.map.set(type, new Set());
    this.map.get(type).add(fn);
    return () => this.off(type, fn);
  }
  once(type, fn) {
    const off = this.on(type, (payload) => {
      off();
      fn(payload);
    });
    return off;
  }
  off(type, fn) {
    const set = this.map.get(type);
    if (set) set.delete(fn);
  }
  emit(type, payload) {
    const set = this.map.get(type);
    if (!set) return;
    for (const fn of [...set]) {
      try {
        fn(payload);
      } catch (err) {
        console.error(`[bus:${type}]`, err);
      }
    }
  }
}

export const bus = new EventBus();

/* -----------------------------------------------------------------------------
 * Loop —— 统一 rAF 循环：基于真实时间的 dt，页面隐藏时自动暂停
 * -------------------------------------------------------------------------- */
export class Loop {
  constructor({ fps = 0, onFrame, maxDt = 1 / 8 }) {
    this.onFrame = onFrame;
    this.fps = fps;
    this.maxDt = maxDt; // 单帧最大步长：太小会让低帧率设备上的动画整体变慢
    this.running = false;
    this.time = 0;
    this.frames = 0;
    this.fpsAvg = 60;
    this._last = 0;
    this._acc = 0;
    this._tick = this._tick.bind(this);
    this._onVisibility = () => (document.hidden ? this.stop() : this.start());
    document.addEventListener('visibilitychange', this._onVisibility);
  }
  start() {
    if (this.running) return;
    this.running = true;
    this._last = performance.now();
    this._acc = 0;
    this._raf = requestAnimationFrame(this._tick);
  }
  stop() {
    this.running = false;
    cancelAnimationFrame(this._raf);
  }
  _tick(now) {
    if (!this.running) return;
    this._raf = requestAnimationFrame(this._tick);
    let dt = (now - this._last) / 1000;
    this._last = now;
    if (!isFinite(dt) || dt <= 0) return;
    dt = Math.min(dt, this.maxDt); // 掉帧时钳制，避免物理爆炸
    if (this.fps > 0) {
      this._acc += dt;
      const step = 1 / this.fps;
      if (this._acc < step) return;
      dt = this._acc;
      this._acc = 0;
    }
    this.time += dt;
    this.frames++;
    this.fpsAvg += (1 / dt - this.fpsAvg) * 0.06;
    this.onFrame(dt, this.time);
  }
}
