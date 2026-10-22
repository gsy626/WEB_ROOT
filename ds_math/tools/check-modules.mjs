#!/usr/bin/env node
/**
 * tools/check-modules.mjs
 * 静态校验：语法 + 导入/导出图（不执行浏览器代码）。
 * 用 vm.SourceTextModule 解析并与真实文件链接，可捕获
 *   - 语法错误
 *   - 找不到的文件
 *   - 导入了并不存在的命名导出
 * 用法: node --experimental-vm-modules tools/check-modules.mjs
 */
import { readFile, readdir } from 'node:fs/promises';
import { fileURLToPath, pathToFileURL } from 'node:url';
import path from 'node:path';
import vm from 'node:vm';

const root = path.dirname(fileURLToPath(import.meta.url)) + '/..';
const rootURL = pathToFileURL(path.resolve(root) + '/').href;

async function walk(dir) {
  const out = [];
  for (const e of await readdir(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) out.push(...(await walk(p)));
    else if (e.name.endsWith('.js')) out.push(p);
  }
  return out;
}

const files = await walk(path.join(root, 'js'));
let failed = 0;

// 每个入口用独立的 context + cache，避免 link 状态互相污染
function makeLoader(context) {
  const cache = new Map();
  async function load(url) {
    if (cache.has(url)) return cache.get(url);
    const src = await readFile(fileURLToPath(url), 'utf8');
    const mod = new vm.SourceTextModule(src, { identifier: url, context });
    mod.__url = url;
    cache.set(url, mod);
    return mod;
  }
  return { load, cache };
}

function linkAll(mod, load) {
  return mod.link(async (specifier, referencing) => {
    if (!specifier.startsWith('.')) return null; // 裸模块（CDN）跳过
    // 关键：必须相对「引用者」解析，而不是入口模块
    const base = referencing?.__url || mod.__url;
    return load(new URL(specifier, base).href);
  });
}

for (const f of files) {
  const url = pathToFileURL(path.resolve(f)).href;
  const rel = path.relative(root, f);
  const context = vm.createContext({ console });
  const { load, cache } = makeLoader(context);
  try {
    const mod = await load(url);
    await linkAll(mod, load);
    console.log(`  ok    ${rel}  (${mod.dependencySpecifiers.length} imports, ${cache.size - 1} deps)`);
  } catch (err) {
    failed++;
    console.error(`  FAIL  ${rel}\n        ${err.message.split('\n')[0]}`);
  }
}

console.log(
  failed === 0
    ? `\n✓ ${files.length} 个模块语法与依赖图全部通过`
    : `\n✗ ${failed} 个模块存在问题`
);
process.exit(failed === 0 ? 0 : 1);
