/* =============================================================================
 * intro.js —— 开屏演出：时空漩涡中的数理演化
 *
 * 阶段机：
 *   wake    信标亮起，符号自虚空汇聚
 *   vortex  漩涡全速旋转，叙事沿时间轴推进（前300 → 今日）
 *   burst   奇点爆发（白闪 + 冲击波）
 *   settle  漩涡退到背景，站点内容接管
 * ========================================================================== */
import { clamp, lerp, smoothstep, easeOutCubic, easeOutQuint, damp } from '../core/utils.js';
import { NARRATIVE } from '../core/config.js';
import { bus } from '../core/event-bus.js';

/** 阶段时长（秒）。全站时间单位统一为「秒」，避免 ms/s 混用。 */
const PHASES = [
  { name: 'wake', dur: 1.5 },
  { name: 'vortex', dur: 7.1 },
  { name: 'burst', dur: 1.3 },
  { name: 'settle', dur: 2.6 },
];
const TOTAL = PHASES.reduce((a, p) => a + p.dur, 0); // ≈ 11.8s

export class Intro {
  /**
   * @param {{
   *   root:HTMLElement, vortex:any, fx:any, onSkip?:(reason:string)=>void
   * }} opts
   */
  constructor({ root, vortex, fx, onDone }) {
    this.root = root;
    this.vortex = vortex;
    this.fx = fx;
    this.onDone = onDone;
    this.t = 0;
    this.phaseIndex = -1;
    this.phaseT = 0;
    this.phase = 'idle';
    this.done = false;
    this.narrIndex = -1;
    this.els = {
      year: root.querySelector('[data-intro="year"]'),
      label: root.querySelector('[data-intro="label"]'),
      text: root.querySelector('[data-intro="text"]'),
      progress: root.querySelector('[data-intro="progress"]'),
      skip: root.querySelector('[data-intro="skip"]'),
      hint: root.querySelector('[data-intro="hint"]'),
      canvas: root.querySelector('#intro-canvas'),
      narration: root.querySelector('.intro-narration'),
      title: root.querySelector('.intro-title'),
    };
    this._bindSkip();
  }

  _bindSkip() {
    const skip = () => this.skip();
    this._skipHandler = skip;
    window.addEventListener('keydown', (e) => {
      if (this.done) return;
      if (e.key === 'Escape' || e.key === 'Enter' || e.key === ' ') skip();
    });
    this.els.skip?.addEventListener('click', skip);
    this.root.addEventListener('wheel', skip, { passive: true });
    this.root.addEventListener('touchstart', skip, { passive: true });
  }

  skip() {
    if (this.done) return;
    const last = PHASES[PHASES.length - 1];
    this._toPhase(PHASES.length - 1);
    // 跳到 settle 阶段的后半段：让「退场」动画立刻发生但不显得突兀
    this.t = TOTAL - last.dur * 0.45;
    this.phaseT = last.dur * 0.55;
    this.vortex.glyphAlpha = 0.35;
    this.vortex.assembled = 0.55;
    this.vortex.expansion = Math.max(this.vortex.expansion, 0.5);
    this.vortex.flash = Math.max(this.vortex.flash, 0.6);
    bus.emit('intro:skip');
  }

  _toPhase(i) {
    if (i === this.phaseIndex) return;
    this.phaseIndex = i;
    this.phaseT = 0;
    this.phase = PHASES[i].name;
    bus.emit('intro:phase', { phase: this.phase, index: i });
    this.root.dataset.phase = this.phase;
  }

  /**
   * 看门狗：无论因为掉帧、后台标签页还是别的意外，
   * 都保证开屏不会把用户永久挡在黑场之后。
   * 策略：允许「按当前进度再走完约 4 秒」，但不少于 10 秒——
   * 真正的慢设备仍能放完开场，而卡死的情况十秒内必然解锁。
   */
  _armWatchdog() {
    if (this._watchdog || this.done) return;
    if (typeof setTimeout !== 'function') return;
    const delay = Math.max(10000, (TOTAL - (this.t || 0) + 4) * 1000);
    this._watchdog = setTimeout(() => {
      if (this.done) return;
      bus.emit('intro:watchdog', { t: this.t, delay });
      this.skip();
    }, delay);
  }

  update(dt) {
    if (this.done) return;
    this.t += dt;
    this.phaseT += dt;
    this._armWatchdog();
    const v = this.vortex;

    // 阶段推进
    let acc = 0;
    let idx = PHASES.length - 1;
    for (let i = 0; i < PHASES.length; i++) {
      acc += PHASES[i].dur;
      if (this.t < acc) {
        idx = i;
        break;
      }
    }
    this._toPhase(idx);
    const p = PHASES[idx];
    const k = clamp(this.phaseT / p.dur); // 阶段内进度 0..1

    switch (this.phase) {
      case 'wake':
        this._updateWake(k, dt);
        break;
      case 'vortex':
        this._updateVortex(k, dt);
        break;
      case 'burst':
        this._updateBurst(k, dt);
        break;
      case 'settle':
        this._updateSettle(k, dt);
        break;
    }

    // 进度条 & 叙事文字
    const prog = clamp(this.t / TOTAL);
    if (this.els.progress) this.els.progress.style.transform = `scaleX(${prog.toFixed(4)})`;
    this._updateNarration(prog);
    if (this.els.hint) {
      this.els.hint.style.opacity = clamp(1 - this.t * 0.9) * (this.phase === 'burst' ? 1 : 0.9);
    }
  }

