/**
 * tools/bidi-client.mjs —— 极简 WebDriver BiDi 客户端（零依赖）
 * 供 tools/bidi.mjs（单次截图）与 tools/audit.mjs（完整巡检）共用。
 */
import net from 'node:net';
import http from 'node:http';
import crypto from 'node:crypto';
import { readFile } from 'node:fs/promises';

export class WS {
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
      this.sock.on('data', (d) => {
        this.buf = Buffer.concat([this.buf, d]);
        if (!this.open) {
          const i = this.buf.indexOf('\r\n\r\n');
          if (i < 0) return;
          this.open = true;
          this.buf = this.buf.subarray(i + 4);
          res();
        }
        this.parse();
      });
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

export class BiDi {
  constructor(ws) {
    this.ws = ws;
    this.id = 0;
    this.pending = new Map();
    this.events = [];
    ws.onmessage = (msg) => {
      if (msg.id !== undefined && this.pending.has(msg.id)) {
        const { res, rej } = this.pending.get(msg.id);
        this.pending.delete(msg.id);
        msg.type === 'error' ? rej(new Error(JSON.stringify(msg.error || msg.message))) : res(msg.result);
      } else if (msg.type === 'event') {
        this.events.push(msg);
        this.onEvent?.(msg);
      }
    };
  }
  send(method, params = {}, timeout = 90000) {
    const id = ++this.id;
    return new Promise((res, rej) => {
      this.pending.set(id, { res, rej });
      this.ws.send(JSON.stringify({ id, method, params }));
      setTimeout(() => {
        if (this.pending.has(id)) {
          this.pending.delete(id);
          rej(new Error(`timeout: ${method}`));
        }
      }, timeout);
    });
  }
  evaluate(expression, context, awaitPromise = true) {
    return this.send('script.evaluate', {
      expression,
      target: { context },
      awaitPromise,
      resultOwnership: 'none',
    });
  }
}

export const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

export function httpGetJson(u) {
  return new Promise((res, rej) =>
    http
      .get(u, (r) => {
        let d = '';
        r.on('data', (c) => (d += c));
        r.on('end', () => {
          if (r.statusCode !== 200) return rej(new Error(`HTTP ${r.statusCode} ${u}`));
          try {
            res(JSON.parse(d));
          } catch {
            rej(new Error(`非 JSON: ${u}`));
          }
        });
      })
      .on('error', rej)
  );
}

/** 端口解析：参数 > 环境变量 > profile 内的 WebDriverBiDiServer.json */
export async function resolvePort(explicit, profileDirs = ['tmp/ff', '.tmp/ff']) {
  if (explicit) return Number(explicit);
  if (process.env.BIDI_PORT) return Number(process.env.BIDI_PORT);
  for (const p of profileDirs) {
    try {
      const j = JSON.parse(await readFile(`${p}/WebDriverBiDiServer.json`, 'utf8'));
      if (j.ws_port) return j.ws_port;
    } catch { /* 继续 */ }
  }
  return 9333;
}

/** 建立会话：优先复用已有的 session id，否则新建 */
export async function openSession(port) {
  const ws = new WS(`ws://127.0.0.1:${port}/session`);
  await ws.ready;
  const bidi = new BiDi(ws);
  let sessionId = null;
  try {
    const r = await bidi.send('session.new', { capabilities: {} });
    sessionId = r.sessionId;
    bidi.sessionId = sessionId;
  } catch (e) {
    // Firefox 每个实例只允许一个 session；此处无法复用，交由调用方重启浏览器
    throw new Error(`session.new 失败（可能需要重启浏览器实例）: ${e.message}`);
  }
  return { ws, bidi, sessionId };
}

/** 判断某个页面是否已加载完成（轮询用） */
export async function waitFor(bidi, context, expression, { timeout = 20000, interval = 250 } = {}) {
  const t0 = Date.now();
  while (Date.now() - t0 < timeout) {
    try {
      const r = await bidi.evaluate(`Boolean(${expression})`, context);
      if (r.result?.value === true) return true;
    } catch { /* 页面尚未就绪 */ }
    await sleep(interval);
  }
  return false;
}
