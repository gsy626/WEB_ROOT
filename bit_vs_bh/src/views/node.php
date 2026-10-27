<?php
declare(strict_types=1);

$ownerMeta = faction_meta($owner);
$myFactionId = (int) $user['faction_id'];
$fortify = (int) $node['fortify'];
$garrison = (int) $node['garrison'];
$cap = (int) $node['garrison_cap'];
$garrisonPct = min(100, round($garrison / max(1, $cap) * 100));
$staticDefense = (int) round($garrison * (1 + $fortify / 100));
$isNeutral = $node['owner_faction_id'] === null;
$defaultUnit = $defaultAction === 'attack' ? 'air' : 'engineer';
$initialPower = calculate_base_power($defaultUnit, $defaultAction, $node, $faction);

$powerMap = [];
foreach (UNITS as $code => $u) {
    foreach (['attack', 'defend', 'support'] as $act) {
        $powerMap[$code][$act] = calculate_base_power($code, $act, $node, $faction);
    }
}
$actionMeta = [];
foreach (ACTIONS as $code => $a) {
    $actionMeta[$code] = [
        'name' => $a['name'],
        'ap' => $a['ap'],
        'instant' => in_array($code, ['scout', 'repair'], true),
        'available' => $availability[$code] ?? false,
        'reason' => $reasons[$code] ?? '',
    ];
}
$lore = [
    'plaza' => '主楼前的台阶和旗杆是全校最显眼的坐标。谁站在这里，谁就能把阵营色刷进所有人的信息流。',
    'library' => '自习区的灯常年亮到很晚。这里的价值不在书架，而在于谁能安静地把整支队伍藏进闭馆前的最后一小时。',
    'station' => '校车、共享单车和外卖骑手在此交汇。控制车站，就控制了整条战线的补给节奏。',
    'bridge' => '连接两个片区的天桥，风大、人多、视野极好。中立守备队在这里摆了一张折叠桌。',
    'canteen' => '夜宵窗口是校园里最稳定的后勤设施。香气能鼓舞士气，也能暴露集结位置。',
    'lab' => '工程训练中心的机床声盖过了一切。这里的工事最厚，修复也最快。',
    'field' => '操场看台视野开阔，适合观察，也适合把望远镜借给对面。',
    'hangar' => '机库卷帘门半开，里面停着几架模型机。飞行器队把这里当作起飞点，工程兵则盯上了门口的沙袋。',
];
$myPendingCount = (int) ($ownPending['count'] ?? 0);
?><nav class="breadcrumb" aria-label="面包屑">
    <a href="<?= url('/map') ?>">战区地图</a>
    <span>/</span>
    <span><?= e($node['zone']) ?></span>
    <span>/</span>
    <strong><?= e($node['name']) ?></strong>
</nav>

<section class="node-hero <?= $isNeutral ? 'is-neutral' : '' ?>"
         style="--faction:<?= e($ownerMeta['color']) ?>;--faction-deep:<?= e($ownerMeta['deep']) ?>">
    <div class="node-hero-main">
        <div class="node-title-row">
            <span class="node-zone"><?= e($node['zone']) ?>战区</span>
            <span class="owner-badge"><i class="owner-dot"></i><?= e($ownerMeta['name']) ?></span>
            <span class="kind-chip"><?= e(node_kind_label($node['kind'])) ?></span>
            <?php if ($isOwner): ?><span class="my-tag">我方控制</span><?php endif; ?>
        </div>
        <h1><?= e($node['name']) ?></h1>
        <p class="node-lore"><?= e($lore[$node['kind']] ?? '') ?></p>
        <div class="node-stats">
            <div class="node-stat">
                <span>驻军</span>
                <strong><?= $garrison ?><small>/<?= $cap ?></small></strong>
                <span class="garrison-bar"><span style="width:<?= $garrisonPct ?>%"></span></span>
            </div>
            <div class="node-stat"><span>工事加成</span><strong>+<?= $fortify ?>%</strong></div>
            <div class="node-stat"><span>节点价值</span><strong><?= (int) $node['value'] ?><small> 分</small></strong></div>
            <div class="node-stat"><span>静态防御</span><strong>≈<?= $staticDefense ?></strong></div>
        </div>
    </div>
    <div class="node-hero-side">
        <div class="node-clock">
            <span>下轮结算</span>
            <strong data-countdown data-until="<?= e(js_time($tick['next_tick_at'])) ?>">--:--</strong>
            <small><?= e(fmt_time($tick['next_tick_at'], 'm-d H:i')) ?></small>
        </div>
        <div class="node-pending-summary">
            <div><span>本阵营待结算</span><strong><?= $myPendingCount ?></strong></div>
            <div><span>敌方调动</span><strong><?= (int) $enemyCount ?></strong></div>
        </div>
    </div>
</section>

