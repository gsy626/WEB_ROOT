# WEB_ROOT 博客站

`PERSONAL_PROJECTS/tex`（LaTeX）与 `PERSONAL_PROJECTS/qd`（Quarkdown）里的文稿，
转换成的静态博客网页。**站点以本目录为文档根**，所有链接都是根相对路径。

## 目录结构

```
WEB_ROOT/
├── index.html                  首页：全部博文，可按风格筛选
├── styles.html                 风格说明
├── content/<slug>/index.html   每篇博文一个文件夹
│   └── assets/                 该篇的图片、PDF 原件、预览图
├── style/                      风格与交互
│   ├── base.css                公共骨架（设计变量、排版、组件）
│   ├── math.css                数理风格
│   ├── casual.css              休闲随笔风格
│   ├── gallery.css             图鉴风格
│   ├── blog.js                 公式渲染、灯箱、目录、筛选
│   └── vendor/katex/           KaTeX（本地，离线可用）
└── .tools/                     生成器（不参与站点发布）
```

## 三种风格

| 风格 | 文件 | 判断规则 | 篇数 |
| --- | --- | --- | --- |
| 数理风格 | `style/math.css` | 正文出现行内或行间 LaTeX 公式 | 11 |
| 休闲随笔风格 | `style/casual.css` | 不含公式的叙事、小说、随笔 | 1 |
| 图鉴风格 | `style/gallery.css` | 以图像、海报或绘图代码为主体 | 2 |

新增风格只需再写一个 CSS 文件：覆盖 `base.css` 里的设计变量，页面按
`base.css` + `<风格名>.css` 的顺序引用即可。

## 重新生成

```powershell
# 1) （可选）把 TikZ 源码重新渲染成预览图，产物在 .tools/render-out/
pwsh -File .tools/render-tex.ps1

# 2) 生成全部页面
node .tools/build.js

# 只看某一篇
node .tools/build.js cicada-song
```

## 自检

```powershell
node .tools/verify-math.js   # 用 KaTeX 预渲染全部公式，报告语法错误
node .tools/check-site.js    # 检查链接、素材、风格引用、页面结构
node .tools/audit.js         # 逐篇统计公式/图片，并检查正文是否残留 LaTeX 或 $ 定界符
```

当前状态：**604 条公式全部渲染通过，0 处 LaTeX 残渣，167 条链接与 39 张图片全部可达。**

## 本地预览

```powershell
npx -y light-server -s . -p 8080 -w "**/*.html,**/*/*.html,**/*.css,**/*.js"
```

然后打开 <http://localhost:8080/index.html>。

## 说明

- 公式使用本地 KaTeX 渲染，无需联网。
- 时间取自项目文件夹名（如 `2026-6-15=normal-distribution`）或文件夹内带时间的文件；
  少数没有日期线索的项目，按文件时间推断并在页面标注「（推断）」。
- 页面保留原始项目文件路径，便于回溯。
