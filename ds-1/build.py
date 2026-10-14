#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
build.py —— 把多文件源码打包成 dist/index.html 单文件版本。

设计约束（刻意保持极简，不引入任何构建依赖）：
  * 所有 ES Module 的相对导入在打包时被剥离；模块仍保留 export 关键字，
    由浏览器原生模块语义在同一 <script type="module"> 内解析，因此
    export 的绑定是「活」的（与开发时行为一致）。
  * 打包前会做重复顶层声明的静态检查：跨文件同名会造成
    SyntaxError，必须在这里拦住。
  * 运行时依赖的名词性导入已被移除，故构建时会校验「每个被导入的名字
    确实在某处被声明」，避免出现 ReferenceError。

用法:
    python3 build.py            # 生成 dist/index.html
    python3 build.py --check    # 只做检查，不写文件
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent
JS_DIR = ROOT / "js"
CSS_DIR = ROOT / "css"

CSS_ORDER = ["tokens.css", "base.css", "components.css", "sections.css", "animations.css"]
ENTRY = JS_DIR / "main.js"

IMPORT_RE = re.compile(
    r"^[ \t]*import\s+(?:(?P<clause>[^;'\"]+?)\s+from\s+)?['\"](?P<spec>[^'\"]+)['\"]\s*;?\s*$",
    re.MULTILINE,
)
NAMED_IMPORT_RE = re.compile(r"\{([^}]*)\}", re.S)

# 顶层声明（用于冲突检测与名称解析）
# 关键：只匹配行首（无缩进）的声明 —— 缩进的一定在函数/类体内部，不构成顶层冲突。
DECL_RES = [
    re.compile(r"^export\s+(?:async\s+)?(?:class|function)\s+([A-Za-z_$][\w$]*)", re.M),
    re.compile(r"^export\s+(?:const|let|var)\s+([A-Za-z_$][\w$]*)", re.M),
    re.compile(r"^(?:async\s+)?function\s+([A-Za-z_$][\w$]*)", re.M),
    re.compile(r"^class\s+([A-Za-z_$][\w$]*)", re.M),
    re.compile(r"^(?:const|let|var)\s+([A-Za-z_$][\w$]*)", re.M),
]

# 各种导出写法。打包后所有模块同处一个 module，重名导出会让浏览器
# 直接 SyntaxError（整页黑屏），因此必须在构建期拦住。
EXPORT_NAMED_RE = re.compile(r"^export\s*\{([^}]*)\}", re.M)
EXPORT_LIST_ITEM_RE = re.compile(r"([A-Za-z_$][\w$]*)\s*(?:as\s+([A-Za-z_$][\w$]*))?")
EXPORT_DEFAULT_RE = re.compile(r"^export\s+default\b", re.M)


def collect_exports(src: str) -> list[str]:
    """收集该文件对外暴露的导出名（含 export {} 与 export const/class/function）"""
    names: list[str] = []
    for rx in DECL_RES:  # 前两条即以 export 开头
        for m in rx.finditer(src):
            line_start = src.rfind("\n", 0, m.start()) + 1
            if src[line_start : m.start()].strip() == "" and src[m.start() :].startswith("export"):
                names.append(m.group(1))
    for m in EXPORT_NAMED_RE.finditer(src):
        for item in m.group(1).split(","):
            item = item.strip()
            if not item or item.startswith("type "):
                continue
            im = EXPORT_LIST_ITEM_RE.match(item.replace("type ", ""))
            if im:
                names.append(im.group(2) or im.group(1))
    return names



def strip_imports(src: str, origin: str, imported: list[tuple[str, str]]) -> str:
    """移除 import 语句，并记录被导入的具名符号"""

    def repl(m: re.Match) -> str:
        clause = (m.group("clause") or "").strip()
        spec = m.group("spec")
        if clause:
            braces = NAMED_IMPORT_RE.search(clause)
            if braces:
                for raw in braces.group(1).split(","):
                    raw = raw.strip()
                    if not raw:
                        continue
                    name = raw.split(" as ")[-1].strip()
                    imported.append((name, spec))
            else:
                # 默认导入（本项目未使用）
                imported.append((clause, spec))
        return f"/* [bundle] import 已就地内联: {spec} */"

    return IMPORT_RE.sub(repl, src)


def collect_decls(src: str) -> set[str]:
    names: set[str] = set()
    for rx in DECL_RES:
        names.update(rx.findall(src))
    return names


