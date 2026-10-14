# 数理 · DS-1

> 一个以「数理」为主题的网站首页示例。
> 开屏是一段 Canvas 绘制的**时空穿梭漩涡**：数学与物理的符号沿螺旋轨道被吸入奇点，
> 同时推进两千五百年来的关键节点；随后站点内容接管画面。

零依赖、零构建：原生 ES Module + Canvas 2D，离线可直接打开（`dist/index.html`）。

---

## 快速开始

**想看效果，直接双击 `dist/index.html`** —— 脚本与样式已全部内联，不需要本地服务，
Chrome / Edge / Firefox / Safari 都能直接打开。

想改代码，则用本地服务（源码版 `index.html` 是 ES Module，
Chrome / Edge 会拦截 `file://` 下的模块加载）：

```bash
python3 -m http.server 8791
# 然后访问 http://127.0.0.1:8791/index.html

python3 build.py            # 改完重新生成 dist/index.html
```

> 打不开、黑屏？页面本身带兜底：若脚本 9 秒内没有任何启动迹象，
> 会直接显示一段「该怎么办」的提示而不是停在黑场。
> 仍不正常的话按 F12 看控制台报错。

### 三个入口的区别

| 入口 | 打开方式 | 说明 |
| --- | --- | --- |
| `dist/index.html` | 双击即可 | 单文件产物，**给别人看/离线用这个** |
| `index.html` | 需本地服务 | 源码版，改代码用；被 `build.py` 读取 |
| `js/` `css/` | —— | 真正的源头，`dist` 是它们生成的 |

---

## 目录结构

```
ds-1/
├── index.html              # 页面骨架（只有容器与文案占位，内容由 JS 装配）
├── css/
│   ├── tokens.css          # 设计变量：色彩 / 字体 / 间距 / 缓动 / 层级
│   ├── base.css            # 重置、排版基座、背景层、迷你公式排版样式
│   ├── components.css      # 导航 / 按钮 / 进度条 / 标签 / 光标光晕
│   ├── sections.css        # 开屏层 / 首屏 / 时间轴 / 实验室 / 关于 / 页脚
│   └── animations.css      # 关键帧、滚动显现、reduced-motion 兜底
├── js/
│   ├── main.js             # 唯一入口：装配模块、驱动渲染循环
│   ├── core/
│   │   ├── config.js       # 全局参数（漩涡、色彩、配色、文案时间轴）
│   │   ├── utils.js        # 纯函数：缓动 / 插值 / 随机 / 色彩 / 转义
│   │   ├── event-bus.js    # 事件总线 + 统一 rAF 主循环
│   │   ├── renderer.js     # 画布管理：DPR、ResizeObserver、离屏缓冲、拖尾
│   │   ├── primitives.js   # 预渲染精灵：光晕 / 环 / 星芒 / 文本栅格化
│   │   └── mathtext.js     # 迷你 LaTeX 排版器（分式 / 根号 / 上下标）
│   ├── canvas/
│   │   ├── stars.js        # 星场点云 + 快速三角函数查找表
│   │   ├── vortex.js       # 时空漩涡引擎（悬臂 / 环 / 透镜弧 / 公式 / 奇点）
│   │   └── fx.js           # 后处理：泛光 / 色散 / 颗粒 / 扫描线 / 暗角
│   ├── intro/intro.js      # 开屏演出阶段机（wake → vortex → burst → settle）
│   ├── site/
│   │   ├── ui.js           # 滚动显现、导航、进度条、指针归一化
│   │   ├── hero.js         # 首屏渲染 + 视差
│   │   ├── timeline.js     # 数理演进时间轴
│   │   └── toys.js         # 三个可交互物理实验
│   └── data/
│       ├── formulas.js     # 公式库
│       └── sections.js     # 页面内容数据（导航 / 时间轴 / 实验室 / 页脚）
├── tools/                  # 自检与截图工具（不参与线上运行）
│   ├── check-modules.mjs   # 语法 + 导入/导出图静态校验（vm.SourceTextModule）
│   ├── bidi-client.mjs     # 极简 WebDriver BiDi 客户端（零依赖）
│   ├── audit.mjs           # 无头浏览器全页巡检（逐步截图 + 回读状态）
│   ├── bidi.mjs            # 单次截图
│   ├── run-audit.sh        # 起服务 + 起浏览器 + 跑巡检
│   ├── check-boot.mjs      # 黑屏专项检查（启动 / 画布内容 / 开屏退场）
│   ├── run-check-boot.sh   # 依次检查 HTTP源码 / HTTP单文件 / file单文件
│   ├── extract.mjs         # 导出画布原始像素，用于定位「画布有无内容」类问题
│   └── run-extract.sh      # extract 的编排脚本
├── build.py                # 打包为 dist/index.html 单文件
└── Makefile
```

