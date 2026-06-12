#!/bin/bash
# ============================================================
# iptables 白名单 - 一键部署脚本
# 在服务器上执行：sudo bash setup-whitelist-iptables.sh
# ============================================================
set -e
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'

[[ $(id -u) -eq 0 ]] || { echo -e "${RED}请用 root 运行：sudo bash $0${NC}"; exit 1; }

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HELPER_SRC="$SCRIPT_DIR/ws-whitelist-helper.sh"
HELPER_DST="/usr/local/bin/ws-whitelist-helper.sh"

echo -e "${GREEN}=== 1. 安装 ipset ===${NC}"
if command -v apt-get &>/dev/null; then
    export DEBIAN_FRONTEND=noninteractive
    export APT_LISTCHANGES_FRONTEND=none
    export NEEDRESTART_MODE=a
    apt-get -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold install -y ipset iptables-persistent 2>/dev/null || \
        apt-get -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold install -y ipset || true
elif command -v yum &>/dev/null; then
    yum install -y ipset ipset-service 2>/dev/null || true
fi
command -v ipset &>/dev/null || { echo -e "${RED}ipset 安装失败，请手动安装后重试${NC}"; exit 1; }
echo "ipset 已就绪"

echo -e "${GREEN}=== 2. 安装 helper 脚本 ===${NC}"
if [ ! -f "$HELPER_SRC" ]; then
    echo -e "${RED}找不到 $HELPER_SRC${NC}"; exit 1
fi
cp "$HELPER_SRC" "$HELPER_DST"
chmod 755 "$HELPER_DST"
echo "已复制到 $HELPER_DST"

echo -e "${GREEN}=== 3. 配置 sudoers ===${NC}"
# 检测 PHP 运行用户
WEB_USER="www-data"
id nginx &>/dev/null && WEB_USER="nginx"
id www-data &>/dev/null && WEB_USER="www-data"
# 宝塔环境常用 www
id www &>/dev/null && WEB_USER="www"

SUDOERS_FILE="/etc/sudoers.d/ws-whitelist"
cat > "$SUDOERS_FILE" << EOF
# 允许 PHP 进程（$WEB_USER）无密码调用白名单脚本
$WEB_USER ALL=(root) NOPASSWD: $HELPER_DST
EOF
chmod 440 "$SUDOERS_FILE"
visudo -c -f "$SUDOERS_FILE" || { echo -e "${RED}sudoers 语法错误，已回滚${NC}"; rm -f "$SUDOERS_FILE"; exit 1; }
echo "sudoers 已配置（用户：$WEB_USER）"

echo -e "${GREEN}=== 4. 初始化 iptables 规则 ===${NC}"
bash "$HELPER_DST" init

echo -e "${GREEN}=== 5. 保存 iptables 规则（重启后生效）===${NC}"
if command -v netfilter-persistent &>/dev/null; then
    netfilter-persistent save
elif command -v service &>/dev/null && service iptables save &>/dev/null 2>&1; then
    echo "iptables 规则已保存"
elif [ -f /etc/iptables/rules.v4 ]; then
    iptables-save > /etc/iptables/rules.v4
    echo "已保存到 /etc/iptables/rules.v4"
else
    echo -e "${YELLOW}⚠ 无法自动保存 iptables 规则，服务器重启后规则会丢失。"
    echo "  请手动执行：iptables-save > /etc/iptables/rules.v4"
    echo "  或安装：apt install iptables-persistent${NC}"
fi

echo ""
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}  白名单 iptables 部署完成！${NC}"
echo -e "${GREEN}========================================${NC}"
echo "端口 8888 和 9999 现在只有登录用户的 IP 才能连接。"
echo ""
echo "验证命令："
echo "  ipset list ws_whitelist          # 查看当前白名单 IP"
echo "  iptables -L INPUT -n --line-numbers  # 查看 iptables 规则"
echo ""
echo -e "${YELLOW}注意：如果要临时禁用白名单（如排查问题），执行：${NC}"
echo "  iptables -D INPUT -p tcp --dport 8888 -j DROP"
echo "  iptables -D INPUT -p tcp --dport 9999 -j DROP"