def check_bundle_syntax(bundle: str) -> str | None:
    """
    用 Node 的 vm.SourceTextModule 把 bundle 解析为单个 module。
    解析成功返回 None，否则返回错误信息（不带 Node 可执行文件时跳过）。
    """
    import shutil
    import subprocess

    node = shutil.which("node")
    if not node:
        print("! 未找到 node，跳过单文件语法解析检查")
        return None

    script = r"""
const fs = require('fs');
const vm = require('vm');
const src = fs.readFileSync(0, 'utf8');   // 从 stdin 读入 bundle
try {
  new vm.SourceTextModule(src, { context: vm.createContext({}) });
  console.log('BUNDLE_OK');
} catch (err) {
  console.log('BUNDLE_ERR ' + String(err.message).split('\n')[0]);
}
"""
    try:
        out = subprocess.run(
            [node, "--experimental-vm-modules", "-e", script],
            input=bundle,
            capture_output=True,
            text=True,
            timeout=120,
        )
    except subprocess.TimeoutExpired:
        return "解析超时"
    lines = [l for l in (out.stdout or "").splitlines() if l.startswith("BUNDLE_")]
    if not lines:
        err = (out.stderr or "").strip().splitlines()
        return err[-1] if err else "未知解析错误"
    line = lines[-1]
    if line == "BUNDLE_OK":
        return None
    return line[len("BUNDLE_ERR ") :].strip()


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--check", action="store_true", help="只检查，不写文件")
    args = ap.parse_args()

    # 1) 按 main.js 的导入顺序收集模块（深度优先，保证被依赖者在前）
    order: list[Path] = []
    seen: set[Path] = set()

    def visit(path: Path) -> None:
        path = path.resolve()
        if path in seen:
            return
        seen.add(path)
        src = path.read_text(encoding="utf-8")
        for m in IMPORT_RE.finditer(src):
            spec = m.group("spec")
            if spec.startswith("."):
                visit((path.parent / spec))
        order.append(path)

    visit(ENTRY)

    # 2) 语法/命名检查
    errors: list[str] = []
    owner: dict[str, str] = {}
    bodies: list[tuple[Path, str]] = []
    imported_names: list[tuple[str, str, str]] = []  # (name, spec, origin)
    export_owner: dict[str, str] = {}

    for path in order:
        raw = path.read_text(encoding="utf-8")
        imported: list[tuple[str, str]] = []
        src = strip_imports(raw, path.name, imported)
        for name, spec in imported:
            imported_names.append((name, spec, path.name))
        decls = collect_decls(src)
        for d in decls:
            if d in owner:
                errors.append(f"重复的顶层声明 `{d}`：{owner[d]} 与 {path.relative_to(ROOT)}")
            owner[d] = str(path.relative_to(ROOT))
        # 导出名不得重复：打包成单文件后同处一个 module，重名即 SyntaxError
        for name in collect_exports(src):
            if name in export_owner:
                errors.append(
                    f"重复的导出名 `{name}`：{export_owner[name]} 与 {path.relative_to(ROOT)}"
                    "（单文件打包后会直接 SyntaxError，导致整页空白）"
                )
            export_owner[name] = str(path.relative_to(ROOT))
        bodies.append((path, src))

    for name, spec, origin in imported_names:
        if name not in owner:
            errors.append(f"{origin} 导入了 `{name}`（来自 {spec}），但没有任何模块声明它")

    if errors:
        print("✗ 打包检查未通过：")
        for e in errors:
            print("   -", e)
        return 1

    # 3) 拼接
    parts = [
        "/* 由 build.py 自动生成 —— 请勿直接编辑；改动请改 js/ 与 css/ 下的源文件 */",
        "",
    ]
    for path, src in bodies:
        rel = path.relative_to(ROOT)
        parts.append(f"/* ==================== {rel} ==================== */")
        parts.append(src.rstrip())
        parts.append("")
    bundle = "\n".join(parts)

    # 4) 把拼好的 bundle 当成「一个 module」真解析一遍：
    #    这样才能抓到只在单文件形态下才暴露的语法错误（例如重复导出名）。
    syntax_error = check_bundle_syntax(bundle)
    if syntax_error:
        print("✗ 单文件 bundle 解析失败（浏览器会整页空白）：")
        print("   -", syntax_error)
        return 1

    html = (ROOT / "index.html").read_text(encoding="utf-8")
    css = "\n".join(
        f"/* ===== {name} ===== */\n" + (CSS_DIR / name).read_text(encoding="utf-8")
        for name in CSS_ORDER
    )
    for name in CSS_ORDER:
        html = html.replace(f'<link rel="stylesheet" href="css/{name}">\n', "")
    html = html.replace("</head>", f"<style>\n{css}\n</style>\n</head>")
    html = html.replace(
        '<script type="module" src="js/main.js"></script>',
        f'<script type="module">\n{bundle}\n</script>',
    )

    if args.check:
        print(f"✓ 检查通过：{len(bodies)} 个模块，{len(owner)} 个顶层符号，无冲突")
        return 0

    out = ROOT / "dist"
    out.mkdir(exist_ok=True)
    target = out / "index.html"
    target.write_text(html, encoding="utf-8")
    kb = target.stat().st_size / 1024
    print(f"✓ 已生成 {target.relative_to(ROOT)}（{kb:.1f} KB，{len(bodies)} 个模块，{len(CSS_ORDER)} 个样式表）")
    return 0


if __name__ == "__main__":
    sys.exit(main())
