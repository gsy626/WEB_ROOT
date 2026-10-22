#!/usr/bin/env bash
set -u
cd "$(dirname "$0")/.."
PORT_HTTP=${PORT_HTTP:-8791}; PORT_BIDI=${PORT_BIDI:-9333}
PROFILE="$PWD/.tmp/ff"; rm -rf "$PROFILE"; mkdir -p "$PROFILE" _shots
python3 -m http.server "$PORT_HTTP" --bind 127.0.0.1 >/dev/null 2>&1 & HTTP_PID=$!
MOZ_HEADLESS=1 firefox --headless --profile "$PROFILE" --remote-debugging-port "$PORT_BIDI" --window-size=1440,900 about:blank >/dev/null 2>&1 & FF_PID=$!
for i in $(seq 1 60); do [ -f "$PROFILE/WebDriverBiDiServer.json" ] && node -e "require('net').connect($PORT_BIDI,'127.0.0.1').on('connect',()=>process.exit(0)).on('error',()=>process.exit(1))" 2>/dev/null && break; sleep 0.5; done
sleep 1.5
node tools/extract.mjs "http://127.0.0.1:$PORT_HTTP/index.html" _shots/extract "$PORT_BIDI" "${1:-6000}" "${2:-}"
kill $FF_PID $HTTP_PID 2>/dev/null; wait 2>/dev/null
