<?php
declare(strict_types=1);
$oldUsername = (string) ($old['username'] ?? '');
?><section class="auth-shell">
    <div class="auth-visual auth-visual-login" aria-hidden="true">
        <div class="auth-visual-grid"></div>
        <div class="auth-emblem">
            <span class="emblem-half emblem-bit"></span>
            <span class="emblem-half emblem-buaa"></span>
            <span class="emblem-line"></span>
        </div>
        <div class="auth-visual-copy">
            <span class="eyebrow">RETURN TO THE MAP</span>
            <h2>指挥官，<br>你的节点还在等你。</h2>
            <p>上一轮的战报已经归档。查看驻军、侦察敌情，再把今天剩下的行动点投进战场。</p>
        </div>
        <div class="auth-coord">39.96°N / 116.31°E</div>
    </div>

    <div class="auth-card">
        <div class="auth-head">
            <p class="eyebrow">SIGN IN</p>
            <h1>登录战场</h1>
            <p>使用注册时的用户名和密码。</p>
        </div>
        <form method="post" action="<?= url('/login') ?>" class="stack-form">
            <?= csrf_field() ?>
            <label class="field">
                <span>用户名</span>
                <input type="text" name="username" value="<?= e($oldUsername) ?>" autocomplete="username" required autofocus maxlength="16" placeholder="例如：良乡夜班工兵">
            </label>
            <label class="field">
                <span>密码</span>
                <input type="password" name="password" autocomplete="current-password" required maxlength="72" placeholder="至少 8 位">
            </label>
            <button class="btn btn-primary btn-block btn-lg" type="submit">进入战区</button>
        </form>
        <p class="auth-switch">还没有账号？<a href="<?= url('/register') ?>">选择阵营并注册</a></p>
        <div class="auth-demo">
            <span>本地演示</span>
            <p><code>北理演示号</code> / <code>demo12345</code><br><code>北航演示号</code> / <code>demo12345</code></p>
        </div>
        <p class="auth-fineprint">连续 6 次输错会触发 10 分钟冷却。本站为虚构娱乐作品，请勿使用真实校园账号密码。</p>
    </div>
</section>
