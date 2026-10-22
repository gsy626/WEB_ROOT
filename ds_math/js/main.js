/* =============================================================================
 * main.js —— 编排：渲染循环 / 开场联动 / 站点装配
 * 单一入口，所有模块在此接线，模块之间不互相依赖。
 * ========================================================================== */
import { CFG } from './core/config.js';
import { bus } from './core/event-bus.js';
import { Loop } from './core/event-bus.js';
import { Renderer } from './core/renderer.js';
import { perfTier, prefersReducedMotion, clamp } from './core/utils.js';
import { bakeFormulas, typeset } from './core/mathtext.js';
import { INTRO_FORMULAS } from './data/formulas.js';
import { ABOUT, FOOTER } from './data/sections.js';
import { Vortex } from './canvas/vortex.js';
import { FX } from './canvas/fx.js';
import { Intro } from './intro/intro.js';
import {
  initReveal, playHeroEnter, initNav, initScrollProgress, initPointer, initAnchorButtons,
} from './site/ui.js';
import { renderHero } from './site/hero.js';
import { renderTimeline } from './site/timeline.js';
import { renderLab } from './site/toys.js';

const doc = document.documentElement;
doc.classList.add('js');

/* ------------------------------------------------------------ 视口锁定 ---- */
const lockScroll = (locked) => {
  doc.classList.toggle('is-locked', locked);
  if (locked) window.scrollTo(0, 0);
};

/* --------------------------------------------------------------- 主流程 -- */
function boot() {
  const canvas = document.querySelector('#bg');
  if (!canvas) return;

  const tier = perfTier();
  const reduce = prefersReducedMotion();

  const renderer = new Renderer(canvas, { dprMax: tier > 0.8 ? 1.85 : 1.35, alphaFade: null });
  const vortex = new Vortex(renderer, CFG.vortex, {});
  const fx = new FX(renderer, vortex);
  // 关键：Renderer 构造时视口可能尚未测量，且窗口尺寸变化必须同步到投影
  vortex.resize();
  renderer.onResize = () => {
    vortex.resize();
    fx.resize();
  };
  const quality = reduce ? 0 : tier > 0.9 ? 2 : 1;
  fx.setQuality(quality);
  vortex.setTier(clamp(tier, 0.5, 1));

  /* ---------------- 站点装配（先出结构，再烘焙纹理） ---------------- */
  renderHero();
  renderTimeline();
  renderLab();
  renderAbout();
  typeset();
  initNav();
  initScrollProgress();
  initAnchorButtons();
  initPointer((nx, ny) => vortex.pointer(nx, ny));
  initReveal();

  /* ---------------- 开屏演出 ---------------- */
  const introRoot = document.querySelector('#intro');
  const intro = new Intro({
    root: introRoot,
    vortex,
    fx,
    onDone: () => {
      lockScroll(false);
      introRoot.classList.add('is-gone');
      document.body.classList.add('is-revealed');
      playHeroEnter();
      setTimeout(() => introRoot.setAttribute('hidden', ''), 1400);
    },
  });

  /* ---------------- 纹理烘焙（字体就绪后） ---------------- */
  const bakeHost = document.querySelector('#bake');
  const startBake = () => {
    bakeFormulas(INTRO_FORMULAS, bakeHost, { fontSize: 30 })
      .then((list) => {
        vortex.setGlyphs(list.map((l) => l.canvas));
        bus.emit('assets:ready');
      })
      .catch((err) => {
        console.warn('[bake] 公式纹理烘焙失败，使用空漩涡', err);
        vortex.setGlyphs([]);
      });
  };
  if (document.fonts?.ready) document.fonts.ready.then(() => setTimeout(startBake, 30));
  else setTimeout(startBake, 120);

  /* ---------------- 渲染循环 ---------------- */
  const loop = new Loop({
    fps: quality === 2 ? 0 : 45,
    maxDt: 1 / 8, // 低帧率设备上的单帧步长上限
    onFrame: (dt) => {
      vortex.update(dt);
      intro.update(dt);
      vortex.draw();
      fx.captureFrame();
      fx.compose(dt);
    },
  });
  loop.start();

  /* ---------------- 自适应降级：连续低帧率则削减后处理 ---------------- */
  let lowFrames = 0;
  setInterval(() => {
    if (loop.fpsAvg < 40) {
      lowFrames++;
      if (lowFrames >= 3 && fx.quality > 0) {
        fx.setQuality(fx.quality - 1);
        loop.fps = fx.quality === 2 ? 0 : 45;
        lowFrames = 0;
        bus.emit('perf:downgrade', { quality: fx.quality, fps: Math.round(loop.fpsAvg) });
      }
    } else {
      lowFrames = 0;
    }
  }, 2000);

  /* ---------------- 其它接线 ---------------- */
  initPointerFx();
  bus.on('intro:done', () => bus.emit('site:ready'));
  lockScroll(!reduce);

  // 调试入口
  window.__DS1__ = { vortex, fx, renderer, loop, intro, bus, CFG };
}

/* --------------------------------------------------------- 关于 / 页脚 --- */
function renderAbout() {
  const about = document.querySelector('#about');
  if (about) {
    about.querySelector('.about__inner').innerHTML = `
      <div class="about__text">
        <h2 class="section-title" data-reveal>${ABOUT.title}</h2>
        ${ABOUT.paragraphs.map((p, i) => `<p data-reveal data-reveal-delay="${80 + i * 70}">${p}</p>`).join('')}
      </div>
      <div class="about__stats" data-reveal>
        ${ABOUT.stats
          .map(
            (s) => `<div class="stat"><span class="stat__value" data-count="${s.value}">${s.value}</span><span class="stat__label">${s.label}</span></div>`
          )
          .join('')}
      </div>`;
  }
  const footer = document.querySelector('.footer');
  if (footer) {
    footer.innerHTML = `
      <p class="footer__line">${FOOTER.line}</p>
      <ul class="footer__tags">${FOOTER.tags.map((t) => `<li>${t}</li>`).join('')}</ul>
      <p class="footer__note">${FOOTER.note} · © ${new Date().getFullYear()}</p>`;
  }
}

/* ------------------------------------------------ 指针跟随的萤火微光 ---- */
function initPointerFx() {
  if (prefersReducedMotion() || !matchMedia('(pointer:fine)').matches) return;
  const dot = document.createElement('div');
  dot.className = 'cursor-halo';
  document.body.appendChild(dot);
  let x = innerWidth / 2;
  let y = innerHeight / 2;
  let cx = x;
  let cy = y;
  addEventListener('pointermove', (e) => {
    x = e.clientX;
    y = e.clientY;
  }, { passive: true });
  const tick = () => {
    cx += (x - cx) * 0.14;
    cy += (y - cy) * 0.14;
    dot.style.transform = `translate3d(${cx.toFixed(1)}px, ${cy.toFixed(1)}px, 0)`;
    requestAnimationFrame(tick);
  };
  requestAnimationFrame(tick);
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
  boot();
}