<div class="node-layout">
    <div class="node-main">
        <section class="panel order-panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow">ISSUE ORDERS</p>
                    <h2>下达行动</h2>
                </div>
                <span class="ap-inline">AP <strong><?= (int) $user['ap'] ?></strong> / <?= AP_MAX ?></span>
            </div>

            <?php if ($isNewPlayer): ?>
                <p class="notice notice-info">新玩家保护期内（注册 24 小时内）只能驻防、支援、侦察或修复。再等一等就能进攻。</p>
            <?php endif; ?>
            <?php if ((int) $user['ap'] < 1): ?>
                <p class="notice notice-warn">行动点已经用完了。每天会补满 <?= AP_DAILY ?> 点，先去看看战报吧。</p>
            <?php endif; ?>

            <form method="post" action="<?= url('/order') ?>" class="order-form" id="order-form"
                  data-powers="<?= e(json_encode($powerMap, JSON_UNESCAPED_UNICODE)) ?>"
                  data-actions="<?= e(json_encode($actionMeta, JSON_UNESCAPED_UNICODE)) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="node_id" value="<?= (int) $node['id'] ?>">

                <fieldset class="field-group">
                    <legend>行动类型</legend>
                    <div class="action-picker">
                        <?php foreach (ACTIONS as $code => $a):
                            $available = (bool) ($availability[$code] ?? false);
                            $checked = $defaultAction === $code;
                            ?>
                            <label class="action-option <?= $available ? '' : 'is-disabled' ?> <?= $checked ? 'is-checked' : '' ?> <?= $code === 'repair' ? 'is-repair' : '' ?>"
                                   title="<?= e($available ? $a['desc'] : ($reasons[$code] ?? '当前不可用')) ?>">
                                <input type="radio" name="action" value="<?= e($code) ?>" <?= $checked ? 'checked' : '' ?> <?= $available ? '' : 'disabled' ?>>
                                <span class="action-name"><?= e($a['name']) ?></span>
                                <span class="action-cost"><?= (int) $a['ap'] ?> AP</span>
                                <?php if (!$available): ?><span class="action-lock"><?= e($reasons[$code] ?? '不可用') ?></span><?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>

                <fieldset class="field-group">
                    <legend>派出兵种</legend>
                    <div class="unit-picker">
                        <?php foreach (UNITS as $code => $u):
                            $checked = $defaultUnit === $code;
                            ?>
                            <label class="unit-option <?= $checked ? 'is-checked' : '' ?>" data-unit="<?= e($code) ?>">
                                <input type="radio" name="unit_code" value="<?= e($code) ?>" <?= $checked ? 'checked' : '' ?>>
                                <span class="unit-top">
                                    <span class="unit-glyph"><?= e($u['glyph']) ?></span>
                                    <span class="unit-heading">
                                        <strong><?= e($u['name']) ?></strong>
                                        <small><?= e($u['role']) ?></small>
                                    </span>
                                    <span class="unit-check" aria-hidden="true">✓</span>
                                </span>
                                <span class="unit-stats">
                                    <span>攻 <?= (int) $u['atk'] ?></span>
                                    <span>守 <?= (int) $u['def'] ?></span>
                                </span>
                                <span class="unit-special"><?= e($u['special']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <?php if (in_array($node['kind'], LANDMARK_KINDS, true)): ?>
                        <p class="field-hint">该节点属于地标，学术小队在此作战获得 +30% 战力。</p>
                    <?php endif; ?>
                </fieldset>

                <div class="order-summary">
                    <div class="summary-item">
                        <span>行动消耗</span>
                        <strong data-cost-output><?= ap_cost($defaultAction) ?> <small>AP</small></strong>
                    </div>
                    <div class="summary-item">
                        <span>战力预估</span>
                        <strong data-power-output>≈<?= $initialPower ?></strong>
                    </div>
                    <div class="summary-item summary-note">
                        <span data-hint-output><?= e(ACTIONS[$defaultAction]['desc']) ?></span>
                    </div>
                    <button class="btn btn-primary btn-lg" type="submit" data-submit-label>下达命令</button>
                </div>
                <p class="form-fineprint">结算前可在「我的行动」撤回并返还行动点；结算后按实际战力与克制关系计算。随机浮动 ±8%。</p>
            </form>
        </section>

        <section class="panel battle-history">
            <div class="panel-head">
                <div>
                    <p class="eyebrow">AFTER ACTION</p>
                    <h2>节点战报</h2>
                </div>
                <a class="text-link" href="<?= url('/reports?node=' . (int) $node['id']) ?>">全部 →</a>
            </div>
            <?php if (!$battles): ?>
                <div class="mini-empty">
                    <p>这里还没有战斗记录。</p>
                    <span>第一场攻防结束后，战报会出现在这里。</span>
                </div>
            <?php else: ?>
                <div class="battle-log">
                    <?php foreach ($battles as $battle):
                        $w = faction_by_id($battle['winner_faction_id'] !== null ? (int) $battle['winner_faction_id'] : null);
                        $wm = faction_meta($w);
                        ?>
                        <details class="battle-detail" style="--faction:<?= e($wm['color']) ?>">
                            <summary>
                                <span class="battle-result <?= (int) $battle['captured'] === 1 ? 'is-capture' : 'is-hold' ?>">
                                    <?= (int) $battle['captured'] === 1 ? '易主' : '守住' ?>
                                </span>
                                <span class="battle-summary">
                                    <strong><?= e($battle['headline']) ?></strong>
                                    <small><?= e(fmt_time($battle['created_at'], 'm-d H:i')) ?> · 攻 <?= (int) $battle['attack_power'] ?> / 守 <?= (int) $battle['defense_power'] ?> · 战损 <?= (int) $battle['attacker_losses'] ?>/<?= (int) $battle['defender_losses'] ?></small>
                                </span>
                                <span class="battle-toggle" aria-hidden="true">＋</span>
                            </summary>
                            <div class="battle-report"><?= nl2br(e($battle['report'])) ?></div>
                        </details>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <aside class="node-side">
        <section class="panel intel-card">
            <div class="panel-head">
                <div>
                    <p class="eyebrow">SIGNAL INTELLIGENCE</p>
                    <h2>敌军情报</h2>
                </div>
                <?php if ($intel): ?>
                    <span class="intel-acc <?= $intelStale ? 'is-stale' : '' ?>"><?= (int) $intel['accuracy'] ?>% 精度</span>
                <?php endif; ?>
            </div>
            <?php if (!$intel): ?>
                <div class="mini-empty">
                    <p>本阵营还没有该节点的侦察情报。</p>
                    <span>派出一支信息兵执行「侦察」，情报会共享给全阵营。</span>
                </div>
            <?php else: ?>
                <div class="intel-range">
                    <span>敌方待结算战力</span>
                    <strong><?= (int) $intel['power_min'] ?><?php if ((int) $intel['power_max'] !== (int) $intel['power_min']): ?>–<?= (int) $intel['power_max'] ?><?php endif; ?></strong>
                    <small><?= $intelStale ? '情报已过期，仅供参照' : '有效至 ' . e(fmt_time($intel['tick_at'], 'H:i')) ?></small>
                </div>
                <ul class="intel-meta">
                    <li><span>侦察兵</span><strong><?= e($intel['username']) ?></strong></li>
                    <li><span>时间</span><strong><?= e(fmt_ago($intel['created_at'])) ?></strong></li>
                    <li><span>精度</span><strong><?= (int) $intel['accuracy'] ?>%</strong></li>
                </ul>
                <?php if ($intelStale): ?>
                    <p class="notice notice-warn">上一轮的情报已经过期，敌军可能已经增兵。</p>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <section class="panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow">FRIENDLY FORCES</p>
                    <h2>我方调度</h2>
                </div>
                <span class="count-badge"><?= $myPendingCount ?></span>
            </div>
            <?php if (!$myOrdersHere): ?>
                <div class="mini-empty">
                    <p>本阵营在该节点没有待结算命令。</p>
                    <span>敌方的调动数量：<?= (int) $enemyCount ?> 支。</span>
                </div>
            <?php else: ?>
                <ul class="friendly-list">
                    <?php foreach ($myOrdersHere as $order): ?>
                        <li>
                            <span class="unit-glyph"><?= e($order['unit']['glyph'] ?? '?') ?></span>
                            <span class="friendly-copy">
                                <strong><?= e($order['username']) ?></strong>
                                <small><?= e(ACTIONS[$order['action']]['name'] ?? $order['action']) ?> · <?= e($order['unit']['name'] ?? '') ?> · 战力 <?= (int) $order['power'] ?></small>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="mini-more">敌方另有 <?= (int) $enemyCount ?> 支调动，具体战力需侦察。</p>
            <?php endif; ?>
        </section>

        <section class="panel defense-card">
            <div class="panel-head">
                <div>
                    <p class="eyebrow">DEFENSE READOUT</p>
                    <h2>防御构成</h2>
                </div>
            </div>
            <ul class="defense-list">
                <li><span>基础驻军</span><strong><?= $garrison ?></strong></li>
                <li><span>工事加成</span><strong>+<?= $fortify ?>%</strong></li>
                <li><span>静态防御</span><strong>≈<?= $staticDefense ?></strong></li>
                <li><span>归属</span><strong style="color:<?= e($ownerMeta['color']) ?>"><?= e($ownerMeta['short']) ?></strong></li>
            </ul>
            <p class="defense-note">若本轮守方获胜，驻防部队会有一部分转化为常驻守军；每轮自然损耗 <?= GARRISON_DECAY_PCT ?>%。</p>
        </section>
    </aside>
</div>
