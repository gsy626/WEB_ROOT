<?php
declare(strict_types=1);
$nodeRows = nodes();
?><div class="page-head">
    <div>
        <p class="eyebrow">FIELD MANUAL</p>
        <h1>作战规则</h1>
        <p class="lede">不用下载、不用蹲点。看懂行动点、克制和结算，就能把一整周的战役排明白。</p>
    </div>
    <div class="page-head-actions">
        <a class="btn btn-ghost" href="<?= url('/map') ?>">去地图</a>
        <a class="btn btn-primary" href="<?= url('/register') ?>">加入战场</a>
    </div>
</div>

<div class="rules-layout">
    <div class="rules-main">
        <section class="panel rules-section">
            <div class="panel-head">
                <div><p class="eyebrow">01 / FLOW</p><h2>基本流程</h2></div>
            </div>
            <ol class="rule-steps">
                <li><strong>选阵营并注册。</strong>注册即获得 <?= AP_MAX ?> 行动点；新玩家前 <?= NEW_PLAYER_HOURS ?> 小时只能驻防、支援、侦察或修复。</li>
                <li><strong>在地图上选节点。</strong>每个节点有归属、驻军、工事加成和积分价值。中立节点也可以直接争夺。</li>
                <li><strong>下达行动。</strong>选择兵种与行动类型，消耗行动点；命令进入待结算队列，可在结算前撤回。</li>
                <li><strong>等待统一结算。</strong>每 <?= TICK_MINUTES ?> 分钟，PHP 会把同一节点上的所有命令放在一起结算，生成文字战报。</li>
                <li><strong>看排名，等下周。</strong>每周一 00:00（Asia/Shanghai）赛季切换，地图、积分与行动点重置。</li>
            </ol>
            <p class="notice notice-info">没有 cron 也能运行：任意玩家访问页面时，系统会检查是否到达结算时间并补跑结算。配置 cron 会更准时。</p>
        </section>

        <section class="panel rules-section">
            <div class="panel-head">
                <div><p class="eyebrow">02 / UNITS</p><h2>兵种</h2></div>
            </div>
            <div class="table-wrap">
                <table class="data-table rules-table">
                    <thead><tr><th>兵种</th><th>攻 / 守</th><th>定位</th><th>特性</th></tr></thead>
                    <tbody>
                    <?php foreach (UNITS as $code => $u): ?>
                        <tr>
                            <td><span class="unit-inline"><span class="unit-glyph"><?= e($u['glyph']) ?></span><strong><?= e($u['name']) ?></strong></span></td>
                            <td><span class="nowrap">攻 <?= (int) $u['atk'] ?> / 守 <?= (int) $u['def'] ?></span></td>
                            <td><?= e($u['role']) ?></td>
                            <td>
                                <?= e($u['special']) ?>
                                <?php if (isset(UNIT_COUNTERS[$code])): ?>
                                    <span class="counter-note">克制 <?= e(UNITS[UNIT_COUNTERS[$code]]['name']) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="rule-note">克制环：工程兵 → 飞行器队 → 信息兵 → 工程兵。克制方进攻时战力 ×1.2，被反制时 ×0.8。后勤队与学术小队不参与克制环。</p>
        </section>

        <section class="panel rules-section">
            <div class="panel-head">
                <div><p class="eyebrow">03 / ORDERS</p><h2>行动与消耗</h2></div>
            </div>
            <div class="table-wrap">
                <table class="data-table rules-table">
                    <thead><tr><th>行动</th><th>消耗</th><th>使用条件</th><th>效果</th></tr></thead>
                    <tbody>
                    <?php foreach (ACTIONS as $code => $a): ?>
                        <tr>
                            <td><span class="action-pill"><?= e($a['name']) ?></span></td>
                            <td><?= (int) $a['ap'] ?> AP</td>
                            <td>
                                <?php if ($code === 'attack'): ?>目标非本方节点，且不在新玩家保护期
                                <?php elseif ($code === 'defend'): ?>目标为本方节点
                                <?php elseif ($code === 'support'): ?>任意节点
                                <?php elseif ($code === 'scout'): ?>任意节点
                                <?php else: ?>目标为本方节点，且兵种为工程兵<?php endif; ?>
                            </td>
                            <td><?= e($a['desc']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel rules-section">
            <div class="panel-head">
                <div><p class="eyebrow">04 / FORMULA</p><h2>结算公式</h2></div>
            </div>
            <div class="formula-box">
                <pre><code>进攻方战力 = Σ（行动战力） × 兵种克制 ×（1 + 人数补正）
防守方战力 =（驻军 + Σ驻防/支援战力）×（1 + 工事加成）×（1 + 人数补正）

行动战力 = 兵种基础攻/防
          × 学术地标加成（+30%）
          × 阵营特性（北理驻防 +10%，北航进攻 +8%）
          × 随机浮动（0.92–1.08）</code></pre>
            </div>
            <ul class="rule-list">
                <li><strong>进攻方战力严格大于防守方</strong>才夺点；持平视为防守成功。</li>
                <li>夺点后，新驻军 = 40 + 剩余攻击力 × 0.45（不超过节点上限），工事清零。</li>
                <li>守方获胜时，驻军会保留一部分；所有节点驻军每轮自然损耗 <?= GARRISON_DECAY_PCT ?>%。</li>
                <li>兵种克制看双方参战数量最多的兵种；中立守备队不参与克制。</li>
                <li>人数补正只给在营人数较少的一方，最高 +15%。</li>
            </ul>
        </section>

        <section class="panel rules-section">
            <div class="panel-head">
                <div><p class="eyebrow">05 / NODES</p><h2>节点与积分</h2></div>
            </div>
            <div class="table-wrap">
                <table class="data-table rules-table">
                    <thead><tr><th>节点</th><th>片区</th><th>类型</th><th>价值</th><th>开局归属</th></tr></thead>
                    <tbody>
                    <?php foreach ($nodeRows as $node):
                        $owner = faction_by_id($node['owner_faction_id'] !== null ? (int) $node['owner_faction_id'] : null);
                        $meta = faction_meta($owner);
                        ?>
                        <tr>
                            <td><a href="<?= url('/node/' . (int) $node['id']) ?>"><?= e($node['name']) ?></a></td>
                            <td><?= e($node['zone']) ?></td>
                            <td><?= e(node_kind_label($node['kind'])) ?></td>
                            <td><strong><?= (int) $node['value'] ?></strong></td>
                            <td><span class="faction-text" style="--faction:<?= e($meta['color']) ?>"><?= e($meta['short']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="rule-note">阵营积分 = 当前占领节点价值之和 + 夺点次数 ×5 + 胜场 ×2。个人贡献来自每次结算的参战表现，夺点与防守成功会获得额外贡献。</p>
        </section>

        <section class="panel rules-section">
            <div class="panel-head">
                <div><p class="eyebrow">06 / FAIR PLAY</p><h2>防刷与平衡</h2></div>
            </div>
            <ul class="rule-list two-col">
                <li>每日行动点上限 <?= AP_MAX ?>，跨天补满，不能囤积超过上限。</li>
                <li>同一玩家两次行动间隔 <?= ACTION_INTERVAL_SECONDS ?> 秒，防止脚本连点。</li>
                <li>同时最多 <?= MAX_PENDING_ORDERS ?> 条待结算命令。</li>
                <li>新玩家 <?= NEW_PLAYER_HOURS ?> 小时保护期，只能驻防、支援、侦察、修复。</li>
                <li>随机浮动限制在 ±8%，人数补正最高 +15%，避免单边碾压。</li>
                <li>所有表单带 CSRF Token，使用 PRG 模式避免重复提交；登录连续失败会冷却。</li>
            </ul>
        </section>

        <section class="panel rules-section">
            <div class="panel-head">
                <div><p class="eyebrow">07 / SEASON</p><h2>赛季与称号</h2></div>
            </div>
            <p>每周一 00:00 自动结算上一赛季。冠军阵营全体成员、赛季 MVP、最佳进攻、最佳防守、最佳侦察会获得对应称号，显示在排行榜与个人页。</p>
            <div class="title-showcase">
                <span>第 N 赛季·冠军</span>
                <span>第 N 赛季·MVP</span>
                <span>第 N 赛季·沙河突袭者</span>
                <span>第 N 赛季·良乡守门员</span>
                <span>第 N 赛季·学院路情报官</span>
            </div>
            <p class="rule-note">新赛季开始时，地图恢复初始归属、驻军重置、贡献清零，但总贡献、胜负场与历史称号保留。</p>
        </section>

        <section class="panel rules-section disclaimer-section">
            <div class="panel-head">
                <div><p class="eyebrow">DISCLAIMER</p><h2>虚构声明</h2></div>
            </div>
            <p><?= e(DISCLAIMER) ?></p>
            <p>游戏中的“北理”“北航”仅作为娱乐化阵营名称，玩法、战报、称号均为虚构创作。请勿把游戏内的阵营对抗带入现实，也不要使用真实校园账号密码注册。</p>
        </section>
    </div>

    <aside class="rules-side">
        <section class="panel sticky-panel">
            <div class="panel-head"><div><p class="eyebrow">QUICK REFERENCE</p><h2>速查</h2></div></div>
            <ul class="quick-rules">
                <li><span>结算</span><strong><?= TICK_MINUTES ?> 分钟 / 轮</strong></li>
                <li><span>行动点</span><strong><?= AP_MAX ?> / 天</strong></li>
                <li><span>进攻</span><strong>3 AP</strong></li>
                <li><span>驻防 / 修复</span><strong>2 AP</strong></li>
                <li><span>支援 / 侦察</span><strong>1 AP</strong></li>
                <li><span>最多待结算</span><strong><?= MAX_PENDING_ORDERS ?> 条</strong></li>
                <li><span>新玩家保护</span><strong><?= NEW_PLAYER_HOURS ?> 小时</strong></li>
                <li><span>赛季长度</span><strong><?= SEASON_DAYS ?> 天</strong></li>
            </ul>
            <a class="btn btn-primary btn-block" href="<?= url('/register') ?>">加入战场</a>
        </section>
    </aside>
</div>
