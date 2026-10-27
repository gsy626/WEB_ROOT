<?php
declare(strict_types=1);

$myFactionId = (int) $user['faction_id'];
$standingsList = array_values($standings);
$scoreTotal = 0;
foreach ($standingsList as $row) {
    $scoreTotal += (int) $row['score'];
}
$leaderId = leading_faction_id($standings);
$balanceBonus = (float) ($balance['bonuses'][$myFactionId] ?? 0);
?><section class="map-hero">
    <div class="map-hero-copy">
        <p class="eyebrow"><span class="live-dot"></span> WAR MAP / <?= e($season['name'] ?? '') ?></p>
        <h1>校区战区</h1>
        <p class="lede">九个节点，四个片区。每 <?= TICK_MINUTES ?> 分钟统一结算一次；行动下达后仍可在结算前撤回。</p>
    </div>
    <div class="map-hero-clock">
        <div class="clock-block">
            <span>距离下轮结算</span>
            <strong data-countdown data-until="<?= $tick ? e(js_time($tick['next_tick_at'])) : '' ?>">--:--</strong>
        </div>
        <div class="season-progress">
            <div class="season-progress-head">
                <span>赛季进度</span>
                <span><?= (int) round($seasonProgress) ?>%</span>
            </div>
            <div class="progress-track"><span style="width:<?= (int) round($seasonProgress) ?>%"></span></div>
        </div>
    </div>
</section>

<div class="score-duel" aria-label="阵营比分">
    <?php foreach ($standingsList as $index => $row):
        $meta = faction_meta(faction_by_id((int) $row['id']));
        $isLeader = $leaderId !== null && $leaderId === (int) $row['id'];
        $share = $scoreTotal > 0 ? round((int) $row['score'] / $scoreTotal * 100) : 50;
        ?>
        <div class="duel-side <?= $isLeader ? 'is-leader' : '' ?>" style="--faction:<?= e($meta['color']) ?>;--faction-deep:<?= e($meta['deep']) ?>">
            <div class="duel-side-head">
                <span class="duel-sigil"><?= e($meta['glyph']) ?></span>
                <div>
                    <h2><?= e($meta['name']) ?><?php if ($isLeader): ?><span class="leader-tag">领先</span><?php endif; ?></h2>
                    <p><?= e($meta['motto']) ?></p>
                </div>
                <strong class="duel-score"><?= (int) $row['score'] ?><small>分</small></strong>
            </div>
            <div class="duel-bar"><span style="width:<?= $share ?>%"></span></div>
            <div class="duel-meta">
                <span>节点 <?= (int) $row['node_count'] ?>/<?= count($nodeList) ?></span>
                <span>夺点 <?= (int) $row['captures'] ?></span>
                <span>胜场 <?= (int) $row['wins'] ?></span>
                <?php if ((int) $row['id'] === $myFactionId): ?><span class="my-tag">我方</span><?php endif; ?>
            </div>
        </div>
        <?php if ($index === 0): ?>
            <div class="duel-vs" aria-hidden="true"><span>VS</span><small>WEEK <?= e($season['id'] ?? '') ?></small></div>
        <?php endif; ?>
    <?php endforeach; ?>
</div>