  _updateWake(k, dt) {
    const v = this.vortex;
    const ease = easeOutQuint(k);
    v.assembled = ease;
    v.warp = lerp(0.06, 0.55, ease);
    v.tunnelK = lerp(1.9, 1.12, ease);
    v.converge = lerp(0.85, 0.62, ease);
    v.starAlpha = 0.25 + 0.75 * ease;
    v.glyphAlpha = clamp((k - 0.35) / 0.5);
    v.brightness = lerp(0.35, 0.9, ease);
    v.spinBoost = lerp(0.25, 0.05, ease);
    v.trail = lerp(0.42, 0.26, ease);
    this._spinUp = ease;
  }

  _updateVortex(k, dt) {
    const v = this.vortex;
    const ease = smoothstep(k);
    v.assembled = 1;
    v.warp = lerp(0.62, 1.25, ease);
    v.tunnelK = lerp(1.12, 1.0, ease);
    v.converge = lerp(0.62, 0.5, ease);
    v.starAlpha = 1;
    v.glyphAlpha = 1;
    // 呼吸式增亮，制造「能量聚积」的预感
    v.brightness = 1.18 + 0.12 * Math.sin(this.t * 2.1) + ease * 0.22;
    v.spinBoost = lerp(0.05, 0.42, ease * ease);
    v.trail = lerp(0.26, 0.18, ease);
    // 渐进的向心收缩
    v.converge = lerp(v.converge, 0.3, ease * 0.55);
    if (k > 0.86 && !this._preBurst) {
      this._preBurst = true;
      v.burst(0.35);
    }
  }

  _updateBurst(k, dt) {
    const v = this.vortex;
    if (!this._burstFired) {
      this._burstFired = true;
      v.burst(1);
      v.warp = 2.6;
      v.spinBoost = 1.15;
      v.glyphAlpha = 0.5;
      bus.emit('intro:burst');
    }
    const ease = easeOutCubic(k);
    v.brightness = lerp(1.25, 0.95, ease);
    v.warp = lerp(2.6, 0.7, easeOutQuint(k));
    v.spinBoost = lerp(1.15, 0.25, ease);
    v.converge = lerp(0.3, 0.72, ease);
    v.assembled = lerp(1, 0.86, ease);
    v.glyphAlpha = lerp(0.5, 0, ease);
    v.trail = lerp(0.18, 0.36, ease);
    // 白闪回落
    this.fx?.finishFlash(clamp(1 - k * 1.35) ** 1.6 * 0.9);
  }

  _updateSettle(k, dt) {
    const v = this.vortex;
    const ease = smoothstep(k);
    v.brightness = damp(v.brightness, 0.9, 2.2, dt);
    v.warp = damp(v.warp, 0.34, 2.0, dt);
    v.spinBoost = damp(v.spinBoost, 0.08, 2.0, dt);
    v.assembled = damp(v.assembled, 0.72, 1.6, dt);
    v.converge = damp(v.converge, 0.8, 1.6, dt);
    v.tunnelK = damp(v.tunnelK, 1.18, 1.6, dt);
    v.glyphAlpha = 0;
    v.starAlpha = lerp(1, 0.85, ease);
    if (k > 0.35 && !this._revealed) {
      this._revealed = true;
      this.finish();
    }
  }

  finish() {
    if (this.done) return;
    this.done = true;
    if (this._watchdog) {
      clearTimeout(this._watchdog);
      this._watchdog = null;
    }
    this.root.dataset.done = '1';
    this.root.classList.add('is-done');
    bus.emit('intro:done');
    this.onDone?.();
  }

  _updateNarration(prog) {
    // 叙事按时间轴插值：找到当前段落
    let idx = 0;
    for (let i = 0; i < NARRATIVE.length; i++) {
      if (prog >= NARRATIVE[i].at) idx = i;
    }
    if (idx === this.narrIndex) {
      // 段落内淡入
      const cur = NARRATIVE[idx];
      const next = NARRATIVE[idx + 1];
      const end = next ? next.at : 1.02;
      const local = clamp((prog - cur.at) / Math.max(0.001, (end - cur.at) * 0.45));
      const fade = Math.min(local * 2.4, 1) * (1 - clamp((prog - cur.at - (end - cur.at) * 0.82) / ((end - cur.at) * 0.18)) * 0.35);
      if (this.els.narration) this.els.narration.style.opacity = this.phase === 'settle' ? 0 : fade;
      return;
    }
    this.narrIndex = idx;
    const cur = NARRATIVE[idx];
    const el = this.els;
    if (el.year) {
      el.year.textContent = cur.year;
      el.year.animate?.(
        [{ opacity: 0, transform: 'translateY(14px)' }, { opacity: 1, transform: 'none' }],
        { duration: 520, easing: 'cubic-bezier(.19,1,.22,1)', fill: 'both' }
      );
    }
    if (el.label) {
      el.label.textContent = cur.label;
      el.label.animate?.(
        [{ opacity: 0 }, { opacity: 1 }],
        { duration: 700, easing: 'ease-out', fill: 'both' }
      );
    }
    if (el.text) {
      el.text.textContent = cur.text;
      el.text.animate?.(
        [
          { opacity: 0, filter: 'blur(10px)', transform: 'translateY(10px)' },
          { opacity: 1, filter: 'blur(0px)', transform: 'none' },
        ],
        { duration: 780, easing: 'cubic-bezier(.19,1,.22,1)', fill: 'both' }
      );
    }
    bus.emit('intro:narration', cur);
  }
}

export const INTRO_TOTAL = TOTAL; // 秒
export const INTRO_PHASES = PHASES;
