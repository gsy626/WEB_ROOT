/* =============================================================================
 * sections.js —— 页面内容数据（导航 / 时间轴 / 交互实验室 / 页脚）
 * 内容与渲染分离：改文案只需改这里。
 * ========================================================================== */
import { CARD_FORMULAS } from './formulas.js';

export const NAV = [
  { href: '#hero', label: '始' },
  { href: '#timeline', label: '演进' },
  { href: '#lab', label: '实验' },
  { href: '#about', label: '关于' },
];

export const HERO = {
  kicker: 'MATHEMATICA · PHYSICA',
  title: '数理',
  subtitle: '数与形的秩序，能量与场的律动',
  lead:
    '当一条曲线被写下，宇宙便多了一层可被推演的结构。' +
    '这里以时空漩涡为入口，串联两千五百年来人类理解自然的十六个瞬间。',
  actions: [
    { href: '#timeline', label: '进入演进史', kind: 'primary' },
    { href: '#lab', label: '动手实验', kind: 'ghost' },
  ],
  scrollHint: '向下滚动，穿越时空',
};

/** 时间轴：数理发展史 */
export const TIMELINE = [
  {
    key: 'euclid',
    year: '约前 300',
    field: 'math',
    fieldLabel: '几何',
    title: '《几何原本》：公理化的第一次胜利',
    body:
      '欧几里得把零散的测量经验压缩成五条公设，再用演绎推理展开四百六十五条命题。' +
      '从此「证明」成为一种可传递的知识形式。',
    points: ['公设 → 定理 的演绎结构', '《原本》被用作教科书逾两千年', '第五公设的疑问最终催生非欧几何'],
    formula: CARD_FORMULAS.euclid,
    tags: ['公理化', '演绎', '比例论'],
  },
  {
    key: 'calculus',
    year: '1665–1687',
    field: 'math',
    fieldLabel: '分析',
    title: '微积分：把「变化」变成可计算的对象',
    body:
      '牛顿与莱布尼茨各自把切线问题与面积问题统一为互逆运算。' +
      '瞬时变化率与累积量之间的桥梁，成为此后一切物理定律的语言。',
    points: ['导数与积分的互逆关系', '极限思想使无穷小合法化', '为微分方程建模铺路'],
    formula: CARD_FORMULAS.calculus,
    tags: ['极限', '微分方程', '分析'],
  },
  {
    key: 'gravity',
    year: '1687',
    field: 'physics',
    fieldLabel: '经典力学',
    title: '万有引力：天与地服从同一法则',
    body:
      '《自然哲学的数学原理》用平方反比律同时解释了苹果下落与行星轨道，' +
      '第一次证明地面力学与天体力学是同一条定律。',
    points: ['F = G·m₁m₂ / r²', '开普勒三定律被推导出来', '拉格朗日、哈密顿给出更普适的重构'],
    formula: 'F=G\\frac{m_{1}m_{2}}{r^{2}}',
    tags: ['平方反比', '确定性', '可预测'],
  },
  {
    key: 'maxwell',
    year: '1865',
    field: 'physics',
    fieldLabel: '电磁场',
    title: '麦克斯韦方程组：光是电磁波',
    body:
      '四个方程统一了电、磁与光，并预言电磁波以光速传播。' +
      '方程中的对称性缺陷，二十年后被相对论补齐。',
    points: ['电与磁的统一', '预言电磁波的存在', '直接启发狭义相对论'],
    formula: CARD_FORMULAS.maxwell,
    tags: ['场', '波动', '统一'],
  },
  {
    key: 'riemann',
    year: '1854',
    field: 'math',
    fieldLabel: '几何',
    title: '黎曼几何：空间本身可以被弯曲',
    body:
      '黎曼在就职演讲中提出，几何应由度量张量在每一点局部定义。' +
      '六十一年后，这份纯数学成为广义相对论的现成语言。',
    points: ['度量张量 gμν', '内蕴曲率取代外在空间', '张量分析成为现代物理语法'],
    formula: CARD_FORMULAS.riemann,
    tags: ['流形', '张量', '内蕴'],
  },
  {
    key: 'relativity',
    year: '1905 / 1915',
    field: 'physics',
    fieldLabel: '时空',
    title: '相对论：时空是可弯折的整体',
    body:
      '狭义相对论取消了绝对同时性，广义相对论把引力重新解释为时空曲率。' +
      '光速不变与等效原理，改写了时间与空间的含义。',
    points: ['E = mc²：质量与能量的等价', '引力 = 曲率，而非力', '引力波与黑洞成为可观测预言'],
    formula: CARD_FORMULAS.relativity,
    tags: ['不变性', '曲率', '因果'],
  },
  {
    key: 'quantum',
    year: '1925–1928',
    field: 'physics',
    fieldLabel: '量子',
    title: '量子力学：可观测量的代数',
    body:
      '海森堡、薛定谔与狄拉克给出三条等价却气质迥异的表述。' +
      '不确定性不再是无知，而是自然的结构性限制。',
    points: ['波函数与算符', '不确定关系 Δx·Δp ≥ ℏ/2', '狄拉克方程预言反物质'],
    formula: CARD_FORMULAS.schrodinger,
    tags: ['概率', '算符', '叠加'],
  },
  {
    key: 'gauge',
    year: '1954–1973',
    field: 'physics',
    fieldLabel: '场论',
    title: '规范场论与标准模型',
    body:
      '杨–米尔斯理论把「对称性决定相互作用」变成工程方法，' +
      '电磁、弱、强三种相互作用被同一套框架收纳，并在 2012 年补上希格斯玻色子。',
    points: ['局部对称性 → 规范玻色子', '自发对称性破缺赋予质量', '预言精度达到十亿分之一量级'],
    formula: CARD_FORMULAS.gauge,
    tags: ['对称性', '重整化', '标准模型'],
  },
  {
    key: 'chaos',
    year: '1963–1975',
    field: 'both',
    fieldLabel: '交汇',
    title: '混沌与分形：确定的规则，不可预测的结果',
    body:
      '洛伦兹在天气预报模型里发现对初值的敏感依赖，曼德博用分形刻画自相似结构。' +
      '决定论与可预测性从此被明确区分开。',
    points: ['初值敏感 → 李雅普诺夫指数', '奇怪吸引子与分形维数', '连接数理与计算的桥梁'],
    formula: CARD_FORMULAS.chaos,
    tags: ['非线性', '自相似', '涌现'],
  },
];

