<?php
require_once __DIR__ . '/auth.php';
$user_info = require_level(3);
$tab = $_GET['tab'] ?? 'users';
if( ! in_array($tab,['users','articles'],true) )
{
    $tab = 'users';
}
include __DIR__ . "/nav.php";
?>
<h1>管理员中心</h1>
<br>
<?php
    if( $tab === 'users' )
    {
        if(isset($_POST['submit']) )
        {
            verify_token();
            $action = $_POST['action'] ?? '';
            if((int)$_POST['ID'] == (int)$user_info['id'])
            {
                die("error: can not delete yourself");
			}
            if(!in_array($action,['delete_user','change_level'],true))
            {
                die("action error");
            } 
            if($action === "delete_user" )
            {
                if( !db_execute( $db , "DELETE FROM users WHERE id=:id" , [':id' => (int)$_POST['ID'] ] ) )
                {
                    die("database error");
                }
                header("Location: " . $self . "?tab=users");
                exit;
            }
            elseif($action === "change_level" )
            {
                if(!in_array((int)$_POST['new_level'],[1,2,3,4,5,6,7],true))
                {
                    die("invalid level");
                }
                if(!db_execute( $db,"UPDATE users SET level=:level WHERE id =:id",[':level' => (int)$_POST['new_level'] , ':id' => (int)$_POST['ID'] ] ) )
                {
                    die("database error");
                }
                header("Location: " . $self . "?tab=users");
                exit;
            }
        }
    }
    elseif($tab === 'articles')
    {
        if(isset($_POST['submit']))
        {
            verify_token();
            $action = $_POST['action'] ?? '';
            if(!in_array($action,['delete_article'],true))
            {
                die("action error");
            }
            if($action === "delete_article")
            {
                if( !db_execute( $db , "DELETE FROM articles WHERE id=:id" , [':id' => (int)$_POST['ID'] ] ) )
                {
                    die("database error");
                }
                header("Location: " . $self . "?tab=articles");
                exit;
            }
        }
    }
?>

<?php if($tab === 'users') : ?>
<h2>用户列表</h2>
<?php
$users_table = db_query($db,"SELECT id , name , level FROM users ORDER BY id DESC");
foreach( $users_table as $each_user )
{
    if((int)$each_user['id'] !== $user_info['id']) :
?>
    <strong>USER_ID: </strong><?= (int)$each_user['id'] ?>
    <strong>NAME: </strong><?= htmlspecialchars((string)$each_user['name']) ?>
    <strong>LEVEL: </strong><?= (int)$each_user['level'] ?>
    
    <form action="" method="post" onsubmit="return confirm('确认删除?');" >
        <input type="hidden" name="token" value="<?= htmlspecialchars($_SESSION['token']) ?>">
        <input type="hidden" name="action" value="delete_user">
        <input type="hidden" name="ID" value="<?= (int)$each_user['id'] ?>">
        <input type="submit" name="submit" value="删除">  
    </form>
    
    <form action="" method="post" onsubmit="return confirm('确认更改?');" >
        <input type="hidden" name="token" value="<?= htmlspecialchars($_SESSION['token']) ?>">
        <input type="hidden" name="action" value="change_level">
        <input type="hidden" name="ID" value="<?= (int)$each_user['id'] ?>">
        <select name="new_level">
            <option value="1">1</option>
            <option value="2">2</option>
            <option value="3">3</option>
            <option value="4">4</option>
            <option value="5">5</option>
            <option value="6">6</option>
            <option value="7">7</option>
        </select>
        <input type="submit" name="submit" value="修改权限"> 
    </form>
<?php
  endif;
}
?>
<?php elseif($tab === 'articles') : ?>
<h2>文章列表</h2>
<?php
$articles_table = db_query($db,"SELECT id , user_id , title , create_t , update_t  FROM articles ORDER BY id DESC");
foreach( $articles_table as $each_article )
{    
?>
    <strong>ARTICLE_ID: </strong><?= (int)$each_article['id'] ?>
    <strong>TITLE: </strong><?= htmlspecialchars((string)$each_article['title']) ?>
    <strong>OWNER: </strong><?= (int)$each_article['user_id'] ?>
    <strong>CREATE_AT: </strong><?= htmlspecialchars((string)$each_article['create_t'])  ?>
    <strong>UPDATE_AT: </strong><?= htmlspecialchars((string)$each_article['update_t'])  ?>
    
    <form action="" method="post" onsubmit="return confirm('确认删除?');" >
        <input type="hidden" name="token" value="<?= htmlspecialchars($_SESSION['token']) ?>">
        <input type="hidden" name="action" value="delete_article">
        <input type="hidden" name="ID" value="<?= (int)$each_article['id'] ?>">
        <input type="submit" name="submit" value="删除">  
    </form>
<?php } ?>
<?php endif; ?>


