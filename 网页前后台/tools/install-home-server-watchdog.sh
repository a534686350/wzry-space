#!/usr/bin/env bash
set -euo pipefail

PROJECT_DIR="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
PROJECT_DIR="$(cd "$PROJECT_DIR" && pwd)"
JAR_PATH="${PROJECT_DIR}/home-server-0.0.1-SNAPSHOT.jar"
TOOLS_DIR="${PROJECT_DIR}/tools"
LOG_DIR="${PROJECT_DIR}/logs"
JAVA_BIN="${JAVA_BIN:-$(command -v java || true)}"

SERVER_SERVICE="/etc/systemd/system/wzry-home-server.service"
WATCHDOG_SERVICE="/etc/systemd/system/wzry-home-watchdog.service"
WATCHDOG_TIMER="/etc/systemd/system/wzry-home-watchdog.timer"

if [[ "$(id -u)" != "0" ]]; then
  echo "请用 root 执行，例如：sudo bash tools/install-home-server-watchdog.sh ${PROJECT_DIR}" >&2
  exit 1
fi

if [[ ! -f "$JAR_PATH" ]]; then
  echo "找不到 Java 后端文件：${JAR_PATH}" >&2
  exit 1
fi

if [[ -z "$JAVA_BIN" ]]; then
  echo "找不到 java 命令，请先安装 JDK/JRE，或执行前设置 JAVA_BIN=/完整/java/路径" >&2
  exit 1
fi

mkdir -p "$LOG_DIR"
chmod +x "${TOOLS_DIR}/home-server-watchdog.sh"

cat > "$SERVER_SERVICE" <<EOF
[Unit]
Description=WZRY Home Java Server
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
WorkingDirectory=${PROJECT_DIR}
ExecStart=${JAVA_BIN} -jar ${JAR_PATH}
Restart=always
RestartSec=5
SuccessExitStatus=143
StandardOutput=append:${LOG_DIR}/home-server.log
StandardError=append:${LOG_DIR}/home-server.err.log

[Install]
WantedBy=multi-user.target
EOF

cat > "$WATCHDOG_SERVICE" <<EOF
[Unit]
Description=WZRY Home Server Port Watchdog
After=wzry-home-server.service

[Service]
Type=oneshot
Environment=HOME_SERVER_HOST=127.0.0.1
Environment=HOME_SERVER_PORT=8888
Environment=HOME_SERVER_SERVICE=wzry-home-server.service
Environment=HOME_SERVER_WATCHDOG_LOG=${LOG_DIR}/home-server-watchdog.log
ExecStart=/bin/bash ${TOOLS_DIR}/home-server-watchdog.sh
EOF

cat > "$WATCHDOG_TIMER" <<EOF
[Unit]
Description=Check WZRY Home Server 8888 Port

[Timer]
OnBootSec=30
OnUnitActiveSec=30
AccuracySec=5
Unit=wzry-home-watchdog.service

[Install]
WantedBy=timers.target
EOF

systemctl daemon-reload
systemctl enable --now wzry-home-server.service
systemctl enable --now wzry-home-watchdog.timer

echo "已安装完成。"
echo "Java 服务：systemctl status wzry-home-server.service"
echo "守护定时器：systemctl status wzry-home-watchdog.timer"
echo "守护日志：tail -f ${LOG_DIR}/home-server-watchdog.log"
