<?php
declare(strict_types=1);

$standingsList = array_values($standings);
$leaderId = leading_faction_id($standings);
$totalScore = 0;
foreach ($standingsList as $row) {
    $totalScore += $row['score'];
}
$nodeTotal = (int) db()->query('SELECT COUNT(*) FROM nodes')->fetchColumn();
?><section class="home-hero">
    <div class="hero-copy">
        <p class="eyebrow"><span class="live-dot"></span> WEEKLY ASYNC CAMPUS CLASH</p>
        <h1 class="hero-title">
            <span>京工双雄</span>
            <em>校区混战</em>
        </h1>
        <p class="hero-sub">北理 vs 北航 · 异步占点战</p>
        <p class="hero-desc">
            加入一方阵营，用每日行动点部署工程兵、飞行器队与信息兵，争夺学院路、良乡、中关村与沙河的九个节点。
            每 <?= TICK_MINUTES ?> 分钟由 PHP 统一结算战报，一周之后，占领度更高的一方成为「京工双雄」。
        </p>
        <div class="hero-actions">
            <a class="btn btn-primary btn-lg" href="<?= url('/register') ?>">加入战场</a>
            <a class="btn btn-ghost btn-lg" href="<?= url('/rules') ?>">先看规则</a>
        </div>
        <div class="hero-stats">
            <div><strong><?= $playerCount ?></strong><span>在册指挥官</span></div>
            <div><strong><?= $battleCount ?></strong><span>已结算战斗</span></div>
            <div><strong><?= $nodeTotal ?></strong><span>争夺节点</span></div>
        </div>
    </div>

    <aside class="liveboard" aria-label="实时战局">
        <div class="liveboard-head">
            <div>
                <span class="eyebrow">LIVE STANDINGS</span>
                <h2><?= e($season['name'] ?? '本周赛季') ?></h2>
            </div>
            <span class="season-badge">进行中</span>
        </div>
        <div class="liveboard-factions">
            <?php foreach ($standingsList as $row):
                $meta = faction_meta(faction_by_id((int) $row['id']));
                $share = $totalScore > 0 ? round($row['score'] / $totalScore * 100) : 50;
                $isLeader = $leaderId !== null && $leaderId === (int) $row['id'];
                ?>
                <div class="faction-row" style="--faction:<?= e($meta['color']) ?>;--faction-deep:<?= e($meta['deep']) ?>">
                    <div class="faction-row-top">
                        <span class="faction-name">
                            <span class="faction-sigil"><?= e($meta['glyph']) ?></span>
                            <?= e($meta['name']) ?>
                            <?php if ($isLeader): ?><span class="leader-tag">领跑</span><?php endif; ?>
                        </span>
                        <strong class="faction-score"><?= (int) $row['score'] ?><small>分</small></strong>
                    </div>
                    <div class="score-bar"><span style="width:<?= $share ?>%"></span></div>
                    <div class="faction-row-meta">
                        <span>占领 <?= (int) $row['node_count'] ?> 节点</span>
                        <span>夺点 <?= (int) $row['captures'] ?> · 胜场 <?= (int) $row['wins'] ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="liveboard-foot">
            <div class="liveboard-timer">
                <span>下轮结算</span>
                <strong data-countdown data-until="<?= $tick ? e(js_time($tick['next_tick_at'])) : '' ?>">--:--</strong>
            </div>
            <a class="text-link" href="<?= url('/ranking') ?>">完整排行 →</a>
        </div>
        <div class="liveboard-decoration" aria-hidden="true">
            <span></span><span></span><span></span>
        </div>
    </aside>
</section>

<section class="home-strip">
    <div class="strip-item">
        <span class="strip-index">01</span>
        <div><strong>异步开战</strong><p>提交行动即可离开，结算时统一交战，不用蹲点。</p></div>
    </div>
    <div class="strip-item">
        <span class="strip-index">02</span>
        <div><strong>兵种克制</strong><p>工程、飞行、信息三类互相克制，侦察决定胜负。</p></div>
    </div>
    <div class="strip-item">
        <span class="strip-index">03</span>
        <div><strong>每周清零</strong><p>地图、积分与称号每周重开，随时可以后来居上。</p></div>
    </div>
