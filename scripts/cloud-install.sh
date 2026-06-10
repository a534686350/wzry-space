#!/usr/bin/env bash
# WZRY Space cloud installer.
# Run as root in an SSH terminal. The script guides the user through source
# download, web/backend deployment, Java service setup, and port opening.

set -Eeuo pipefail

INSTALL_CODE_SHA256_DEFAULT="c733e96fe535e3b70c05da0559698c941c0973e3ebc69688231dd5e8fa77a6f4"

SOURCE="${REPO_SOURCE:-gitee}"
BRANCH="${REPO_BRANCH:-main}"
SRC_DIR="${SRC_DIR:-/opt/wzry-space-src}"
SITE_DIR="${SITE_DIR:-/www/wwwroot/wzry-space}"
SERVER_NAME="${SERVER_NAME:-_}"
INSTALL_CODE="${WZRY_INSTALL_CODE:-}"
DB_ROOT_USER="${DB_ROOT_USER:-root}"
DB_ROOT_PASSWORD="${DB_ROOT_PASSWORD:-}"
DB_NAME="${DB_NAME:-wzry_space}"
DB_USER="${DB_USER:-wzry_space}"
DB_PASSWORD="${DB_PASSWORD:-}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-}"
RESET_ADMIN_KEY="${RESET_ADMIN_KEY:-}"
REPO_URL="${REPO_URL:-}"
SKIP_SERVICES=0
SKIP_DB=0
REINSTALL_DB=0
YES=0
DB_INITIALIZED=0
ADMIN_CREATED=0

red() { printf '\033[0;31m%s\033[0m\n' "$*"; }
green() { printf '\033[0;32m%s\033[0m\n' "$*"; }
yellow() { printf '\033[1;33m%s\033[0m\n' "$*"; }
blue() { printf '\033[0;36m%s\033[0m\n' "$*"; }
die() { red "错误：$*"; exit 1; }

usage() {
    cat <<'EOF'
用法：
  bash scripts/cloud-install.sh
  bash scripts/cloud-install.sh --install-code CODE --source gitee

常用参数：
  --install-code CODE          安装授权码；不传则在 SSH 窗口交互输入
  --source github|gitee        源码下载源，默认 gitee
  --repo-url URL               自定义源码仓库地址
  --branch NAME                分支，默认 main
  --server-name NAME           域名；不绑定域名可填 _
  --site-dir DIR               网站目录，默认 /www/wwwroot/wzry-space
  --src-dir DIR                源码目录，默认 /opt/wzry-space-src
  --db-root-password PASS      MySQL root 密码；新服务器常可留空
  --db-name NAME               数据库名，默认 wzry_space
  --db-user NAME               数据库用户，默认 wzry_space
  --db-password PASS           数据库密码；不传则交互输入或自动生成
  --admin-user NAME            后台用户名，默认 admin
  --admin-password PASS        后台密码；不传则交互输入或自动生成
  --reinstall-db               强制重新导入数据库和重建后台账号
  --skip-db                    跳过数据库初始化
  --skip-services              跳过 Java/WebSocket 服务
  -y, --yes                    使用默认值，不询问确认
  -h, --help                   查看帮助

私有仓库下载：
  GitHub：export GITHUB_TOKEN='你的Token'
  Gitee： export GIT_USERNAME='用户名'; export GIT_TOKEN='私人令牌'
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --install-code) INSTALL_CODE="${2:-}"; shift 2 ;;
        --install-code=*) INSTALL_CODE="${1#*=}"; shift ;;
        --source) SOURCE="${2:-}"; shift 2 ;;
        --source=*) SOURCE="${1#*=}"; shift ;;
        --repo-url) REPO_URL="${2:-}"; shift 2 ;;
        --repo-url=*) REPO_URL="${1#*=}"; shift ;;
        --branch) BRANCH="${2:-}"; shift 2 ;;
        --branch=*) BRANCH="${1#*=}"; shift ;;
        --src-dir) SRC_DIR="${2:-}"; shift 2 ;;
        --src-dir=*) SRC_DIR="${1#*=}"; shift ;;
        --site-dir) SITE_DIR="${2:-}"; shift 2 ;;
        --site-dir=*) SITE_DIR="${1#*=}"; shift ;;
        --server-name) SERVER_NAME="${2:-}"; shift 2 ;;
        --server-name=*) SERVER_NAME="${1#*=}"; shift ;;
        --db-root-password) DB_ROOT_PASSWORD="${2:-}"; shift 2 ;;
        --db-root-password=*) DB_ROOT_PASSWORD="${1#*=}"; shift ;;
        --db-name) DB_NAME="${2:-}"; shift 2 ;;
        --db-name=*) DB_NAME="${1#*=}"; shift ;;
        --db-user) DB_USER="${2:-}"; shift 2 ;;
        --db-user=*) DB_USER="${1#*=}"; shift ;;
        --db-password) DB_PASSWORD="${2:-}"; shift 2 ;;
        --db-password=*) DB_PASSWORD="${1#*=}"; shift ;;
        --admin-user) ADMIN_USER="${2:-}"; shift 2 ;;
        --admin-user=*) ADMIN_USER="${1#*=}"; shift ;;
        --admin-password) ADMIN_PASSWORD="${2:-}"; shift 2 ;;
        --admin-password=*) ADMIN_PASSWORD="${1#*=}"; shift ;;
        --reset-admin-key) RESET_ADMIN_KEY="${2:-}"; shift 2 ;;
        --reset-admin-key=*) RESET_ADMIN_KEY="${1#*=}"; shift ;;
        --skip-db) SKIP_DB=1; shift ;;
        --skip-services) SKIP_SERVICES=1; shift ;;
        --reinstall-db) REINSTALL_DB=1; shift ;;
        -y|--yes) YES=1; shift ;;
        -h|--help) usage; exit 0 ;;
        *) die "未知参数：$1" ;;
    esac
