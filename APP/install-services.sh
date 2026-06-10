#!/bin/bash
# ============================================================
# 一键安装所有守护进程和开机自启服务
# 包含：
#   1. home-server（JAR）崩溃自动重启
#   2. 服务器重启后白名单自动恢复
#   3. 定时清理过期白名单（每小时）
#
# 用法：sudo bash install-services.sh [项目目录]
# 例如：sudo bash install-services.sh /www/wwwroot/radar
# ============================================================
set -e
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'

[[ $(id -u) -eq 0 ]] || { echo -e "${RED}请用 root 运行：sudo bash $0${NC}"; exit 1; }

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="${1:-$SCRIPT_DIR}"
PROJECT_DIR="$(realpath "$PROJECT_DIR")"

JAR_PATH="$PROJECT_DIR/home-server-0.0.1-SNAPSHOT.jar"
START_SCRIPT="$PROJECT_DIR/start-server.sh"
RESTORE_SCRIPT="$PROJECT_DIR/restore-whitelist.sh"
HELPER_SCRIPT="$PROJECT_DIR/ws-whitelist-helper.sh"

echo -e "${GREEN}>>> 项目目录: $PROJECT_DIR${NC}"

# 检查必要文件
[ -f "$JAR_PATH" ] || { echo -e "${RED}找不到 $JAR_PATH${NC}"; exit 1; }
[ -f "$RESTORE_SCRIPT" ] || { echo -e "${RED}找不到 $RESTORE_SCRIPT${NC}"; exit 1; }

if [ -f "$START_SCRIPT" ]; then
    chmod +x "$START_SCRIPT"
    HOME_SERVER_EXEC="/bin/bash $START_SCRIPT"
else
    HOME_SERVER_EXEC="/usr/bin/java -jar $JAR_PATH --server.port=18888 --ws.port=19999"
fi

# -------- 1. 安装 home-server systemd 服务 --------
echo -e "${GREEN}=== 1. 安装 home-server 守护进程 ===${NC}"

cat > /etc/systemd/system/home-server.service << EOF
[Unit]
Description=Home Server (WebSocket 雷达数据服务)
After=network.target restore-whitelist.service
Requires=restore-whitelist.service

[Service]
Type=simple
WorkingDirectory=$PROJECT_DIR
ExecStart=$HOME_SERVER_EXEC
User=root
Restart=always
RestartSec=5
StartLimitIntervalSec=120
StartLimitBurst=10
StandardOutput=journal
StandardError=journal
SyslogIdentifier=home-server

[Install]
WantedBy=multi-user.target
EOF

echo "home-server.service 已写入"

# -------- 2. 安装 helper 脚本（白名单工具）--------
echo -e "${GREEN}=== 2. 安装 ws-whitelist-helper 脚本 ===${NC}"

HELPER_DST="/usr/local/bin/ws-whitelist-helper.sh"
if [ -f "$HELPER_SCRIPT" ]; then
    cp "$HELPER_SCRIPT" "$HELPER_DST"
    chmod 755 "$HELPER_DST"
    echo "helper 已复制到 $HELPER_DST"
else
    echo -e "${YELLOW}⚠ 未找到 ws-whitelist-helper.sh，跳过${NC}"
fi

# -------- 3. 配置 sudoers（PHP 调用 helper 用）--------
echo -e "${GREEN}=== 3. 配置 sudoers ===${NC}"

WEB_USER="www-data"
for u in www www-data nginx; do id "$u" &>/dev/null && WEB_USER="$u" && break; done

SUDOERS_FILE="/etc/sudoers.d/ws-whitelist"
cat > "$SUDOERS_FILE" << EOF
$WEB_USER ALL=(root) NOPASSWD: $HELPER_DST
EOF
chmod 440 "$SUDOERS_FILE"
visudo -c -f "$SUDOERS_FILE" 2>/dev/null || { echo -e "${RED}sudoers 语法错误，已删除${NC}"; rm -f "$SUDOERS_FILE"; }
echo "sudoers 已配置（$WEB_USER）"

# -------- 4. 安装白名单恢复服务 --------
echo -e "${GREEN}=== 4. 安装开机白名单恢复服务 ===${NC}"

chmod +x "$RESTORE_SCRIPT"

cat > /etc/systemd/system/restore-whitelist.service << EOF
[Unit]
Description=恢复 WebSocket 端口 IP 白名单（从数据库）
After=network.target mysql.service mariadb.service mysqld.service
Wants=mysql.service mariadb.service

[Service]
Type=oneshot
ExecStart=/bin/bash $RESTORE_SCRIPT
RemainAfterExit=yes
StandardOutput=journal
StandardError=journal
SyslogIdentifier=restore-whitelist

[Install]
WantedBy=multi-user.target
EOF

echo "restore-whitelist.service 已写入"

