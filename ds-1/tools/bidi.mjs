#!/usr/bin/env node
/**
 * tools/bidi.mjs —— 用 Firefox 的 WebDriver BiDi 协议驱动无头浏览器
 * 纯 Node 实现最小 WebSocket 客户端，无第三方依赖。
 *
 * 用法:
 *   node tools/bidi.mjs <url> <out.png> [等待ms] [宽x高] [额外JS] [端口]
 *
 * 用于本地冒烟测试：截图 + 收集控制台错误 + 回读页面自检数据。
 */
import net from 'node:net';
import http from 'node:http';
import crypto from 'node:crypto';
import { readFile } from 'node:fs/promises';
import { writeFile } from 'node:fs/promises';

const [, , URL_ = 'http://127.0.0.1:8791/index.html', OUT = 'shot.png', WAIT = '5000', SIZE = '1440x900', EXTRA = '', PORT_ARG = ''] = process.argv;

/* ---------------------------------------------------- 最小 WebSocket 客户端 */
class WS {
  constructor(url) {
    const u = new URL(url);
    this.sock = net.connect(Number(u.port), u.hostname);
    this.buf = Buffer.alloc(0);
    this.frags = [];
    this.open = false;
    this.onmessage = () => {};
    this.ready = new Promise((res, rej) => {
      this.sock.on('connect', () => {
        const key = crypto.randomBytes(16).toString('base64');
        this.sock.write(
          `GET ${u.pathname}${u.search} HTTP/1.1\r\n` +
            `Host: ${u.host}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n` +
            `Sec-WebSocket-Key: ${key}\r\nSec-WebSocket-Version: 13\r\n\r\n`
        );
      });
      this.sock.on('error', rej);
      const onData = (d) => {
        this.buf = Buffer.concat([this.buf, d]);
        if (!this.open) {
          const i = this.buf.indexOf('\r\n\r\n');
          if (i < 0) return;
          this.open = true;
          this.buf = this.buf.subarray(i + 4);
          res();
        }
        this.parse();
      };
      this.sock.on('data', onData);
    });
  }
  parse() {
    while (this.buf.length >= 2) {
      const b0 = this.buf[0];
      const b1 = this.buf[1];
      const fin = (b0 & 0x80) !== 0;
      const op = b0 & 0x0f;
      const masked = (b1 & 0x80) !== 0;
      let len = b1 & 0x7f;
      let off = 2;
      if (len === 126) {
        if (this.buf.length < 4) return;
        len = this.buf.readUInt16BE(2);
        off = 4;
      } else if (len === 127) {
        if (this.buf.length < 10) return;
        len = Number(this.buf.readBigUInt64BE(2));
        off = 10;
      }
      const maskLen = masked ? 4 : 0;
      if (this.buf.length < off + maskLen + len) return;
      const mask = masked ? this.buf.subarray(off, off + 4) : null;
      const payload = Buffer.from(this.buf.subarray(off + maskLen, off + maskLen + len));
      if (mask) for (let i = 0; i < payload.length; i++) payload[i] ^= mask[i % 4];
      this.buf = this.buf.subarray(off + maskLen + len);
      if (op === 0x8) return this.sock.end();
      if (op === 0x9) {
        this.send(payload, 0xa);
        continue;
      }
      this.frags.push(payload);
      if (!fin) continue;
      const data = Buffer.concat(this.frags);
      this.frags = [];
      let json;
      try {
        json = JSON.parse(data.toString('utf8'));
      } catch {
        continue;
      }
      this.onmessage(json);
    }
  }
  send(payload, op = 0x1) {
    const data = Buffer.isBuffer(payload) ? payload : Buffer.from(payload);
    const mask = crypto.randomBytes(4);
    const masked = Buffer.from(data);
    for (let i = 0; i < masked.length; i++) masked[i] ^= mask[i % 4];
    let head;
    if (masked.length < 126) head = Buffer.from([0x80 | op, 0x80 | masked.length]);
    else if (masked.length < 65536) {
      head = Buffer.alloc(4);
      head[0] = 0x80 | op;
      head[1] = 0x80 | 126;
      head.writeUInt16BE(masked.length, 2);
    } else {
      head = Buffer.alloc(10);
      head[0] = 0x80 | op;
      head[1] = 0x80 | 127;
      head.writeBigUInt64BE(BigInt(masked.length), 2);
    }
    this.sock.write(Buffer.concat([head, mask, masked]));
  }
  close() {
    this.sock.destroy();
  }
}

