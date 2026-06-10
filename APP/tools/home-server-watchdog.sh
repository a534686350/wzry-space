#!/usr/bin/env bash
set -u

PORT="${HOME_SERVER_PORT:-8888}"
HOST="${HOME_SERVER_HOST:-127.0.0.1}"
SERVICE="${HOME_SERVER_SERVICE:-wzry-home-server.service}"
LOG_FILE="${HOME_SERVER_WATCHDOG_LOG:-/var/log/wzry-home-watchdog.log}"

log() {
  local msg="$1"
  printf '%s %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$msg" >> "$LOG_FILE" 2>/dev/null || true
}

check_port() {
  timeout 3 bash -c "cat < /dev/null > /dev/tcp/${HOST}/${PORT}" >/dev/null 2>&1
}

if check_port; then
  log "OK ${HOST}:${PORT}"
  exit 0
fi

log "DOWN ${HOST}:${PORT}, restarting ${SERVICE}"
systemctl restart "$SERVICE"
sleep 5

if check_port; then
  log "RECOVERED ${HOST}:${PORT}"
  exit 0
fi

log "FAILED ${HOST}:${PORT} still down after restart"
exit 1
