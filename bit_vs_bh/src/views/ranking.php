<?php
declare(strict_types=1);
$season = active_season();
$standingsList = array_values($standings);
$leaderId = leading_faction_id($standings);
$lastWinner = $lastSeason && $lastSeason['winner_faction_id'] !== null
    ? faction_by_id((int) $lastSeason['winner_faction_id'])
    : null;
?><div class="page-head">
    <div>
        <p class="eyebrow">SEASON STANDINGS</p>
        <h1>本周排行榜</h1>
        <p class="lede"><?= e($season['name'] ?? '本周赛季') ?>。阵营积分 = 占领节点价值 + 夺点 ×5 + 胜场 ×2。</p>
    </div>
    <div class="page-head-actions">
        <span class="tick-note">下轮结算 <strong data-countdown data-until="<?= e(js_time($tick['next_tick_at'])) ?>">--:--</strong></span>
        <a class="btn btn-ghost" href="<?= url('/reports') ?>">读战报</a>
    </div>
</div>

<?php if ($user && $myRank): ?>
    <div class="my-rank-strip">
        <div><span>我的总排名</span><strong><?= e(rank_label($myRank['overall'])) ?></strong></div>
        <div><span>阵营内排名</span><strong><?= e(rank_label($myRank['faction'])) ?></strong></div>
        <div><span>赛季贡献</span><strong><?= (int) $user['contribution'] ?></strong></div>
        <div class="my-rank-cta"><a class="text-link" href="<?= url('/map') ?>">继续争夺 →</a></div>
    </div>
<?php endif; ?>

<section class="ranking-factions">
    <?php foreach ($standingsList as $row):
        $meta = faction_meta(faction_by_id((int) $row['id']));
        $isLeader = $leaderId !== null && $leaderId === (int) $row['id'];
        ?>
        <article class="faction-standings-card <?= $isLeader ? 'is-leader' : '' ?>" style="--faction:<?= e($meta['color']) ?>;--faction-deep:<?= e($meta['deep']) ?>">
            <div class="faction-standings-head">
                <span class="faction-sigil large"><?= e($meta['glyph']) ?></span>
                <div>
                    <h2><?= e($meta['name']) ?><?php if ($isLeader): ?><span class="leader-tag">领跑</span><?php endif; ?></h2>
                    <p><?= e($meta['motto']) ?></p>
                </div>
                <strong class="faction-big-score"><?= (int) $row['score'] ?><small>分</small></strong>
            </div>
            <div class="faction-breakdown">
                <div><span>节点价值</span><strong><?= (int) $row['node_value'] ?></strong></div>
                <div><span>占领节点</span><strong><?= (int) $row['node_count'] ?>/9</strong></div>
                <div><span>夺点</span><strong><?= (int) $row['captures'] ?></strong></div>
                <div><span>胜场</span><strong><?= (int) $row['wins'] ?></strong></div>
            </div>
        </article>
    <?php endforeach; ?>
</section>

