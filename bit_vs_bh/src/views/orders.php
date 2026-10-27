<?php
declare(strict_types=1);
?><div class="page-head">
    <div>
        <p class="eyebrow">COMMAND LOG</p>
        <h1>我的行动</h1>
        <p class="lede">查看待结算命令、撤回误操作，以及这一周的贡献轨迹。</p>
    </div>
    <div class="page-head-actions">
        <span class="tick-note">下轮结算 <strong data-countdown data-until="<?= e(js_time($tick['next_tick_at'])) ?>">--:--</strong></span>
        <a class="btn btn-primary" href="<?= url('/map') ?>">回到地图</a>
    </div>
</div>

<div class="dashboard-grid">
    <div class="dashboard-main">
        <section class="panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow">PENDING ORDERS</p>
                    <h2>待结算行动</h2>
                </div>
                <span class="count-badge"><?= count($pending) ?></span>
            </div>
            <?php if (!$pending): ?>
                <div class="empty-state compact">
                    <span class="empty-glyph">⌁</span>
                    <h3>没有待结算的命令</h3>
                    <p>去地图上选一个节点，把今天的行动点换成战力。</p>
                    <a class="btn btn-primary" href="<?= url('/map') ?>">选择节点</a>
                </div>
            <?php else: ?>
                <div class="order-cards">
                    <?php foreach ($pending as $order):
                        $u = unit($order['unit_code']);
                        ?>
                        <article class="order-card">
                            <span class="unit-glyph"><?= e($u['glyph'] ?? '?') ?></span>
                            <div class="order-card-main">
                                <div class="order-card-title">
                                    <a href="<?= url('/node/' . (int) $order['node_id']) ?>"><?= e($order['node_name']) ?></a>
                                    <span class="action-tag"><?= e(ACTIONS[$order['action']]['name'] ?? $order['action']) ?></span>
                                </div>
                                <p><?= e($u['name'] ?? '') ?> · 战力 <strong><?= (int) $order['power'] ?></strong> · <?= e(fmt_time($order['created_at'], 'm-d H:i')) ?> 下达</p>
                            </div>
                            <form method="post" action="<?= url('/order/cancel') ?>" class="cancel-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                <button class="btn btn-ghost btn-sm" type="submit">撤回</button>
                            </form>
                        </article>
                    <?php endforeach; ?>
                </div>
                <p class="form-fineprint">撤回会返还该行动消耗的行动点；结算开始后无法撤回。</p>
            <?php endif; ?>
        </section>

        <section class="panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow">HISTORY</p>
                    <h2>行动记录</h2>
                </div>
                <span class="muted-note">最近 30 条</span>
            </div>
            <?php if (!$history): ?>
                <div class="mini-empty"><p>还没有已结算的行动。</p><span>第一轮结算后，这里会记录每一次派兵与贡献。</span></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                        <tr>
                            <th>时间</th>
                            <th>节点</th>
                            <th>行动</th>
                            <th>贡献</th>
                            <th>结果</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($history as $row):
                            $u = unit($row['unit_code']);
                            $isVoid = $row['status'] === 'void';
                            ?>
                            <tr class="<?= $isVoid ? 'is-muted' : '' ?>">
                                <td><span class="nowrap"><?= e(fmt_time($row['created_at'], 'm-d H:i')) ?></span></td>
                                <td><a href="<?= url('/node/' . (int) $row['node_id']) ?>"><?= e($row['node_name']) ?></a></td>
                                <td><?= e(ACTIONS[$row['action']]['name'] ?? $row['action']) ?> <span class="muted">/ <?= e($u['name'] ?? '') ?></span></td>
                                <td><?= $isVoid ? '—' : '+' . (int) $row['contribution'] ?></td>
                                <td>
                                    <?php if ($isVoid): ?>
                                        <span class="status-pill is-void">已撤回</span>
                                    <?php elseif ($row['headline']): ?>
                                        <span class="status-pill is-done">已结算</span>
                                        <small class="table-note"><?= e($row['headline']) ?></small>
                                    <?php else: ?>
                                        <span class="status-pill is-done">已执行</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <aside class="dashboard-side">
        <section class="panel profile-card">
            <div class="profile-head">
                <div class="profile-avatar" style="--faction:<?= e($faction['color'] ?? '#94a3b8') ?>"><?= e(mb_substr($user['username'], 0, 1)) ?></div>
                <div>
                    <h2><?= e($user['username']) ?></h2>
                    <p><?= e($faction['name'] ?? '') ?><?php if ($user['title']): ?> · <span class="title-badge"><?= e($user['title']) ?></span><?php endif; ?></p>
                </div>
            </div>
            <div class="ap-row">
                <div class="ap-ring small" style="--ap:<?= max(0, min(100, (int) $user['ap'] / AP_MAX * 100)) ?>">
                    <span><strong><?= (int) $user['ap'] ?></strong><small>/<?= AP_MAX ?></small></span>
                </div>
                <div><span class="muted">今日行动点</span><strong class="block"><?= (int) $user['ap'] ?> / <?= AP_MAX ?></strong></div>
            </div>
            <div class="stat-grid">
                <div><span>赛季贡献</span><strong><?= (int) $user['contribution'] ?></strong></div>
                <div><span>阵营排名</span><strong><?= e(rank_label($rank['faction'])) ?></strong></div>
                <div><span>全服排名</span><strong><?= e(rank_label($rank['overall'])) ?></strong></div>
                <div><span>夺点次数</span><strong><?= (int) $user['season_captures'] ?></strong></div>
                <div><span>胜 / 负</span><strong><?= (int) $user['wins'] ?> / <?= (int) $user['losses'] ?></strong></div>
                <div><span>总贡献</span><strong><?= (int) $user['total_contribution'] ?></strong></div>
            </div>
            <div class="season-stat-bars">
                <div><span>进攻贡献</span><strong><?= (int) $user['season_attack'] ?></strong></div>
                <div><span>防守贡献</span><strong><?= (int) $user['season_defense'] ?></strong></div>
                <div><span>侦察次数</span><strong><?= (int) $user['season_scout'] ?></strong></div>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow">TEAM</p>
                    <h2>小队</h2>
                </div>
            </div>
            <?php if ($team): ?>
                <div class="team-summary">
                    <strong><?= e($team['name']) ?></strong>
                    <p>小队贡献与个人贡献同步累计，可在排行榜查看小队名次。</p>
                    <a class="btn btn-ghost btn-block" href="<?= url('/team') ?>">进入小队页面</a>
                </div>
            <?php else: ?>
                <div class="mini-empty">
                    <p>还没有加入小队。</p>
                    <span>和室友、社团一起上榜，团队贡献单独排名。</span>
                    <a class="btn btn-ghost btn-sm" href="<?= url('/team') ?>">创建 / 加入</a>
                </div>
            <?php endif; ?>
        </section>

        <section class="panel">
            <div class="panel-head"><div><p class="eyebrow">RULES OF ENGAGEMENT</p><h2>行动速查</h2></div></div>
            <ul class="tip-list compact">
                <li><span>攻</span>进攻 3 AP，目标为非本方节点。</li>
                <li><span>守</span>驻防 2 AP，只限本方节点。</li>
                <li><span>援</span>支援 1 AP，按节点归属计入攻/防。</li>
                <li><span>侦</span>侦察 1 AP，情报全阵营共享。</li>
                <li><span>修</span>修复 2 AP，工程兵专属，立即生效。</li>
            </ul>
        </section>
    </aside>
</div>
