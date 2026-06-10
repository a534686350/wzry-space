#!/bin/bash
# ============================================================
# 服务器重启后从数据库恢复 IP 白名单到 ipset
# 由 systemd 的 restore-whitelist.service 在开机时自动调用
# ============================================================

IPSET_NAME="ws_whitelist"
# 从 PHP 配置文件读取数据库连接信息（避免重复维护两份配置）
CONFIG_PHP="$(dirname "$0")/auth/config.php"

if [ ! -f "$CONFIG_PHP" ]; then
    echo "[restore-whitelist] 找不到 $CONFIG_PHP，跳过恢复" >&2
    exit 1
fi

# 用 PHP 解析配置，输出 KEY=VALUE 格式
eval "$(php -r "
    \$c = require '$CONFIG_PHP';
    echo 'DB_HOST=' . escapeshellarg(\$c['db_host'] ?? '127.0.0.1') . \"\n\";
    echo 'DB_PORT=' . escapeshellarg(\$c['db_port'] ?? '3306') . \"\n\";
    echo 'DB_NAME=' . escapeshellarg(\$c['db_name'] ?? '') . \"\n\";
    echo 'DB_USER=' . escapeshellarg(\$c['db_user'] ?? '') . \"\n\";
    echo 'DB_PASS=' . escapeshellarg(\$c['db_password'] ?? '') . \"\n\";
" 2>/dev/null)"

if [ -z "$DB_NAME" ] || [ -z "$DB_USER" ]; then
    echo "[restore-whitelist] 数据库配置读取失败" >&2
    exit 1
fi

echo "[restore-whitelist] 开始恢复白名单..."

# 1. 重建 ipset
ipset destroy "$IPSET_NAME" 2>/dev/null || true
ipset create "$IPSET_NAME" hash:ip timeout 0

# 2. 永远放行本机
ipset add "$IPSET_NAME" 127.0.0.1 2>/dev/null || true

# 3. 重建 iptables 规则
if ! iptables -C INPUT -p tcp --dport 8888 -m set --match-set "$IPSET_NAME" src -j ACCEPT 2>/dev/null; then
    iptables -I INPUT -p tcp --dport 8888 -m set --match-set "$IPSET_NAME" src -j ACCEPT
fi
if ! iptables -C INPUT -p tcp --dport 9999 -m set --match-set "$IPSET_NAME" src -j ACCEPT 2>/dev/null; then
    iptables -I INPUT -p tcp --dport 9999 -m set --match-set "$IPSET_NAME" src -j ACCEPT
fi
if ! iptables -C INPUT -p tcp --dport 8888 -j DROP 2>/dev/null; then
    iptables -A INPUT -p tcp --dport 8888 -j DROP
fi
if ! iptables -C INPUT -p tcp --dport 9999 -j DROP 2>/dev/null; then
    iptables -A INPUT -p tcp --dport 9999 -j DROP
fi

# 4. 从数据库读取未过期的 IP，写入 ipset
MYSQL_PWD="$DB_PASS" mysql \
    -h "$DB_HOST" \
    -P "$DB_PORT" \
    -u "$DB_USER" \
    "$DB_NAME" \
    --batch --skip-column-names \
    -e "SELECT ip FROM ip_whitelist WHERE expires_at IS NULL OR expires_at > NOW();" \
2>/dev/null | while IFS= read -r ip; do
    ip="$(echo "$ip" | tr -d '[:space:]')"
    [ -z "$ip" ] && continue
    ipset add "$IPSET_NAME" "$ip" 2>/dev/null || true
    echo "[restore-whitelist] 已恢复: $ip"
done

COUNT=$(ipset list "$IPSET_NAME" 2>/dev/null | grep -c "^[0-9]" || echo 0)
echo "[restore-whitelist] 完成，共恢复 $COUNT 个 IP（含127.0.0.1）"