# -------- 5. 安装定时清理过期白名单（cron）--------
echo -e "${GREEN}=== 5. 配置定时清理过期白名单 ===${NC}"

CRON_FILE="/etc/cron.d/radar-whitelist-cleanup"
cat > "$CRON_FILE" << EOF
# 每小时整点清理过期白名单（删数据库 + 踢 iptables）
0 * * * * root /usr/bin/php -r "
define('API_MODULE','cleanup_whitelist_cron');
require '$PROJECT_DIR/auth/bootstrap.php';
require '$PROJECT_DIR/api/core_cron.php';
" >> /var/log/radar-whitelist-cleanup.log 2>&1
EOF

# 写一个独立的 cron PHP 入口（不依赖 HTTP 请求）
cat > "$PROJECT_DIR/api/core_cron.php" << 'PHPEOF'
<?php
/**
 * cron 专用：清理过期白名单
 * 由 /etc/cron.d/radar-whitelist-cleanup 调用
 */
if (php_sapi_name() !== 'cli') exit;
require_once __DIR__ . '/../auth/bootstrap.php';

function callIptablesHelper($action, $ip = '') {
    $helper = '/usr/local/bin/ws-whitelist-helper.sh';
    if (!file_exists($helper)) return;
    $cmd = "sudo $helper " . escapeshellarg($action) . ($ip !== '' ? ' ' . escapeshellarg($ip) : '') . ' 2>&1';
    @shell_exec($cmd);
}

function ensureIpWhitelistTable($pdo) {
    static $done = false;
    if ($done) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `ip_whitelist` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `ip` varchar(64) NOT NULL,
            `user_id` int unsigned NOT NULL,
            `username` varchar(64) NOT NULL DEFAULT '',
            `expires_at` datetime DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`), UNIQUE KEY `uk_ip` (`ip`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $done = true;
    } catch (PDOException $e) { $done = true; }
}

ensureIpWhitelistTable($pdo);
try {
    $stmt = $pdo->prepare("SELECT ip FROM ip_whitelist WHERE expires_at IS NOT NULL AND expires_at < NOW()");
    $stmt->execute();
    $ips = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($ips)) {
        $ph = implode(',', array_fill(0, count($ips), '?'));
        $pdo->prepare("DELETE FROM ip_whitelist WHERE ip IN ($ph)")->execute($ips);
        foreach ($ips as $ip) callIptablesHelper('del', $ip);
        echo date('[Y-m-d H:i:s]') . " 已清理 " . count($ips) . " 个过期 IP\n";
    } else {
        echo date('[Y-m-d H:i:s]') . " 无过期 IP\n";
    }
} catch (Exception $e) {
    echo date('[Y-m-d H:i:s]') . " 清理失败: " . $e->getMessage() . "\n";
}
PHPEOF

echo "cron 已写入 $CRON_FILE"

# -------- 6. 初始化 iptables（如果还没做过）--------
echo -e "${GREEN}=== 6. 初始化 iptables 白名单规则 ===${NC}"

if [ -f "$HELPER_DST" ]; then
    bash "$HELPER_DST" init
else
    echo -e "${YELLOW}⚠ helper 未安装，跳过 iptables 初始化${NC}"
fi

# -------- 7. 启动所有服务 --------
echo -e "${GREEN}=== 7. 启动所有服务 ===${NC}"

systemctl daemon-reload

systemctl enable restore-whitelist.service
systemctl start restore-whitelist.service
echo "restore-whitelist: $(systemctl is-active restore-whitelist.service)"

systemctl enable home-server.service
systemctl start home-server.service
sleep 2
echo "home-server:       $(systemctl is-active home-server.service)"

# 确保 nginx/php/mysql 也开机自启
for svc in nginx php-fpm php8.2-fpm php8.1-fpm php7.4-fpm mysql mariadb mysqld; do
    if systemctl list-unit-files "${svc}.service" &>/dev/null 2>&1 | grep -q "${svc}"; then
        systemctl enable "${svc}.service" 2>/dev/null && echo "${svc}: 已设置开机自启" || true
    fi
done

echo ""
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}  所有服务安装完成！${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
echo "常用命令："
echo "  systemctl status home-server        # 查看 JAR 状态"
echo "  journalctl -u home-server -f        # 实时查看 JAR 日志"
echo "  systemctl status restore-whitelist  # 查看白名单恢复状态"
echo "  ipset list ws_whitelist             # 查看当前白名单 IP"
echo "  systemctl restart home-server       # 手动重启 JAR"
echo ""
echo "服务器重启后会自动："
echo "  ✅ 恢复 iptables 白名单"
echo "  ✅ 启动 home-server JAR"
echo "  ✅ 启动 nginx / PHP / MySQL"
echo "  ✅ 每小时清理过期白名单"