done

[[ "$(id -u)" -eq 0 ]] || die "请使用 root 执行，例如：sudo bash scripts/cloud-install.sh"

has_tty() {
    [[ -e /dev/tty && -r /dev/tty && -w /dev/tty ]]
}

prompt_text() {
    local var_name="$1"
    local label="$2"
    local default_value="${3:-}"
    local secret="${4:-0}"
    local current="${!var_name-}"
    local value=""

    [[ -n "$current" ]] && default_value="$current"
    [[ "$YES" == "1" ]] && { printf -v "$var_name" '%s' "$default_value"; return 0; }
    has_tty || { printf -v "$var_name" '%s' "$default_value"; return 0; }

    if [[ -n "$default_value" ]]; then
        printf '%s [%s]: ' "$label" "$default_value" > /dev/tty
    else
        printf '%s: ' "$label" > /dev/tty
    fi

    if [[ "$secret" == "1" ]]; then
        IFS= read -r -s value < /dev/tty || true
        printf '\n' > /dev/tty
    else
        IFS= read -r value < /dev/tty || true
    fi

    [[ -n "$value" ]] || value="$default_value"
    printf -v "$var_name" '%s' "$value"
}

prompt_choice() {
    local var_name="$1"
    local label="$2"
    local default_value="$3"
    local value=""
    local current="${!var_name-}"

    [[ -n "$current" ]] && default_value="$current"
    [[ "$YES" == "1" ]] && { printf -v "$var_name" '%s' "$default_value"; return 0; }
    has_tty || { printf -v "$var_name" '%s' "$default_value"; return 0; }

    while true; do
        printf '%s [%s]: ' "$label" "$default_value" > /dev/tty
        IFS= read -r value < /dev/tty || true
        value="${value:-$default_value}"
        case "$value" in
            github|gitee) printf -v "$var_name" '%s' "$value"; return 0 ;;
            *) yellow "请输入 github 或 gitee" ;;
        esac
    done
}

ask_yes_no() {
    local label="$1"
    local default_value="${2:-n}"
    local value=""

    [[ "$YES" == "1" ]] && { [[ "$default_value" =~ ^[Yy]$ ]]; return $?; }
    has_tty || { [[ "$default_value" =~ ^[Yy]$ ]]; return $?; }

    while true; do
        if [[ "$default_value" =~ ^[Yy]$ ]]; then
            printf '%s [Y/n]: ' "$label" > /dev/tty
        else
            printf '%s [y/N]: ' "$label" > /dev/tty
        fi
        IFS= read -r value < /dev/tty || true
        value="${value:-$default_value}"
        case "$value" in
            y|Y|yes|YES) return 0 ;;
            n|N|no|NO) return 1 ;;
            *) yellow "请输入 y 或 n" ;;
        esac
    done
}

