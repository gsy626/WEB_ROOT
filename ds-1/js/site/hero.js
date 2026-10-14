/* =============================================================================
 * hero.js —— 首屏渲染 + 视差
 * 内容来自 data/sections.js，HTML 里只留容器。
 * ========================================================================== */
import { HERO } from '../data/sections.js';
import { bus } from '../core/event-bus.js';
import { clamp, esc } from '../core/utils.js';

export function renderHero(root = document.querySelector('#hero')) {
  if (!root) return null;
  const stack = root.querySelector('.hero-stack');
  stack.innerHTML = `
    <p class="hero-kicker" data-enter data-enter-step="0">
      <span class="hero-kicker__line"></span>${esc(HERO.kicker)}<span class="hero-kicker__line"></span>
    </p>
    <h1 class="hero-title" data-enter data-enter-step="1">
      <span class="hero-title__cn">${esc(HERO.title)}</span>
      <span class="hero-title__glow" aria-hidden="true">${esc(HERO.title)}</span>
    </h1>
    <p class="hero-sub" data-enter data-enter-step="2">${esc(HERO.subtitle)}</p>
    <p class="hero-lead" data-enter data-enter-step="3">${esc(HERO.lead)}</p>
    <div class="hero-actions" data-enter data-enter-step="4">
      ${HERO.actions
        .map(
          (a) =>
            `<a class="btn btn--${a.kind}" href="${a.href}"><span>${esc(a.label)}</span><i class="btn__sheen"></i></a>`
        )
        .join('')}
    </div>
  `;

  const hint = root.querySelector('.scroll-hint');
  if (hint) hint.innerHTML = `<span class="scroll-hint__text">${esc(HERO.scrollHint)}</span><i class="scroll-hint__arrow"></i>`;

  // 视差：首屏随滚动轻微上移 + 淡出
  let raf = 0;
  const onScroll = () => {
    if (raf) return;
    raf = requestAnimationFrame(() => {
      raf = 0;
      const rect = root.getBoundingClientRect();
      const p = clamp(-rect.top / Math.max(1, rect.height), 0, 1);
      stack.style.transform = `translate3d(0, ${(-p * 70).toFixed(1)}px, 0)`;
      stack.style.opacity = String(clamp(1 - p * 1.45, 0, 1));
      stack.style.filter = `blur(${(p * 5).toFixed(2)}px)`;
      if (hint) hint.style.opacity = String(clamp(1 - p * 3, 0, 1));
      bus.emit('hero:parallax', p);
    });
  };
  addEventListener('scroll', onScroll, { passive: true });
  onScroll();
  return { stack, hint, onScroll };
}