**模块化原则**：`data/` 只放内容，`core/` 只放无副作用的基础设施，
`canvas/` 只负责画，`site/` 只负责 DOM，`intro/` 只负责编排时间。
模块之间通过 `event-bus.js` 通信，不互相 import 实现细节。

---

## 开屏演出：时空漩涡

一次演出的四个阶段（总时长约 11.8 秒，任意时刻点击 / 滚动 / 按 Esc 可跳过）：

| 阶段 | 时长 | 画面 |
| --- | --- | --- |
| `wake` | 1.5s | 漩涡从虚无中凝聚，口径收拢，星场亮起 |
| `vortex` | 7.1s | 全速旋转；叙事沿时间轴推进（前300 → 今日），公式逐个被吸入 |
| `burst` | 1.3s | 奇点爆发：白闪、冲击波、曲率骤变 |
| `settle` | 1.9s | 漩涡退为背景（失焦、降饱和），站点内容接管 |

视觉由五层叠加而成，全部在 `js/canvas/vortex.js` 中：

1. **隧道壁**（`haze`）：大量极淡粒子构成管壁体积感
2. **旋臂细丝**：沿对数螺旋的连续亮线 —— 一眼认出「漩涡」的关键结构
3. **深度环**：随 z 前进的椭圆环，给纵深一个可读的刻度
4. **公式飞行物**：`data/formulas.js` 中的 16 个公式沿螺旋向内，穿过光子球后被吞没
5. **奇点**：事件视界（纯黑）+ 光子环 + 吸积盘 + 相对论喷流 + 星芒

后处理（`js/canvas/fx.js`）：降采样泛光、RGB 色散、胶片颗粒、扫描线、暗角。
所有参数集中在 `js/core/config.js`，改一处即可整体调色。

### 关于性能

- 每帧只做 `drawImage`：渐变、光晕、公式都在启动时烘焙成离屏纹理
- 运动拖尾用「半透明填充」实现，代价极低且不依赖 `filter`
- 三角运算走查找表 + 线性插值（`js/canvas/stars.js` 的 `fastSin/fastCos`）
- 连续低帧率会自动降级后处理等级（`main.js` 中的自适应逻辑）
- 尊重 `prefers-reduced-motion`：关闭装饰性动画并直接进入站点
- 实验画布仅在进入视口时才推进物理

---

## 页面区块

| 区块 | 内容 |
| --- | --- |
| 首屏 | 主标题、导语、双按钮、滚动提示（带视差与逐级入场） |
| 演进 | 9 个数理里程碑，脊线随滚动点亮，卡片按「数学 / 物理 / 交汇」着色 |
| 实验 | 向量场、双摆（混沌）、阻尼受迫谐振，均为真实微分方程逐帧积分 |
| 关于 | 站点说明 + 数据统计 |
| 页脚 | 结语、标签、技术说明 |

三个实验可拖动滑块或直接拖拽画布改变初值，观察系统响应。

---

## 自检工具

```bash
# 静态校验：语法 + 导入/导出图（会捕获「导入了不存在的导出」）
make check          # 等价于 node --experimental-vm-modules tools/check-modules.mjs

# 打包单文件。构建期有三道闸门：
#   ① 顶层声明重名  ② 导出名重名  ③ 把拼好的 bundle 真解析一遍
# 第 ② ③ 道是必需的：源码分模块时重复导出完全合法，但打包成一个
# module 后会让浏览器直接 SyntaxError —— 整页空白。这个坑踩过一次。
make build          # 生成 dist/index.html

# 全页巡检：无头 Firefox + WebDriver BiDi，逐步截图并回读页面状态
make audit          # 截图输出到 _shots/，同时汇总控制台错误

# 黑屏专项：逐个入口判定「会不会把用户挡在黑场之后」
make check-boot     # 检查 启动 / 画布有内容 / 开屏会退场
```

`tools/` 仅用于开发验证，部署时不需要。

---

## 浏览器支持

面向所有支持 ES Module、`Canvas 2D`、`ResizeObserver` 与
`backdrop-filter` 的现代浏览器（Chrome / Edge / Firefox / Safari 近年版本）。
泛光使用的 `ctx.filter` 在个别旧版 Safari 上会退化为无模糊，视觉仍然成立。

## 许可

示例代码，可自由取用与改写。