show_banner() {
    blue "========================================"
    blue "  王者荣耀空间 - 云服务器 SSH 一键搭建"
    blue "========================================"
    printf '全程在当前 SSH 窗口完成：授权码、下载源码、依赖安装、数据库、前后台、Java 服务、端口开放。\n\n'
}

validate_identifier() {
    local name="$1"
    local value="$2"
    [[ "$value" =~ ^[A-Za-z0-9_]+$ ]] || die "$name 只能包含字母、数字和下划线：$value"
}

hash_text() {
    if command -v sha256sum >/dev/null 2>&1; then
        printf '%s' "$1" | sha256sum | awk '{print $1}'
    elif command -v openssl >/dev/null 2>&1; then
        printf '%s' "$1" | openssl dgst -sha256 -r | awk '{print $1}'
    else
        die "系统缺少 sha256sum/openssl，无法校验授权码"
    fi
}

check_install_code() {
    local expected="${WZRY_INSTALL_CODE_SHA256:-$INSTALL_CODE_SHA256_DEFAULT}"
    [[ -n "$INSTALL_CODE" ]] || die "缺少安装授权码"
    local actual
    actual="$(hash_text "$INSTALL_CODE")"
    [[ "$actual" == "$expected" ]] || die "安装授权码错误，已停止部署"
    green "授权码校验通过"
}

random_hex() {
    openssl rand -hex "${1:-16}"
}

collect_inputs() {
    green "开始一键搭建，请按提示填写信息"
    if [[ -z "$INSTALL_CODE" ]]; then
        prompt_text INSTALL_CODE "请输入安装授权码" "" 1
    fi
    check_install_code

    prompt_choice SOURCE "请选择源码下载源：github 或 gitee" "$SOURCE"
    prompt_text BRANCH "请输入源码分支" "$BRANCH" 0
    prompt_text SRC_DIR "请输入源码保存目录" "$SRC_DIR" 0
    prompt_text SITE_DIR "请输入网站部署目录" "$SITE_DIR" 0

    if [[ "$SERVER_NAME" == "_" || -z "$SERVER_NAME" ]]; then
        if ask_yes_no "是否需要添加域名" "n"; then
            SERVER_NAME=""
            prompt_text SERVER_NAME "请输入域名，例如 example.com" "" 0
            [[ -n "$SERVER_NAME" ]] || SERVER_NAME="_"
        else
            SERVER_NAME="_"
        fi
    fi

    if [[ "$SKIP_DB" != "1" ]]; then
        if [[ -f "$SITE_DIR/auth/config.php" && "$REINSTALL_DB" != "1" ]]; then
            yellow "检测到已有数据库配置：$SITE_DIR/auth/config.php"
            if ask_yes_no "是否重新初始化数据库和后台账号" "n"; then
                REINSTALL_DB=1
            fi
        fi

        if [[ ! -f "$SITE_DIR/auth/config.php" || "$REINSTALL_DB" == "1" ]]; then
            if [[ -z "$DB_ROOT_PASSWORD" ]]; then
                prompt_text DB_ROOT_PASSWORD "请输入 MySQL root 密码（新服务器可直接回车）" "" 1
            fi
            prompt_text DB_NAME "请输入数据库名" "$DB_NAME" 0
            prompt_text DB_USER "请输入数据库用户名" "$DB_USER" 0
            if [[ -z "$DB_PASSWORD" ]]; then
                prompt_text DB_PASSWORD "请输入数据库密码（直接回车自动生成）" "" 1
            fi
            prompt_text ADMIN_USER "请输入后台用户名" "$ADMIN_USER" 0
            if [[ -z "$ADMIN_PASSWORD" ]]; then
                prompt_text ADMIN_PASSWORD "请输入后台密码（直接回车自动生成）" "" 1
            fi
        fi
    fi

    validate_identifier "数据库名" "$DB_NAME"
    validate_identifier "数据库用户名" "$DB_USER"

    blue ""
    blue "即将开始部署："
    printf '  源码源：%s\n' "$SOURCE"
    printf '  分支：%s\n' "$BRANCH"
    printf '  源码目录：%s\n' "$SRC_DIR"
    printf '  网站目录：%s\n' "$SITE_DIR"
    printf '  域名：%s\n' "$SERVER_NAME"
    printf '  数据库：%s / %s\n' "$DB_NAME" "$DB_USER"
    printf '  后台用户名：%s\n' "$ADMIN_USER"
    blue ""

    if ! ask_yes_no "确认开始一键部署吗" "y"; then
        die "用户取消部署"
    fi
}

