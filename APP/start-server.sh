#!/bin/bash
set -e

JAR="home-server-0.0.1-SNAPSHOT.jar"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
JAR_PATH="$SCRIPT_DIR/$JAR"

if [ ! -f "$JAR_PATH" ]; then
    echo "[error] Missing $JAR_PATH"
    exit 1
fi

echo "[start] home-server uses public ports 8888 / 9999 directly"
exec java -jar "$JAR_PATH"
