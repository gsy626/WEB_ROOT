#!/usr/bin/env bash
#
# 公式自检：把全站公式抽出来，用本地 KaTeX 全部试渲染一遍。
#
#   bin/check-math.sh
#
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
API="$(php -r 'echo basename(ini_get("extension_dir"));')"

EXT_ARGS=()
if ! php -r 'exit(extension_loaded("pdo_sqlite") || extension_loaded("sqlite3") ? 0 : 1);' >/dev/null 2>&1; then
  for so in sqlite3.so pdo_sqlite.so; do
    f="$DIR/vendor/php-ext/usr/lib/php/$API/$so"
    [ -f "$f" ] && EXT_ARGS+=(-d "extension=$f")
  done
fi

php ${EXT_ARGS[@]+"${EXT_ARGS[@]}"} "$DIR/bin/extract-math.php"
node "$DIR/bin/check-katex.js"
