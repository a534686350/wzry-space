#!/bin/bash
# ============================================================
# WebSocket 端口白名单 ipset 辅助脚本
# 由 PHP（www-data/nginx用户）通过 sudo 调用，不要直接执行
#
# 用法：
#   sudo /usr/local/bin/ws-whitelist-helper.sh add   <IP> [port]
#   sudo /usr/local/bin/ws-whitelist-helper.sh del   <IP> [port]
#   sudo /usr/local/bin/ws-whitelist-helper.sh init        (首次初始化)
#   sudo /usr/local/bin/ws-whitelist-helper.sh flush       (清空白名单)
# ============================================================

ACTION="$1"
IP="$2"
IPSET_NAME="ws_whitelist"

# 验证动作
case "$ACTION" in
    add|del|init|flush) ;;
    *)
        echo "用法: $0 {add|del|init|flush} [IP]" >&2
        exit 1
        ;;
esac

# 验证 IP 格式（只允许合法的 IPv4）
validate_ip() {
    local ip="$1"
    if [[ ! "$ip" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]]; then
        echo "非法 IP: $ip" >&2
        exit 2
    fi
    IFS='.' read -r -a octets <<< "$ip"
    for octet in "${octets[@]}"; do
        if (( octet > 255 )); then
            echo "非法 IP: $ip" >&2
            exit 2
        fi
    done
}

init_ipset() {
    # 创建 ipset（如已存在则跳过）
    ipset create "$IPSET_NAME" hash:ip timeout 0 2>/dev/null || true

    # 永久放行本机和内网（避免自己被锁在外面）
    ipset add "$IPSET_NAME" 127.0.0.1 2>/dev/null || true

    # iptables 规则：先放行 whitelist 中的 IP，其余 DROP
    # 检查规则是否已存在，避免重复添加
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

    echo "[ws-whitelist] 初始化完成，8888/9999 端口已保护"
}

case "$ACTION" in
    init)
        init_ipset
        ;;

    add)
        [ -z "$IP" ] && { echo "add 需要 IP 参数" >&2; exit 1; }
        validate_ip "$IP"
        # 确保 ipset 已初始化
        ipset list "$IPSET_NAME" &>/dev/null || init_ipset
        # timeout=0 表示永不自动过期（PHP 负责到期删除）
        ipset add "$IPSET_NAME" "$IP" timeout 0 2>/dev/null || \
            ipset test "$IPSET_NAME" "$IP" 2>/dev/null && \
            ipset del "$IPSET_NAME" "$IP" 2>/dev/null && \
            ipset add "$IPSET_NAME" "$IP" timeout 0 2>/dev/null
        echo "[ws-whitelist] 已添加 $IP"
        ;;

    del)
        [ -z "$IP" ] && { echo "del 需要 IP 参数" >&2; exit 1; }
        validate_ip "$IP"
        # 127.0.0.1 永远保留
        [ "$IP" = "127.0.0.1" ] && exit 0
        ipset del "$IPSET_NAME" "$IP" 2>/dev/null || true
        echo "[ws-whitelist] 已移除 $IP"
        ;;

    flush)
        ipset flush "$IPSET_NAME" 2>/dev/null || true
        # 重新加回本机
        ipset add "$IPSET_NAME" 127.0.0.1 timeout 0 2>/dev/null || true
        echo "[ws-whitelist] 已清空白名单（保留127.0.0.1）"
        ;;
esac

exit 0
