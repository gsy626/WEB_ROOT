<?php
declare(strict_types=1);
$oldUsername = (string) ($old['username'] ?? '');
$oldTeam = (string) ($old['team_name'] ?? '');
$selectedFaction = in_array($presetFaction, array_keys(FACTIONS), true) ? $presetFaction : 'bit';
?><section class="auth-shell auth-shell-wide">
    <div class="auth-visual auth-visual-register" aria-hidden="true">
        <div class="auth-visual-grid"></div>
        <div class="auth-emblem">
            <span class="emblem-half emblem-bit"></span>
            <span class="emblem-half emblem-buaa"></span>
            <span class="emblem-line"></span>
        </div>
        <div class="auth-visual-copy">
            <span class="eyebrow">CHOOSE YOUR SIDE</span>
            <h2>同一张地图，<br>两种打法。</h2>
            <p>北理把节点修成工事，北航从楼顶绕过防线。没有绝对强势的阵营，只有更会读战报的指挥官。</p>
        </div>
        <div class="auth-versus">
            <span class="versus-side versus-bit">BIT<small>工事 · 防守</small></span>
            <span class="versus-mark">VS</span>
            <span class="versus-side versus-buaa">BUAA<small>机动 · 进攻</small></span>
        </div>
        <div class="auth-coord">WEEKLY SEASON / 9 NODES</div>
    </div>

    <div class="auth-card auth-card-register">
        <div class="auth-head">
            <p class="eyebrow">ENLIST</p>
            <h1>加入战场</h1>
            <p>注册即获得 20 行动点。新玩家前 24 小时只能驻防、支援、侦察或修复。</p>
        </div>
        <form method="post" action="<?= url('/register') ?>" class="stack-form">
            <?= csrf_field() ?>

            <fieldset class="field-group">
                <legend>选择阵营</legend>
                <div class="faction-picker">
                    <?php foreach (FACTIONS as $code => $meta):
                        $accent = $meta['color'];
                        $checked = $selectedFaction === $code;
                        ?>
                        <label class="faction-option" style="--faction:<?= e($accent) ?>;--faction-deep:<?= e($meta['deep']) ?>">
                            <input type="radio" name="faction" value="<?= e($code) ?>" <?= $checked ? 'checked' : '' ?> required>
                            <span class="faction-option-inner">
                                <span class="faction-option-glyph"><?= e($meta['glyph']) ?></span>
                                <span class="faction-option-text">
                                    <strong><?= e($meta['name']) ?></strong>
                                    <small><?= e($meta['motto']) ?></small>
                                    <em><?= e($meta['trait']) ?></em>
                                </span>
                                <span class="faction-option-check" aria-hidden="true">✓</span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <div class="field-row">
                <label class="field">
                    <span>用户名</span>
                    <input type="text" name="username" value="<?= e($oldUsername) ?>" autocomplete="username" required minlength="2" maxlength="16" placeholder="2–16 位中文 / 字母 / 数字">
                </label>
                <label class="field">
                    <span>小队（可选）</span>
                    <input type="text" name="team_name" value="<?= e($oldTeam) ?>" maxlength="20" placeholder="输入队名可创建或加入">
                </label>
            </div>
            <p class="field-hint">小队须与本阵营一致；队长无需额外创建，填写同一个队名即可加入。</p>

            <div class="field-row">
                <label class="field">
                    <span>密码</span>
                    <input type="password" name="password" autocomplete="new-password" required minlength="8" maxlength="72" placeholder="至少 8 位">
                </label>
                <label class="field">
                    <span>确认密码</span>
                    <input type="password" name="password_confirm" autocomplete="new-password" required minlength="8" maxlength="72" placeholder="再输一次">
                </label>
            </div>

            <button class="btn btn-primary btn-block btn-lg" type="submit">领取行动点，进入战区</button>
        </form>
        <p class="auth-switch">已有账号？<a href="<?= url('/login') ?>">直接登录</a></p>
        <p class="auth-fineprint"><?= e(DISCLAIMER) ?> 请勿使用真实校园账号密码。</p>
    </div>
</section>
