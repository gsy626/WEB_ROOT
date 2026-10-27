<?php
declare(strict_types=1);
?><section class="error-page">
    <div class="error-code"><?= (int) $errorCode ?></div>
    <p class="eyebrow">OUT OF BOUNDS</p>
    <h1><?= e($errorTitle) ?></h1>
    <p><?= e($errorMessage) ?></p>
    <div class="error-actions">
        <a class="btn btn-primary" href="<?= url('/map') ?>">回到战区地图</a>
        <a class="btn btn-ghost" href="<?= url('/') ?>">返回首页</a>
    </div>
</section>
