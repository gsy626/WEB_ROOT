<?php
declare(strict_types=1);
?><div class="page-head">
    <div>
        <p class="eyebrow">WAR ARCHIVE</p>
        <h1>战报档案</h1>
        <p class="lede">每一轮结算都会留下文字战报。兵力、克制、人数补正与战损都记录在这里。</p>
    </div>
    <div class="page-head-actions">
        <span class="tick-note">下轮结算 <strong data-countdown data-until="<?= e(js_time($tick['next_tick_at'])) ?>">--:--</strong></span>
    </div>
</div>

<div class="filter-bar">
    <form method="get" action="<?= url('/reports') ?>" class="filter-form">
        <label class="field inline-field">
            <span>按节点筛选</span>
            <select name="node">
                <option value="0">全部节点</option>
                <?php foreach ($nodeOptions as $option): ?>
                    <option value="<?= (int) $option['id'] ?>" <?= $nodeFilter === (int) $option['id'] ? 'selected' : '' ?>><?= e($option['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="btn btn-ghost btn-sm" type="submit">筛选</button>
        <?php if ($nodeFilter > 0): ?>
            <a class="text-link" href="<?= url('/reports') ?>">清空</a>
        <?php endif; ?>
    </form>
    <span class="muted-note">共 <?= (int) $total ?> 份战报</span>
</div>

<?php if (!$battles): ?>
    <div class="empty-state">
        <span class="empty-glyph">⌁</span>
        <h3>没有找到战报</h3>
        <p><?= $nodeFilter > 0 ? '这个节点还没有发生过战斗。' : '战场尚未开火，第一轮结算后会生成战报。' ?></p>
        <a class="btn btn-primary" href="<?= url('/map') ?>">前往地图</a>
    </div>
<?php else: ?>
    <div class="report-feed">
        <?php foreach ($battles as $battle):
            $winner = faction_by_id($battle['winner_faction_id'] !== null ? (int) $battle['winner_faction_id'] : null);
            $winnerMeta = faction_meta($winner);
            $lines = explode("\n", $battle['report']);
            $teaser = implode("\n", array_slice($lines, 1, 2));
            ?>
            <article class="report-card" style="--faction:<?= e($winnerMeta['color']) ?>">
                <div class="report-card-head">
                    <div class="report-node">
                        <span class="node-zone"><?= e($battle['zone']) ?></span>
                        <a href="<?= url('/node/' . (int) $battle['node_id']) ?>"><?= e($battle['node_name']) ?></a>
                    </div>
                    <span class="report-round">第 <?= (int) $battle['tick_no'] ?> 轮 · <?= e(fmt_time($battle['created_at'], 'm-d H:i')) ?></span>
                    <span class="result-badge <?= (int) $battle['captured'] === 1 ? 'is-capture' : 'is-hold' ?>">
                        <?= (int) $battle['captured'] === 1 ? '节点易主' : '防守成功' ?>
                    </span>
                </div>
                <h2><?= e($battle['headline']) ?></h2>
                <div class="report-teaser"><?= nl2br(e($teaser)) ?></div>
                <div class="report-numbers">
                    <div><span>进攻方</span><strong><?= e($battle['attacker_short'] ?? '—') ?></strong><small>战力 <?= (int) $battle['attack_power'] ?></small></div>
                    <div><span>防守方</span><strong><?= e($battle['defender_short'] ?? '中立守备队') ?></strong><small>战力 <?= (int) $battle['defense_power'] ?></small></div>
                    <div><span>战损</span><strong><?= (int) $battle['attacker_losses'] ?> / <?= (int) $battle['defender_losses'] ?></strong><small>攻 / 守</small></div>
                    <div><span>参战部队</span><strong><?= (int) $battle['attacker_units'] ?> / <?= (int) $battle['defender_units'] ?></strong><small>攻 / 守</small></div>
                </div>
                <details class="report-full">
                    <summary>阅读完整战报</summary>
                    <div class="battle-report"><?= nl2br(e($battle['report'])) ?></div>
                </details>
            </article>
        <?php endforeach; ?>
    </div>

    <?php if ($pages > 1): ?>
        <nav class="pagination" aria-label="战报分页">
            <?php for ($i = 1; $i <= $pages; $i++):
                $query = ['page' => $i];
                if ($nodeFilter > 0) {
                    $query['node'] = $nodeFilter;
                }
                ?>
                <?php if ($i === $page): ?>
                    <span class="page-chip is-current"><?= $i ?></span>
                <?php else: ?>
                    <a class="page-chip" href="<?= url('/reports?' . http_build_query($query)) ?>"><?= $i ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>
