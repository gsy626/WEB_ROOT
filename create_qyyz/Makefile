.PHONY: serve init-db

# 本地 PHP 内置服务器（口令页和排行榜接口都需要 PHP）
serve:
	php -S 127.0.0.1:8099 -t .

init-db:
	php server/init-db.php
