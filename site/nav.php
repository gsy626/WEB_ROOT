<?php 
require_once __DIR__ . "/auth.php";
$user = current_user();
?>
<nav>
<a href="index.php">首页</a>
<?php if(!$user) : ?>
    <a href="login.php">登录</a>
<?php else : ?>
    <a href="logout.php">退出登录</a>
    <?php if((int)$user['level'] >= 2) : ?>
        <a href="article_edit.php?id=0">写文章</a> 
        <?php if((int)$user['level'] ===7 ) : ?>
            <a href="super_admin_center.php?tab=users">管理用户</a>
            <a href="super_admin_center.php?tab=articles">管理文章</a>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>
</nav>