install_packages() {
    green "正在安装基础环境：Nginx、PHP、MariaDB、Java、Git、端口工具"
    if command -v apt-get >/dev/null 2>&1; then
        export DEBIAN_FRONTEND=noninteractive
        apt-get update
        apt-get install -y \
            git curl rsync unzip sudo openssl ca-certificates cron \
            nginx mariadb-server mariadb-client \
            php-cli php-fpm php-mysql php-curl php-mbstring php-xml \
            openjdk-17-jre-headless ipset iptables ufw
    elif command -v dnf >/dev/null 2>&1; then
        dnf install -y epel-release || true
        dnf install -y \
            git curl rsync unzip sudo openssl cronie \
            nginx mariadb-server mariadb \
            php-cli php-fpm php-mysqlnd php-curl php-mbstring php-xml \
            java-17-openjdk-headless ipset iptables-services firewalld
    elif command -v yum >/dev/null 2>&1; then
        yum install -y epel-release || true
        yum install -y \
            git curl rsync unzip sudo openssl cronie \
            nginx mariadb-server mariadb \
            php-cli php-fpm php-mysqlnd php-curl php-mbstring php-xml \
            java-17-openjdk-headless ipset iptables-services firewalld
    else
        die "暂不支持此 Linux 发行版，需要 apt、dnf 或 yum"
    fi
    green "基础环境安装完成"
}

enable_service_if_exists() {
    local svc="$1"
    if systemctl list-unit-files "${svc}.service" >/dev/null 2>&1; then
        systemctl enable --now "${svc}.service" >/dev/null 2>&1 || true
    fi
}

start_base_services() {
    green "正在启动 Nginx、PHP、数据库等基础服务"
    for svc in mariadb mysql mysqld nginx php-fpm php8.4-fpm php8.3-fpm php8.2-fpm php8.1-fpm php8.0-fpm php7.4-fpm crond cron; do
        enable_service_if_exists "$svc"
    done
    green "基础服务启动完成"
}

repo_clean_url() {
    if [[ -n "$REPO_URL" ]]; then
        printf '%s\n' "$REPO_URL"
        return
    fi
    case "$SOURCE" in
        github) printf 'https://github.com/a534686350/wzry-space.git\n' ;;
        gitee) printf 'https://gitee.com/hl515/wzry-space.git\n' ;;
        *) printf '%s\n' "$REPO_URL" ;;
    esac
}

build_repo_url() {
    if [[ -n "$REPO_URL" ]]; then
        printf '%s\n' "$REPO_URL"
        return
    fi

    case "$SOURCE" in
        github)
            if [[ -n "${GITHUB_TOKEN:-}" ]]; then
                printf 'https://x-access-token:%s@github.com/a534686350/wzry-space.git\n' "$GITHUB_TOKEN"
            elif [[ -n "${GIT_TOKEN:-}" ]]; then
                printf 'https://%s:%s@github.com/a534686350/wzry-space.git\n' "${GIT_USERNAME:-x-access-token}" "$GIT_TOKEN"
            else
                printf 'https://github.com/a534686350/wzry-space.git\n'
            fi
            ;;
        gitee)
            if [[ -n "${GIT_TOKEN:-}" && -n "${GIT_USERNAME:-}" ]]; then
                printf 'https://%s:%s@gitee.com/hl515/wzry-space.git\n' "$GIT_USERNAME" "$GIT_TOKEN"
            elif [[ -n "${GITEE_TOKEN:-}" && -n "${GIT_USERNAME:-}" ]]; then
                printf 'https://%s:%s@gitee.com/hl515/wzry-space.git\n' "$GIT_USERNAME" "$GITEE_TOKEN"
            else
                printf 'https://gitee.com/hl515/wzry-space.git\n'
            fi
            ;;
        *)
            die "源码源只能是 github 或 gitee"
            ;;
    esac
}

