#!/usr/bin/env bash
# 巡检 dist/index.html 单文件版（这是实际交付给用户的产物）
set -u
cd "$(dirname "$0")/.."
PORT_HTTP=${PORT_HTTP:-8792}; PORT_BIDI=${PORT_BIDI:-9334}
PROFILE="$PWD/.tmp/ff-dist"; rm -rf "$PROFILE"; mkdir -p "$PROFILE" _shots
python3 -m http.server "$PORT_HTTP" --bind 127.0.0.1 >/dev/null 2>&1 & HTTP_PID=$!
MOZ_HEADLESS=1 firefox --headless --profile "$PROFILE" --remote-debugging-port "$PORT_BIDI" --window-size=1440,900 about:blank >/dev/null 2>&1 & FF_PID=$!
for i in $(seq 1 60); do
  [ -f "$PROFILE/WebDriverBiDiServer.json" ] && node -e "require('net').connect($PORT_BIDI,'127.0.0.1').on('connect',()=>process.exit(0)).on('error',()=>process.exit(1))" 2>/dev/null && break
  sleep 0.5
done
sleep 1.5
BIDI_PORT="$PORT_BIDI" node tools/audit.mjs "http://127.0.0.1:$PORT_HTTP/dist/index.html" _shots "$PORT_BIDI"
RC=$?
kill $FF_PID $HTTP_PID 2>/dev/null; wait 2>/dev/null
exit $RC
