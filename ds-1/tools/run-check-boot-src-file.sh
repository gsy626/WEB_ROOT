#!/usr/bin/env bash
# 直接双击源码版 index.html（file://）时，应看到明确提示而不是黑屏
set -u
cd "$(dirname "$0")/.."
PORT_BIDI=${PORT_BIDI:-9336}
PROFILE="$PWD/.tmp/ff-boot2"; rm -rf "$PROFILE"; mkdir -p "$PROFILE" _shots
MOZ_HEADLESS=1 firefox --headless --profile "$PROFILE" --remote-debugging-port "$PORT_BIDI" --window-size=1440,900 about:blank >/dev/null 2>&1 & FF_PID=$!
for i in $(seq 1 60); do
  [ -f "$PROFILE/WebDriverBiDiServer.json" ] && node -e "require('net').connect($PORT_BIDI,'127.0.0.1').on('connect',()=>process.exit(0)).on('error',()=>process.exit(1))" 2>/dev/null && break
  sleep 0.5
done
sleep 1.5
BIDI_PORT="$PORT_BIDI" node tools/check-boot.mjs "file://$PWD/index.html" "${WAIT:-6000}"
RC=$?
kill $FF_PID 2>/dev/null; wait 2>/dev/null
exit $RC
