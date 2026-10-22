/**
 * 把运行中页面的画布 / 元素导出为 PNG，用于判断「画布真的有内容」还是「合成器没画出来」。
 */
import { writeFile, mkdir } from 'node:fs/promises';
import { openSession, resolvePort, sleep, waitFor } from './bidi-client.mjs';

const URL_ = process.argv[2] || 'http://127.0.0.1:8791/index.html';
const OUT = process.argv[3] || '_shots/extract';
const PORT = await resolvePort(process.argv[4]);
await mkdir(OUT, { recursive: true });
const { ws, bidi } = await openSession(PORT);

const errors = [];
bidi.onEvent = (m) => {
  if (m.method === 'log.entryAdded' && m.params.level === 'error') errors.push(m.params.text);
};

const { context } = await bidi.send('browsingContext.create', { type: 'tab' });
await bidi.send('browsingContext.setViewport', { context, viewport: { width: 1440, height: 900 } });
await bidi.send('browsingContext.navigate', { context, url: URL_, wait: 'complete' });
await waitFor(bidi, context, 'window.__DS1__', { timeout: 25000 });

const probe = `(async () => {
  await new Promise(r => setTimeout(r, ${Number(process.argv[5] || 6000)}));
  const preset = ${JSON.stringify(process.argv[6] || '')};
  if (preset === 'nosink') {
    window.__DS1__.vortex.sinkGain = 0;   // 只看隧道与公式层
    window.__DS1__.fx.bloomGain = 0.4;
  }
  if (preset === 'nofx') { window.__DS1__.fx.quality = 0; window.__DS1__.fx.setQuality(0); }
  await new Promise(r => setTimeout(r, 900));
  const bg = document.querySelector('#bg');
  // 1) 主画布原始像素统计
  const tmp = document.createElement('canvas');
  tmp.width = 240; tmp.height = 150;
  const tx = tmp.getContext('2d');
  tx.drawImage(bg, 0, 0, 240, 150);
  const d = tx.getImageData(0, 0, 240, 150).data;
  let sum = 0, max = 0, lit = 0;
  for (let i = 0; i < d.length; i += 4) { const l = (d[i]+d[i+1]+d[i+2])/3; sum += l; if (l>max) max=l; if (l>40) lit++; }
  const n = d.length/4;
  // 2) 画布内部有内容 -> 导出为 PNG
  const b64 = bg.toDataURL('image/png');
  // 3) 元素几何
  const r = bg.getBoundingClientRect();
  const ir = document.querySelector('#intro').getBoundingClientRect();
  return JSON.stringify({
    raw: { mean: +(sum/n).toFixed(2), max: Math.round(max), litPct: +(lit/n*100).toFixed(2) },
    canvasRect: { w: r.width, h: r.height, top: r.top, left: r.left },
    introRect: { w: ir.width, h: ir.height },
    style: (() => { const s = getComputedStyle(bg); return { opacity: s.opacity, display: s.display, visibility: s.visibility, transform: s.transform, filter: s.filter }; })(),
    dataUrlLen: b64.length,
    png: b64
  });
})()`;

const r = await bidi.evaluate(probe, context);
const value = JSON.parse(r.result.value);
const { png, ...meta } = value;
await writeFile(`${OUT}/canvas-raw.png`, Buffer.from(png.split(',')[1], 'base64'));
console.log(JSON.stringify(meta, null, 2));

const shot = await bidi.send('browsingContext.captureScreenshot', { context, origin: 'viewport' });
await writeFile(`${OUT}/viewport.png`, Buffer.from(shot.data, 'base64'));

// 隐藏开屏层后再截一张，检查合成器是否遗漏 canvas 图层
await bidi.evaluate("document.querySelector('#intro').style.display='none'; 'ok'", context, false);
await sleep(1200);
const shot2 = await bidi.send('browsingContext.captureScreenshot', { context, origin: 'viewport' });
await writeFile(`${OUT}/viewport-no-intro.png`, Buffer.from(shot2.data, 'base64'));

// 隐藏 canvas 后再截一张，检查画布是否贡献了像素
await bidi.evaluate("document.querySelector('#bg').style.display='none'; 'ok'", context, false);
await sleep(800);
const shot3 = await bidi.send('browsingContext.captureScreenshot', { context, origin: 'viewport' });
await writeFile(`${OUT}/viewport-no-canvas.png`, Buffer.from(shot3.data, 'base64'));

console.log('errors:', errors.length ? errors.join('\n') : '(无)');
ws.close();
process.exit(0);
