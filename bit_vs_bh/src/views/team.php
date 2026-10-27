<?php
declare(strict_types=1);
$teamScore = 0;
$teamWins = 0;
foreach ($members as $member) {
    $teamScore += (int) $member['contribution'];
    $teamWins += (int) $member['wins'];
}
$joinableTeams = array_values(array_filter($teams, static fn ($t) => (int) $t['faction_id'] === (int) $user['faction_id']));
?><div class="page-head">
    <div>
        <p class="eyebrow">SQUAD ROSTER</p>
        <h1>小队</h1>
        <p class="lede">小队不提供额外战力，但会把成员的贡献加在一起，单独排一张榜。找几个熟人，把名字挂在同一面旗上。</p>
    </div>
    <div class="page-head-actions">
        <span class="tick-note">下轮结算 <strong data-countdown data-until="<?= e(js_time($tick['next_tick_at'])) ?>">--:--</strong></span>
        <a class="btn btn-ghost" href="<?= url('/ranking') ?>">小队榜</a>
    </div>
</div>

<div class="team-layout">
    <div class="team-main">
        <?php if ($team): ?>
            <section class="panel team-hero" style="--faction:<?= e($faction['color'] ?? '#94a3b8') ?>;--faction-deep:<?= e($faction['deep'] ?? '#334155') ?>">
                <div class="team-hero-main">
                    <span class="team-crest"><?= e(mb_substr($team['name'], 0, 1)) ?></span>
                    <div>
                        <p class="eyebrow"><?= e($faction['name'] ?? '') ?> · SQUAD</p>
                        <h2><?= e($team['name']) ?></h2>
                        <p>创建于 <?= e(fmt_time($team['created_at'], 'Y-m-d')) ?> · 队长席位开放，按贡献说话</p>
                    </div>
                </div>
                <div class="team-hero-stats">
                    <div><span>小队排名</span><strong>#<?= (int) ($teamRank ?? '—') ?></strong></div>
                    <div><span>小队贡献</span><strong><?= $teamScore ?></strong></div>
                    <div><span>成员</span><strong><?= count($members) ?></strong></div>
                    <div><span>累计胜场</span><strong><?= $teamWins ?></strong></div>
                </div>
            </section>

            <section class="panel">
                <div class="panel-head">
                    <div><p class="eyebrow">MEMBERS</p><h2>成员名单</h2></div>
                    <span class="muted-note">按赛季贡献排序</span>
                </div>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr><th>#</th><th>指挥官</th><th>称号</th><th class="col-number">贡献</th><th class="col-number">胜 / 负</th><th>最近在线</th></tr></thead>
                        <tbody>
                        <?php foreach ($members as $index => $member): ?>
                            <tr class="<?= (int) $member['id'] === (int) $user['id'] ? 'is-me' : '' ?>">
                                <td><?= $index + 1 ?></td>
                                <td><strong><?= e($member['username']) ?><?= (int) $member['id'] === (int) $user['id'] ? '（你）' : '' ?></strong></td>
                                <td><?= $member['title'] ? '<span class="title-badge">' . e($member['title']) . '</span>' : '<span class="muted">—</span>' ?></td>
                                <td class="col-number"><strong><?= (int) $member['contribution'] ?></strong></td>
                                <td class="col-number"><?= (int) $member['wins'] ?> / <?= (int) $member['losses'] ?></td>
                                <td class="muted"><?= e(fmt_ago($member['last_seen_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="panel">
                <div class="panel-head"><div><p class="eyebrow">SQUAD SETTINGS</p><h2>小队操作</h2></div></div>
                <p class="muted">退出小队后，你的贡献会从该队总分中移除；已获得的个人贡献与称号不受影响。</p>
                <form method="post" action="<?= url('/team') ?>" class="inline-action-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="team_action" value="leave">
                    <button class="btn btn-danger-ghost" type="submit">退出小队</button>
                </form>
            </section>
        <?php else: ?>
            <section class="panel join-panel">
                <div class="panel-head"><div><p class="eyebrow">FORM A SQUAD</p><h2>创建小队</h2></div></div>
                <form method="post" action="<?= url('/team') ?>" class="stack-form narrow-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="team_action" value="create">
                    <label class="field">
                        <span>小队名</span>
                        <input type="text" name="name" required minlength="2" maxlength="20" placeholder="例如：良乡夜班工兵">
                    </label>
                    <p class="field-hint">2–20 位，可用中文、字母、数字、空格、下划线与间隔号。小队会绑定当前阵营。</p>
                    <button class="btn btn-primary" type="submit">创建并加入</button>
                </form>
            </section>

            <section class="panel">
                <div class="panel-head">
                    <div><p class="eyebrow">OPEN ROSTERS</p><h2>加入已有小队</h2></div>
                    <span class="muted-note">仅显示本阵营</span>
                </div>
                <?php if (!$joinableTeams): ?>
                    <div class="mini-empty"><p>本阵营还没有小队。</p><span>直接在上方创建一个，成为第一个挂旗的人。</span></div>
                <?php else: ?>
                    <div class="join-list">
                        <?php foreach ($joinableTeams as $row):
                            $meta = faction_meta(faction_by_id((int) $row['faction_id']));
                            ?>
                            <div class="join-row">
                                <span class="faction-dot" style="--dot:<?= e($meta['color']) ?>"></span>
                                <div class="join-copy">
                                    <strong><?= e($row['name']) ?></strong>
                                    <small><?= (int) $row['members'] ?> 名成员 · 贡献 <?= (int) $row['score'] ?></small>
                                </div>
                                <form method="post" action="<?= url('/team') ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="team_action" value="join">
                                    <input type="hidden" name="team_id" value="<?= (int) $row['id'] ?>">
                                    <button class="btn btn-ghost btn-sm" type="submit">加入</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>

    <aside class="team-side">
        <section class="panel">
            <div class="panel-head"><div><p class="eyebrow">TOP SQUADS</p><h2>小队总榜</h2></div></div>
            <ol class="squad-rank">
                <?php foreach ($teams as $index => $row):
                    $meta = faction_meta(faction_by_id((int) $row['faction_id']));
                    $isMine = $team && (int) $team['id'] === (int) $row['id'];
                    ?>
                    <li class="<?= $isMine ? 'is-mine' : '' ?>">
                        <span class="squad-index"><?= $index + 1 ?></span>
                        <span class="squad-name">
                            <strong><?= e($row['name']) ?></strong>
                            <small><i class="faction-dot" style="--dot:<?= e($meta['color']) ?>"></i><?= e($meta['short']) ?> · <?= (int) $row['members'] ?> 人</small>
                        </span>
                        <span class="squad-score"><?= (int) $row['score'] ?></span>
                    </li>
                <?php endforeach; ?>
                <?php if (!$teams): ?><li class="mini-empty">还没有小队上榜。</li><?php endif; ?>
            </ol>
        </section>

        <section class="panel">
            <div class="panel-head"><div><p class="eyebrow">SQUAD NOTES</p><h2>说明</h2></div></div>
            <ul class="tip-list compact">
                <li><span>01</span>小队贡献 = 全部成员赛季贡献之和。</li>
                <li><span>02</span>小队绑定阵营，跨阵营无法加入。</li>
                <li><span>03</span>队长没有额外权限，也不提供战力加成。</li>
                <li><span>04</span>赛季切换时小队保留，成员贡献重新累计。</li>
            </ul>
        </section>
    </aside>
</div>
