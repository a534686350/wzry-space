#!/usr/bin/env bash
# WZRY Space cloud updater.
# Run as root in SSH to pull the latest public source and redeploy the web app.

set -Eeuo pipefail

SOURCE="${REPO_SOURCE:-gitee}"
BRANCH="${REPO_BRANCH:-main}"
SRC_DIR="${SRC_DIR:-/opt/wzry-space-src}"
SITE_DIR="${SITE_DIR:-/www/wwwroot/wzry-space}"
REPO_URL="${REPO_URL:-}"
SKIP_DB_MIGRATE=0
SKIP_SERVICE_RESTART=0
SOURCE_SET=0
BRANCH_SET=0
SRC_DIR_SET=0
SITE_DIR_SET=0

red() { printf '\033[0;31m%s\033[0m\n' "$*"; }
green() { printf '\033[0;32m%s\033[0m\n' "$*"; }
yellow() { printf '\033[1;33m%s\033[0m\n' "$*"; }
blue() { printf '\033[0;36m%s\033[0m\n' "$*"; }
die() { red "错误：$*"; exit 1; }

usage() {
    cat <<'EOF'
用法：
  bash scripts/cloud-update.sh
  bash scripts/cloud-update.sh --source github

参数：
  --source github|gitee        更新源，默认 gitee
  --repo-url URL               自定义 Git 仓库地址
  --branch NAME                分支，默认 main
  --src-dir DIR                源码目录，默认 /opt/wzry-space-src
  --site-dir DIR               网站目录，默认 /www/wwwroot/wzry-space
  --skip-db-migrate            跳过数据库升级 SQL
  --skip-service-restart       跳过 Nginx/Java 服务重启
  -h, --help                   查看帮助
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --source) SOURCE="${2:-}"; SOURCE_SET=1; shift 2 ;;
        --source=*) SOURCE="${1#*=}"; SOURCE_SET=1; shift ;;
        --repo-url) REPO_URL="${2:-}"; shift 2 ;;
        --repo-url=*) REPO_URL="${1#*=}"; shift ;;
        --branch) BRANCH="${2:-}"; BRANCH_SET=1; shift 2 ;;
        --branch=*) BRANCH="${1#*=}"; BRANCH_SET=1; shift ;;
        --src-dir) SRC_DIR="${2:-}"; SRC_DIR_SET=1; shift 2 ;;
        --src-dir=*) SRC_DIR="${1#*=}"; SRC_DIR_SET=1; shift ;;
        --site-dir) SITE_DIR="${2:-}"; SITE_DIR_SET=1; shift 2 ;;
        --site-dir=*) SITE_DIR="${1#*=}"; SITE_DIR_SET=1; shift ;;
        --skip-db-migrate) SKIP_DB_MIGRATE=1; shift ;;
        --skip-service-restart) SKIP_SERVICE_RESTART=1; shift ;;
        -h|--help) usage; exit 0 ;;
        *) die "未知参数：$1" ;;
    esac
done

[[ "$(id -u)" -eq 0 ]] || die "请使用 root 执行，例如：sudo bash scripts/cloud-update.sh"

read_install_receipt() {
    local receipt="/root/wzry-space-install.env"
    [[ -f "$receipt" ]] || return 0
    local key value
    while IFS='=' read -r key value; do
        case "$key" in
            SOURCE) [[ "$SOURCE_SET" == "0" && -n "$value" ]] && SOURCE="$value" ;;
            BRANCH) [[ "$BRANCH_SET" == "0" && -n "$value" ]] && BRANCH="$value" ;;
            SRC_DIR) [[ "$SRC_DIR_SET" == "0" && -n "$value" ]] && SRC_DIR="$value" ;;
            SITE_DIR) [[ "$SITE_DIR_SET" == "0" && -n "$value" ]] && SITE_DIR="$value" ;;
        esac
    done < "$receipt"
}

repo_url() {
    if [[ -n "$REPO_URL" ]]; then
        printf '%s\n' "$REPO_URL"
        return
    fi
    case "$SOURCE" in
        github) printf 'https://github.com/a534686350/wzry-space.git\n' ;;
        gitee) printf 'https://gitee.com/hl515/wzry-space.git\n' ;;
        *) die "更新源只能是 github 或 gitee" ;;
    esac
}

ensure_tools() {
    green "正在检查更新工具..."
    if command -v git >/dev/null 2>&1 && command -v rsync >/dev/null 2>&1; then
        return
    fi
    if command -v apt-get >/dev/null 2>&1; then
        apt-get update
        apt-get install -y git rsync ca-certificates
    elif command -v dnf >/dev/null 2>&1; then
        dnf install -y git rsync ca-certificates
    elif command -v yum >/dev/null 2>&1; then
        yum install -y git rsync ca-certificates
    else
        die "缺少 git/rsync，且未识别包管理器"
    fi
}

sync_source() {
    green "正在通过 SSH 远程更新源码..."
    local url
    url="$(repo_url)"
    if [[ -d "$SRC_DIR/.git" ]]; then
        git -C "$SRC_DIR" remote set-url origin "$url"
        git -C "$SRC_DIR" fetch --prune origin "$BRANCH"
        git -C "$SRC_DIR" checkout -B "$BRANCH" "origin/$BRANCH"
    else
        [[ ! -e "$SRC_DIR" ]] || die "$SRC_DIR 已存在，但不是 Git 仓库"
        mkdir -p "$(dirname "$SRC_DIR")"
        git clone --branch "$BRANCH" --depth 1 "$url" "$SRC_DIR"
    fi
    green "源码更新完成"
}

