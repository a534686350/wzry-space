#!/bin/bash
set -euo pipefail

JAR="wz.jar"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
JAR_PATH="$SCRIPT_DIR/$JAR"
LICENSE_SERVER="${LICENSE_SERVER:-http://101.200.36.103:3000}"
LICENSE_HOST="${LICENSE_HOST:-}"
LICENSE_MODE="${LICENSE_MODE:-ops}"
LICENSE_CHECK_INTERVAL="${LICENSE_CHECK_INTERVAL:-60}"
LICENSE_FAIL_GRACE="${LICENSE_FAIL_GRACE:-300}"

if [ ! -f "$JAR_PATH" ]; then
    echo "[error] Missing $JAR_PATH"
    exit 1
fi

if [ -z "$LICENSE_HOST" ]; then
    LICENSE_HOST="$(hostname -I 2>/dev/null | awk '{print $1}')"
fi
if [ -z "$LICENSE_HOST" ]; then
    LICENSE_HOST="$(hostname 2>/dev/null || echo unknown)"
fi

urlencode() {
    python - "$1" <<'PY' 2>/dev/null || php -r 'echo rawurlencode($argv[1]);' "$1" 2>/dev/null || printf '%s' "$1"
import sys
from urllib.parse import quote
print(quote(sys.argv[1]), end="")
PY
}

check_license() {
    local base host mode url body
    base="${LICENSE_SERVER%/}"
    host="$(urlencode "$LICENSE_HOST")"
    mode="$(urlencode "$LICENSE_MODE")"
    url="$base/api/license/check?host=$host&domain=$host&mode=$mode&_=$(date +%s)"
    body="$(curl -fsSL --connect-timeout 5 --max-time 10 "$url" 2>/dev/null || true)"
    if printf '%s' "$body" | grep -q '"authorized"[[:space:]]*:[[:space:]]*true'; then
        return 0
    fi
    if [ -n "$body" ]; then
        echo "[license] denied for host=$LICENSE_HOST mode=$LICENSE_MODE server=$base"
        return 2
    fi
    echo "[license] check failed, server unreachable: $base"
    return 1
}

echo "[license] server=$LICENSE_SERVER host=$LICENSE_HOST mode=$LICENSE_MODE"
if ! check_license; then
    code=$?
    if [ "$code" -eq 2 ]; then
        exit 45
    fi
fi

echo "[start] home-server uses public ports 8888 / 9999 directly"
java -jar "$JAR_PATH" &
child=$!
last_ok="$(date +%s)"

trap 'kill "$child" 2>/dev/null || true; wait "$child" 2>/dev/null || true' INT TERM EXIT

while kill -0 "$child" 2>/dev/null; do
    sleep "$LICENSE_CHECK_INTERVAL"
    if check_license; then
        last_ok="$(date +%s)"
        continue
    fi
    code=$?
    now="$(date +%s)"
    if [ "$code" -eq 2 ] || [ $((now - last_ok)) -ge "$LICENSE_FAIL_GRACE" ]; then
        echo "[license] stopping home-server"
        kill "$child" 2>/dev/null || true
        wait "$child" 2>/dev/null || true
        exit 45
    fi
done

wait "$child"
