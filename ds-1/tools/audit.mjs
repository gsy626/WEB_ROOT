#!/usr/bin/env node
/**
 * tools/audit.mjs —— 单会话完整巡检
 * 一次浏览器会话内：导航 → 分阶段等待 → 逐步滚动 → 每步截图 + 采集自检数据。
 * 避免反复 session.new（Firefox 每个实例只允许一个 BiDi 会话）。
 *
 * 用法: node tools/audit.mjs [url] [outDir]
 */
import { writeFile, mkdir } from 'node:fs/promises';
import path from 'node:path';
import { openSession, resolvePort, sleep, waitFor } from './bidi-client.mjs';

const URL_ = process.argv[2] || 'http://127.0.0.1:8791/index.html';
const OUT_DIR = process.argv[3] || '_shots';
const PORT = await resolvePort(process.argv[4]);
await mkdir(OUT_DIR, { recursive: true });

const { ws, bidi, sessionId } = await openSession(PORT);

/** 运行时日志（含网络错误、JS 异常） */
const errors = [];
const notes = [];
bidi.onEvent = (msg) => {
  if (msg.method === 'log.entryAdded') {
    const e = msg.params;
    const line = `[${e.level}/${e.type}] ${e.text}${e.source?.url ? ` @ ${e.source.url}` : ''}`;
    if (e.level === 'error' || e.type === 'javascript') errors.push(line);
    else notes.push(line);
  }
};

// 注意：Firefox 只接受它已实现的 BiDi 事件名，未知事件会直接报 invalid argument
await bidi.send('session.subscribe', { events: ['log.entryAdded', 'browsingContext.load'] });

const viewport = { width: 1440, height: 900 };

const { context } = await bidi.send('browsingContext.create', { type: 'tab' });
await bidi
  .send('browsingContext.setViewport', { context, viewport })
  .catch((e) => errors.push(`[viewport] ${e.message}`));

await bidi.send('browsingContext.navigate', { context, url: URL_, wait: 'complete' });
await waitFor(bidi, context, 'window.__DS1__', { timeout: 25000 });

const DIAG = `JSON.stringify({
  appT: +(window.__DS1__?.intro?.t?.toFixed(1) ?? -1),
  introPhase: document.querySelector('#intro')?.dataset.phase,
  reveal: document.body.classList.contains('is-revealed'),
  texLeft: document.querySelectorAll('[data-tex]').length,
  mathEls: document.querySelectorAll('.math').length,
  tlFormulaHtml: (document.querySelector('.tl-formula')?.innerHTML || '').slice(0, 60),
  introOpacity: getComputedStyle(document.querySelector('#intro')).opacity,
  revealed: document.body.classList.contains('is-revealed'),
  canvas: (()=>{const c=document.querySelector('#bg');return c? c.width+'x'+c.height:'none'})(),
  glyphs: window.__DS1__?.vortex?.glyphs?.length ?? -1,
  assembled: +(window.__DS1__?.vortex?.assembled?.toFixed(3) ?? -1),
  warp: +(window.__DS1__?.vortex?.warp?.toFixed(2) ?? -1),
  streamCount: window.__DS1__?.vortex?.streamCount ?? -1,
  fps: Math.round(window.__DS1__?.loop?.fpsAvg ?? 0),
  quality: window.__DS1__?.fx?.quality,
  cards: document.querySelectorAll('.tl-card').length,
  labCanvas: [...document.querySelectorAll('.lab-canvas')].map(c=>c.width+'x'+c.height).join(','),
  mathNodes: document.querySelectorAll('.math').length,
  scrollY: Math.round(scrollY),
  docH: document.documentElement.scrollHeight
})`;

/** 采样主画布亮度直方图，判断漩涡是否真的在发光 */
const BRIGHT = `(() => {
  const src = document.querySelector('#bg');
  const w = 240, h = 150;
  const c = document.createElement('canvas');
  c.width = w; c.height = h;
  const x = c.getContext('2d');
  x.drawImage(src, 0, 0, w, h);
  const d = x.getImageData(0, 0, w, h).data;
  let sum = 0, max = 0, lit = 0;
  for (let i = 0; i < d.length; i += 4) {
    const l = (d[i] + d[i+1] + d[i+2]) / 3;
    sum += l;
    if (l > max) max = l;
    if (l > 40) lit++;
  }
  const n = d.length / 4;
  return JSON.stringify({ mean: +(sum/n).toFixed(2), max: Math.round(max), litPct: +(lit/n*100).toFixed(2) });
})()`;

