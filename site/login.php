<?php
require_once __DIR__ . "/auth.php";// $self -> PATH/TO/FILE
$msg = '';
$tab = $_GET['tab'] ?? 'login';
if( ! in_array($tab,['login','register'],true) )
{
    $tab = 'login';
}
if ($_SERVER["REQUEST_METHOD"] == "POST")
{
    verify_token();
    $name=trim($_POST['name'] ?? '');
    $password=$_POST['password'] ?? '';
    if($name === '' || $password === '')
    {
        $msg = "用户名和密码不能为空";
    }
    if($msg === '')
    {
        if(isset($_POST['submit']))
		{
			if($tab === 'login')
			{
				if($_POST['action'] === 'login')
				{
					$find_user = db_query($db,"SELECT id , password , level FROM users WHERE name=:name",[':name' => $name ] );
					if(empty($find_user[0]))
					{
						$msg =  "用户不存在,请先注册";
					}
					else
					{
						if(!password_verify($password,$find_user[0]['password']))
						{
							$msg = "密码错误";
						}
						else
						{
							session_regenerate_id(true);
							$_SESSION['id']=(int)$find_user[0]['id'];
							$_SESSION['name']=$find_user[0]['name'];
							$_SESSION['level']=(int)$find_user[0]['level'];
							header("Location:index.php");
							exit;
						}
					}
				}
			}
			elseif($tab === 'register')
			{
				if($_POST['action'] === 'register')
				{
					$find_user = db_query($db,"SELECT id FROM users WHERE name=:name",[':name' => $name ] );
					if(!empty($find_user[0]))
					{
						$msg = "用户已存在，请重新注册";
					}
					else
					{
						$hash = password_hash($password,PASSWORD_DEFAULT);
						if(!db_execute($db,"INSERT INTO users (name,password,level) VALUES (:name,:password,:level)",[':name' => $name , ':password' => $hash , ':level' => 1 ] ) )
						{
							die("error:database not connected");
						}
							$msg =  "注册成功,请再次登录";
					}
				}
			}
		}
    }
}
?>
<form method="post" action="" >
<input type="hidden" name="token" value="<?= htmlspecialchars($_SESSION['token']) ?>">
name:<input type="text" name="name">
password:<input type="password" name="password">
<?php if($tab === 'login') : ?>
<input type="submit" name="submit" value="登录">
<input type="hidden" name="action" value="login">
<br>
<a href="<?= $self ?>?tab=register">注册</a>
<?php elseif($tab === 'register') : ?>
<input type="submit" name="submit" value="注册">
<input type="hidden" name="action" value="register">
<br>
<a href="<?= $self ?>?tab=login">登录</a>
<?php endif; ?>
<span><?= htmlspecialchars($msg) ?></span>
</form>
<a href="index.php">直接浏览</a>
