<?php
declare(strict_types=1);

$factionMeta = $user ? faction_meta(faction_by_id((int) $user['faction_id'])) : null;
$nextTickIso = $tick ? js_time($tick['next_tick_at']) : '';
$bodyStyle = $factionMeta ? '--my-color:' . e($factionMeta['color']) . ';--my-deep:' . e($factionMeta['deep']) . ';' : '';
?><!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#080d17">
    <meta name="description" content="<?= e(APP_NAME . ' · ' . APP_SUBTITLE . '。加入北理或北航阵营，用行动点争夺校区节点，PHP 定时结算，每周决出京工双雄。') ?>">
    <title><?= e($pageTitle) ?></title>
    <link rel="icon" href="<?= asset('assets/favicon.svg') ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">
    <script src="<?= asset('assets/js/app.js') ?>" defer></script>
</head>
<body style="<?= $bodyStyle ?>" data-page="<?= e(ltrim($currentPath, '/')) ?>">
<a class="skip-link" href="#main">跳到主要内容</a>
<header class="topbar">
    <div class="container topbar-inner">
        <a class="brand" href="<?= url('/') ?>" aria-label="<?= e(APP_NAME) ?> 首页">
            <span class="brand-mark" aria-hidden="true">
                <span class="brand-half brand-half-bit"></span>
                <span class="brand-half brand-half-buaa"></span>
                <span class="brand-core"></span>
            </span>
            <span class="brand-text">
                <strong>京工双雄</strong>
                <small>BIT × BUAA · 校区混战</small>
            </span>
        </a>

        <nav class="main-nav" aria-label="主导航">
            <a class="<?= nav_active('/map') ?>" href="<?= url('/map') ?>">战区地图</a>
            <a class="<?= nav_active('/reports') ?>" href="<?= url('/reports') ?>">战报</a>
            <a class="<?= nav_active('/ranking') ?>" href="<?= url('/ranking') ?>">排行榜</a>
            <?php if ($user): ?>
                <a class="<?= nav_active('/orders') ?>" href="<?= url('/orders') ?>">我的行动</a>
                <a class="<?= nav_active('/team') ?>" href="<?= url('/team') ?>">小队</a>
            <?php endif; ?>
            <a class="<?= nav_active('/rules') ?>" href="<?= url('/rules') ?>">规则</a>
        </nav>

        <div class="topbar-actions">
            <?php if ($tick): ?>
                <span class="tick-chip" title="每 <?= TICK_MINUTES ?> 分钟结算一次">
                    <span class="tick-dot"></span>
                    <span class="tick-label">下轮</span>
                    <span class="tick-time" data-countdown data-until="<?= e($nextTickIso) ?>">--:--</span>
                </span>
            <?php endif; ?>
            <?php if ($user && $factionMeta): ?>
                <a class="ap-chip" href="<?= url('/orders') ?>" title="每日行动点，跨天补满">
                    <span class="ap-label">AP</span>
                    <strong><?= (int) $user['ap'] ?></strong><span class="ap-max">/<?= AP_MAX ?></span>
                </a>
                <div class="user-chip" title="<?= e($factionMeta['name']) ?>">
                    <span class="faction-dot" style="--dot:<?= e($factionMeta['color']) ?>"></span>
                    <span class="user-name"><?= e($user['username']) ?></span>
                    <form method="post" action="<?= url('/logout') ?>" class="inline-form">
                        <?= csrf_field() ?>
                        <button class="logout-btn" type="submit" title="退出登录" aria-label="退出登录">⏻</button>
                    </form>
                </div>
            <?php else: ?>
                <a class="btn btn-ghost btn-sm" href="<?= url('/login') ?>">登录</a>
                <a class="btn btn-primary btn-sm" href="<?= url('/register') ?>">加入战场</a>
            <?php endif; ?>
        </div>
    </div>
</header>

<?php if ($flashes): ?>
    <div class="container flash-stack" role="status" aria-live="polite">
        <?php foreach ($flashes as $flash): ?>
            <div class="flash flash-<?= e($flash['type']) ?>">
                <span class="flash-mark" aria-hidden="true"></span>
                <span><?= e($flash['message']) ?></span>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<main id="main" class="container page">
    <?= $content ?>
</main>

<footer class="site-footer">
    <div class="container footer-inner">
        <div class="footer-brand">
            <span class="footer-mark">京工双雄</span>
            <p><?= e(DISCLAIMER) ?></p>
        </div>
        <div class="footer-meta">
            <span>PHP <?= e(PHP_VERSION) ?> · SQLite · <?= TICK_MINUTES ?> 分钟一轮</span>
            <span>页面以表单提交为主，少量 JS 只负责倒计时与战力估算。</span>
        </div>
    </div>
</footer>
</body>
</html>