/* --------------------------------------------------------------- BiDi 封装 */
class BiDi {
  constructor(ws) {
    this.ws = ws;
    this.id = 0;
    this.pending = new Map();
    this.logs = [];
    ws.onmessage = (msg) => {
      if (msg.id !== undefined && this.pending.has(msg.id)) {
        const { res, rej } = this.pending.get(msg.id);
        this.pending.delete(msg.id);
        msg.type === 'error' ? rej(new Error(JSON.stringify(msg.error || msg.message))) : res(msg.result);
      } else if (msg.type === 'event') {
        this.onEvent?.(msg);
      }
    };
  }
  send(method, params = {}) {
    const id = ++this.id;
    return new Promise((res, rej) => {
      this.pending.set(id, { res, rej });
      this.ws.send(JSON.stringify({ id, method, params }));
      setTimeout(() => {
        if (this.pending.has(id)) {
          this.pending.delete(id);
          rej(new Error(`timeout: ${method}`));
        }
      }, 90000);
    });
  }
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/** 端口：参数 > 环境变量 > 主题目录下 profile 里的 WebDriverBiDiServer.json */
async function resolvePort() {
  if (PORT_ARG) return Number(PORT_ARG);
  if (process.env.CDP_PORT) return Number(process.env.CDP_PORT);
  for (const p of ['tmp/ff', '.tmp/ff']) {
    try {
      const j = JSON.parse(await readFile(`${p}/WebDriverBiDiServer.json`, 'utf8'));
      if (j.ws_port) return j.ws_port;
    } catch { /* 继续 */ }
  }
  return 9333;
}

const port = await resolvePort();
const ws = new WS(`ws://127.0.0.1:${port}/session`);
await ws.ready;
const bidi = new BiDi(ws);

const logs = [];
bidi.onEvent = (msg) => {
  const text = JSON.stringify(msg.params ?? {});
  if (msg.method === 'log.entryAdded') {
    const e = msg.params;
    if (e.level === 'error' || e.type === 'javascript') {
      logs.push(`[${e.level}/${e.type}] ${e.text}${e.stackTrace ? '' : ''}`);
    }
  }
  if (msg.method.startsWith('log.')) return;
  void text;
};

const session = (await bidi.send('session.new', { capabilities: {} })).sessionId;
const { context } = await bidi.send('browsingContext.create', { type: 'tab' });
await bidi.send('session.subscribe', {
  events: ['log.entryAdded', 'browsingContext.load'],
  contexts: [context],
});

// 视口尺寸：BiDi 通过 setViewport 控制
const [W, H] = SIZE.split('x').map(Number);
try {
  await bidi.send('browsingContext.setViewport', { context, viewport: { width: W, height: H } });
} catch (e) {
  logs.push(`[note] setViewport 不可用: ${e.message}`);
}

await bidi.send('browsingContext.navigate', { context, url: URL_, wait: 'complete' });
await sleep(Number(WAIT));

if (EXTRA) {
  try {
    const r = await bidi.send('script.evaluate', {
      expression: EXTRA,
      target: { context },
      awaitPromise: true,
      resultOwnership: 'none',
    });
    logs.push(`[eval] ${r.result?.value ?? r.result?.type ?? JSON.stringify(r)}`);
  } catch (e) {
    logs.push(`[eval-error] ${e.message}`);
  }
  await sleep(700);
}

try {
  const shot = await bidi.send('browsingContext.captureScreenshot', { context, origin: 'viewport' });
  await writeFile(OUT, Buffer.from(shot.data, 'base64'));
} catch (e) {
  logs.push(`[screenshot-error] ${e.message}`);
}

try {
  const diag = await bidi.send('script.evaluate', {
    expression: `JSON.stringify({
      introPhase: document.querySelector('#intro')?.dataset.phase,
      revealed: document.body.classList.contains('is-revealed'),
      canvas: (()=>{const c=document.querySelector('#bg');return c? c.width+'x'+c.height : 'none'})(),
      glyphs: window.__DS1__?.vortex?.glyphs?.length ?? -1,
      fps: Math.round(window.__DS1__?.loop?.fpsAvg ?? 0),
      quality: window.__DS1__?.fx?.quality,
      cards: document.querySelectorAll('.tl-card').length,
      labCanvas: [...document.querySelectorAll('.lab-canvas')].map(c=>c.width+'x'+c.height).join(','),
      mathNodes: document.querySelectorAll('.math').length,
      scrollY: Math.round(scrollY),
      boot: window.__DS1__ ? 'ok' : 'missing'
    })`,
    target: { context },
    resultOwnership: 'none',
  });
  logs.push(`[diag] ${diag.result?.value}`);
} catch (e) {
  logs.push(`[diag-error] ${e.message}`);
}

console.log(logs.length ? logs.join('\n') : '(无异常输出)');
console.log(`saved ${OUT} (session ${session.slice(0, 8)})`);
ws.close();
process.exit(0);