sync_source() {
    green "正在远程下载源码，请稍等..."
    local clone_url clean_url
    clone_url="$(build_repo_url)"
    clean_url="$(repo_clean_url)"

    if [[ -d "$SRC_DIR/.git" ]]; then
        git -C "$SRC_DIR" remote set-url origin "$clone_url"
        git -C "$SRC_DIR" fetch --prune origin "$BRANCH"
        git -C "$SRC_DIR" checkout -B "$BRANCH" "origin/$BRANCH"
        git -C "$SRC_DIR" remote set-url origin "$clean_url" || true
    else
        if [[ -e "$SRC_DIR" ]]; then
            die "$SRC_DIR 已存在，但不是 Git 仓库"
        fi
        mkdir -p "$(dirname "$SRC_DIR")"
        git clone --branch "$BRANCH" --depth 1 "$clone_url" "$SRC_DIR"
        git -C "$SRC_DIR" remote set-url origin "$clean_url" || true
    fi

    green "源码下载完成"
}

deploy_web_files() {
    green "现在开始搭建前端..."
    local web_package="$SRC_DIR/网页前后台"
    [[ -d "$web_package" ]] || die "未找到发布包目录：$web_package"

    mkdir -p "$SITE_DIR"
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
    mkdir -p "$SITE_DIR/logs"
    green "前端搭建完成"
}

sql_escape() {
    printf '%s' "$1" | sed "s/\\\\/\\\\\\\\/g; s/'/''/g"
}

php_string_escape() {
    printf '%s' "$1" | sed "s/\\\\/\\\\\\\\/g; s/'/\\\\'/g"
}

mysql_root() {
    if [[ -n "$DB_ROOT_PASSWORD" ]]; then
        MYSQL_PWD="$DB_ROOT_PASSWORD" mysql -u "$DB_ROOT_USER" "$@"
    else
        mysql -u "$DB_ROOT_USER" "$@"
    fi
}

mysql_app() {
    MYSQL_PWD="$DB_PASSWORD" mysql -h 127.0.0.1 -u "$DB_USER" "$DB_NAME" "$@"
}

write_php_config() {
    local reset_key="$RESET_ADMIN_KEY"
    local php_db_name php_db_user php_db_password php_reset_key
    [[ -n "$reset_key" ]] || reset_key="$(random_hex 24)"
    php_db_name="$(php_string_escape "$DB_NAME")"
    php_db_user="$(php_string_escape "$DB_USER")"
    php_db_password="$(php_string_escape "$DB_PASSWORD")"
    php_reset_key="$(php_string_escape "$reset_key")"

    mkdir -p "$SITE_DIR/auth"
    cat > "$SITE_DIR/auth/config.php" <<PHP
<?php
/**
 * Web auth system database config.
 * Generated by scripts/cloud-install.sh
 */
return [
    'db_host'     => '127.0.0.1',
    'db_port'     => 3306,
    'db_name'     => '$php_db_name',
    'db_user'     => '$php_db_user',
    'db_password' => '$php_db_password',
    'db_charset'  => 'utf8mb4',
    'session_name' => 'AUTH_SESS',
    'reset_admin_key' => '$php_reset_key',
];
PHP
    chmod 640 "$SITE_DIR/auth/config.php"
}

import_sql_file() {
    local file="$1"
    local force="${2:-0}"
    [[ -f "$file" ]] || return 0
    if [[ "$force" == "1" ]]; then
        mysql_app --force < "$file"
    else
        mysql_app < "$file"
    fi
}