<div class="map-layout">
    <section class="panel map-panel">
        <div class="panel-head map-panel-head">
            <div>
                <p class="eyebrow">TACTICAL OVERVIEW</p>
                <h2>节点地图</h2>
            </div>
            <div class="map-legend">
                <span><i class="legend-dot" style="--dot:<?= e(FACTIONS['bit']['color']) ?>"></i>北理</span>
                <span><i class="legend-dot" style="--dot:<?= e(FACTIONS['buaa']['color']) ?>"></i>北航</span>
                <span><i class="legend-dot" style="--dot:#94a3b8"></i>中立</span>
            </div>
        </div>

        <div class="map-board">
            <div class="map-grid" aria-hidden="true"></div>
            <span class="zone-label zone-label-academy" aria-hidden="true">学院路</span>
            <span class="zone-label zone-label-zc" aria-hidden="true">中关村</span>
            <span class="zone-label zone-label-lx" aria-hidden="true">良乡</span>
            <span class="zone-label zone-label-sh" aria-hidden="true">沙河</span>

            <svg class="map-links" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true" focusable="false">
                <?php
                $coord = [];
                foreach ($nodeList as $n) {
                    $coord[$n['code']] = [(float) $n['x'], (float) $n['y']];
                }
                foreach (NODE_LINKS as [$from, $to]):
                    if (!isset($coord[$from], $coord[$to])) {
                        continue;
                    }
                    ?>
                    <line x1="<?= $coord[$from][0] ?>" y1="<?= $coord[$from][1] ?>" x2="<?= $coord[$to][0] ?>" y2="<?= $coord[$to][1] ?>"></line>
                <?php endforeach; ?>
            </svg>

            <div class="map-nodes">
                <?php foreach ($nodeList as $node):
                    $ownerMeta = faction_meta($node['owner']);
                    $isMine = $node['owner_faction_id'] !== null && (int) $node['owner_faction_id'] === $myFactionId;
                    $pendingCount = (int) $node['pending']['total_count'];
                    $ownPendingCount = (int) ($node['pending']['by_faction'][$myFactionId]['count'] ?? 0);
                    $enemyPendingCount = $pendingCount - $ownPendingCount;
                    $garrisonPct = min(100, round((int) $node['garrison'] / max(1, (int) $node['garrison_cap']) * 100));
                    ?>
                    <a class="map-node <?= $node['owner_faction_id'] === null ? 'is-neutral' : '' ?> <?= $isMine ? 'is-mine' : '' ?>"
                       href="<?= url('/node/' . (int) $node['id']) ?>"
                       style="--x:<?= (float) $node['x'] ?>%;--y:<?= (float) $node['y'] ?>%;--faction:<?= e($ownerMeta['color']) ?>;--faction-deep:<?= e($ownerMeta['deep']) ?>"
                       aria-label="<?= e($node['name'] . '，' . $ownerMeta['name'] . '，驻军 ' . $node['garrison']) ?>">
                        <span class="map-node-top">
                            <span class="map-node-zone"><?= e($node['zone']) ?></span>
                            <span class="map-node-value"><?= (int) $node['value'] ?> 分</span>
                        </span>
                        <span class="map-node-name"><?= e(preg_replace('/^.*?·/', '', $node['name'])) ?></span>
                        <span class="map-node-owner">
                            <i class="owner-dot"></i>
                            <?= e($ownerMeta['short']) ?>
                            <span class="map-node-garrison">驻军 <?= (int) $node['garrison'] ?></span>
                        </span>
                        <span class="garrison-bar" aria-hidden="true"><span style="width:<?= $garrisonPct ?>%"></span></span>
                        <span class="map-node-foot">
                            <span class="node-kind"><?= e(node_kind_label($node['kind'])) ?></span>
                            <?php if ($pendingCount > 0): ?>
                                <span class="pending-chip <?= $ownPendingCount > 0 ? 'has-own' : '' ?>">
                                    待结算 <?= $pendingCount ?><?= $enemyPendingCount > 0 ? ' · 敌 ' . $enemyPendingCount : '' ?>
                                </span>
                            <?php else: ?>
                                <span class="pending-chip is-empty">无调动</span>
                            <?php endif; ?>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <aside class="map-side">
        <section class="panel command-panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow">YOUR COMMAND</p>
                    <h2>今日指挥</h2>
                </div>
                <span class="faction-pill" style="--faction:<?= e($faction['color'] ?? '#94a3b8') ?>"><?= e($faction['short'] ?? '') ?></span>
            </div>
            <div class="ap-display">
                <div class="ap-ring" style="--ap:<?= max(0, min(100, (int) $user['ap'] / AP_MAX * 100)) ?>">
                    <span><strong><?= (int) $user['ap'] ?></strong><small>/<?= AP_MAX ?></small></span>
                </div>
                <div class="ap-copy">
                    <strong>行动点</strong>
                    <p>每天补满 <?= AP_DAILY ?> 点。进攻 3，驻防/修复 2，支援/侦察 1。</p>
                </div>
            </div>
            <div class="stat-pair">
                <div><span>赛季贡献</span><strong><?= (int) $user['contribution'] ?></strong></div>
                <div><span>总排名</span><strong><?= e(rank_label($rank['overall'])) ?></strong></div>
            </div>
            <div class="command-links">
                <a class="btn btn-primary btn-block" href="<?= url('/orders') ?>">查看我的行动</a>
                <a class="btn btn-ghost btn-block" href="<?= url('/team') ?>"><?= $user['team_id'] ? '我的小队' : '创建 / 加入小队' ?></a>
            </div>
            <?php if ($balanceBonus > 0.005): ?>
                <p class="balance-note">
                    人数补正生效：我方在营人数较少，战力 +<?= (int) round($balanceBonus * 100) ?>%。
                </p>
            <?php endif; ?>
        </section>

        <section class="panel pending-panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow">INCOMING</p>
                    <h2>待结算行动</h2>
                </div>
                <span class="count-badge"><?= count($myPending) ?></span>
            </div>
            <?php if (!$myPending): ?>
                <div class="mini-empty">
                    <p>还没有待结算命令。</p>
                    <span>点地图上的节点，派出一支部队。</span>
                </div>
            <?php else: ?>
                <ul class="pending-list">
                    <?php foreach (array_slice($myPending, 0, 4) as $order):
                        $u = unit($order['unit_code']);
                        ?>
                        <li>
                            <span class="unit-glyph"><?= e($u['glyph'] ?? '?') ?></span>
                            <span class="pending-copy">
                                <strong><?= e($order['node_name']) ?></strong>
                                <small><?= e(ACTIONS[$order['action']]['name'] ?? $order['action']) ?> · <?= e($u['name'] ?? '') ?> · 战力 <?= (int) $order['power'] ?></small>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if (count($myPending) > 4): ?>
                    <p class="mini-more">还有 <?= count($myPending) - 4 ?> 条命令，去「我的行动」查看。</p>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <section class="panel intel-panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow">FIELD NOTES</p>
                    <h2>指挥提示</h2>
                </div>
            </div>
            <ul class="tip-list">
                <li><span>01</span>图书馆价值 12 分，但中立驻军不低；先侦察再集火。</li>
                <li><span>02</span>工程兵守点、飞行器队攻坚，信息兵的情报全阵营共享。</li>
                <li><span>03</span>驻防部队守住后会转化为驻军，但每轮自然损耗 <?= GARRISON_DECAY_PCT ?>%。</li>
                <li><span>04</span>人数少的一方会获得最高 +15% 的战场补正。</li>
            </ul>
        </section>
    </aside>
</div>
