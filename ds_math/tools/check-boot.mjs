#!/usr/bin/env node
/**
 * tools/check-boot.mjs —— 黑屏专项检查
 * 逐个打开目标页面，判定「用户是否会被永久挡在黑场之后」：
 *   1. 页面是否真的启动（window.__DS1__ / data-boot）
 *   2. 主画布是否有内容（像素均值 + 点亮比例）
 *   3. 开屏层是否最终退场（is-done / opacity）
 * 任何一个不达标就返回非 0。
 *
 * 用法: node tools/check-boot.mjs <url> [端口] [等待毫秒]
 */
import { openSession, resolvePort, sleep, waitFor } from './bidi-client.mjs';

const URL_ = process.argv[2] || 'http://127.0.0.1:8791/index.html';
const URLS = process.argv[2] && process.argv[2].includes(',') ? process.argv[2].split(',') : [URL_];
// 第 3 个参数是逗号分隔的 URL 列表或单个 URL；端口用 BIDI_PORT 环境变量传入
const PORT = await resolvePort(process.env.BIDI_PORT);
const WAIT = Number(process.argv[3] || 34000);

const { ws, bidi } = await openSession(PORT);
const errors = [];
bidi.onEvent = (m) => {
  if (m.method === 'log.entryAdded' && (m.params.level === 'error' || m.params.type === 'javascript')) {
    errors.push(`[${m.params.type}] ${m.params.text}`);
  }
};
// 一个浏览器实例只能有一个 BiDi 会话，因此所有页面共用它、只开一个新标签
const { context } = await bidi.send('browsingContext.create', { type: 'tab' });
await bidi.send('browsingContext.setViewport', { context, viewport: { width: 1440, height: 900 } });

const probe = `(() => {
  const bg = document.querySelector('#bg');
  let mean = -1, litPct = -1;
  if (bg && bg.width) {
    const t = document.createElement('canvas'); t.width = 240; t.height = 150;
    const x = t.getContext('2d');
    x.drawImage(bg, 0, 0, 240, 150);
    const d = x.getImageData(0, 0, 240, 150).data;
    let s = 0, lit = 0;
    for (let i = 0; i < d.length; i += 4) { const l = (d[i]+d[i+1]+d[i+2])/3; s += l; if (l > 24) lit++; }
    mean = +(s / (d.length / 4)).toFixed(2);
    litPct = +(lit / (d.length / 4) * 100).toFixed(2);
  }
  const intro = document.querySelector('#intro');
  return JSON.stringify({
    booted: !!window.__DS1__,
    bootFlag: document.documentElement.dataset.boot || null,
    introPhase: document.querySelector('#intro')?.dataset.phase || null,
    introDone: intro?.classList.contains('is-done') ?? null,
    introOpacity: intro ? +getComputedStyle(intro).opacity : null,
    revealed: document.body.classList.contains('is-revealed'),
    warnVisible: (() => {
      const w = document.querySelector('.intro__protocol-warn');
      return w ? getComputedStyle(w).display !== 'none' : null;
    })(),
    canvasMean: mean,
    canvasLitPct: litPct,
    canvasSize: bg ? bg.width + 'x' + bg.height : null,
  });
})()`;

let failed = 0;
for (const url of URLS) {
  errors.length = 0;
  await bidi.send('browsingContext.navigate', { context, url, wait: 'complete' });
  await sleep(WAIT);
  const r = await bidi.evaluate(probe, context);
  const info = JSON.parse(r.result.value);
  const problems = [];
  if (!info.booted) problems.push('脚本没有启动（window.__DS1__ 不存在）');
  if (info.warnVisible) problems.push('显示的是「请通过本地服务打开」提示');
  if (info.booted && info.canvasMean < 3) problems.push(`主画布几乎全黑（均值 ${info.canvasMean}）`);
  if (info.booted && info.introOpacity > 0.05) problems.push(`开屏层没有退场（opacity ${info.introOpacity}）`);

  console.log(`\n目标: ${url}`);
  console.log(JSON.stringify(info));
  if (errors.length) console.log('  控制台错误: ' + [...new Set(errors)].join(' | '));
  console.log(problems.length ? `  ✗ ${problems.join('；')}` : '  ✓ 启动正常、画面有内容、开屏已退场');
  if (problems.length || errors.length) failed++;
}
ws.close();
process.exit(failed ? 1 : 0);
