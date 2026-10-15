'use strict';
/* ==========================================================================
   posts.js —— 博文清单（转为网页的唯一数据来源）
   字段说明：
     slug      英文目录名，最终落地为 WEB_ROOT/content/<slug>/
     title     文章标题（中文原题优先）
     subtitle  英文名 / 副标题
     date      发布日期，取自项目文件夹名或文件夹内带时间的文件
     dateNote  日期来源说明（inferred 表示按文件时间推断）
     style     风格：math（数理）| casual（休闲随笔）| gallery（图鉴）
     sources   [{path,label,lang}] 原始项目文件
     assets    需要拷贝到文章目录 assets/ 的素材
     kind      'article' 默认 | 'gallery' | 'poster' | 'bundle'
   ========================================================================== */

const P = 'D:\\HOME\\PERSONAL_PROJECTS';
const T = P + '\\tex';
const Q = P + '\\qd';

const posts = [
  /* ---------------------------------------------------------------- 休闲随笔 */
  {
    slug: 'cicada-song',
    title: '蝉鸣',
    subtitle: 'Cicada Song — 文言短篇志怪小说',
    date: '2026-06-13',
    dateNote: '项目文件夹 2026-6-13=chanming',
    style: 'casual',
    kind: 'bundle',
    summary: '蝉，生于土，鸣于夏，死于秋。数年蛰伏，不过一季高歌——明执意化人，令安于蝉身，一翅留于儿床，其若虚无。',
    tags: ['小说', '文言', '志怪'],
    sources: [
      { path: Q + '\\2026-6-13=chanming\\main.qd', label: 'Quarkdown 主文档 main.qd', lang: 'quarkdown' },
      { path: T + '\\2026-6-9=chanming\\chanming.tex', label: 'LaTeX 单行本初稿 chanming.tex', lang: 'latex' }
    ],
    assets: [
      { src: Q + '\\2026-6-13=chanming\\image\\chan2.jpg', as: 'cover.jpg' },
      { src: Q + '\\2026-6-13=chanming\\image\\guoziao.jpg', as: 'author.jpg' }
    ]
  },

  /* ------------------------------------------------------------------ 数理风格 */
  {
    slug: 'normal-distribution-area-is-one',
    title: '正态分布曲线下的面积为什么是 1',
    subtitle: 'Prove the normal distribution area equals one',
    date: '2026-06-15',
    dateNote: '项目文件夹 2026-6-15=normal-distribution 与 2026 06 15.txt',
    style: 'math',
    summary: '从标准正态分布出发，讨论 S² 的二重积分与极坐标变换，并给出适合高中生的面积微元提示。',
    tags: ['微积分', '概率', '二重积分'],
    sources: [{ path: Q + '\\2026-6-15=normal-distribution\\main.qd', label: 'Quarkdown 主文档 main.qd', lang: 'quarkdown' }],
    assets: [
      { src: Q + '\\2026-6-15=normal-distribution\\image\\image1.png', as: 'image/image1.png' },
      { src: Q + '\\2026-6-15=normal-distribution\\image\\image2.png', as: 'image/image2.png' }
    ]
  },
  {
    slug: 'shift-of-the-extremum-point',
    title: '一道极值点偏移问题',
    subtitle: 'Shift of the extremum point',
    date: '2026-06-15',
    dateNote: '项目文件夹 2026-6-15=shift-of-the-extremum-point 与 2026 06 15.txt',
    style: 'math',
    summary: '已知 g(x)=−1−x+ln x+t 有两个零点 x₁、x₂，证明 x₁+x₂>t：一次完整的构造函数 + 消元 + 单调性推理。',
    tags: ['导数', '极值点偏移', '不等式证明'],
    sources: [
      { path: Q + '\\2026-6-15=shift-of-the-extremum-point\\main.qd', label: 'Quarkdown 主文档 main.qd', lang: 'quarkdown' },
      { path: T + '\\ji zhi dian pian yi\\ji zhi dian pian yi.tex', label: 'LaTeX 双栏版 ji zhi dian pian yi.tex', lang: 'latex' }
    ],
    assets: [
      { src: Q + '\\2026-6-15=shift-of-the-extremum-point\\image\\fig.png', as: 'image/fig.png' },
      { src: T + '\\ji zhi dian pian yi\\2.png', as: 'image/fig2.png' }
    ]
  },
  {
    slug: 'optical-properties-of-the-ellipse',
    title: '椭圆的光学性质',
    subtitle: 'Elliptical optical properties',
    date: '2026-06-15',
    dateNote: '项目文件夹 2026-6-15；文中自注 2026/6/13',
    style: 'math',
    summary: '证明椭圆焦点发出的光线经反射后必经另一焦点：证法一求导得法向量，证法二用梯度与等值线的垂直关系。',
    tags: ['解析几何', '向量', '梯度'],
    sources: [
      { path: Q + '\\2026-6-15=Elliptical Optical Properties\\main.qd', label: 'Quarkdown 主文档 main.qd', lang: 'quarkdown' },
      { path: T + '\\Optical Properties of Ellipse\\椭圆光学性质.txt', label: 'LaTeX 草稿 椭圆光学性质.txt', lang: 'latex' }
    ],
    assets: [
      { src: Q + '\\2026-6-15=Elliptical Optical Properties\\image\\1.png', as: 'image/1.png' },
      { src: Q + '\\2026-6-15=Elliptical Optical Properties\\image\\2.png', as: 'image/2.png' }
    ]
  },
  {
    slug: 'charged-particles-in-combined-fields',
    title: '复合场中带电粒子的运动',
    subtitle: 'The motion of charged particles in a combined field',
    date: '2026-06-17',
    dateNote: '项目文件夹 2026-6-17 与 2026 06 17.txt',
    style: 'math',
    summary: '匀强磁场加恒定电场中带电粒子的轨迹：由牛顿第二定律解出速度分量的圆与漂移，再积分得到 x、y、z 的完整参数方程。',
    tags: ['电磁学', '洛伦兹力', '参数方程'],
    sources: [{ path: Q + '\\2026-6-17=The motion of charged particles in a combined field (magnetic field and electric field)\\main.qd', label: 'Quarkdown 主文档 main.qd', lang: 'quarkdown' }],
    assets: [
      { src: Q + '\\2026-6-17=The motion of charged particles in a combined field (magnetic field and electric field)\\image\\fig1.png', as: 'image/fig1.png' },
      { src: Q + '\\2026-6-17=The motion of charged particles in a combined field (magnetic field and electric field)\\image\\fig2.png', as: 'image/fig2.png' }
    ]
  },
  {
    slug: 'distance-function-and-equipotential-lines',
    title: '代数法寻找费马点（一）：距离函数与等势线',
    subtitle: 'Some ideas of the function of distance',
    date: '2026-06-19',
    dateNote: '项目文件夹 2026-6-19 与 2026 06 19.txt',
    style: 'math',
    summary: '把费马点问题写成二元函数 f(x,y)=|PA|+|PB|+|PC|，借用物理里的等势线直觉，寻找极小值点。',
    tags: ['费马点', '多元函数', '等值线'],
    sources: [{ path: Q + '\\2026-6-19=the-function-of-distance\\main.qd', label: 'Quarkdown 主文档 main.qd', lang: 'quarkdown' }],
    assets: [
      { src: Q + '\\2026-6-19=the-function-of-distance\\image\\fig1.png', as: 'image/fig1.png' },
      { src: Q + '\\2026-6-19=the-function-of-distance\\image\\fig2.png', as: 'image/fig2.png' },
      { src: Q + '\\2026-6-19=the-function-of-distance\\image\\fig3.png', as: 'image/fig3.png' },
      { src: Q + '\\2026-6-19=the-function-of-distance\\image\\fig4.png', as: 'image/fig4.png' }
    ]
  },
  {
    slug: 'fermat-point',
    title: '费马点（二）：梯度、泰勒展开与定义域边界',
    subtitle: 'Fermat point',
    date: '2026-07-12',
    dateNote: '项目文件夹 2026-7-12=Fermat point',
    style: 'math',
    summary: '用梯度为零得到三个单位向量之和为零，从而确定费马点；再用二元泰勒展开证明它是极小值点，并讨论内角大于 2π/3 时顶点取最小值的边界情形。',
    tags: ['费马点', '梯度', '泰勒展开'],
    sources: [{ path: Q + '\\2026-7-12=Fermat point\\main.qd', label: 'Quarkdown 主文档 main.qd', lang: 'quarkdown' }],
    assets: [
      { src: Q + '\\2026-7-12=Fermat point\\image\\image1.png', as: 'image/image1.png' },
      { src: Q + '\\2026-7-12=Fermat point\\image\\image2.png', as: 'image/image2.png' },
      { src: Q + '\\2026-7-12=Fermat point\\image\\image3.png', as: 'image/image3.png' },
      { src: Q + '\\2026-7-12=Fermat point\\image\\image4.png', as: 'image/image4.png' }
    ]
  },
  {
    slug: 'thoughts-on-the-distance-function',
    title: '有关距离函数的思考',
    subtitle: 'Thoughts on the distance function',
    date: '2026-06-15',
    dateNote: 'LaTeX 辅助文件时间 2026-06-15（文件夹名不含日期）',
    style: 'math',
    summary: '把椭圆方程看作函数 z=x²/a²+y²/b² 的等值线，用全微分与梯度重新导出法向量；再从椭圆定义出发，得到与光学性质一致的结论。',
    tags: ['等值线', '全微分', '梯度'],
    sources: [{ path: T + '\\距离函数的思考\\距离函数的思考.tex', label: 'LaTeX 源文件 距离函数的思考.tex', lang: 'latex' }]
  },
  {
    slug: 'eight-province-joint-exam-finale',
    title: '八省联考压轴题的另一种写法',
    subtitle: 'Finale of the eight-province joint exam',
    date: '2026-06-21',
    dateNote: 'inferred：按项目文件时间 2026-10-14 归档前的原始写作时间推断',
    style: 'math',
    summary: '围绕 h(x)=√(x+45)−√(x+5) 与迭代数列 x_{n+1}=g(x_n)，构造三条夹逼直线完成放缩，证明 [Σxᵢ]=4n。',
    tags: ['数列', '放缩法', '夹逼'],
    sources: [{ path: T + '\\八省联考压轴\\八省联考压轴题.tex', label: 'LaTeX 源文件 八省联考压轴题.tex', lang: 'latex' }],
    assets: [
      { src: T + '\\八省联考压轴\\八省联考压轴题_页面_1.png', as: 'image/page-1.png' },
      { src: T + '\\八省联考压轴\\八省联考压轴题_页面_2.png', as: 'image/page-2.png' }
    ]
  },
  {
    slug: '28-tone-temperament-line-groups',
    title: '28 音律线组的 LaTeX 排版',
    subtitle: 'Generating four groups of seven lines with TikZ',
    date: '2026-06-22',
    dateNote: '项目文件夹 2026-6-22=28xianpu 与 2026 06 22.txt',
    style: 'gallery',
    kind: 'poster',
    summary: '按给定的相对间距生成四组线（每组七条），用 TikZ 精确控制线宽、虚实与左右标注；含两版不同的标注方案。',
    tags: ['TikZ', '排版', '音律'],
    sources: [
      { path: T + '\\2026-6-22=28xianpu\\28xianpu\\28xianpu.tex', label: 'LaTeX 源文件 28xianpu.tex（第一版）', lang: 'latex' },
      { path: T + '\\2026-6-22=28xianpu\\28xianpu3\\28xianpu3.tex', label: 'LaTeX 源文件 28xianpu3.tex（第二版）', lang: 'latex' }
    ],
    assets: [
      { src: T + '\\2026-6-22=28xianpu\\28xianpu\\28xianpu.pdf', as: 'pdf/28xianpu.pdf' },
      { src: T + '\\2026-6-22=28xianpu\\28xianpu3\\28xianpu3.pdf', as: 'pdf/28xianpu3.pdf' }
    ]
  },
  {
    slug: 'exam-paper-typesetting-templates',
    title: '高中数学试卷排版模板',
    subtitle: 'High school maths exam paper templates',
    date: '2026-06-14',
    dateNote: '项目文件夹 2026-6-14=shijuan 与 2026 06 14.txt',
    style: 'math',
    summary: '从题干到选项再到答题卡的完整试卷模板：自定义 \\choices 选项宏、页脚、标题双横线与题目分区。',
    tags: ['LaTeX 模板', '试卷排版'],
    sources: [
      { path: T + '\\2026-6-14=shijuan\\shijuan-modle.tex', label: '模板 shijuan-modle.tex', lang: 'latex' },
      { path: T + '\\2026-6-14=shijuan\\shijuan-example.tex', label: '示例 shijuan-example.tex', lang: 'latex' }
    ],
    assets: [
      { src: T + '\\2026-6-14=shijuan\\shijuan.pdf', as: 'pdf/shijuan.pdf' },
      { src: T + '\\2026-6-14=shijuan\\shijuan-example.pdf', as: 'pdf/shijuan-example.pdf' },
      { src: T + '\\2026-6-14=shijuan\\shijuan-modle.pdf', as: 'pdf/shijuan-modle.pdf' }
    ]
  },
  {
    slug: 'june-maths-homework-paper',
    title: '高一数学六月作业卷',
    subtitle: 'June maths homework paper',
    date: '2026-06-15',
    dateNote: '项目文件夹 2026-6-15=shijuan1 与 2026 06 15.txt',
    style: 'math',
    summary: '清苑一中高一数学组六月供题：单选、多选、填空、解答共 19 题，含三角函数图象与性质的一批典型题。',
    tags: ['试卷', '三角函数', '高一'],
    sources: [{ path: T + '\\2026-6-15=shijuan1\\shijuan1.tex', label: 'LaTeX 源文件 shijuan1.tex', lang: 'latex' }],
    assets: [
      { src: T + '\\2026-6-15=shijuan1\\figure16.png', as: 'image/figure16.png' },
      { src: T + '\\2026-6-15=shijuan1\\figure18.png', as: 'image/figure18.png' },
      { src: T + '\\2026-6-15=shijuan1\\shijuan1.pdf', as: 'pdf/shijuan1.pdf' }
    ]
  },

  /* ------------------------------------------------------------------ 图鉴风格 */
  {
    slug: 'deepseek-wallpapers',
    title: 'DeepSeek 制作的壁纸',
    subtitle: 'Wallpapers drawn with TikZ',
    date: '2026-09-12',
    dateNote: 'background 目录内的空标记文件 20260912',
    style: 'gallery',
    kind: 'gallery',
    summary: '十二张用纯 TikZ 代码画出来的桌面与手机壁纸：深空星野、金环暗夜、宣纸远山……每张都附完整源码。',
    tags: ['壁纸', 'TikZ', '生成艺术'],
    sources: [
      { path: T + '\\background\\README.md', label: 'README.md', lang: 'markdown' },
      { path: T + '\\background\\bkg1\\document.tex', label: 'bkg1/document.tex', lang: 'latex' },
      { path: T + '\\background\\bkg2.1\\document.tex', label: 'bkg2.1/document.tex', lang: 'latex' },
      { path: T + '\\background\\bkg2.2\\document.tex', label: 'bkg2.2/document.tex', lang: 'latex' },
      { path: T + '\\background\\bkg3\\document.tex', label: 'bkg3/document.tex', lang: 'latex' },
      { path: T + '\\background\\bkg4\\bkg4.tex', label: 'bkg4/bkg4.tex', lang: 'latex' },
      { path: T + '\\background\\bkg4.2\\bkg4.2.tex', label: 'bkg4.2/bkg4.2.tex', lang: 'latex' },
      { path: T + '\\background\\bkg5\\bkg5.tex', label: 'bkg5/bkg5.tex', lang: 'latex' },
      { path: T + '\\background\\bkg5.2\\bkg5.2.tex', label: 'bkg5.2/bkg5.2.tex', lang: 'latex' },
      { path: T + '\\background\\bkg6\\bkg6.tex', label: 'bkg6/bkg6.tex', lang: 'latex' },
      { path: T + '\\background\\bkg6.2\\bkg6.2.tex', label: 'bkg6.2/bkg6.2.tex', lang: 'latex' },
      { path: T + '\\background\\bkg7\\bkg7.tex', label: 'bkg7/bkg7.tex', lang: 'latex' },
      { path: T + '\\background\\bkg7.2\\bkg7.2.tex', label: 'bkg7.2/bkg7.2.tex', lang: 'latex' }
    ],
    items: [
      { name: 'bkg1', label: '深空星野', src: T + '\\background\\bkg1\\document.tex', preview: 'png-bkg1-1.png', paper: '16:9 桌面', palette: ['#0B1020', '#22D3EE', '#E879F9'] },
      { name: 'bkg2.1', label: '青蓝极光', src: T + '\\background\\bkg2.1\\document.tex', preview: 'png-bkg2.1-1.png', paper: '16:9 桌面', palette: ['#071A2B', '#38BDF8', '#A7F3D0'] },
      { name: 'bkg2.2', label: '极光叠影', src: T + '\\background\\bkg2.2\\document.tex', preview: 'png-bkg2.2-1.png', paper: '16:9 桌面', palette: ['#04121F', '#22D3EE', '#818CF8'] },
      { name: 'bkg3', label: '极简流光', src: T + '\\background\\bkg3\\document.tex', preview: 'png-bkg3-1.png', paper: '16:9 桌面', palette: ['#0A0A0A', '#F5F5F5', '#94A3B8'] },
      { name: 'bkg4', label: '鎏金同心环', src: T + '\\background\\bkg4\\bkg4.tex', preview: 'png-bkg4-1.png', paper: '32:18 桌面', palette: ['#090B11', '#CBA866', '#1A2234'] },
      { name: 'bkg4.2', label: '鎏金同心环 · 疏', src: T + '\\background\\bkg4.2\\bkg4.2.tex', preview: 'png-bkg4.2-1.png', paper: '32:18 桌面', palette: ['#090B11', '#CBA866', '#8E764A'] },
      { name: 'bkg5', label: '朱砂留白', src: T + '\\background\\bkg5\\bkg5.tex', preview: 'png-bkg5-1.png', paper: '手机竖屏', palette: ['#F2ECDE', '#1C1E24', '#B23A30'] },
      { name: 'bkg5.2', label: '朱砂留白 · 疏', src: T + '\\background\\bkg5.2\\bkg5.2.tex', preview: 'png-bkg5.2-1.png', paper: '手机竖屏', palette: ['#F2ECDE', '#1C1E24', '#B23A30'] },
      { name: 'bkg6', label: '宣纸远山', src: T + '\\background\\bkg6\\bkg6.tex', preview: 'png-bkg6-1.png', paper: '手机竖屏', palette: ['#F0ECDC', '#3E6448', '#AF3C2D'] },
      { name: 'bkg6.2', label: '宣纸远山 · 疏', src: T + '\\background\\bkg6.2\\bkg6.2.tex', preview: 'png-bkg6.2-1.png', paper: '手机竖屏', palette: ['#F0ECDC', '#3E6448', '#AF3C2D'] },
      { name: 'bkg7', label: '青绿山水', src: T + '\\background\\bkg7\\bkg7.tex', preview: 'png-bkg7-1.png', paper: '手机竖屏', palette: ['#F3EEE0', '#385C46', '#B23A2E'] },
      { name: 'bkg7.2', label: '青绿山水 · 疏', src: T + '\\background\\bkg7.2\\bkg7.2.tex', preview: 'png-bkg7.2-1.png', paper: '手机竖屏', palette: ['#F3EEE0', '#385C46', '#B23A2E'] }
    ],
    assets: [],
    manifest: 'render-out'
  },
  {
    slug: 'strong-army-posters',
    title: '强军主题海报',
    subtitle: 'Posters on the theme of a strong military',
    date: '2026-10-14',
    dateNote: 'inferred：项目文件时间 2026-10-14（军训小组作业）',
    style: 'gallery',
    kind: 'poster',
    summary: '军训小组作业：以深红与鎏金为主色的 A3 横版海报，六幅插图矩阵 + 放射光芒 + 四角装饰，全部由 TikZ 绘制。',
    tags: ['海报', 'TikZ', '国防教育'],
    sources: [
      { path: T + '\\poster\\README.md', label: 'README.md', lang: 'markdown' },
      { path: T + '\\poster\\poster4\\poster4.tex', label: 'poster4/poster4.tex', lang: 'latex' },
      { path: T + '\\poster\\poster5\\poster5.tex', label: 'poster5/poster5.tex', lang: 'latex' }
    ],
    items: [
      { name: 'poster4', label: '全民国防教育 · 强军有我', src: T + '\\poster\\poster4\\poster4.tex', preview: 'png-poster4-1.png', paper: 'A3 横版' },
      { name: 'poster5', label: '建军百年 · 强军有我', src: T + '\\poster\\poster5\\poster5.tex', preview: 'png-poster5-1.png', paper: 'A3 横版' }
    ],
    figures: [
      { src: T + '\\poster\\poster4\\images_png\\tank.png', as: 'figures/tank.png', label: '陆战铁甲' },
      { src: T + '\\poster\\poster4\\images_png\\missile.png', as: 'figures/missile.png', label: '大国长剑' },
      { src: T + '\\poster\\poster4\\images_png\\ship.png', as: 'figures/ship.png', label: '向海图强' },
      { src: T + '\\poster\\poster4\\images_png\\jet.png', as: 'figures/jet.png', label: '制空雄鹰' },
      { src: T + '\\poster\\poster4\\images_png\\emblem.png', as: 'figures/emblem.png', label: '军魂永驻' },
      { src: T + '\\poster\\poster4\\images_png\\logo.png', as: 'figures/logo.png', label: '忠诚担当' }
    ],
    assets: [{ src: T + '\\poster\\poster5\\poster5.pdf', as: 'pdf/poster5.pdf' }, { src: T + '\\poster\\poster4\\poster4.pdf', as: 'pdf/poster4.pdf' }],
    manifest: 'render-out'
  }
];

/** 风格说明（首页与样式页展示用） */
const styles = [
  {
    name: 'math',
    file: 'math.css',
    label: '数理风格',
    desc: '冷色纸面 + 极淡方格底纹，衬线正文，编号公式卡与定理式提示框。',
    rule: '判断规则：正文出现行内或行间 LaTeX 数学公式。'
  },
  {
    name: 'casual',
    file: 'casual.css',
    label: '休闲随笔风格',
    desc: '暖纸底色 + 手写感标题 + 首字下沉 + 便签提示框，适合叙事与散文。',
    rule: '判断规则：不含数学公式的叙事、小说、随笔。'
  },
  {
    name: 'gallery',
    file: 'gallery.css',
    label: '图鉴风格',
    desc: '深色展台 + 大图展墙 + 代码展板，可点击放大，适合作品集与生成艺术。',
    rule: '判断规则：以图像、海报或绘图代码为主体，原理性叙述较少。'
  }
];

module.exports = { posts, styles, ROOT: P };