init_database() {
    green "前端搭建完成，开始搭建后台..."
    if [[ "$SKIP_DB" == "1" ]]; then
        yellow "已跳过数据库初始化"
        return
    fi

    if [[ -f "$SITE_DIR/auth/config.php" && "$REINSTALL_DB" != "1" ]]; then
        yellow "已有数据库配置，保留后台数据库和账号"
        return
    fi

    [[ -n "$DB_PASSWORD" ]] || DB_PASSWORD="$(random_hex 16)"
    [[ -n "$ADMIN_PASSWORD" ]] || ADMIN_PASSWORD="$(random_hex 8)"

    local db_pass_sql admin_user_sql db_user_sql
    db_pass_sql="$(sql_escape "$DB_PASSWORD")"
    admin_user_sql="$(sql_escape "$ADMIN_USER")"
    db_user_sql="$(sql_escape "$DB_USER")"

    green "正在创建数据库和导入数据表..."
    mysql_root <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$db_user_sql'@'localhost' IDENTIFIED BY '$db_pass_sql';
CREATE USER IF NOT EXISTS '$db_user_sql'@'127.0.0.1' IDENTIFIED BY '$db_pass_sql';
ALTER USER '$db_user_sql'@'localhost' IDENTIFIED BY '$db_pass_sql';
ALTER USER '$db_user_sql'@'127.0.0.1' IDENTIFIED BY '$db_pass_sql';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$db_user_sql'@'localhost';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$db_user_sql'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

    write_php_config
    import_sql_file "$SITE_DIR/auth/install.sql" 0
    import_sql_file "$SITE_DIR/auth/install_extra.sql" 1
    import_sql_file "$SITE_DIR/auth/upgrade_agent.sql" 1
    import_sql_file "$SRC_DIR/APP/auth/upgrade_whitelist.sql" 1

    local admin_hash admin_hash_sql
    admin_hash="$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$ADMIN_PASSWORD")"
    admin_hash_sql="$(sql_escape "$admin_hash")"
    mysql_app <<SQL
INSERT INTO admin_users (username, password, role)
VALUES ('$admin_user_sql', '$admin_hash_sql', 'admin')
ON DUPLICATE KEY UPDATE password = VALUES(password), role = 'admin';
SQL

    touch "$SITE_DIR/auth/install.lock"
    chmod 640 "$SITE_DIR/auth/install.lock"
    DB_INITIALIZED=1
    ADMIN_CREATED=1
    green "后台数据库搭建完成"
}

detect_php_fastcgi() {
    local sock
    for sock in /run/php/php*-fpm.sock /var/run/php/php*-fpm.sock /run/php-fpm/www.sock /var/run/php-fpm/www.sock /tmp/php-cgi-*.sock; do
        if [[ -S "$sock" ]]; then
            printf 'unix:%s\n' "$sock"
            return
        fi
    done
    printf '127.0.0.1:9000\n'
}

detect_web_user() {
    for user in www-data nginx www nobody; do
        if id "$user" >/dev/null 2>&1; then
            printf '%s\n' "$user"
            return
        fi
    done
    printf 'root\n'
}

disable_default_nginx_site() {
    local ts
    ts="$(date +%Y%m%d%H%M%S)"
    for file in /etc/nginx/sites-enabled/default /etc/nginx/conf.d/default.conf; do
        if [[ -e "$file" ]]; then
            mv "$file" "${file}.disabled-by-wzry-${ts}"
        fi
    done
}