async function step(name, { wait = 0, js = '', shot = true, size = null } = {}) {
  if (size) await bidi.send('browsingContext.setViewport', { context, viewport: size }).catch(() => {});
  if (js) {
    try {
      await bidi.evaluate(js, context, false);
    } catch (e) {
      errors.push(`[step:${name}] ${e.message}`);
    }
  }
  if (wait) await sleep(wait);
  let diag = 'n/a';
  let bright = 'n/a';
  try {
    diag = (await bidi.evaluate(DIAG, context)).result?.value ?? 'null';
    bright = (await bidi.evaluate(BRIGHT, context)).result?.value ?? 'null';
  } catch (e) {
    errors.push(`[diag:${name}] ${e.message}`);
  }
  if (shot) {
    try {
      const s = await bidi.send('browsingContext.captureScreenshot', { context, origin: 'viewport' });
      await writeFile(path.join(OUT_DIR, `${name}.png`), Buffer.from(s.data, 'base64'));
    } catch (e) {
      errors.push(`[shot:${name}] ${e.message}`);
    }
  }
  console.log(`\n== ${name}\n   diag:   ${diag}\n   canvas: ${bright}`);
}

// 无头软件渲染下 rAF 只有几帧/秒，开场动画要等很久；
// 这里先用真实代码路径（intro.skip()）跳到结尾，再验证站点各区块。
await step('01-wake', { wait: 2500 });
await step('02-vortex-early', { wait: 4500 });
await step('03-vortex-mid', { wait: 4500 });
await step('04-vortex-late', { wait: 3000 });
await step('05-intro-skip', {
  js: "window.__DS1__.intro.skip(); 'skipped'",
  wait: 5000,
});
await step('06-hero', { wait: 3000 });
await step('07-timeline', { js: "document.querySelector('#timeline').scrollIntoView({block:'start'});" , wait: 1800 });
await step('08-lab', { js: "document.querySelector('#lab').scrollIntoView({block:'start'});", wait: 3000 });
await step('09-lab-pendulum', {
  js: "document.querySelector('#toy-pendulum').scrollIntoView({block:'center'}); 'ok'",
  wait: 3000,
});
await step('10-lab-wave', {
  js: "document.querySelector('#toy-wave').scrollIntoView({block:'center'}); 'ok'",
  wait: 3000,
});
await step('11-about', { js: "document.querySelector('#about').scrollIntoView({block:'start'});", wait: 1800 });
await step('12-footer', { js: "window.scrollTo(0, document.body.scrollHeight);", wait: 1600 });
await step('13-formulas', {
  js: "document.querySelector('#timeline').scrollIntoView({block:'start'}); window.scrollBy(0,-40); 'ok'",
  wait: 1500,
  size: { width: 1440, height: 900 },
});
await step('14-mobile-timeline', {
  size: { width: 420, height: 860 },
  js: "window.scrollTo(0,0); document.querySelector('#timeline').scrollIntoView({block:'start'});",
  wait: 1800,
});
await step('15-mobile-hero', { size: { width: 420, height: 860 }, js: 'window.scrollTo(0,0);', wait: 1400 });
await step('16-back-to-hero', { size: { width: 1440, height: 900 }, js: 'window.scrollTo(0,0);', wait: 900, shot: false });

console.log('\n================ 错误汇总 ================');
console.log(errors.length ? [...new Set(errors)].join('\n') : '（无错误）');
console.log('\n================ 其它日志 ================');
console.log(notes.length ? [...new Set(notes)].slice(0, 20).join('\n') : '（无）');
console.log(`\nsession ${sessionId?.slice(0, 8)} / 截图目录 ${OUT_DIR}`);
ws.close();
process.exit(errors.length ? 1 : 0);
