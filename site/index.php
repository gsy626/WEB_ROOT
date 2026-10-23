<?php
require_once __DIR__ . "/auth.php";
include __DIR__ . "/Parsedown.php";
include __DIR__ ."/ParsedownMath.php";
$parsedown = new ParsedownMath([
    'math' => [
        'enabled' => true // Write true to enable the module
    ]
]);
$parsedown -> setSafeMode(true);
$user = current_user();
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
<?php include __DIR__ . "/nav.php"; ?>
<?php   
    $articles_info = db_query($db,"SELECT id , title , content , user_id  FROM articles ORDER BY id DESC");
    foreach($articles_info as $each_article )
	{
		$md_content = $parsedown -> text( $each_article['content'] );
		$summary = mb_substr(strip_tags($md_content),0,100);
		$md_title = $parsedown -> line( $each_article['title'] );
?>
<strong>ID: </strong> <?= (int)$each_article['id'] ?> 
<br>
<strong>TITLE: </strong> <?= $md_title ?>
<br>
<strong>CONTENT: </strong><?= htmlspecialchars((string)$summary) ?>
<a href="article_detail.php?id=<?= (int)$each_article['id'] ?>">阅读全文</a>
<?php if(isset($user)) : ?>
	<?php if((int)$user['level'] === 7) : ?>
		<a href="article_edit.php?id=<?= (int)$each_article['id'] ?>">edit</a>
	<?php elseif((int)$user['level'] >= 2 && (int)$each_article['user_id'] === (int)$user['id'] ) : ?>
		<a href="article_edit.php?id=<?= $each_article['id'] ?>">edit</a>
	<?php endif; ?>
<?php endif; ?>
<br>
<?php } ?>

</html>