write_nginx_configs() {
    green "正在搭建后台访问入口和 Nginx 代理..."
    local php_fastcgi web_user site_conf ws_conf
    php_fastcgi="$(detect_php_fastcgi)"
    web_user="$(detect_web_user)"
    site_conf="/etc/nginx/conf.d/00-wzry-space.conf"
    ws_conf="/etc/nginx/conf.d/01-wzry-space-ws.conf"

    disable_default_nginx_site

    cat > "$site_conf" <<NGINX
server {
    listen 80;
    server_name $SERVER_NAME 127.0.0.1 localhost;

    root $SITE_DIR;
    index index.html index.php;
    client_max_body_size 100m;

    location ~ /\\. {
        deny all;
    }

    location ~* ^/auth/(config\\.php|install\\.lock|.*\\.sql)$ {
        deny all;
    }

    location ~ \\.php$ {
        include fastcgi_params;
        fastcgi_pass $php_fastcgi;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_param HTTP_X_REAL_IP \$remote_addr;
        fastcgi_param HTTP_X_FORWARDED_FOR \$proxy_add_x_forwarded_for;
    }

    location / {
        try_files \$uri \$uri/ /index.html;
    }
}
NGINX

    cat > "$ws_conf" <<'NGINX'
server {
    listen 8888;

    location /ws {
        auth_request /ws_auth;
        error_page 401 403 = @ws_deny;

        proxy_pass http://127.0.0.1:18888;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
    }

    location = /ws_auth {
        internal;
        proxy_pass http://127.0.0.1/api/index.php?module=check_ws_access;
        proxy_pass_request_body off;
        proxy_set_header Content-Length "";
        proxy_set_header Host 127.0.0.1;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header Cookie $http_cookie;
    }

    location @ws_deny {
        add_header Content-Type application/json;
        return 403 '{"code":403,"msg":"请先登录后再使用"}';
    }

    location / {
        auth_request /ws_auth;
        error_page 401 403 = @ws_deny;
        proxy_pass http://127.0.0.1:18888;
        proxy_set_header X-Real-IP $remote_addr;
    }
}

server {
    listen 9999;

    location /ws {
        auth_request /ws_auth;
        error_page 401 403 = @ws_deny;

        proxy_pass http://127.0.0.1:19999;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
    }

    location = /ws_auth {
        internal;
        proxy_pass http://127.0.0.1/api/index.php?module=check_ws_access;
        proxy_pass_request_body off;
        proxy_set_header Content-Length "";
        proxy_set_header Host 127.0.0.1;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header Cookie $http_cookie;
    }

    location @ws_deny {
        add_header Content-Type application/json;
        return 403 '{"code":403,"msg":"请先登录后再使用"}';
    }

    location / {
        auth_request /ws_auth;
        error_page 401 403 = @ws_deny;
        proxy_pass http://127.0.0.1:19999;
        proxy_set_header X-Real-IP $remote_addr;
    }
}
NGINX

    chown -R "$web_user:$web_user" "$SITE_DIR" || true
    find "$SITE_DIR" -type d -exec chmod 755 {} \;
    find "$SITE_DIR" -type f -exec chmod 644 {} \;
    chmod +x "$SITE_DIR"/*.sh 2>/dev/null || true
    chmod 640 "$SITE_DIR/auth/config.php" 2>/dev/null || true

    nginx -t
    systemctl reload nginx || systemctl restart nginx
    green "后台入口和 Nginx 代理搭建完成"
}

install_runtime_services() {
    if [[ "$SKIP_SERVICES" == "1" ]]; then
        yellow "已跳过 Java/WebSocket 服务"
        return
    fi
    green "正在搭建 JAVA 端和 WebSocket 服务..."
    [[ -f "$SITE_DIR/install-services.sh" ]] || die "未找到 $SITE_DIR/install-services.sh"
    bash "$SITE_DIR/install-services.sh" "$SITE_DIR"
    green "JAVA 端搭建完成"
}

open_port_ufw() {
    local port="$1"
    ufw allow "${port}/tcp" >/dev/null 2>&1 || true
}

configure_firewall() {
    green "正在开放 SSH 服务器系统端口：80、8888、9999"

    if command -v ufw >/dev/null 2>&1; then
        open_port_ufw 22
        open_port_ufw 80
        open_port_ufw 8888
        open_port_ufw 9999
        if ufw status 2>/dev/null | grep -qi "Status: active"; then
            green "UFW 已启用，端口规则已写入"
        else
            yellow "UFW 未启用，已写入规则但不会强制开启，避免误锁 SSH"
        fi
    fi

    if command -v firewall-cmd >/dev/null 2>&1 && systemctl is-active --quiet firewalld; then
        firewall-cmd --permanent --add-port=80/tcp >/dev/null 2>&1 || true
        firewall-cmd --permanent --add-port=8888/tcp >/dev/null 2>&1 || true
        firewall-cmd --permanent --add-port=9999/tcp >/dev/null 2>&1 || true
        firewall-cmd --reload >/dev/null 2>&1 || true
        green "firewalld 已启用，端口规则已写入"
    elif command -v firewall-cmd >/dev/null 2>&1; then
        yellow "firewalld 未启用，不强制开启，避免误锁 SSH"
    fi

    if command -v iptables >/dev/null 2>&1; then
        iptables -C INPUT -p tcp --dport 80 -j ACCEPT 2>/dev/null || iptables -I INPUT -p tcp --dport 80 -j ACCEPT
        iptables -C INPUT -p tcp --dport 18888 ! -s 127.0.0.1 -j DROP 2>/dev/null || iptables -I INPUT -p tcp --dport 18888 ! -s 127.0.0.1 -j DROP
        iptables -C INPUT -p tcp --dport 19999 ! -s 127.0.0.1 -j DROP 2>/dev/null || iptables -I INPUT -p tcp --dport 19999 ! -s 127.0.0.1 -j DROP
    fi

    green "端口处理完成：80/8888/9999 已尝试开放，18888/19999 已限制为本机内部端口"
    yellow "如果云厂商安全组仍拦截，请在云厂商控制台放行 80、8888、9999。服务器内防火墙已在 SSH 中处理。"
}

write_install_receipt() {
    local receipt="/root/wzry-space-install.env"
    cat > "$receipt" <<EOF
SOURCE=$SOURCE
BRANCH=$BRANCH
SRC_DIR=$SRC_DIR
SITE_DIR=$SITE_DIR
SERVER_NAME=$SERVER_NAME
DB_NAME=$DB_NAME
DB_USER=$DB_USER
DB_PASSWORD=$DB_PASSWORD
ADMIN_USER=$ADMIN_USER
ADMIN_PASSWORD=$ADMIN_PASSWORD
EOF
    chmod 600 "$receipt"
}

server_host() {
    local ip
    ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
    [[ -n "$ip" ]] || ip="服务器IP"
    if [[ "$SERVER_NAME" == "_" || -z "$SERVER_NAME" ]]; then
        printf '%s\n' "$ip"
    else
        printf '%s\n' "$SERVER_NAME"
    fi
}

latest_apk_name() {
    find "$SITE_DIR/apk" -maxdepth 1 -type f -name 'ALinRadar-v*.apk' -printf '%f\n' 2>/dev/null | sort -V | tail -n 1
}

verify_install() {
    green "正在做部署结果检查..."
    nginx -t >/dev/null
    systemctl is-active nginx >/dev/null || systemctl restart nginx
    if [[ "$SKIP_SERVICES" != "1" ]]; then
        systemctl is-active home-server >/dev/null || yellow "home-server 当前不是 active，请用 journalctl -u home-server -f 查看日志"
    fi
    green "部署检查完成"
}

print_summary() {
    local host app_file
    host="$(server_host)"
    app_file="$(latest_apk_name)"

    green "========================================"
    green "  全部搭建成功"
    green "========================================"
    printf '你的前台地址是：    http://%s/\n' "$host"
    printf '你的后台地址是：    http://%s/admin/\n' "$host"
    printf '后台用户名是：      %s\n' "$ADMIN_USER"
    if [[ "$ADMIN_CREATED" == "1" ]]; then
        printf '后台密码是：        %s\n' "$ADMIN_PASSWORD"
    else
        printf '后台密码是：        已保留原有密码\n'
    fi
    printf '数据库名是：        %s\n' "$DB_NAME"
    printf '数据库用户名是：    %s\n' "$DB_USER"
    if [[ "$DB_INITIALIZED" == "1" ]]; then
        printf '数据库密码是：      %s\n' "$DB_PASSWORD"
    else
        printf '数据库密码是：      已保留原有配置\n'
    fi
    if [[ -n "$app_file" ]]; then
        printf 'APP 下载路径是：    http://%s/apk/%s\n' "$host" "$app_file"
    else
        printf 'APP 下载路径是：    未发现 APK，请检查 %s/apk/\n' "$SITE_DIR"
    fi
    printf 'WebSocket 端口是：  8888 / 9999\n'
    printf '源码目录是：        %s\n' "$SRC_DIR"
    printf '网站目录是：        %s\n' "$SITE_DIR"
    printf '安装记录保存于：    /root/wzry-space-install.env\n'
    printf '\n常用 SSH 检查命令：\n'
    printf '  systemctl status nginx\n'
    printf '  systemctl status home-server\n'
    printf '  journalctl -u home-server -f\n'
    printf '  ipset list ws_whitelist\n'
}

show_banner
collect_inputs
install_packages
start_base_services
sync_source
green "正在一键部署中..."
deploy_web_files
init_database
write_nginx_configs
install_runtime_services
configure_firewall
verify_install
write_install_receipt
print_summary
