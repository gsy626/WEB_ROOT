/* =============================================================================
 * timeline.js —— 数理演进时间轴
 * 左侧进度脊线随滚动点亮，卡片按领域着色（数学 / 物理 / 交汇），
 * 卡片上的公式由 mathtext 就地排版。
 * ========================================================================== */
import { TIMELINE } from '../data/sections.js';
import { clamp, damp, esc } from '../core/utils.js';

export function renderTimeline(root = document.querySelector('#timeline')) {
  if (!root) return;
  const list = root.querySelector('.timeline__list');
  list.innerHTML = TIMELINE.map((item, i) => `
    <li class="tl-item tl-item--${item.field}" data-reveal data-reveal-delay="${Math.min(i * 60, 240)}">
      <span class="tl-node" aria-hidden="true">
        <span class="tl-node__ring"></span>
        <span class="tl-node__core"></span>
      </span>
      <article class="tl-card">
        <header class="tl-card__head">
          <span class="tl-year">${esc(item.year)}</span>
          <span class="tl-field">${esc(item.fieldLabel)}</span>
        </header>
        <h3 class="tl-card__title">${esc(item.title)}</h3>
        <div class="tl-formula" data-tex="${esc(item.formula)}"></div>
        <p class="tl-card__body">${esc(item.body)}</p>
        <ul class="tl-points">
          ${item.points.map((p) => `<li>${esc(p)}</li>`).join('')}
        </ul>
        <footer class="tl-tags">
          ${item.tags.map((t) => `<span class="tag">${esc(t)}</span>`).join('')}
        </footer>
      </article>
    </li>
  `).join('');

  const fill = root.querySelector('.timeline__fill');
  const items = [...list.querySelectorAll('.tl-item')];

  // 脊线点亮进度
  let raf = 0;
  const update = () => {
    raf = 0;
    const rect = root.getBoundingClientRect();
    const mid = innerHeight * 0.5;
    const p = clamp((mid - rect.top) / Math.max(1, rect.height), 0, 1);
    if (fill) fill.style.transform = `scaleY(${p.toFixed(4)})`;
    items.forEach((el) => {
      const r = el.getBoundingClientRect();
      el.classList.toggle('is-passed', r.top + r.height * 0.4 < mid);
    });
  };
  addEventListener('scroll', () => (raf ||= requestAnimationFrame(update)), { passive: true });
  addEventListener('resize', update, { passive: true });
  update();

  // 卡片轻微的 3D 倾斜（克制的交互质感）
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (!reduce && matchMedia('(pointer:fine)').matches) {
    list.querySelectorAll('.tl-card').forEach((card) => {
      let target = { x: 0, y: 0 };
      let cur = { x: 0, y: 0 };
      let running = false;
      const tick = () => {
        cur.x = damp(cur.x, target.x, 7, 1 / 60);
        cur.y = damp(cur.y, target.y, 7, 1 / 60);
        card.style.transform = `perspective(900px) rotateY(${cur.x.toFixed(2)}deg) rotateX(${cur.y.toFixed(2)}deg)`;
        if (Math.abs(cur.x - target.x) < 0.01 && Math.abs(cur.y - target.y) < 0.01) {
          running = false;
          return;
        }
        requestAnimationFrame(tick);
      };
      card.addEventListener('pointermove', (e) => {
        const r = card.getBoundingClientRect();
        target.x = ((e.clientX - r.left) / r.width - 0.5) * 5.5;
        target.y = -((e.clientY - r.top) / r.height - 0.5) * 4.5;
        if (!running) {
          running = true;
          requestAnimationFrame(tick);
        }
      });
      card.addEventListener('pointerleave', () => {
        target = { x: 0, y: 0 };
        if (!running) {
          running = true;
          requestAnimationFrame(tick);
        }
      });
    });
  }
}
