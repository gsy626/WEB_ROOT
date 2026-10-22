/* =============================================================================
 * ui.js —— 通用界面行为：滚动显现、导航、进度条、指针事件归一化
 * ========================================================================== */
import { bus } from '../core/event-bus.js';

/** 元素进入视口时添加 .is-in 触发 CSS 过渡 */
export function initReveal(root = document) {
  const items = root.querySelectorAll('[data-reveal]');
  const io = new IntersectionObserver(
    (entries) => {
      for (const e of entries) {
        if (!e.isIntersecting) continue;
        const el = e.target;
        const delay = Number(el.dataset.revealDelay || 0);
        setTimeout(() => el.classList.add('is-in'), delay);
        io.unobserve(el);
      }
    },
    { rootMargin: '0px 0px -12% 0px', threshold: 0.12 }
  );
  items.forEach((el) => io.observe(el));
  return io;
}

/** 首屏元素依次入场（开场结束后调用） */
export function playHeroEnter(root = document) {
  const items = root.querySelectorAll('[data-enter]');
  items.forEach((el, i) => {
    const step = Number(el.dataset.enterStep || 0) || i;
    setTimeout(() => el.classList.add('is-in'), 120 + step * 110);
  });
}

/** 导航：滚动玻璃态、当前区块高亮、移动端展开 */
export function initNav() {
  const nav = document.querySelector('.nav');
  if (!nav) return;
  const links = [...nav.querySelectorAll('.nav-link')];
  const sentinel = document.querySelector('#hero');
  const io = new IntersectionObserver(
    (entries) => {
      nav.classList.toggle('is-stuck', !entries[0].isIntersecting);
    },
    { threshold: 0.35 }
  );
  if (sentinel) io.observe(sentinel);

  const sections = links
    .map((l) => document.querySelector(l.getAttribute('href')))
    .filter(Boolean);
  const active = new IntersectionObserver(
    (entries) => {
      for (const e of entries) {
        if (!e.isIntersecting) continue;
        const id = `#${e.target.id}`;
        links.forEach((l) => l.classList.toggle('is-active', l.getAttribute('href') === id));
      }
    },
    { threshold: 0.4 }
  );
  sections.forEach((s) => active.observe(s));

  // 平滑滚动（尊重 reduced motion）
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  links.forEach((l) =>
    l.addEventListener('click', (e) => {
      const target = document.querySelector(l.getAttribute('href'));
      if (!target) return;
      e.preventDefault();
      target.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
      bus.emit('nav:go', l.getAttribute('href'));
    })
  );
}

/** 顶部阅读进度条 */
export function initScrollProgress() {
  const bar = document.querySelector('.scroll-progress__bar');
  if (!bar) return;
  let raf = 0;
  const update = () => {
    raf = 0;
    const h = document.documentElement.scrollHeight - innerHeight;
    const p = h > 0 ? clamp01(scrollY / h) : 0;
    bar.style.transform = `scaleX(${p.toFixed(4)})`;
  };
  addEventListener(
    'scroll',
    () => {
      if (!raf) raf = requestAnimationFrame(update);
    },
    { passive: true }
  );
  update();
}

const clamp01 = (v) => (v < 0 ? 0 : v > 1 ? 1 : v);

/** 指针 / 触摸统一归一化到 [-1, 1] */
export function initPointer(onMove) {
  const state = { x: 0, y: 0, active: false };
  const handle = (cx, cy) => {
    state.x = (cx / innerWidth) * 2 - 1;
    state.y = (cy / innerHeight) * 2 - 1;
    state.active = true;
    onMove?.(state.x, state.y, state);
  };
  addEventListener('pointermove', (e) => handle(e.clientX, e.clientY), { passive: true });
  addEventListener('pointerdown', (e) => handle(e.clientX, e.clientY), { passive: true });
  addEventListener('pointerleave', () => {
    state.active = false;
    onMove?.(0, 0, state);
  });
  addEventListener(
    'touchmove',
    (e) => {
      const t = e.touches[0];
      if (t) handle(t.clientX, t.clientY);
    },
    { passive: true }
  );
  return state;
}

/** 平滑锚点：任意 href="#id" 的按钮 */
export function initAnchorButtons(root = document) {
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  root.querySelectorAll('a[href^="#"]').forEach((a) => {
    if (a.classList.contains('nav-link')) return;
    a.addEventListener('click', (e) => {
      const t = document.querySelector(a.getAttribute('href'));
      if (!t) return;
      e.preventDefault();
      t.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
    });
  });
}