<div class="ranking-layout">
    <section class="panel ranking-panel">
        <div class="panel-head">
            <div>
                <p class="eyebrow">INDIVIDUAL</p>
                <h2>个人贡献榜</h2>
            </div>
            <span class="muted-note">本周前 20</span>
        </div>
        <div class="table-wrap">
            <table class="data-table rank-table">
                <thead>
                <tr>
                    <th class="col-rank">#</th>
                    <th>指挥官</th>
                    <th>阵营</th>
                    <th>小队</th>
                    <th class="col-number">贡献</th>
                    <th class="col-number">夺点</th>
                    <th class="col-number">胜/负</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$individuals): ?>
                    <tr><td colspan="7" class="table-empty">本赛季还没有产生贡献，第一轮结算后见。</td></tr>
                <?php endif; ?>
                <?php foreach ($individuals as $index => $player):
                    $meta = faction_meta(faction_by_id((int) $player['faction_id']));
                    $isMe = $user && (int) $user['id'] === (int) $player['id'];
                    ?>
                    <tr class="<?= $isMe ? 'is-me' : '' ?>">
                        <td class="col-rank"><span class="rank-number <?= $index < 3 ? 'rank-top rank-' . ($index + 1) : '' ?>"><?= $index + 1 ?></span></td>
                        <td>
                            <span class="player-cell">
                                <span class="faction-dot" style="--dot:<?= e($meta['color']) ?>"></span>
                                <strong><?= e($player['username']) ?><?= $isMe ? '（你）' : '' ?></strong>
                                <?php if ($player['title']): ?><span class="title-badge"><?= e($player['title']) ?></span><?php endif; ?>
                            </span>
                        </td>
                        <td><span class="faction-text" style="--faction:<?= e($meta['color']) ?>"><?= e($meta['short']) ?></span></td>
                        <td class="muted"><?= e($player['team_name'] ?? '—') ?></td>
                        <td class="col-number"><strong><?= (int) $player['contribution'] ?></strong></td>
                        <td class="col-number"><?= (int) $player['season_captures'] ?></td>
                        <td class="col-number"><?= (int) $player['wins'] ?>/<?= (int) $player['losses'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <div class="ranking-side">
        <section class="panel ranking-panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow">TEAMS</p>
                    <h2>小队榜</h2>
                </div>
                <span class="muted-note">前 20</span>
            </div>
            <div class="table-wrap">
                <table class="data-table rank-table compact-table">
                    <thead>
                    <tr><th class="col-rank">#</th><th>小队</th><th class="col-number">成员</th><th class="col-number">贡献</th></tr>
                    </thead>
                    <tbody>
                    <?php if (!$teams): ?>
                        <tr><td colspan="4" class="table-empty">还没有小队创建。</td></tr>
                    <?php endif; ?>
                    <?php foreach ($teams as $index => $team):
                        $meta = faction_meta(faction_by_id((int) $team['faction_id']));
                        ?>
                        <tr>
                            <td class="col-rank"><span class="rank-number <?= $index < 3 ? 'rank-top rank-' . ($index + 1) : '' ?>"><?= $index + 1 ?></span></td>
                            <td>
                                <span class="player-cell">
                                    <span class="faction-dot" style="--dot:<?= e($meta['color']) ?>"></span>
                                    <strong><?= e($team['name']) ?></strong>
                                </span>
                                <small class="table-note"><?= e($meta['short']) ?></small>
                            </td>
                            <td class="col-number"><?= (int) $team['members'] ?></td>
                            <td class="col-number"><strong><?= (int) $team['score'] ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <?php if ($lastSeason): ?>
            <section class="panel season-archive">
                <div class="panel-head">
                    <div>
                        <p class="eyebrow">LAST SEASON</p>
                        <h2>上赛季结算</h2>
                    </div>
                    <span class="season-badge">已归档</span>
                </div>
                <?php if ($lastWinner): ?>
                    <p class="archive-winner" style="--faction:<?= e(faction_meta($lastWinner)['color']) ?>">
                        <span>冠军</span><strong><?= e($lastWinner['name']) ?></strong>
                    </p>
                <?php endif; ?>
                <div class="archive-summary"><?= nl2br(e($lastSeason['summary'] ?? '')) ?></div>
            </section>
        <?php endif; ?>
    </div>
</div>

<?php if (!$user): ?>
    <section class="home-cta compact-cta">
        <div>
            <p class="eyebrow">JOIN THE NEXT ROUND</p>
            <h2>下一轮结算前，还来得及入列。</h2>
            <p>注册即得 20 行动点，选择阵营后马上可以驻防与侦察。</p>
        </div>
        <div class="home-cta-actions">
            <a class="btn btn-primary" href="<?= url('/register') ?>">加入阵营</a>
        </div>
    </section>
<?php endif; ?>
