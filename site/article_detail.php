<?php
require_once __DIR__ . "/auth.php";
include __DIR__ . "/Parsedown.php";
include __DIR__ ."/ParsedownMath.php";
include __DIR__ ."/nav.php";
$user = current_user();
$article_id = (int)($_GET['id'] ?? 0);
if($article_id <= 0)
{
	die("error:article not found");
}
$articles_info = db_query($db,"SELECT  user_id , title , content , create_t , update_t FROM articles WHERE id=:id",[':id' => (int)$article_id]);
$article_detail = $articles_info[0] ?? NULL;
if(!$article_detail)
{
	die("error:article not found");
}
$user_id = $article_detail['user_id'];//actually writer's id
$title = $article_detail['title'];
$content = $article_detail['content'];
$create_t = $article_detail['create_t'];
$update_t = $article_detail['update_t'];
$writers_info = db_query($db,"SELECT name , level FROM users WHERE id=:id",[':id' => (int)$user_id ]);
$writer_detail = $writers_info[0] ?? NULL;
if(!$writer_detail)
{
	die("error:writer not found");
}
$writer_name = $writer_detail['name'];
$writer_level = $writer_detail['level'];


//$parsedown = new Parsedown();
$parsedown = new ParsedownMath([
    'math' => [
        'enabled' => true // Write true to enable the module
    ]
]);
$parsedown -> setSafeMode(true);
$md_content = $parsedown -> text( $content );
$md_title = $parsedown -> text( $title );
?>

<html lang="zh-CN">
	<head>
	<script>
	window.MathJax = {
		tex: {
			inlineMath: [['$', '$'], ['\\(', '\\)']],
			displayMath: [['$$', '$$'], ['\\[', '\\]']]
		}
	};
	</script>
	<script src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-svg.js"></script>
	</head>
	
	<h1><?= $md_title ?></h1>
	<?php if((int)$user['level'] >= 2 && (int)$user_id === (int)$user['id'] ) : ?>
	<a href="article_edit.php?id=<?= $article_id ?>">edit</a>
	<?php endif; ?>
	<strong> 作者 </strong><?= htmlspecialchars((string)$writer_name) ?>
	<strong> level </strong><?= (int)$writer_level ?>
	<strong> 发布时间 </strong><?= htmlspecialchars((string)$create_t) ?>
	<strong> 更新时间 </strong><?= htmlspecialchars((string)$update_t) ?>
	<?= $md_content ?>
</html>