</section>

<section class="section">
    <div class="section-head">
        <div>
            <p class="eyebrow">HOW IT PLAYS</p>
            <h2>三步进入战场</h2>
        </div>
        <a class="text-link" href="<?= url('/rules') ?>">阅读完整规则 →</a>
    </div>
    <div class="feature-grid">
        <article class="feature-card">
            <span class="feature-glyph">旗</span>
            <h3>选阵营，建小队</h3>
            <p>北理偏工程与防守，北航偏机动与进攻。拉上室友或社团成员组队，小队贡献单独排行。</p>
        </article>
        <article class="feature-card">
            <span class="feature-glyph">图</span>
            <h3>看地图，下命令</h3>
            <p>九个节点各有价值、驻军与工事。进攻消耗 3 AP，驻防 2 AP，支援与侦察只要 1 AP。</p>
        </article>
        <article class="feature-card">
            <span class="feature-glyph">报</span>
            <h3>等结算，读战报</h3>
            <p>每轮结算会生成文字战报：兵种克制、人数补正、战损与夺点一目了然，还能分享给队友。</p>
        </article>
    </div>
</section>

<section class="section">
    <div class="section-head">
        <div>
            <p class="eyebrow">LATEST DISPATCHES</p>
            <h2>最新战报</h2>
        </div>
        <a class="text-link" href="<?= url('/reports') ?>">全部战报 →</a>
    </div>
    <?php if (!$recentBattles): ?>
        <div class="empty-state">
            <span class="empty-glyph">⌁</span>
            <h3>战场还很安静</h3>
            <p>第一轮战斗尚未发生。加入阵营、下达第一条命令，成为第一份战报的主角。</p>
            <a class="btn btn-primary" href="<?= url('/register') ?>">成为第一位指挥官</a>
        </div>
    <?php else: ?>
        <div class="dispatch-list">
            <?php foreach ($recentBattles as $battle):
                $winner = faction_by_id($battle['winner_faction_id'] !== null ? (int) $battle['winner_faction_id'] : null);
                $winnerMeta = faction_meta($winner);
                ?>
                <article class="dispatch" style="--faction:<?= e($winnerMeta['color']) ?>">
                    <div class="dispatch-meta">
                        <span class="node-zone"><?= e($battle['zone']) ?></span>
                        <span class="dispatch-time"><?= e(fmt_time($battle['created_at'], 'm-d H:i')) ?></span>
                    </div>
                    <h3><?= e($battle['headline']) ?></h3>
                    <p><?= e(mb_strimwidth(trim(explode("\n", $battle['report'])[1] ?? $battle['report']), 0, 92, '…')) ?></p>
                    <div class="dispatch-foot">
                        <span class="result-badge <?= (int) $battle['captured'] === 1 ? 'is-capture' : 'is-hold' ?>">
                            <?= (int) $battle['captured'] === 1 ? '节点易主' : '防守成功' ?>
                        </span>
                        <span><?= e($winnerMeta['short']) ?> · 攻 <?= (int) $battle['attack_power'] ?> / 守 <?= (int) $battle['defense_power'] ?></span>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="home-cta">
    <div>
        <p class="eyebrow">READY FOR THE WEEK</p>
        <h2>今天的行动点，打算投在哪？</h2>
        <p>登录后即可查看实时地图、敌军调动与自己的排名。零安装，浏览器即开即玩。</p>
    </div>
    <div class="home-cta-actions">
        <a class="btn btn-primary btn-lg" href="<?= url('/register') ?>">注册并选阵营</a>
        <a class="btn btn-ghost btn-lg" href="<?= url('/login') ?>">已有账号</a>
    </div>
</section>
