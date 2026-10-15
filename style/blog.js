/* blog.js —— 博客前台交互：公式渲染、图片灯箱、目录、返回顶部、列表筛选
   依赖：style/vendor/katex/katex.min.js、style/vendor/katex/auto-render.min.js（同目录）
   约定：页面中数学用 <span class="math-inline">TEX</span> / <span class="math-block">TEX</span> 输出，
        数据以 textContent 形式保存，脚本读取后用 KaTeX 渲染。 */
(function () {
  'use strict';

  var KATEX_OPTS = {
    throwOnError: false,
    errorColor: '#A8332B',
    strict: false,
    trust: false,
    macros: {
      '\\bvec': '\\overrightarrow{#1}',
      '\\D': '\\Delta',
      '\\R': '\\mathbb{R}'
    }
  };

  /* ---------------- 公式渲染 ---------------- */
  function renderMath(root) {
    if (typeof window.katex === 'undefined') {
      document.documentElement.classList.add('no-katex');
      return { ok: 0, fail: 0, skipped: true };
    }
    var ok = 0, fail = 0;
    var blocks = root.querySelectorAll('.math-block, .math-inline');
    Array.prototype.forEach.call(blocks, function (el) {
      if (el.dataset.rendered === '1') return;
      var tex = el.textContent;
      var display = el.classList.contains('math-block');
      try {
        window.katex.render(tex, el, Object.assign({ displayMode: display }, KATEX_OPTS));
        el.dataset.rendered = '1';
        if (el.querySelector('.katex-error')) { fail++; el.classList.add('has-error'); }
        else { ok++; }
      } catch (err) {
        fail++;
        el.classList.add('has-error');
        el.textContent = String(err && err.message ? err.message : err);
      }
    });
    return { ok: ok, fail: fail, skipped: false };
  }

  /* ---------------- 图片灯箱 ---------------- */
  function initLightbox() {
    var zoomables = document.querySelectorAll('.gallery-grid img, figure img[data-zoom], .zoomable');
    if (!zoomables.length) return;
    var box = document.createElement('div');
    box.className = 'lightbox';
    box.innerHTML = '<button class="lightbox-close" type="button" aria-label="关闭">✕</button>' +
                    '<img alt=""/><div class="lightbox-cap"></div>';
    document.body.appendChild(box);
    var img = box.querySelector('img');
    var cap = box.querySelector('.lightbox-cap');

    function close() { box.classList.remove('is-open'); document.body.style.overflow = ''; }
    function open(src, alt) {
      img.src = src; img.alt = alt || '';
      cap.textContent = alt || '';
      box.classList.add('is-open');
      document.body.style.overflow = 'hidden';
    }
    Array.prototype.forEach.call(zoomables, function (el) {
      el.style.cursor = 'zoom-in';
      el.addEventListener('click', function () {
        var fig = el.closest('figure');
        var text = el.getAttribute('alt') || '';
        if (!text && fig) {
          var fc = fig.querySelector('figcaption');
          if (fc) text = fc.textContent.trim();
        }
        open(el.currentSrc || el.src, text);
      });
    });
    box.addEventListener('click', function (e) {
      if (e.target === box || e.target.classList.contains('lightbox-close')) close();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') close();
    });
  }

  /* ---------------- 代码复制 ---------------- */
  function initCopyButtons() {
    Array.prototype.forEach.call(document.querySelectorAll('.code-copy'), function (btn) {
      btn.addEventListener('click', function () {
        var panel = btn.closest('.code-panel');
        var code = panel && panel.querySelector('code');
        if (!code) return;
        var text = code.textContent;
        var done = function () {
          var old = btn.textContent;
          btn.textContent = '已复制';
          setTimeout(function () { btn.textContent = old; }, 1400);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(text).then(done, function () {});
        } else {
          var ta = document.createElement('textarea');
          ta.value = text; document.body.appendChild(ta); ta.select();
          try { document.execCommand('copy'); done(); } catch (e) {}
          document.body.removeChild(ta);
        }
      });
    });
  }

  /* ---------------- 目录高亮 ---------------- */
  function initToc() {
    var toc = document.querySelector('.toc');
    if (!toc) return;
    var links = Array.prototype.slice.call(toc.querySelectorAll('a[href^="#"]'));
    if (!links.length) return;
    var targets = links.map(function (a) {
      return document.getElementById(decodeURIComponent(a.getAttribute('href').slice(1)));
    }).filter(Boolean);
    if (!targets.length) return;

    function sync() {
      var best = 0;
      var y = window.scrollY + 120;
      targets.forEach(function (t, i) { if (t.offsetTop <= y) best = i; });
      links.forEach(function (a, i) { a.style.color = i === best ? 'var(--pine)' : ''; });
    }
    window.addEventListener('scroll', sync, { passive: true });
    sync();
  }

  /* ---------------- 返回顶部 ---------------- */
  function initToTop() {
    var btn = document.querySelector('.to-top');
    if (!btn) return;
    function sync() { btn.classList.toggle('is-visible', window.scrollY > 600); }
    window.addEventListener('scroll', sync, { passive: true });
    btn.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: 'smooth' }); });
    sync();
  }

  /* ---------------- 首页筛选 ---------------- */
  function initFilters() {
    var bar = document.querySelector('.filter-bar');
    if (!bar) return;
    var cards = Array.prototype.slice.call(document.querySelectorAll('.card'));
    var buttons = Array.prototype.slice.call(bar.querySelectorAll('button[data-filter]'));
    var counter = document.querySelector('.filter-count');

    function apply(filter) {
      var shown = 0;
      cards.forEach(function (card) {
        var style = card.getAttribute('data-style') || '';
        var keep = filter === 'all' || style === filter;
        card.classList.toggle('hidden', !keep);
        if (keep) shown++;
      });
      buttons.forEach(function (b) {
        b.setAttribute('aria-pressed', String(b.getAttribute('data-filter') === filter));
      });
      if (counter) counter.textContent = shown + ' 篇';
    }
    buttons.forEach(function (b) {
      b.addEventListener('click', function () { apply(b.getAttribute('data-filter')); });
    });
    var initial = bar.getAttribute('data-initial') || 'all';
    // 支持 index.html?style=math 直达某一类
    try {
      var q = new URLSearchParams(window.location.search).get('style');
      if (q && buttons.some(function (b) { return b.getAttribute('data-filter') === q; })) initial = q;
    } catch (e) {}
    apply(initial);
  }

  /* ---------------- 启动 ---------------- */
  function boot() {
    renderMath(document);
    initLightbox();
    initCopyButtons();
    initToc();
    initToTop();
    initFilters();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
