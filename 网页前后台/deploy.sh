#!/bin/bash
# ============================================
# 雷达出租/代理系统 - SSH 下一键部署（需 root）
# 用法：上传项目到服务器后，在项目目录执行：
#   chmod +x deploy.sh && sudo ./deploy.sh
# 或指定目录：sudo ./deploy.sh /www/wwwroot/你的站点
# ============================================

set -e
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

[[ $(id -u) -eq 0 ]] || { echo -e "${RED}请使用 root 运行此脚本：sudo $0${NC}"; exit 1; }

# 项目目录：第一个参数或当前脚本所在目录
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="${1:-$SCRIPT_DIR}"
PROJECT_DIR="$(realpath "$PROJECT_DIR")"

if [[ ! -f "$PROJECT_DIR/auth/config.php" ]] && [[ ! -f "$PROJECT_DIR/install.php" ]]; then
    echo -e "${RED}未在 $PROJECT_DIR 检测到项目文件（auth/config.php 或 install.php），请确认路径。${NC}"
    exit 1
fi

echo -e "${GREEN}>>> 项目目录: $PROJECT_DIR${NC}"

# 检测系统并安装依赖
install_deps() {
    if command -v apt-get &>/dev/null; then
        echo -e "${YELLOW}>>> 检测到 Debian/Ubuntu，安装 nginx + PHP ...${NC}"
        export DEBIAN_FRONTEND=noninteractive
        export APT_LISTCHANGES_FRONTEND=none
        export NEEDRESTART_MODE=a
        apt-get update -qq
        apt-get -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold install -y -qq nginx php-fpm php-mysql php-curl php-json php-mbstring php-xml unzip 2>/dev/null || true
        PHP_SOCK="/run/php/php-fpm.sock"
        [[ -S /run/php/php8.1-fpm.sock ]] && PHP_SOCK="/run/php/php8.1-fpm.sock"
        [[ -S /run/php/php8.2-fpm.sock ]] && PHP_SOCK="/run/php/php8.2-fpm.sock"
        [[ -S /run/php/php7.4-fpm.sock ]] && PHP_SOCK="/run/php/php7.4-fpm.sock"
        WEB_USER="www-data"
    elif command -v yum &>/dev/null || command -v dnf &>/dev/null; then
        echo -e "${YELLOW}>>> 检测到 CentOS/RHEL，尝试安装 nginx + PHP ...${NC}"
        if command -v dnf &>/dev/null; then
            dnf install -y nginx php-fpm php-mysqlnd php-curl php-json php-mbstring php-xml 2>/dev/null || true
        else
            yum install -y epel-release 2>/dev/null || true
            yum install -y nginx 2>/dev/null || true
            yum install -y php-fpm php-mysqlnd php-curl php-json php-mbstring php-xml 2>/dev/null || true
        fi
        PHP_SOCK="127.0.0.1:9000"
        [[ -S /run/php-fpm/www.sock ]] && PHP_SOCK="unix:/run/php-fpm/www.sock"
        [[ -S /tmp/php-cgi-74.sock ]] && PHP_SOCK="unix:/tmp/php-cgi-74.sock"
        [[ -S /tmp/php-cgi-80.sock ]] && PHP_SOCK="unix:/tmp/php-cgi-80.sock"
        WEB_USER="nginx"
        id nginx &>/dev/null || WEB_USER="www-data"
        id "$WEB_USER" &>/dev/null || WEB_USER="nobody"
    else
        echo -e "${YELLOW}>>> 未识别包管理器，请手动安装 nginx、php-fpm、php-mysql、php-curl、php-json、php-mbstring${NC}"
        PHP_SOCK="127.0.0.1:9000"
        WEB_USER="www-data"
    fi
    export PHP_SOCK WEB_USER
}

# 写 nginx 站点配置（可通过环境变量指定域名：SERVER_NAME=your.domain.com ./deploy.sh）
setup_nginx() {
    local server_name="${SERVER_NAME:-_}"
    local conf_dir="/etc/nginx/conf.d"
    [[ -d /etc/nginx/sites-available ]] && conf_dir="/etc/nginx/sites-available"
    mkdir -p "$conf_dir"
    local conf_file="$conf_dir/leida_proxy.conf"
    echo -e "${YELLOW}>>> 写入 Nginx 配置: $conf_file${NC}"
    cat > "$conf_file" << NGINX
server {
    listen 80;
    server_name $server_name;
    root $PROJECT_DIR;
    index index.html index.php;
    location ~ \\.php\$ {
        fastcgi_pass $PHP_SOCK;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_param HTTP_X_REAL_IP \$remote_addr;
        fastcgi_param HTTP_X_FORWARDED_FOR \$proxy_add_x_forwarded_for;
        include fastcgi_params;
    }
    location / {
        try_files \$uri \$uri/ /index.html;
    }
}
NGINX
    # 若使用 sites-available，需启用并 reload
    if [[ -d /etc/nginx/sites-available ]] && [[ -d /etc/nginx/sites-enabled ]]; then
        ln -sf "$conf_file" /etc/nginx/sites-enabled/ 2>/dev/null || true
    fi
    if command -v nginx &>/dev/null; then
        nginx -t 2>/dev/null && (systemctl reload nginx 2>/dev/null || service nginx reload 2>/dev/null) && echo -e "${GREEN}>>> Nginx 已重载${NC}" || echo -e "${YELLOW}>>> Nginx 未安装或未启动，请先安装/启动 nginx 后执行: nginx -t && systemctl reload nginx${NC}"
    else
        echo -e "${YELLOW}>>> 未检测到 nginx，配置已写入 $conf_file。请先安装 nginx（如 yum install -y epel-release && yum install -y nginx）后重载。${NC}"
    fi
}

# 设置目录权限
set_permissions() {
    chown -R "$WEB_USER:$WEB_USER" "$PROJECT_DIR"
    chmod -R 755 "$PROJECT_DIR"
    chmod 644 "$PROJECT_DIR/auth/config.php" 2>/dev/null || true
    echo -e "${GREEN}>>> 已设置属主为 $WEB_USER${NC}"
}

# 主流程
install_deps
setup_nginx
set_permissions

# 输出后续步骤
echo ""
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}  一键部署已完成${NC}"
echo -e "${GREEN}========================================${NC}"
echo "1. 浏览器访问: http://$(hostname -I 2>/dev/null | awk '{print $1}')/install.php"
echo "   填写数据库信息和管理员密码，完成建库建表。"
echo "2. 安装成功后请删除 install.php。"
echo "3. 后台地址: http://你的IP或域名/admin/"
echo ""
