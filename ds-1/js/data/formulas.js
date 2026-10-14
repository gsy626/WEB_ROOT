/* =============================================================================
 * formulas.js —— 公式库（开场漩涡中的飞行符号 / 卡片配图）
 * 使用本项目的迷你 LaTeX 子集，支持 \frac \sqrt \int \sum ^{} _{} 等。
 * ========================================================================== */

/** 开场漩涡：按「历史顺序」被吸入奇点的公式 */
export const INTRO_FORMULAS = [
  'a^{2}+b^{2}=c^{2}',
  '\\frac{a}{b}=\\frac{c}{d}',
  '\\sqrt{2}\\notin\\mathbb{Q}',
  '\\pi r^{2}',
  'x=\\frac{-b\\pm\\sqrt{b^{2}-4ac}}{2a}',
  '\\int_{0}^{1}x^{2}\\,dx=\\frac{1}{3}',
  'e^{i\\pi}+1=0',
  '\\sum_{n=1}^{\\infty}\\frac{1}{n^{2}}=\\frac{\\pi^{2}}{6}',
  '\\nabla\\cdot\\vec{E}=\\frac{\\rho}{\\varepsilon_{0}}',
  'F=G\\frac{m_{1}m_{2}}{r^{2}}',
  'E=mc^{2}',
  'R_{\\mu\\nu}-\\frac{1}{2}Rg_{\\mu\\nu}=\\frac{8\\pi G}{c^{4}}T_{\\mu\\nu}',
  'i\\hbar\\frac{\\partial}{\\partial t}\\Psi=\\hat{H}\\Psi',
  '\\Delta x\\,\\Delta p\\geq\\frac{\\hbar}{2}',
  '\\oint_{C}\\vec{B}\\cdot d\\vec{l}=\\mu_{0}I',
  '\\left[\\hat{x},\\hat{p}\\right]=i\\hbar',
];

/** 卡片正文里用到的公式（供 typeset() 使用） */
export const CARD_FORMULAS = {
  euclid: 'a^{2}+b^{2}=c^{2}',
  calculus: '\\frac{dy}{dx}=\\lim_{h\\to 0}\\frac{f(x+h)-f(x)}{h}',
  riemann: 'ds^{2}=g_{\\mu\\nu}\\,dx^{\\mu}dx^{\\nu}',
  maxwell: '\\nabla\\times\\vec{B}=\\mu_{0}\\vec{J}+\\mu_{0}\\varepsilon_{0}\\frac{\\partial\\vec{E}}{\\partial t}',
  relativity: 'E^{2}=p^{2}c^{2}+m^{2}c^{4}',
  schrodinger: 'i\\hbar\\frac{\\partial\\Psi}{\\partial t}=\\hat{H}\\Psi',
  gauge: '\\mathcal{L}=-\\frac{1}{4}F_{\\mu\\nu}F^{\\mu\\nu}',
  entropy: 'S=k_{B}\\ln\\Omega',
  chaos: 'x_{n+1}=r\\,x_{n}(1-x_{n})',
};
