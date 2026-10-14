#!/usr/bin/env bash
# 依次检查：源码版(HTTP) / 单文件版(HTTP) / 单文件版(file://)
set -u
cd "$(dirname "$0")/.."
PORT_HTTP=${PORT_HTTP:-8793}; PORT_BIDI=${PORT_BIDI:-9335}
PROFILE="$PWD/.tmp/ff-boot"; rm -rf "$PROFILE"; mkdir -p "$PROFILE"
python3 -m http.server "$PORT_HTTP" --bind 127.0.0.1 >/dev/null 2>&1 & HTTP_PID=$!
MOZ_HEADLESS=1 firefox --headless --profile "$PROFILE" --remote-debugging-port "$PORT_BIDI" --window-size=1440,900 about:blank >/dev/null 2>&1 & FF_PID=$!
for i in $(seq 1 60); do
  [ -f "$PROFILE/WebDriverBiDiServer.json" ] && node -e "require('net').connect($PORT_BIDI,'127.0.0.1').on('connect',()=>process.exit(0)).on('error',()=>process.exit(1))" 2>/dev/null && break
  sleep 0.5
done
sleep 1.5
BIDI_PORT="$PORT_BIDI" node tools/check-boot.mjs \
  "http://127.0.0.1:$PORT_HTTP/index.html,http://127.0.0.1:$PORT_HTTP/dist/index.html,file://$PWD/dist/index.html" \
  "${WAIT:-34000}"
RC=$?
kill $FF_PID $HTTP_PID 2>/dev/null; wait 2>/dev/null
exit $RC
