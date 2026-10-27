<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$config = game_config();
$initialGame = (string)($_GET['game'] ?? '');
if (!preg_match('/^[a-f0-9]{16}$/', $initialGame)) {
    $initialGame = '';
}
$inlineConfig = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

$initialState = null;
if ($initialGame !== '') {
    try {
        require_once __DIR__ . '/lib/db.php';
        require_once __DIR__ . '/lib/game.php';
        $initialState = get_state($initialGame);
    } catch (Throwable $e) {
        $initialGame = '';
        $initialState = null;
    }
}
$inlineState = $initialState !== null
    ? json_encode($initialState, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
    : 'null';
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#9d211c">
    <meta name="description" content="五校逐鹿：一款用学术交流吞并邻校的大学城策略小游戏。">
    <title>五校逐鹿 · 985 大学城动态淘汰赛</title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='10' fill='%239d211c'/%3E%3Ctext x='32' y='43' text-anchor='middle' font-size='34' fill='%23f7efe0' font-family='serif'%3E%E9%B9%BF%3C/text%3E%3C/svg%3E">
    <link rel="stylesheet" href="assets/style.css?v=1.1.0">
</head>
<body>
<div id="app">

    <section id="startScreen" class="screen start-screen">
        <div class="paper-noise" aria-hidden="true"></div>
        <header class="masthead">
            <div class="masthead-left">
                <span class="org-seal">教</span>
                <div>
                    <div class="masthead-org">大学城动态淘汰赛组委会</div>
                    <div class="masthead-sub">高等教育资源再分配 · 内部推演文件</div>
                </div>
            </div>
            <div class="masthead-right">
                <span class="confidential">绝密</span>
                <span class="doc-code">教高赛〔2026〕1号</span>
            </div>
        </header>

        <main class="start-main">
            <section class="hero">
                <div class="hero-copy-wrap">
                    <div class="eyebrow"><span></span>反向瘟疫公司 × 大学城吃鸡</div>
                    <h1 class="hero-title" aria-label="五校逐鹿">
                        <span class="title-line">五校</span>
                        <span class="title-line title-outline">逐鹿</span>
                        <span class="hero-stamp">985<br><small>淘汰赛</small></span>
                    </h1>
                    <p class="hero-subtitle">985 大学城动态淘汰赛</p>
                    <p class="hero-intro">
                        教育部突然宣布：五所高校封闭进驻大学城，最后保持独立建制的一所，
                        将获得“超一流大学”称号与无限经费。其余四所，降格为职业技术学院。
                    </p>
                    <p class="hero-intro small">
                        你不必派兵。你要派的是讲座、联培名额、食堂优惠券，以及一句恰到好处的绰号。
                    </p>
                </div>

                <div class="hero-actions">
                    <button class="btn btn-ink btn-lg" id="quickStartBtn" type="button">
                        <span class="btn-icon">印</span>
                        以当前阵容开局
                    </button>
                    <button class="btn btn-paper" id="randomLineupBtn" type="button">
                        <span class="btn-icon">骰</span>
                        随机五校
                    </button>
                    <button class="btn btn-paper" id="resumeBtn" type="button" hidden>
                        <span class="btn-icon">续</span>
                        继续上局
                    </button>
                </div>

                <div class="hero-marquee" aria-hidden="true">
                    <div class="hero-marquee-track">
                        <span>五道口职院连夜运入一批机床</span><i>◆</i>
                        <span>三墩镇人民公园宣布假期再缩短三天</span><i>◆</i>
                        <span>五角场文秘职院附属医院号源被抢空</span><i>◆</i>
                        <span>东川路男子职院联谊群扩容至 5000 人</span><i>◆</i>
                        <span>南七技校保研率上升引发周边高校恐慌</span><i>◆</i>
                        <span>五道口职院连夜运入一批机床</span><i>◆</i>
                        <span>三墩镇人民公园宣布假期再缩短三天</span><i>◆</i>
                        <span>五角场文秘职院附属医院号源被抢空</span><i>◆</i>
                        <span>东川路男子职院联谊群扩容至 5000 人</span><i>◆</i>
                        <span>南七技校保研率上升引发周边高校恐慌</span><i>◆</i>
                    </div>
                </div>

                <div class="rule-grid" id="startRules">
                    <!-- JS 渲染 -->
                </div>

                <div class="hero-footnote">
                    <div class="footnote-mark">玩法提要</div>
                    <p>
                        每回合消耗行政点，从相邻区块投放学科渗透；凑满 100% 即可接管。
                        红专、白专、黑线三条校策路线不可全点。控制大学城 <b>60%</b> 区块，
                        或让对手校长支持率归零，即可赢下这场荒诞的学术战争。
                    </p>
                </div>
            </section>

            <aside class="enlist-panel" aria-label="参战高校名单">
                <div class="panel-tape" aria-hidden="true"></div>
                <div class="panel-head">
                    <div>
                        <div class="panel-kicker">附件一 · 参战高校名单</div>
                        <h2>钦定五校</h2>
                    </div>
                    <div class="panel-stamp">已<br>阅</div>
                </div>
                <p class="panel-desc">
                    你的高校坐镇大学城中央，四方高校环伺。点击每行下拉框即可换校，
                    也可以点右侧加号自建一所从未存在过的大学。
                </p>

                <div id="rosterList" class="roster-list"></div>

                <div class="roster-tools">
                    <button class="text-btn" id="resetLineupBtn" type="button">恢复经典五校</button>
                    <span class="dot-sep">·</span>
                    <button class="text-btn" id="addCustomBtn" type="button">＋ 自建一所高校</button>
                </div>

                <div class="enlist-tip">
                    <span class="tip-icon">注</span>
                    <p>自定义高校可设置校名、绰号、简称、主色、王牌院系、校训、特长与短板。参战五校不可重名。</p>
                </div>

                <div class="enlist-submit">
                    <button class="btn btn-red btn-block" id="startGameBtn" type="button">
                        <span class="btn-icon">戳</span>
                        盖章启动 · 吞并计划
                    </button>
                    <div class="submit-meta">
                        <span><i class="status-dot"></i> 单机人机</span>
                        <span>60 周终局</span>
                        <span>自动存档</span>
                    </div>
                </div>
            </aside>
        </main>

        <footer class="start-footer">
            <span>纯属校园梗娱乐 · 不构成对任何高校的真实评价</span>
            <span>所有校名、绰号与事件均为虚构调侃</span>
            <span>PHP · SQLite · 单文件部署</span>
        </footer>
        <div class="start-side-note" aria-hidden="true">内部资料 · 请勿外传</div>
    </section>

    <section id="gameScreen" class="screen game-screen" hidden>
        <header class="game-header">
            <div class="game-brand">
                <div class="game-seal" id="gameSeal">清</div>
                <div class="game-brand-text">
                    <div class="game-brand-title">五校逐鹿 <span class="game-brand-mark">内部推演</span></div>
                    <div class="game-brand-sub"><span id="playerSchoolName">清华大学</span> · <span id="playerNickname">五道口职业技术学院</span></div>
                </div>
            </div>

            <div class="week-block">
                <span class="week-label">第</span>
                <span class="week-number" id="weekNumber">1</span>
                <span class="week-label">/ <span id="maxWeek">60</span> 周</span>
            </div>

            <div class="stat-strip" id="statStrip">
                <!-- JS 渲染 -->
            </div>

            <div class="header-end">
                <div class="event-chip" id="eventChip" hidden></div>
                <button class="btn btn-red end-turn-btn" id="endTurnBtn" type="button">
                    结束本周
                    <span class="end-turn-sub">盖章推进</span>
                </button>
            </div>
        </header>

        <div class="game-shell">
            <section class="map-card">
                <div class="map-head">
                    <div class="map-title-wrap">
                        <span class="map-kicker">大学城 · 势力图</span>
                        <h2>学术圈地运动</h2>
                    </div>
                    <div class="map-objective">
                        <span class="objective-label">吞并进度</span>
                        <div class="objective-bar"><span id="objectiveBar"></span></div>
                        <span class="objective-num"><b id="controlledCount">4</b> / <span id="thresholdCount">15</span></span>
                    </div>
                    <div class="map-actions">
                        <button class="icon-btn" id="helpBtn" type="button" title="玩法说明">?</button>
                        <button class="icon-btn" id="newGameBtn" type="button" title="重开一局">↻</button>
                    </div>
                </div>
                <div class="map-stage" id="mapStage">
                    <div class="map-loading">正在绘制大学城地图……</div>
                </div>
                <div class="map-foot">
                    <div class="legend" id="mapLegend"></div>
                    <div class="map-hint"><span class="hint-dot"></span>点击区块查看行动 · 左右滑动地图</div>
                </div>
                <div class="news-bar">
                    <div class="news-tag">大学城快讯</div>
                    <div class="ticker-window">
                        <div class="ticker-track" id="newsTicker"></div>
                    </div>
                </div>
            </section>

            <aside class="sidebar">
                <nav class="side-tabs" aria-label="侧栏导航">
                    <button class="side-tab active" type="button" data-tab="action"><span>壹</span>行动</button>
                    <button class="side-tab" type="button" data-tab="tech"><span>贰</span>校策</button>
                    <button class="side-tab" type="button" data-tab="intel"><span>叁</span>情报</button>
                </nav>
                <div class="side-body" id="sideBody">
                    <div class="side-loading">正在调阅档案……</div>
                </div>
                <div class="side-foot">
                    <span id="campaignClock">第 1 周</span>
                    <span>·</span>
                    <span>结算后自动存档</span>
                </div>
            </aside>
        </div>
    </section>

    <div id="modalRoot" class="modal-root" hidden></div>
    <div id="toastRoot" class="toast-root" aria-live="polite"></div>
    <div id="loadingOverlay" class="loading-overlay" hidden>
        <div class="loading-card">
            <div class="loading-seal">审</div>
            <div class="loading-title">正在盖章</div>
            <div class="loading-sub">教务处正在核对你提交的材料……</div>
        </div>
    </div>
</div>

<script>
window.__BOOTSTRAP__ = <?= $inlineConfig ?>;
window.__INITIAL_GAME__ = <?= json_encode($initialGame) ?>;
window.__INITIAL_STATE__ = <?= $inlineState ?>;
</script>
<script src="assets/app.js?v=1.1.0"></script>
</body>
</html>
