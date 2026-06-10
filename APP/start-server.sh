#!/bin/bash
# ============================================================
# home-server 启动脚本（带白名单端口配置）
# 原端口 8888/9999 → 改为内部端口 18888/19999
# nginx 在 8888/9999 做白名单检查后再转发到这里
#
# 用法：
#   chmod +x start-server.sh
#   ./start-server.sh          # 前台启动
#   nohup ./start-server.sh &  # 后台启动
# ============================================================

JAR="home-server-0.0.1-SNAPSHOT.jar"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
JAR_PATH="$SCRIPT_DIR/$JAR"

if [ ! -f "$JAR_PATH" ]; then
    echo "[错误] 找不到 $JAR_PATH"
    exit 1
fi

# Spring Boot 通过 --server.port 修改主端口
# 如果 JAR 里有单独的 WebSocket 端口配置，请根据实际情况调整参数名
echo "[启动] 使用内部端口 18888 / 19999（nginx 在外层做白名单检查）"
java -jar "$JAR_PATH" \
     --server.port=18888 \
     --ws.port=19999
