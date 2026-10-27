<?php
declare(strict_types=1);

/*
 * 《京工双雄：校区混战》游戏配置
 * 纯虚构、娱乐化的校园占点玩法。所有数值均可在本文件中调整。
 */

const APP_NAME = '京工双雄：校区混战';
const APP_SUBTITLE = '北理 vs 北航 · 异步占点战';
const APP_TAGLINE = '一张地图，两支阵营，每周决出京工双雄。';

// 结算周期（分钟）。玩家行动会累积，到点后由 PHP 统一结算。
const TICK_MINUTES = 10;
// 每日行动点
const AP_MAX = 20;
const AP_DAILY = 20;
// 新玩家保护期（小时）：只能驻防 / 支援 / 侦察 / 修复
const NEW_PLAYER_HOURS = 24;
// 同一玩家两次行动的冷却（秒）
const ACTION_INTERVAL_SECONDS = 15;
// 同时待结算的行动上限
const MAX_PENDING_ORDERS = 8;
// 赛季长度（天）
const SEASON_DAYS = 7;
// 每轮驻军自然损耗（百分比）
const GARRISON_DECAY_PCT = 3;
// 防御工事加成上限（百分比）
const FORTIFY_CAP = 30;

const FACTIONS = [
    'bit' => [
        'name' => '北理阵营',
        'short' => '北理',
        'glyph' => '理',
        'motto' => '把工事修到地图上',
        'color' => '#4cc3ff',
        'deep' => '#0b3a55',
        'trait' => '工事加成：驻防行动战力 +10%',
    ],
    'buaa' => [
        'name' => '北航阵营',
        'short' => '北航',
        'glyph' => '航',
        'motto' => '从楼顶起飞，向节点俯冲',
        'color' => '#ff7a6b',
        'deep' => '#5a1c1c',
        'trait' => '机动加成：进攻行动战力 +8%',
    ],
];

const UNIT_ORDER = ['engineer', 'air', 'signal', 'logistics', 'academic'];

const UNITS = [
    'engineer' => [
        'name' => '工程兵',
        'glyph' => '工',
        'atk' => 5,
        'def' => 12,
        'scout' => 0.65,
        'blurb' => '扛着扳手和沙袋上战场，守点与修工事的一把好手。',
        'special' => '修复专精：修复行动 +16 驻军、+2 防御加成',
        'role' => '防守 / 修复',
    ],
    'air' => [
        'name' => '飞行器队',
        'glyph' => '航',
        'atk' => 11,
        'def' => 3,
        'scout' => 0.60,
        'blurb' => '贴着楼顶飞的机动小队，进攻与突袭的首选。',
        'special' => '高攻低防：进攻战力最高，但守点很脆',
        'role' => '进攻 / 突袭',
    ],
    'signal' => [
        'name' => '信息兵',
        'glyph' => '信',
        'atk' => 4,
        'def' => 5,
        'scout' => 0.95,
        'blurb' => '架天线、听频段，把敌军动向变成可读的情报。',
        'special' => '侦察专精：情报精度最高，误差最小',
        'role' => '侦察 / 策应',
    ],
    'logistics' => [
        'name' => '后勤队',
        'glyph' => '后',
        'atk' => 2,
        'def' => 7,
        'scout' => 0.75,
        'blurb' => '食堂、校车、物资调度，驻防时还能顺手补一口行动点。',
        'special' => '驻防返点：驻防结算后返还 1 行动点（不超上限）',
        'role' => '驻防 / 补给',
    ],
    'academic' => [
        'name' => '学术小队',
        'glyph' => '学',
        'atk' => 8,
        'def' => 7,
        'scout' => 0.80,
        'blurb' => '带着投影仪和论文上阵，在图书馆、食堂、实验楼格外能打。',
        'special' => '地标专精：在图书馆 / 食堂 / 实验楼 / 机库作战战力 +30%',
        'role' => '均衡 / 地标',
    ],
];

// 兵种克制环：工程 > 飞行 > 信息 > 工程（仅这三类互相克制）
const UNIT_COUNTERS = [
    'engineer' => 'air',
    'air' => 'signal',
    'signal' => 'engineer',
];

const ACTIONS = [
    'attack' => [
        'name' => '进攻',
        'ap' => 3,
        'side' => 'attack',
        'verb' => '突击',
        'desc' => '向非本方节点派出部队，与同轮同阵营玩家合力攻坚。',
        'tone' => 'red',
    ],
    'defend' => [
        'name' => '驻防',
        'ap' => 2,
        'side' => 'defend',
        'verb' => '驻守',
        'desc' => '在本方节点驻防，结算时转化为防御战力；守住后部分兵力留驻。',
        'tone' => 'blue',
    ],
    'support' => [
        'name' => '支援',
        'ap' => 1,
        'side' => 'auto',
        'verb' => '策应',
        'desc' => '向节点派出策应部队：本方节点计入防御，其他节点计入进攻；战力按 50% 计算。',
        'tone' => 'gold',
    ],
    'scout' => [
        'name' => '侦察',
        'ap' => 1,
        'side' => 'instant',
        'verb' => '侦察',
        'desc' => '立即获取该节点敌军待结算战力的情报，情报对本阵营共享。',
        'tone' => 'cyan',
    ],
    'repair' => [
        'name' => '修复',
        'ap' => 2,
        'side' => 'instant',
        'verb' => '修复',
        'desc' => '工程兵专属：立即修复本方节点工事，增加驻军与防御加成。',
        'tone' => 'green',
    ],
];

