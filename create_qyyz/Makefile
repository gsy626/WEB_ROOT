.PHONY: serve watch init-db

# 本地 PHP 内置服务器（排行榜接口需要 PHP）
serve:
	php -S 127.0.0.1:8099 -t .

# 纯静态预览（没有排行榜，仅调试页面 / 游戏）
watch:
	npx -y light-server -s . -p 8080 -w "**/*.html,**/*/*.html,**/*.css,**/*/*.css,**/*.js,**/*/*.js"

init-db:
	php server/init-db.php