/** 交互实验室 */
export const LAB_ITEMS = [
  {
    id: 'toy-field',
    title: '向量场 · 场的可视化',
    desc: '用流动的粒子描出矢量场的方向与强度；移动鼠标制造吸引子，观察流线被扭曲。',
    caption: '∇·E = ρ/ε₀ —— 场是空间每一点上的箭头',
  },
  {
    id: 'toy-pendulum',
    title: '双摆 · 混沌的起点',
    desc: '两根刚性摆杆，仅两个自由度，却对初值极端敏感。两个近乎相同的初值会迅速分道扬镳。',
    caption: '确定性方程 ≠ 可长期预测',
  },
  {
    id: 'toy-wave',
    title: '阻尼谐振 · 稳与不稳',
    desc: '受迫阻尼振子：拖动鼠标调整阻尼与外驱频率，寻找共振峰与临界阻尼的边界。',
    caption: 'ẍ + 2ζω₀ẋ + ω₀²x = f·cos(ωt)',
  },
];

export const ABOUT = {
  title: '关于这个站点',
  paragraphs: [
    '这是一个以「数理」为主题的个人站点首页示例：开屏用 Canvas 绘制时空漩涡，' +
      '把数学与物理的符号沿螺旋轨道吸入奇点，象征知识向更普适的框架收敛。',
    '整站零依赖、零构建：原生 ES Module + Canvas 2D，离线可用。' +
      '所有视觉参数集中在 js/core/config.js，改一处即可全局调色调参。',
  ],
  stats: [
    { value: '16', label: '飞入漩涡的公式' },
    { value: '9', label: '数理里程碑' },
    { value: '3', label: '可交互物理实验' },
    { value: '0', label: '外部依赖' },
  ],
};

export const FOOTER = {
  line: '以公理为舟，以实验为桨。',
  note: 'Canvas 2D · 原生 ES Module · 无外部依赖',
  tags: ['几何', '分析', '时空', '量子', '混沌'],
};
