/*
 * katex-render.js —— 全站唯一的一段 JavaScript
 *
 * 为什么需要 JS：KaTeX 只有 JavaScript 实现，没有 PHP 版本。
 * 页面结构、导航、评论、提交全部由 PHP 完成，这里只做一件事：
 * 把 PHP 输出的 <... class="qd-math" data-tex="..."> 交给 KaTeX 渲染。
 *
 * 如果脚本没加载成功，页面上会保留原始 TeX 文本，内容不会丢。
 */
(function () {
  'use strict';

  if (typeof window.katex === 'undefined') {
    return;                      // 保留 qd-tex-src 里的原始 TeX 作为回退
  }

  var macros = window.QD_KATEX_MACROS || {};
  var nodes = document.querySelectorAll('.qd-math[data-tex]');

  for (var i = 0; i < nodes.length; i++) {
    var el = nodes[i];
    var tex = el.getAttribute('data-tex') || '';
    if (tex.trim() === '') {
      continue;
    }
    try {
      window.katex.render(tex, el, {
        displayMode: el.getAttribute('data-display') === '1',
        macros: macros,
        throwOnError: false,     // 出错时用红色显示原式，而不是整页崩掉
        errorColor: '#c0392b',
        strict: false,           // 允许 \text{中文}
        trust: false,
        output: 'htmlAndMathml'
      });
    } catch (err) {
      /* 保留原始 TeX */
    }
  }
})();
