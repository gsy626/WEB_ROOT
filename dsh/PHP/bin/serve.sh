#!/usr/bin/env bash
#
# 启动本站（PHP 内置服务器）
#
#   bin/serve.sh          # http://127.0.0.1:8080
#   bin/serve.sh 9000     # 换端口
#
# 这台机器的 PHP 8.4 没有编译 pdo_sqlite / sqlite3，所以脚本会在需要时
# 自动载入随站点附带的扩展（vendor/php-ext），不需要 root 权限。
#
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT="${1:-8080}"

EXT_ARGS=()
if ! php -r 'exit(extension_loaded("pdo_sqlite") || extension_loaded("sqlite3") ? 0 : 1);' >/dev/null 2>&1; then
  API="$(php -r 'echo basename(ini_get("extension_dir"));')"
  for so in sqlite3.so pdo_sqlite.so; do
    f="$DIR/vendor/php-ext/usr/lib/php/$API/$so"
    if [ -f "$f" ]; then
      EXT_ARGS+=(-d "extension=$f")
      echo "载入扩展  $f"
    fi
  done
  if [ ${#EXT_ARGS[@]} -eq 0 ]; then
    echo "警告：没有找到可用的 SQLite 扩展，评论功能将不可用。" >&2
    echo "      可执行：apt-get download php8.4-sqlite3 && dpkg-deb -x php8.4-sqlite3_*.deb vendor/php-ext/" >&2
  fi
fi

echo "站点根目录  $DIR"
echo "访问地址    http://127.0.0.1:$PORT/"

exec php ${EXT_ARGS[@]+"${EXT_ARGS[@]}"} -S "127.0.0.1:$PORT" -t "$DIR"
