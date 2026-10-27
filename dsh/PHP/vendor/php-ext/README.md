# vendor/php-ext

这里是随站点附带的 PHP 扩展，用来补上本机 PHP 缺失的 SQLite 支持。

本机（Debian, PHP 8.4.26）**没有**编译 `pdo_sqlite` 和 `sqlite3`，
而评论系统需要 SQLite。因为拿不到 root，所以把 Debian 官方包解开，
只取扩展文件，启动时用 `-d extension=...` 加载（见 `bin/serve.sh`）。

```
vendor/php-ext/usr/lib/php/20240924/pdo_sqlite.so
vendor/php-ext/usr/lib/php/20240924/sqlite3.so
```

## 重新生成

```bash
cd PHP
apt-get download php8.4-sqlite3
dpkg-deb -x php8.4-sqlite3_*.deb vendor/php-ext/
rm php8.4-sqlite3_*.deb
```

`20240924` 是 PHP API 版本，必须和本机 PHP 一致。查法：

```bash
php -r 'echo basename(ini_get("extension_dir"));'
```

## 什么时候不需要它

如果系统 PHP 自带 `pdo_sqlite`（多数发行版默认安装），
`bin/serve.sh` 会检测到并跳过加载，这里的文件只当备份。

> 这些 `.so` 是机器相关的二进制，仓库的 `.gitignore` 已忽略 `*.so`，
> 换机器时按上面步骤重新解包即可。