const LANDMARK_KINDS = ['library', 'canteen', 'lab', 'hangar'];

const NODE_KIND_LABELS = [
    'plaza' => '广场',
    'library' => '图书馆',
    'station' => '校车站',
    'bridge' => '枢纽',
    'canteen' => '食堂',
    'lab' => '实验楼',
    'field' => '操场',
    'hangar' => '机库',
];

/*
 * 初始地图：x / y 为地图百分比坐标。
 * owner 为 null 表示中立节点。
 * garrison 为开局驻军；neutral_floor 为中立节点被消耗后的最低驻军。
 */
const NODE_SEED = [
    [
        'code' => 'academy-plaza', 'name' => '学院路·主楼广场', 'zone' => '学院路',
        'kind' => 'plaza', 'x' => 32, 'y' => 18, 'value' => 10,
        'base_defense' => 80, 'garrison_cap' => 300, 'owner' => 'bit',
    ],
    [
        'code' => 'academy-library', 'name' => '学院路·图书馆', 'zone' => '学院路',
        'kind' => 'library', 'x' => 10, 'y' => 38, 'value' => 12,
        'base_defense' => 70, 'garrison_cap' => 260, 'owner' => null,
    ],
    [
        'code' => 'academy-station', 'name' => '学院路·校车站', 'zone' => '学院路',
        'kind' => 'station', 'x' => 35, 'y' => 57, 'value' => 8,
        'base_defense' => 65, 'garrison_cap' => 240, 'owner' => null,
    ],
    [
        'code' => 'zc-bridge', 'name' => '中关村·天桥枢纽', 'zone' => '中关村',
        'kind' => 'bridge', 'x' => 52, 'y' => 24, 'value' => 9,
        'base_defense' => 60, 'garrison_cap' => 240, 'owner' => null,
    ],
    [
        'code' => 'zc-canteen', 'name' => '中关村·美食广场', 'zone' => '中关村',
        'kind' => 'canteen', 'x' => 58, 'y' => 47, 'value' => 10,
        'base_defense' => 60, 'garrison_cap' => 240, 'owner' => null,
    ],
    [
        'code' => 'lx-lab', 'name' => '良乡·工程训练中心', 'zone' => '良乡',
        'kind' => 'lab', 'x' => 10, 'y' => 68, 'value' => 11,
        'base_defense' => 85, 'garrison_cap' => 300, 'owner' => 'bit',
    ],
    [
        'code' => 'lx-field', 'name' => '良乡·南操场', 'zone' => '良乡',
        'kind' => 'field', 'x' => 36, 'y' => 85, 'value' => 7,
        'base_defense' => 55, 'garrison_cap' => 220, 'owner' => 'bit',
    ],
    [
        'code' => 'sh-hangar', 'name' => '沙河·飞行器机库', 'zone' => '沙河',
        'kind' => 'hangar', 'x' => 84, 'y' => 23, 'value' => 11,
        'base_defense' => 85, 'garrison_cap' => 300, 'owner' => 'buaa',
    ],
    [
        'code' => 'sh-station', 'name' => '沙河·校车站', 'zone' => '沙河',
        'kind' => 'station', 'x' => 80, 'y' => 50, 'value' => 8,
        'base_defense' => 65, 'garrison_cap' => 240, 'owner' => 'buaa',
    ],
];

// 地图上的通路（仅用于绘制连线）
const NODE_LINKS = [
    ['academy-plaza', 'academy-library'],
    ['academy-plaza', 'academy-station'],
    ['academy-plaza', 'zc-bridge'],
    ['academy-library', 'zc-bridge'],
    ['academy-station', 'zc-canteen'],
    ['zc-bridge', 'zc-canteen'],
    ['zc-canteen', 'lx-field'],
    ['lx-lab', 'lx-field'],
    ['zc-canteen', 'sh-station'],
    ['sh-station', 'sh-hangar'],
];

// 战斗结束后的氛围句子（原创、无真实冲突指向）
const BATTLE_FLAVOR = [
    '食堂的夜宵窗口在交火间隙照常营业，队伍排到了操场。',
    '校车站的末班车晚点了十分钟，司机说前面在“战术拥堵”。',
    '实验楼的投影仪被征用为临时沙盘，论文只好明天再改。',
    '图书馆自习区安静如常，只有桌角的对讲机偶尔闪一下。',
    '操场看台上有人支起望远镜，边看边做战术笔记。',
    '双方在群里刷了三百条“收到”，然后同时开始搬沙袋。',
    '后勤队推着餐车穿过战区，顺手给两边都发了矿泉水。',
];

const DISCLAIMER = '本作品为虚构、娱乐化的校园阵营游戏，与任何真实学校、组织及个人无关；不使用真实校徽、校名标识或受版权保护素材。';