deploy_web_files() {
    green "正在更新前台和后台文件..."
    local web_package="$SRC_DIR/网页前后台"
    [[ -d "$web_package" ]] || die "未找到发布包目录：$web_package"
    [[ -d "$SITE_DIR" ]] || mkdir -p "$SITE_DIR"

    rsync -a --delete \
        --exclude 'auth/config.php' \
        --exclude 'auth/install.lock' \
        --exclude 'logs/' \
        --exclude '*.log' \
        --exclude '.git/' \
        --exclude '.well-known/' \
        --exclude 'apk/ALinRadar-v6-1.2.apk' \
        "$web_package"/ "$SITE_DIR"/

    for file in install-services.sh start-server.sh restore-whitelist.sh ws-whitelist-helper.sh setup-whitelist-iptables.sh nginx-whitelist-ws.conf; do
        if [[ -f "$SRC_DIR/APP/$file" ]]; then
            cp -f "$SRC_DIR/APP/$file" "$SITE_DIR/$file"
        fi
    done

    chmod +x "$SITE_DIR"/*.sh 2>/dev/null || true
    green "前台和后台文件更新完成"
}

load_db_env() {
    local cfg="$SITE_DIR/auth/config.php"
    [[ -f "$cfg" ]] || return 1
    eval "$(php -r "
        \$c = require '$cfg';
        echo 'DB_HOST=' . escapeshellarg(\$c['db_host'] ?? '127.0.0.1') . \"\n\";
        echo 'DB_PORT=' . escapeshellarg(\$c['db_port'] ?? '3306') . \"\n\";
        echo 'DB_NAME=' . escapeshellarg(\$c['db_name'] ?? '') . \"\n\";
        echo 'DB_USER=' . escapeshellarg(\$c['db_user'] ?? '') . \"\n\";
        echo 'DB_PASS=' . escapeshellarg(\$c['db_password'] ?? '') . \"\n\";
    " 2>/dev/null)"
    [[ -n "${DB_NAME:-}" && -n "${DB_USER:-}" ]]
}

mysql_app() {
    MYSQL_PWD="${DB_PASS:-}" mysql -h "${DB_HOST:-127.0.0.1}" -P "${DB_PORT:-3306}" -u "$DB_USER" "$DB_NAME" "$@"
}

import_sql_file() {
    local file="$1"
    [[ -f "$file" ]] || return 0
    mysql_app --force < "$file"
}

migrate_database() {
    if [[ "$SKIP_DB_MIGRATE" == "1" ]]; then
        yellow "已跳过数据库升级"
        return
    fi
    if ! command -v php >/dev/null 2>&1 || ! command -v mysql >/dev/null 2>&1; then
        yellow "缺少 php 或 mysql 命令，跳过数据库升级"
        return
    fi
    if ! load_db_env; then
        yellow "未找到可用数据库配置，跳过数据库升级"
        return
    fi

    green "正在执行数据库升级 SQL..."
    import_sql_file "$SITE_DIR/auth/install_extra.sql"
    import_sql_file "$SITE_DIR/auth/upgrade_agent.sql"
    import_sql_file "$SITE_DIR/auth/upgrade_all.sql"
    import_sql_file "$SRC_DIR/APP/auth/upgrade_whitelist.sql"
    green "数据库升级完成"
}

restart_services() {
    if [[ "$SKIP_SERVICE_RESTART" == "1" ]]; then
        yellow "已跳过服务重启"
        return
    fi

    green "正在重载 Nginx 和重启 Java 服务..."
    if command -v nginx >/dev/null 2>&1; then
        nginx -t
        systemctl reload nginx 2>/dev/null || systemctl restart nginx 2>/dev/null || true
    fi
    if systemctl list-unit-files home-server.service >/dev/null 2>&1; then
        systemctl restart home-server.service || true
    fi
    if systemctl list-unit-files restore-whitelist.service >/dev/null 2>&1; then
        systemctl restart restore-whitelist.service || true
    fi
    green "服务处理完成"
}

server_host() {
    local host ip receipt="/root/wzry-space-install.env"
    host=""
    if [[ -f "$receipt" ]]; then
        host="$(grep '^SERVER_NAME=' "$receipt" | head -n1 | cut -d= -f2- || true)"
    fi
    if [[ -z "$host" || "$host" == "_" ]]; then
        ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
        [[ -n "$ip" ]] || ip="服务器IP"
        printf '%s\n' "$ip"
    else
        printf '%s\n' "$host"
    fi
}

latest_apk_name() {
    find "$SITE_DIR/apk" -maxdepth 1 -type f -name 'ALinRadar-v*.apk' -printf '%f\n' 2>/dev/null | sort -V | tail -n 1
}

print_summary() {
    local host app_file commit
    host="$(server_host)"
    app_file="$(latest_apk_name)"
    commit="$(git -C "$SRC_DIR" rev-parse --short HEAD 2>/dev/null || true)"

    green "========================================"
    green "  远程更新完成"
    green "========================================"
    printf '当前源码版本：      %s\n' "${commit:-unknown}"
    printf '你的前台地址是：    http://%s/\n' "$host"
    printf '你的后台地址是：    http://%s/admin/\n' "$host"
    if [[ -n "$app_file" ]]; then
        printf 'APP 下载路径是：    http://%s/apk/%s\n' "$host" "$app_file"
    fi
    printf '网站目录是：        %s\n' "$SITE_DIR"
    printf '源码目录是：        %s\n' "$SRC_DIR"
}

blue "========================================"
blue "  王者荣耀空间 - SSH 远程更新"
blue "========================================"
read_install_receipt
ensure_tools
sync_source
deploy_web_files
migrate_database
restart_services
print_summary
