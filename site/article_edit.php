<?php
require_once __DIR__ . "/auth.php";
$user_info = current_user();
$article_id = (int)($_GET['id'] ?? 0);
$titleErr=$contentErr=$msg='';
//handle ownership
if($article_id > 0)
{
    $article_info = $db -> query("SELECT * FROM articles WHERE id = " . $article_id) -> fetchArray(SQLITE3_ASSOC);
    if(!$article_info){die('文章不存在');}
    if((int)$article_info['user_id'] !== (int)$user_info['id']){die('你无权编辑此文章');}
    $title = $article_info['title'];
    $content = $article_info['content'];
    $article = [ 'title' => $title , 'content' => $content];
}
else
{
    $article = [ 'title' => '' , 'content' => ''];
    
}
// handle post
if($_SERVER['REQUEST_METHOD']=='POST')
{
    verify_token();
    //post-restart
    if(isset($_POST['restart']))
    {
        header("Location:" . $self . "?id=0");
        exit;
    }
    //post-submit(edit or create)
    if(isset($_POST['submit']))
    {
        $title=($_POST['title'] ?? '');
        $content=($_POST['content'] ?? '');
        $article = [ 'title' => $title , 'content' => $content];
        if(trim($title) === '') $titleErr="标题不能为空";
        if(trim($content) === '') $contentErr="内容不能为空";
        if($titleErr==='' && $contentErr==='')
        {
            //edit
            if($article_id > 0)
            {
                $pre = $db -> prepare("UPDATE articles SET title=:title , content=:content , update_t=(datetime('now','localtime')) WHERE id= " . $article_id);
                if($pre === false){$msg = '数据库请求失败';}
                else
                {
                    $pre -> bindValue(':title',$title,SQLITE3_TEXT);
                    $pre -> bindValue(':content',$content,SQLITE3_TEXT);
                    $src = $pre -> execute();
                    if(!$src){$msg = '数据库请求失败';}
                    else
                    {
                    header("Location:" . $self . "?id=" . (int)$article_id);
                    exit;
                    }
                }
            }
            //create
            else
            {
                $pre = $db -> prepare(
                    "INSERT INTO articles (user_id,title,content) 
                    VALUES (:user_id,:title,:content) 
                    RETURNING id"
                );
                if($pre === false){$msg = '数据库请求失败';}
                else
                {
                    $pre -> bindValue(':user_id',(int)$user_info['id'],SQLITE3_INTEGER);
                    $pre -> bindValue(':title',$title,SQLITE3_TEXT);
                    $pre -> bindValue(':content',$content,SQLITE3_TEXT);
                    $src = $pre -> execute();
                    if($src === false){$msg = '数据库请求失败';}
                    else
                    {
                        $newid = (int)$db -> lastInsertRowID();
                        if($newid <= 0){$msg = '数据库请求失败';}
                        else
                        {
                            header("Location:" . $self . "?id=" . $newid);
                            exit;
                        }
                    }
                }
            }
        }
    }
   
}
?>
<?php include __DIR__ . "/nav.php"; ?>
<form action="" method="post">
<input type="hidden" name="token" value="<?= htmlspecialchars($_SESSION['token'] ?? '')?>">
<h4>标题</h4>
<input type="text" name="title" value="<?php echo htmlspecialchars($article['title']) ?>">
<span><?php echo htmlspecialchars($titleErr) ?></span>
<br>
<h4>内容</h4>
<textarea rows="10" cols="40" name="content"><?php echo htmlspecialchars($article['content']) ?></textarea>
<span><?php echo htmlspecialchars($contentErr) ?></span>
<br>
<input type="submit" name="submit" value="发布文章">
<input type="submit" name="restart" value="再写一篇">
<span><?php echo htmlspecialchars($msg) ?></span>

</form>

