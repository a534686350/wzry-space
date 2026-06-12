<?php
/**
 * API 统一入口：由各桩文件定义 API_MODULE 后引入，集中处理所有接口逻辑
 */
if (!defined('API_MODULE')) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => 400, 'msg' => 'Invalid API module']);
    exit;
}

ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once dirname(__DIR__) . '/auth/bootstrap.php';

// 紧急停止：按当前站点 host 拦截 API（不依赖登录/数据库里的状态）
$host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
$host = trim((string)$host);
if ($host !== '') {
    $host = preg_replace('/:\d+$/', '', $host); // 去掉端口
}
$emergencyActive = false;
if ($host !== '') {
    try {
        $stmt = $pdo->prepare('SELECT active FROM emergency_stop_sites WHERE host = ? LIMIT 1');
        $stmt->execute([$host]);
        $row = $stmt->fetch();
        $emergencyActive = $row && (int)$row['active'] === 1;
    } catch (Exception $e) {
        $emergencyActive = false;
    }
}
if ($emergencyActive) {
    if (ob_get_length()) ob_end_clean();
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => 503, 'msg' => '系统已紧急停止/维护中，请稍后再试'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (json_decode(file_get_contents('php://input'), true) ?: $_POST)
    : $_GET;

$action = isset($_GET['action']) ? trim($_GET['action']) : '';
if ($action === '' && isset($input['action'])) $action = trim((string) $input['action']);

function apiJsonResp($code, $msg, $data = null) {
    if (ob_get_length()) ob_end_clean();
    $out = ['code' => (int) $code, 'msg' => $msg];
    if ($data !== null) $out['data'] = $data;
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
}

/**
 * 兼容旧库：检测表字段是否存在（带静态缓存）
 */
function dbHasColumn(PDO $pdo, $table, $column) {
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];
    if (!preg_match('/^[a-zA-Z0-9_]+$/', (string) $table) || !preg_match('/^[a-zA-Z0-9_]+$/', (string) $column)) {
        $cache[$key] = false;
        return false;
    }
    try {
        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
        $stmt->execute([$table, $column]);
        $cache[$key] = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

function ensureGameServersTable(PDO $pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS game_servers (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(64) DEFAULT NULL,
            host VARCHAR(255) NOT NULL,
            port INT UNSIGNED NOT NULL DEFAULT 8888,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            public_account_visible TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            last_check_status VARCHAR(16) DEFAULT NULL,
            last_check_at DATETIME DEFAULT NULL,
            last_check_ms INT UNSIGNED DEFAULT NULL,
            last_check_error VARCHAR(255) DEFAULT NULL,
            source VARCHAR(16) NOT NULL DEFAULT 'admin',
            reported_username VARCHAR(64) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_host_port (host, port),
            KEY idx_enabled_sort (enabled, sort_order, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    if (!dbHasColumn($pdo, 'game_servers', 'last_check_status')) {
        $pdo->exec("ALTER TABLE game_servers ADD COLUMN last_check_status VARCHAR(16) DEFAULT NULL AFTER sort_order");
    }
    if (!dbHasColumn($pdo, 'game_servers', 'public_account_visible')) {
        $pdo->exec("ALTER TABLE game_servers ADD COLUMN public_account_visible TINYINT(1) NOT NULL DEFAULT 1 AFTER enabled");
    }
    if (!dbHasColumn($pdo, 'game_servers', 'last_check_at')) {
        $pdo->exec("ALTER TABLE game_servers ADD COLUMN last_check_at DATETIME DEFAULT NULL AFTER last_check_status");
    }
    if (!dbHasColumn($pdo, 'game_servers', 'last_check_ms')) {
        $pdo->exec("ALTER TABLE game_servers ADD COLUMN last_check_ms INT UNSIGNED DEFAULT NULL AFTER last_check_at");
    }
    if (!dbHasColumn($pdo, 'game_servers', 'last_check_error')) {
        $pdo->exec("ALTER TABLE game_servers ADD COLUMN last_check_error VARCHAR(255) DEFAULT NULL AFTER last_check_ms");
    }
    if (!dbHasColumn($pdo, 'game_servers', 'source')) {
        $pdo->exec("ALTER TABLE game_servers ADD COLUMN source VARCHAR(16) NOT NULL DEFAULT 'admin' AFTER last_check_error");
    }
    if (!dbHasColumn($pdo, 'game_servers', 'reported_username')) {
        $pdo->exec("ALTER TABLE game_servers ADD COLUMN reported_username VARCHAR(64) DEFAULT NULL AFTER source");
    }
}

function normalizeGameServerHost($host) {
    $host = trim((string) $host);
    $host = preg_replace('#^wss?://#i', '', $host);
    $host = preg_replace('#/.*$#', '', $host);
    $host = preg_replace('/:\d+$/', '', $host);
    return mb_substr($host, 0, 255);
}

function ensureAppSettingsTable(PDO $pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS app_settings (
            setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
            setting_value TEXT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function normalizeExternalLinkUrl($url) {
    $raw = trim((string) $url);
    if ($raw === '') return '';
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $raw)) return $raw;
    if ($raw[0] === '/' || $raw[0] === '#' || strpos($raw, './') === 0 || strpos($raw, '../') === 0) return $raw;
    $hostPart = preg_split('~[/?#]~', $raw, 2)[0];
    if (preg_match('/^\d{1,3}(\.\d{1,3}){3}(:\d+)?$/', $hostPart) || preg_match('/^localhost(:\d+)?$/i', $hostPart)) {
        return 'https://' . $raw;
    }
    if (strpos($hostPart, '.') !== false) {
        $suffix = strtolower(pathinfo($hostPart, PATHINFO_EXTENSION));
        $fileExtensions = ['html', 'htm', 'php', 'asp', 'aspx', 'jsp', 'json', 'xml', 'txt', 'apk', 'zip', 'rar', '7z', 'js', 'css', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico'];
        if (!in_array($suffix, $fileExtensions, true)) return 'https://' . $raw;
    }
    return $raw;
}

function getAppLinkSettings(PDO $pdo) {
    ensureAppSettingsTable($pdo);
    $defaults = [
        'trial_url' => '',
        'buy_card_url' => 'https://vi.dwx888.com/links/C7CF2798',
        'download_url' => 'https://qm.qq.com/q/c9p4QyRczY',
        'group_url' => '',
    ];
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ('trial_url','buy_card_url','download_url','group_url')");
    foreach ($stmt->fetchAll() as $row) {
        if (array_key_exists($row['setting_key'], $defaults)) {
            $defaults[$row['setting_key']] = (string) $row['setting_value'];
        }
    }
    if ($defaults['group_url'] === '' && $defaults['download_url'] !== '') {
        $defaults['group_url'] = $defaults['download_url'];
    }
    foreach (['trial_url', 'buy_card_url', 'download_url', 'group_url'] as $key) {
        $defaults[$key] = normalizeExternalLinkUrl($defaults[$key]);
    }
    return $defaults;
}

function getAppRemoteConfig(PDO $pdo, $includePrivate = false) {
    ensureAppSettingsTable($pdo);
    $defaults = [
        'version_code' => '11',
        'version_name' => 'v6.1.11',
        'apk_url' => '/apk/ALinRadar-v6.1.11.apk',
        'apk_url_github' => 'https://raw.githubusercontent.com/a534686350/wzry-space/main/%E7%BD%91%E9%A1%B5%E5%89%8D%E5%90%8E%E5%8F%B0/apk/ALinRadar-v6.1.11.apk',
        'apk_url_gitee' => 'https://gitee.com/hl515/wzry-space/raw/main/%E7%BD%91%E9%A1%B5%E5%89%8D%E5%90%8E%E5%8F%B0/apk/ALinRadar-v6.1.11.apk',
        'update_title' => '发现新版本',
        'update_message' => '检测到新版本，请下载更新。',
        'force_update' => '0',
        'popup_enabled' => '0',
        'popup_title' => '公告',
        'popup_message' => '',
        'popup_url' => '',
        'app_login_required' => '1',
        'app_login_enabled' => '0',
        'app_login_username' => '',
        'app_login_password' => '',
        'app_login_title' => 'APP 公共账号',
        'app_login_message' => '不会注册的用户可以使用下面账号登录 APP。',
    ];
    $keys = array_keys($defaults);
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ($placeholders)");
    $stmt->execute($keys);
    foreach ($stmt->fetchAll() as $row) {
        if (array_key_exists($row['setting_key'], $defaults)) {
            $defaults[$row['setting_key']] = (string) $row['setting_value'];
        }
    }
    $links = getAppLinkSettings($pdo);
    $apkUrls = [];
    foreach ([$defaults['apk_url'], $defaults['apk_url_github'], $defaults['apk_url_gitee']] as $url) {
        $url = trim((string) $url);
        if ($url !== '' && !in_array($url, $apkUrls, true)) $apkUrls[] = $url;
    }
    $data = [
        'update' => [
            'version_code' => (int) $defaults['version_code'],
            'version_name' => $defaults['version_name'],
            'apk_url' => $defaults['apk_url'],
            'apk_url_github' => $defaults['apk_url_github'],
            'apk_url_gitee' => $defaults['apk_url_gitee'],
            'apk_urls' => $apkUrls,
            'title' => $defaults['update_title'],
            'message' => $defaults['update_message'],
            'force_update' => ((int) $defaults['force_update']) ? true : false,
        ],
        'popup' => [
            'enabled' => ((int) $defaults['popup_enabled']) ? true : false,
            'title' => $defaults['popup_title'],
            'message' => $defaults['popup_message'],
            'url' => $defaults['popup_url'],
        ],
        'login_required' => ((int) $defaults['app_login_required']) ? true : false,
        'links' => $links,
    ];
    if ($includePrivate || ((int) $defaults['app_login_enabled'])) {
        $data['app_login'] = [
            'enabled' => ((int) $defaults['app_login_enabled']) ? true : false,
            'username' => $defaults['app_login_username'],
            'password' => $includePrivate || ((int) $defaults['app_login_enabled']) ? $defaults['app_login_password'] : '',
            'title' => $defaults['app_login_title'],
            'message' => $defaults['app_login_message'],
        ];
    }
    return $data;
}

function testGameServerTcp($host, $port, $timeout = 2.0) {
    $host = normalizeGameServerHost($host);
    $port = (int) $port;
    $started = microtime(true);
    if ($host === '' || $port <= 0 || $port > 65535) {
        return ['status' => 'offline', 'ms' => 0, 'error' => '地址或端口不正确'];
    }
    if (!function_exists('fsockopen')) {
        return ['status' => 'offline', 'ms' => 0, 'error' => '服务器 PHP 禁用了 fsockopen'];
    }
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
    $ms = (int) round((microtime(true) - $started) * 1000);
    if ($fp) {
        fclose($fp);
        return ['status' => 'online', 'ms' => $ms, 'error' => ''];
    }
    $error = trim((string) $errstr);
    if ($error === '') $error = $errno ? ('连接失败 #' . $errno) : '连接超时';
    return ['status' => 'offline', 'ms' => $ms, 'error' => mb_substr($error, 0, 255)];
}

/**
 * 通过8899端口API封禁IP
 * @param string $ip IP地址
 * @param string $reason 封禁原因
 * @return array ['success' => bool, 'error' => string]
 */
function blockIPViaPortLogger($ip, $reason = '黑名单封禁') {
    $hostname = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $port = 8899;
    
    // 解析主机名（去除端口号）
    if (strpos($hostname, ':') !== false) {
        $hostname = explode(':', $hostname)[0];
    }
    
    $url = "http://{$hostname}:{$port}/api/block";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['ip' => $ip, 'reason' => $reason]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['success' => false, 'error' => '连接失败: ' . $error];
    }
    
    if ($http_code !== 200) {
        return ['success' => false, 'error' => 'HTTP ' . $http_code];
    }
    
    $data = json_decode($response, true);
    if ($data && isset($data['success']) && $data['success']) {
        return ['success' => true, 'error' => ''];
    } else {
        $error_msg = isset($data['error']) ? $data['error'] : '未知错误';
        return ['success' => false, 'error' => $error_msg];
    }
}

/**
 * 通过8899端口API解封IP
 * @param string $ip IP地址
 * @return array ['success' => bool, 'error' => string]
 */
function unblockIPViaPortLogger($ip) {
    $hostname = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $port = 8899;
    
    // 解析主机名（去除端口号）
    if (strpos($hostname, ':') !== false) {
        $hostname = explode(':', $hostname)[0];
    }
    
    $url = "http://{$hostname}:{$port}/api/unblock";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['ip' => $ip]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['success' => false, 'error' => '连接失败: ' . $error];
    }
    
    if ($http_code !== 200) {
        return ['success' => false, 'error' => 'HTTP ' . $http_code];
    }
    
    $data = json_decode($response, true);
    if ($data && isset($data['success']) && $data['success']) {
        return ['success' => true, 'error' => ''];
    } else {
        $error_msg = isset($data['error']) ? $data['error'] : '未知错误';
        return ['success' => false, 'error' => $error_msg];
    }
}

/**
 * 清理过期用户/卡密：删除 expires_at 已过期的 card_keys，以及其关联的 users 与登录日志
 * @param PDO $pdo
 * @param array $opts ['limit'=>int, 'dry_run'=>bool]
 * @return array
 */
function runExpiredUsersCardsCleanup($pdo, $opts = []) {
    $limit = isset($opts['limit']) ? (int) $opts['limit'] : 2000;
    $limit = max(1, min(20000, $limit));
    $dryRun = !empty($opts['dry_run']);

    $now = date('Y-m-d H:i:s');
    $stats = [
        'now' => $now,
        'dry_run' => $dryRun,
        'limit' => $limit,
        'expired_cards_scanned' => 0,
        'expired_cards_deleted' => 0,
        'users_deleted' => 0,
        'login_logs_deleted' => 0,
        'room_logs_deleted' => 0,
        'errors' => [],
        'sample' => [],
    ];

    // 拉取一批过期卡密（无论是否已使用，只要过期就可清理）
    $stmt = $pdo->prepare('SELECT id, card_code, user_id, bound_ip, expires_at FROM card_keys WHERE expires_at IS NOT NULL AND expires_at < ? ORDER BY expires_at ASC LIMIT ' . (int) $limit);
    $stmt->execute([$now]);
    $rows = $stmt->fetchAll();
    $stats['expired_cards_scanned'] = count($rows);
    foreach ($rows as $r) {
        if (count($stats['sample']) < 10) {
            $stats['sample'][] = [
                'card_id' => (int) ($r['id'] ?? 0),
                'card_code' => $r['card_code'] ?? null,
                'user_id' => isset($r['user_id']) ? (int) $r['user_id'] : null,
                'bound_ip' => $r['bound_ip'] ?? null,
                'expires_at' => $r['expires_at'] ?? null,
            ];
        }
    }

    if ($dryRun || empty($rows)) {
        return $stats;
    }

    // 实际删除：逐条处理，避免一次性大事务锁太久
    $delUserStmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
    $delLogStmt  = $pdo->prepare('DELETE FROM user_login_log WHERE user_id = ?');
    $delCardStmt = $pdo->prepare('DELETE FROM card_keys WHERE id = ?');
    // 仅当用户当前仍绑定这张过期卡密时，才会删除用户本身；
    // 若用户已经续费绑定了新的卡密，则只删除旧的过期卡，不删除用户。
    $selectUserCardStmt = $pdo->prepare('SELECT card_key_id FROM users WHERE id = ? LIMIT 1');
    $delRoomStmt = null;
    try {
        $delRoomStmt = $pdo->prepare('DELETE FROM user_room_online WHERE ip = ?');
    } catch (Exception $e) {
        $delRoomStmt = null;
    }

    foreach ($rows as $r) {
        $cardId = (int) ($r['id'] ?? 0);
        if ($cardId <= 0) continue;
        $userId = isset($r['user_id']) ? (int) $r['user_id'] : 0;
        try {
            if ($userId > 0) {
                // 仅在该用户当前仍绑定这张过期卡时才删除用户；
                // 如果用户已经续费并绑定了新的卡密，则跳过用户删除，避免误删续费用户。
                $selectUserCardStmt->execute([$userId]);
                $userRow = $selectUserCardStmt->fetch();
                $currentCardId = $userRow ? (int) ($userRow['card_key_id'] ?? 0) : 0;
                if ($currentCardId === 0 || $currentCardId === $cardId) {
                    try {
                        $delLogStmt->execute([$userId]);
                        $stats['login_logs_deleted'] += (int) $delLogStmt->rowCount();
                    } catch (Exception $e) {
                        // 忽略日志删除异常
                    }
                    $delUserStmt->execute([$userId]);
                    $stats['users_deleted'] += (int) $delUserStmt->rowCount();
                }
            }

            $boundIp = trim((string) ($r['bound_ip'] ?? ''));
            if ($delRoomStmt && $boundIp !== '') {
                try {
                    $delRoomStmt->execute([$boundIp]);
                    $stats['room_logs_deleted'] += (int) $delRoomStmt->rowCount();
                } catch (Exception $e) {}
            }

            $delCardStmt->execute([$cardId]);
            $stats['expired_cards_deleted'] += (int) $delCardStmt->rowCount();
        } catch (Exception $e) {
            $stats['errors'][] = '删除失败 card_id=' . $cardId . ': ' . $e->getMessage();
        }
    }

    return $stats;
}

/**
 * 确保分享 token 表存在
 */
function ensureShareTokenTable(PDO $pdo) {
    static $done = false;
    if ($done) return;
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS share_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            token VARCHAR(128) NOT NULL UNIQUE,
            user_id INT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME DEFAULT NULL,
            INDEX idx_user_id (user_id),
            INDEX idx_expires_at (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $done = true;
}

/**
 * 校验分享 token 是否可用，并验证主账号卡密状态
 */
function verifyShareTokenAccess(PDO $pdo, $token) {
    $token = trim((string) $token);
    if ($token === '' || !preg_match('/^[A-Za-z0-9\-_]{16,128}$/', $token)) {
        return ['ok' => false, 'msg' => '分享链接无效'];
    }
    ensureShareTokenTable($pdo);

    $stmt = $pdo->prepare('SELECT id, user_id, expires_at FROM share_tokens WHERE token = ? LIMIT 1');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    if (!$row) {
        return ['ok' => false, 'msg' => '分享链接不存在或已失效'];
    }
    if (!empty($row['expires_at']) && strtotime($row['expires_at']) < time()) {
        return ['ok' => false, 'msg' => '分享链接已过期'];
    }

    $userId = (int) ($row['user_id'] ?? 0);
    if ($userId <= 0) {
        return ['ok' => false, 'msg' => '分享链接无效'];
    }

    $stmt = $pdo->prepare('SELECT u.id, u.username, u.card_key_id, c.card_code, c.paused, c.expires_at FROM users u LEFT JOIN card_keys c ON c.id = u.card_key_id WHERE u.id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $u = $stmt->fetch();
    if (!$u) {
        return ['ok' => false, 'msg' => '分享账号不存在'];
    }
    if (empty($u['card_key_id']) || empty($u['card_code'])) {
        return ['ok' => false, 'msg' => '分享账号未绑定有效卡密'];
    }
    if (!empty($u['paused'])) {
        return ['ok' => false, 'msg' => '分享账号卡密已暂停'];
    }
    if (!empty($u['expires_at']) && strtotime($u['expires_at']) < time()) {
        return ['ok' => false, 'msg' => '分享账号卡密已过期'];
    }

    return [
        'ok' => true,
        'msg' => 'ok',
        'user_id' => $userId,
        'username' => (string) ($u['username'] ?? ''),
        'card_code' => (string) ($u['card_code'] ?? '')
    ];
}

/**
 * 兜底创建前端用户相关表，避免旧库缺表导致后台用户列表报错
 */
function ensureFrontendUserTables(PDO $pdo) {
    static $done = false;
    if ($done) return;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            username VARCHAR(64) NOT NULL,
            password VARCHAR(255) NOT NULL,
            security_code VARCHAR(255) NOT NULL DEFAULT '',
            card_key_id INT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_username (username),
            KEY idx_card_key_id (card_key_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_login_log (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            ip VARCHAR(64) NOT NULL,
            login_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user_id (user_id),
            KEY idx_ip (ip)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $done = true;
}

/**
 * 根据卡密类型计算到期时间
 */
function computeCardExpiry($cardType, $boundAt) {
    $ts = strtotime($boundAt);
    switch ($cardType) {
        case 'day':   return date('Y-m-d H:i:s', $ts + 24 * 3600);
        case 'week':  return date('Y-m-d H:i:s', $ts + 7 * 24 * 3600);
        case 'month': return date('Y-m-d H:i:s', $ts + 30 * 24 * 3600);
        case 'trial': return date('Y-m-d H:i:s', $ts + 5 * 3600);
        default:      return null;
    }
}

/**
 * 卡密类型中文名映射
 */
function cardTypeName($type) {
    static $map = ['day' => '天卡', 'week' => '周卡', 'month' => '月卡', 'trial' => '试用卡'];
    return $map[$type] ?? $type;
}

// ============================================================
// IP 白名单辅助函数
// ============================================================

/**
 * 调用 iptables helper 脚本（需要 sudoers 配置，详见部署文档）
 * @param string $action  'add' | 'del' | 'init' | 'flush'
 * @param string $ip
 */
function callIptablesHelper($action, $ip = '') {
    // helper 脚本路径（部署时复制到此路径并 chmod +x）
    $helper = '/usr/local/bin/ws-whitelist-helper.sh';
    if (!file_exists($helper)) return; // 未部署则跳过，不影响主流程
    $safeAction = escapeshellarg($action);
    $safeIp     = $ip !== '' ? escapeshellarg($ip) : '';
    $cmd = "sudo $helper $safeAction $safeIp 2>&1";
    @shell_exec($cmd); // 忽略返回值，失败不影响登录
}

/**
 * 确保 ip_whitelist 表存在（首次调用时自动建表，兼容旧库）
 */
function ensureIpWhitelistTable($pdo) {
    static $ensured = false;
    if ($ensured) return;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `ip_whitelist` (
              `id` int unsigned NOT NULL AUTO_INCREMENT,
              `ip` varchar(64) NOT NULL COMMENT '客户端 IP',
              `user_id` int unsigned NOT NULL COMMENT '对应用户 ID',
              `username` varchar(64) NOT NULL DEFAULT '' COMMENT '用户名',
              `expires_at` datetime DEFAULT NULL COMMENT '到期时间，NULL=永久',
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uk_ip` (`ip`),
              KEY `idx_user_id` (`user_id`),
              KEY `idx_expires_at` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $ensured = true;
    } catch (PDOException $e) {
        $ensured = true; // 不阻断主流程
    }
}

/**
 * 登录成功后将 IP 加入白名单（或刷新到期时间）
 * @param PDO    $pdo
 * @param string $ip
 * @param int    $userId
 * @param string $username
 * @param string|null $expiresAt  卡密到期时间（datetime格式），null=永久
 */
function whitelistIpOnLogin($pdo, $ip, $userId, $username, $expiresAt = null) {
    if (empty($ip) || $ip === '0.0.0.0') return;
    ensureIpWhitelistTable($pdo);
    // 1. 写数据库（记录 + 备查）
    try {
        $stmt = $pdo->prepare("
            INSERT INTO ip_whitelist (ip, user_id, username, expires_at)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                username = VALUES(username),
                expires_at = VALUES(expires_at),
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$ip, $userId, $username, $expiresAt]);
    } catch (PDOException $e) {}
    // 2. 写 iptables ipset（实际放行 8888/9999 端口）
    callIptablesHelper('add', $ip);
}

/**
 * 登出时将 IP 从白名单移除
 */
function removeIpFromWhitelist($pdo, $ip) {
    if (empty($ip) || $ip === '0.0.0.0') return;
    ensureIpWhitelistTable($pdo);
    // 1. 从数据库移除
    try {
        $pdo->prepare('DELETE FROM ip_whitelist WHERE ip = ?')->execute([$ip]);
    } catch (PDOException $e) {}
    // 2. 从 iptables ipset 移除（立即封锁该 IP）
    callIptablesHelper('del', $ip);
}

/**
 * 暂停/删除卡密时，将该卡密下所有用户的 IP 从白名单移除
 * @param PDO   $pdo
 * @param array $cardIds  card_keys.id 数组
 */
function removeWhitelistByCardIds($pdo, array $cardIds) {
    if (empty($cardIds)) return;
    ensureIpWhitelistTable($pdo);
    try {
        $placeholders = implode(',', array_fill(0, count($cardIds), '?'));
        $stmt = $pdo->prepare("SELECT id FROM users WHERE card_key_id IN ($placeholders)");
        $stmt->execute($cardIds);
        $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (empty($userIds)) return;
        $ph2 = implode(',', array_fill(0, count($userIds), '?'));
        // 先查出要被删的 IP（用于 iptables）
        $ipStmt = $pdo->prepare("SELECT ip FROM ip_whitelist WHERE user_id IN ($ph2)");
        $ipStmt->execute($userIds);
        $ips = $ipStmt->fetchAll(PDO::FETCH_COLUMN);
        // 删数据库
        $pdo->prepare("DELETE FROM ip_whitelist WHERE user_id IN ($ph2)")->execute($userIds);
        // 批量踢出 iptables
        foreach ($ips as $ip) {
            callIptablesHelper('del', $ip);
        }
    } catch (PDOException $e) {}
}

/**
 * 清理白名单中已到期的条目（供定时任务或 cleanup_expired 调用）
 */
function cleanExpiredWhitelist($pdo) {
    ensureIpWhitelistTable($pdo);
    try {
        // 先查出要过期的 IP
        $stmt = $pdo->prepare("SELECT ip FROM ip_whitelist WHERE expires_at IS NOT NULL AND expires_at < NOW()");
        $stmt->execute();
        $ips = $stmt->fetchAll(PDO::FETCH_COLUMN);
        // 删数据库
        $del = $pdo->prepare("DELETE FROM ip_whitelist WHERE expires_at IS NOT NULL AND expires_at < NOW()");
        $del->execute();
        // 批量踢出 iptables
        foreach ($ips as $ip) {
            callIptablesHelper('del', $ip);
        }
        return count($ips);
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * 检查 IP 是否在白名单中且未过期
 * 返回 true=允许连接，false=拒绝
 */
function isIpWhitelisted($pdo, $ip) {
    if (empty($ip) || $ip === '0.0.0.0') return false;
    ensureIpWhitelistTable($pdo);
    try {
        $stmt = $pdo->prepare("
            SELECT id FROM ip_whitelist
            WHERE ip = ?
              AND (expires_at IS NULL OR expires_at > NOW())
            LIMIT 1
        ");
        $stmt->execute([$ip]);
        return (bool) $stmt->fetch();
    } catch (PDOException $e) {
        return false;
    }
}

switch (API_MODULE) {

// ---------- 英雄列表（同源代理，避免浏览器跨域限制） ----------
case 'hero_list':
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        apiJsonResp(405, '请使用 GET');
        exit;
    }
    $heroList = null;
    $sources = [
        dirname(__DIR__) . '/herolist.json',
        'https://pvp.qq.com/web201605/js/herolist.json',
    ];
    foreach ($sources as $src) {
        if (strpos($src, 'http') === 0) {
            if (!function_exists('curl_init')) continue;
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $src);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 8);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            $resp = curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($http !== 200 || !$resp) continue;
            $arr = json_decode($resp, true);
            if (is_array($arr) && !empty($arr)) {
                $heroList = $arr;
                break;
            }
        } else {
            if (!is_file($src)) continue;
            $txt = @file_get_contents($src);
            if ($txt === false || $txt === '') continue;
            $arr = json_decode($txt, true);
            if (is_array($arr) && !empty($arr)) {
                $heroList = $arr;
                break;
            }
        }
    }
    if (!is_array($heroList)) {
        apiJsonResp(500, '获取英雄列表失败');
        exit;
    }
    apiJsonResp(0, 'ok', $heroList);
    break;

// ---------- 召唤师技能列表（同源代理，避免浏览器跨域限制） ----------
case 'summoner_list':
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        apiJsonResp(405, '请使用 GET');
        exit;
    }
    $list = null;
    $sources = [
        dirname(__DIR__) . '/summoner.json',
        'https://pvp.qq.com/web201605/js/summoner.json',
    ];
    foreach ($sources as $src) {
        if (strpos($src, 'http') === 0) {
            if (!function_exists('curl_init')) continue;
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $src);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 8);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            $resp = curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($http !== 200 || !$resp) continue;
            $arr = json_decode($resp, true);
            if (is_array($arr) && !empty($arr)) {
                $list = $arr;
                break;
            }
        } else {
            if (!is_file($src)) continue;
            $txt = @file_get_contents($src);
            if ($txt === false || $txt === '') continue;
            $arr = json_decode($txt, true);
            if (is_array($arr) && !empty($arr)) {
                $list = $arr;
                break;
            }
        }
    }
    if (!is_array($list)) {
        apiJsonResp(500, '获取召唤师技能列表失败');
        exit;
    }
    apiJsonResp(0, 'ok', $list);
    break;

// ---------- 管理员登录 ----------
case 'admin_login':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        apiJsonResp(405, '请使用 POST');
        exit;
    }
    $username = isset($input['username']) ? trim($input['username']) : '';
    $password = isset($input['password']) ? $input['password'] : '';
    $client = isset($input['client']) ? trim((string) $input['client']) : 'web';
    if ($username === '' || $password === '') {
        apiJsonResp(400, '用户名和密码不能为空');
        exit;
    }
    $stmt = $pdo->prepare('SELECT id, username, password, role FROM admin_users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password'])) {
        apiJsonResp(403, '用户名或密码错误');
        exit;
    }
    $_SESSION['admin_user_id'] = (int) $user['id'];
    $_SESSION['admin_username'] = $user['username'];
    $_SESSION['admin_role'] = isset($user['role']) && $user['role'] === 'agent' ? 'agent' : 'admin';
    try {
        $pdo->prepare('UPDATE admin_users SET current_session_id = ? WHERE id = ?')->execute([session_id(), (int) $user['id']]);
    } catch (Exception $e) {}
    try {
        $ip = getClientIp();
        adminLog($pdo, '管理员登录', 'ip=' . $ip);
    } catch (Exception $e) {}
    apiJsonResp(0, '登录成功', ['username' => $user['username']]);
    break;

// ---------- 管理员登出（API，若前端调用） ----------
case 'admin_logout':
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    apiJsonResp(0, '已退出');
    break;

// ---------- 用户登录 ----------
case 'user_login':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        apiJsonResp(405, '请使用 POST');
        exit;
    }
    $username = isset($input['username']) ? trim($input['username']) : '';
    $password = isset($input['password']) ? $input['password'] : '';
    $client = isset($input['client']) ? trim((string) $input['client']) : 'web';
    if ($username === '' || $password === '') {
        apiJsonResp(400, '用户名和密码不能为空');
        exit;
    }
    $client_ip = getClientIp();
    $bl = checkBlacklist($pdo, $client_ip, $username);
    if ($bl['blocked']) {
        apiJsonResp(403, $bl['reason']);
        exit;
    }
    if ($client === 'app') {
        ensureAppSettingsTable($pdo);
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ('app_login_enabled','app_login_username','app_login_password')");
        $appLogin = ['app_login_enabled' => '0', 'app_login_username' => '', 'app_login_password' => ''];
        foreach ($stmt->fetchAll() as $row) {
            if (array_key_exists($row['setting_key'], $appLogin)) $appLogin[$row['setting_key']] = (string) $row['setting_value'];
        }
        if ((int) $appLogin['app_login_enabled'] === 1
            && hash_equals($appLogin['app_login_username'], $username)
            && hash_equals($appLogin['app_login_password'], $password)) {
            apiJsonResp(0, 'APP 公共账号登录成功', [
                'username' => $username,
                'card_code' => 'APP公共账号',
                'expires_at' => '后台控制',
                'card_status' => 'APP专用',
                'app_only' => true,
            ]);
            break;
        }
    }
    $stmt = $pdo->prepare('
        SELECT u.id, u.username, u.password, u.card_key_id,
               c.id AS card_id, c.card_code, c.paused AS card_paused, c.expires_at
        FROM users u
        LEFT JOIN card_keys c ON c.id = u.card_key_id
        WHERE u.username = ?
        LIMIT 1
    ');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password'])) {
        apiJsonResp(403, '用户名或密码错误');
        exit;
    }
    $user_id = (int) $user['id'];
    // 登录前校验卡密是否有效：未到期、未暂停
    if (!empty($user['card_key_id'])) {
        if (empty($user['card_id'])) {
            apiJsonResp(403, '关联卡密不存在，无法登录');
            exit;
        }
        if (!empty($user['card_paused'])) {
            apiJsonResp(403, '卡密已暂停，无法登录');
            exit;
        }
        if ($user['expires_at'] !== null && strtotime($user['expires_at']) < time()) {
            apiJsonResp(403, '账号已到期，无法登录');
            exit;
        }
    }
    try {
        $stmt = $pdo->prepare('INSERT INTO user_login_log (user_id, ip) VALUES (?, ?)');
        $stmt->execute([$user_id, $client_ip]);
    } catch (PDOException $e) {}
    // 登录成功 → 将 IP 加入 WebSocket 白名单
    $loginExpiresAt = isset($user['expires_at']) ? $user['expires_at'] : null;
    whitelistIpOnLogin($pdo, $client_ip, $user_id, $user['username'], $loginExpiresAt);
    $_SESSION['user_id'] = $user_id;
    $_SESSION['username'] = $user['username'];
    if (function_exists('session_write_close')) session_write_close();
    apiJsonResp(0, '登录成功', [
        'username' => $user['username'],
        'card_code' => isset($user['card_code']) ? $user['card_code'] : null,
        'expires_at' => isset($user['expires_at']) ? $user['expires_at'] : null,
        'card_status' => empty($user['card_key_id']) ? '未绑定' : '正常',
    ]);
    break;

// ---------- 用户登出 ----------
case 'user_logout':
    removeIpFromWhitelist($pdo, getClientIp());
    unset($_SESSION['user_id'], $_SESSION['username']);
    apiJsonResp(0, '已退出');
    break;

// ---------- WebSocket 端口白名单校验（供 nginx auth_request 调用） ----------
// nginx 在转发 8888/9999 WebSocket 前先请求此接口，
// 返回 HTTP 200 = 允许，HTTP 403 = 拒绝
case 'check_ws_access':
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    $wsIp = getClientIp();
    if (isIpWhitelisted($pdo, $wsIp)) {
        http_response_code(200);
        if (ob_get_length()) ob_end_clean();
        echo json_encode(['code' => 0, 'msg' => 'ok', 'ip' => $wsIp]);
        exit;
    }
    http_response_code(403);
    if (ob_get_length()) ob_end_clean();
    echo json_encode(['code' => 403, 'msg' => '请先登录后再使用雷达', 'ip' => $wsIp]);
    exit;

// ---------- 访问校验 ----------
case 'check_access':
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    $shareToken = isset($_GET['token']) ? trim((string) $_GET['token']) : '';
    if ($shareToken === '' && isset($input['token'])) {
        $shareToken = trim((string) $input['token']);
    }
    if ($shareToken === '' && isset($_COOKIE['share_token'])) {
        $shareToken = trim((string) $_COOKIE['share_token']);
    }
    // 只要请求携带 token，就优先走 token 校验（避免被残留会话干扰）
    if ($shareToken !== '') {
        $share = verifyShareTokenAccess($pdo, $shareToken);
        if ($share['ok']) {
            if (ob_get_length()) ob_end_clean();
            echo json_encode([
                'code' => 0,
                'allowed' => true,
                'msg' => '分享链接校验通过',
                'access_mode' => 'share_token',
                'share_owner' => $share['username']
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (ob_get_length()) ob_end_clean();
        echo json_encode(['code' => 403, 'allowed' => false, 'msg' => $share['msg']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!isUserLoggedIn()) {
        if (ob_get_length()) ob_end_clean();
        echo json_encode(['code' => 403, 'allowed' => false, 'msg' => '请先登录或注册'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $current_ip = getClientIp();
    $current_username = $_SESSION['username'] ?? '';
    $bl = checkBlacklist($pdo, $current_ip, $current_username);
    if ($bl['blocked']) {
        unset($_SESSION['user_id'], $_SESSION['username']);
        if (ob_get_length()) ob_end_clean();
        echo json_encode(['code' => 403, 'allowed' => false, 'msg' => $bl['reason']]);
        exit;
    }
    $user_id = (int) $_SESSION['user_id'];
    $stmt = $pdo->prepare('SELECT card_key_id FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    $allowed = true;
    if (!$user) {
        $allowed = false;
        unset($_SESSION['user_id'], $_SESSION['username']);
    } elseif (!empty($user['card_key_id'])) {
        $stmt = $pdo->prepare('SELECT id, paused, expires_at FROM card_keys WHERE id = ? LIMIT 1');
        $stmt->execute([$user['card_key_id']]);
        $card = $stmt->fetch();
        if (!$card || !empty($card['paused']) || ($card['expires_at'] !== null && strtotime($card['expires_at']) < time())) {
            $allowed = false;
            unset($_SESSION['user_id'], $_SESSION['username']);
        }
    }
    if (ob_get_length()) ob_end_clean();
    if ($allowed) {
        echo json_encode(['code' => 0, 'allowed' => true, 'msg' => '已登录']);
    } else {
        echo json_encode(['code' => 403, 'allowed' => false, 'msg' => '卡密已失效或已过期，请重新登录或注册']);
    }
    break;

// ---------- 仅检查用户是否已登录 ----------
case 'session_check':
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    if (ob_get_length()) ob_end_clean();
    if (isUserLoggedIn()) {
        echo json_encode(['code' => 0, 'logged_in' => true, 'username' => $_SESSION['username'] ?? '']);
    } else {
        echo json_encode(['code' => 0, 'logged_in' => false]);
    }
    break;

// ---------- 当前登录用户信息（含到期时间） ----------
case 'user_profile':
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    if (!isUserLoggedIn()) {
        apiJsonResp(401, '未登录');
        exit;
    }
    $user_id = (int) ($_SESSION['user_id'] ?? 0);
    if ($user_id <= 0) {
        apiJsonResp(401, '未登录');
        exit;
    }
    $stmt = $pdo->prepare('SELECT u.username, u.card_key_id, c.card_code, c.expires_at, c.paused AS card_paused FROM users u LEFT JOIN card_keys c ON c.id = u.card_key_id WHERE u.id = ? LIMIT 1');
    $stmt->execute([$user_id]);
    $row = $stmt->fetch();
    if (!$row) {
        apiJsonResp(404, '用户不存在');
        exit;
    }
    $expires_at = $row['expires_at'] ?: null;
    $card_status = '未绑定';
    if (!empty($row['card_key_id'])) {
        if (empty($row['card_code'])) {
            $card_status = '已删除/已过期';
        } elseif ($expires_at && strtotime($expires_at) < time()) {
            $card_status = '已过期';
        } elseif (!empty($row['card_paused'])) {
            $card_status = '已暂停';
        } else {
            $card_status = '正常';
        }
    }
    apiJsonResp(0, 'ok', [
        'username' => $row['username'],
        'card_code' => $row['card_code'] ?: null,
        'expires_at' => $expires_at,
        'card_status' => $card_status,
    ]);
    break;

// ---------- 生成分享 Token（登录用户） ----------
case 'share_token_generate':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        apiJsonResp(405, '请使用 POST');
        exit;
    }
    if (!isUserLoggedIn()) {
        apiJsonResp(401, '未登录');
        exit;
    }
    $user_id = (int) ($_SESSION['user_id'] ?? 0);
    if ($user_id <= 0) {
        apiJsonResp(401, '未登录');
        exit;
    }

    // 仅允许卡密有效用户生成分享链接
    $stmt = $pdo->prepare('SELECT u.username, u.card_key_id, c.card_code, c.paused, c.expires_at FROM users u LEFT JOIN card_keys c ON c.id = u.card_key_id WHERE u.id = ? LIMIT 1');
    $stmt->execute([$user_id]);
    $u = $stmt->fetch();
    if (!$u || empty($u['card_key_id']) || empty($u['card_code'])) {
        apiJsonResp(403, '当前账号未绑定有效卡密');
        exit;
    }
    if (!empty($u['paused'])) {
        apiJsonResp(403, '卡密已暂停，无法生成分享链接');
        exit;
    }
    if (!empty($u['expires_at']) && strtotime($u['expires_at']) < time()) {
        apiJsonResp(403, '卡密已过期，无法生成分享链接');
        exit;
    }

    ensureShareTokenTable($pdo);
    try {
        $raw = random_bytes(24);
    } catch (Exception $e) {
        $raw = md5(uniqid((string) mt_rand(), true) . microtime(true), true);
    }
    $token = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    if (!preg_match('/^[A-Za-z0-9\-_]{16,128}$/', $token)) {
        $token = bin2hex(random_bytes(16));
    }
    $expires_at = date('Y-m-d H:i:s', time() + 30 * 24 * 3600); // 30天有效

    $ins = $pdo->prepare('INSERT INTO share_tokens (token, user_id, expires_at) VALUES (?, ?, ?)');
    $ins->execute([$token, $user_id, $expires_at]);

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $apiDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/api/index.php')), '/');
    $siteDir = rtrim(str_replace('\\', '/', dirname($apiDir)), '/');
    if ($siteDir === '.' || $siteDir === '/') $siteDir = '';
    // 必须走 PHP 入口，才能触发 token 校验逻辑
    $url = $scheme . '://' . $host . $siteDir . '/index.php?token=' . rawurlencode($token);

    apiJsonResp(0, 'ok', [
        'token' => $token,
        'expires_at' => $expires_at,
        'url' => $url
    ]);
    break;

// ---------- 校验分享 Token ----------
case 'share_token_check':
    $token = isset($_GET['token']) ? trim((string) $_GET['token']) : '';
    if ($token === '' && isset($input['token'])) {
        $token = trim((string) $input['token']);
    }
    $ret = verifyShareTokenAccess($pdo, $token);
    if (!$ret['ok']) {
        apiJsonResp(403, $ret['msg'], ['allowed' => false]);
        exit;
    }
    apiJsonResp(0, 'ok', [
        'allowed' => true,
        'username' => $ret['username'],
        'card_code' => $ret['card_code']
    ]);
    break;

// ---------- 注册 ----------
case 'register':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        apiJsonResp(405, '请使用 POST');
        exit;
    }
    $username = isset($input['username']) ? trim($input['username']) : '';
    $password = isset($input['password']) ? $input['password'] : '';
    $card_code = isset($input['card_code']) ? trim($input['card_code']) : '';
    $security_code = isset($input['security_code']) ? (string) $input['security_code'] : '';
    $client_ip = getClientIp();
    $bl = checkBlacklist($pdo, $client_ip, $username);
    if ($bl['blocked']) {
        apiJsonResp(403, $bl['reason']);
        exit;
    }
    if (mb_strlen($username) < 4) {
        apiJsonResp(400, '用户名至少4位');
        exit;
    }
    if ($password === '' || strlen($password) < 4) {
        apiJsonResp(400, '密码至少4位');
        exit;
    }
    if ($card_code === '') {
        apiJsonResp(400, '请输入卡密');
        exit;
    }
    if ($security_code === '' || strlen($security_code) < 4) {
        apiJsonResp(400, '安全码至少4位');
        exit;
    }
    $stmt = $pdo->prepare('SELECT id FROM card_keys WHERE card_code = ? LIMIT 1');
    $stmt->execute([$card_code]);
    $card = $stmt->fetch();
    if (!$card) {
        apiJsonResp(404, '卡密不存在');
        exit;
    }
    $stmt = $pdo->prepare('SELECT id, status, paused, expires_at, card_type FROM card_keys WHERE id = ? LIMIT 1');
    $stmt->execute([$card['id']]);
    $card = $stmt->fetch();
    if ((int) $card['status'] === 1) {
        apiJsonResp(403, '该卡密已被使用');
        exit;
    }
    if (!empty($card['paused'])) {
        apiJsonResp(403, '该卡密已暂停');
        exit;
    }
    if ($card['expires_at'] !== null && strtotime($card['expires_at']) < time()) {
        apiJsonResp(403, '卡密已过期');
        exit;
    }
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        apiJsonResp(409, '用户名已被占用');
        exit;
    }
    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    $security_hash = password_hash($security_code, PASSWORD_DEFAULT);
    $bound_at = date('Y-m-d H:i:s');
    $expires_at = $card['expires_at'];
    if (isset($card['card_type']) && ($computed = computeCardExpiry($card['card_type'], $bound_at))) {
        $expires_at = $computed;
    }
    $register_server = isset($_SERVER['HTTP_X_FORWARDED_HOST']) ? trim((string) $_SERVER['HTTP_X_FORWARDED_HOST']) : (isset($_SERVER['HTTP_HOST']) ? trim((string) $_SERVER['HTTP_HOST']) : '');
    if (mb_strlen($register_server) > 255) $register_server = mb_substr($register_server, 0, 255);
    try {
        $hasRegisterServer = dbHasColumn($pdo, 'users', 'register_server');
        $pdo->beginTransaction();
        if ($hasRegisterServer) {
            $stmt = $pdo->prepare('INSERT INTO users (username, password, security_code, card_key_id, register_server) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$username, $password_hash, $security_hash, $card['id'], $register_server === '' ? null : $register_server]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO users (username, password, security_code, card_key_id) VALUES (?, ?, ?, ?)');
            $stmt->execute([$username, $password_hash, $security_hash, $card['id']]);
        }
        $user_id = (int) $pdo->lastInsertId();
        $stmt = $pdo->prepare('UPDATE card_keys SET status = 1, user_id = ?, bound_ip = ?, bound_at = ?, expires_at = ? WHERE id = ?');
        $stmt->execute([$user_id, $client_ip, $bound_at, $expires_at, $card['id']]);
        $stmt = $pdo->prepare('INSERT INTO user_login_log (user_id, ip) VALUES (?, ?)');
        $stmt->execute([$user_id, $client_ip]);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        apiJsonResp(500, '注册失败: ' . $e->getMessage());
        exit;
    }
    $_SESSION['user_id'] = $user_id;
    $_SESSION['username'] = $username;
    apiJsonResp(0, '注册成功', ['username' => $username, 'card_code' => $card_code, 'expires_at' => $expires_at, 'card_status' => '正常']);
    break;

// ---------- 用户通过安全码重置密码 ----------
case 'user_reset_password':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        apiJsonResp(405, '请使用 POST');
        exit;
    }
    $username = isset($input['username']) ? trim((string) $input['username']) : '';
    $security_code = isset($input['security_code']) ? (string) $input['security_code'] : '';
    $new_password = isset($input['new_password']) ? (string) $input['new_password'] : '';
    if ($username === '') {
        apiJsonResp(400, '请输入用户名');
        exit;
    }
    if ($security_code === '' || strlen($security_code) < 4) {
        apiJsonResp(400, '安全码至少4位');
        exit;
    }
    if ($new_password === '' || strlen($new_password) < 4) {
        apiJsonResp(400, '新密码至少4位');
        exit;
    }
    $stmt = $pdo->prepare('SELECT id, security_code FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if (!$user) {
        apiJsonResp(404, '用户不存在');
        exit;
    }
    if (empty($user['security_code']) || !password_verify($security_code, $user['security_code'])) {
        apiJsonResp(403, '安全码错误');
        exit;
    }
    $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
    $stmt->execute([$new_hash, (int) $user['id']]);
    if ($stmt->rowCount() <= 0) {
        apiJsonResp(500, '密码未修改');
        exit;
    }
    // 清理前端登录状态
    unset($_SESSION['user_id'], $_SESSION['username']);
    apiJsonResp(0, '密码已重置，请使用新密码登录');
    break;

// ---------- 根据 IP 查询最近登录用户名（用于前台房间列表显示） ----------
case 'ip_username':
    $ip = isset($_GET['ip']) ? trim((string) $_GET['ip']) : (isset($input['ip']) ? trim((string) $input['ip']) : '');
    if ($ip === '') {
        apiJsonResp(400, '缺少 ip 参数');
        exit;
    }
    try {
        $stmt = $pdo->prepare('SELECT u.username FROM user_login_log l INNER JOIN users u ON u.id = l.user_id WHERE l.ip = ? ORDER BY l.login_at DESC LIMIT 1');
        $stmt->execute([$ip]);
        $row = $stmt->fetch();
        if (!$row) {
            apiJsonResp(404, '没有找到该 IP 对应的用户');
        } else {
            apiJsonResp(0, 'ok', ['ip' => $ip, 'username' => $row['username']]);
        }
    } catch (PDOException $e) {
        apiJsonResp(500, '查询失败: ' . $e->getMessage());
    }
    break;

// ---------- WebSocket 房间上报（home_server -> 后台在线状态） ----------
case 'room_report':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        apiJsonResp(405, '请使用 POST');
        exit;
    }
    // 允许 jar 主动上报，也可以不传 ip，后端自动取请求来源 IP
    $ip = isset($input['ip']) ? trim((string) $input['ip']) : getClientIp();
    $room = isset($input['room']) ? trim((string) $input['room']) : '';
    if ($room === '') {
        apiJsonResp(400, '缺少 room 参数');
        exit;
    }
    if ($ip === '') {
        $ip = getClientIp();
    }
    try {
        // 按需创建表：记录 IP 最近所在房间
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS user_room_online (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                ip VARCHAR(64) NOT NULL,
                room VARCHAR(255) NOT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_ip (ip),
                KEY idx_ip_updated_at (ip, updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        // 同一 IP 仅保留最新一条记录，避免高频上报导致表无限增长
        $stmt = $pdo->prepare('INSERT INTO user_room_online (ip, room, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE room = VALUES(room), updated_at = NOW()');
        $stmt->execute([$ip, $room]);
        apiJsonResp(0, 'ok', ['ip' => $ip, 'room' => $room]);
    } catch (PDOException $e) {
        apiJsonResp(500, '上报失败: ' . $e->getMessage());
    }
    break;

// ---------- APP / 网页端实时在线心跳 ----------
case 'client_online_heartbeat':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        apiJsonResp(405, '请使用 POST');
        exit;
    }
    $client = isset($input['client']) ? trim((string) $input['client']) : 'web';
    if (!in_array($client, ['web', 'app'], true)) $client = 'web';
    $username = isset($input['username']) ? trim((string) $input['username']) : '';
    if ($username === '' && isset($_SESSION['username'])) $username = (string) $_SESSION['username'];
    $deviceId = isset($input['device_id']) ? trim((string) $input['device_id']) : '';
    $ip = getClientIp();
    if ($deviceId === '') $deviceId = $client . ':' . $ip . ':' . ($username !== '' ? $username : session_id());
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS client_online (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                client VARCHAR(16) NOT NULL,
                device_id VARCHAR(128) NOT NULL,
                username VARCHAR(64) DEFAULT NULL,
                ip VARCHAR(64) DEFAULT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_client_device (client, device_id),
                KEY idx_client_updated (client, updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $stmt = $pdo->prepare('INSERT INTO client_online (client, device_id, username, ip, updated_at) VALUES (?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE username = VALUES(username), ip = VALUES(ip), updated_at = NOW()');
        $stmt->execute([$client, mb_substr($deviceId, 0, 128), $username === '' ? null : mb_substr($username, 0, 64), $ip]);
        apiJsonResp(0, 'ok');
    } catch (PDOException $e) {
        apiJsonResp(500, '在线心跳失败: ' . $e->getMessage());
    }
    break;

// ---------- 登录页公开房间统计（返回房间总数与房间号列表） ----------
case 'room_count':
    try {
        // 近 2 分钟内有上报记录的房间视为「在线房间」
        $threshold = date('Y-m-d H:i:s', time() - 120);
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS user_room_online (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                ip VARCHAR(64) NOT NULL,
                room VARCHAR(255) NOT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_ip (ip),
                KEY idx_ip_updated_at (ip, updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        ensureGameServersTable($pdo);
        $stmt = $pdo->prepare('SELECT room, COUNT(DISTINCT ip) AS ip_count FROM user_room_online WHERE updated_at >= ? GROUP BY room ORDER BY ip_count DESC, room ASC');
        $stmt->execute([$threshold]);
        $rooms = [];
        $ipRoomCount = 0;
        while ($row = $stmt->fetch()) {
            $room = trim((string) $row['room']);
            if ($room === '') continue;
            $rooms[] = $room;
            $ipRoomCount += (int) $row['ip_count'];
        }
        $rooms = array_values(array_unique($rooms));
        $serverRooms = [];
        $serverStmt = $pdo->prepare("
            SELECT u.ip, u.room, COALESCE(g.port, 8888) AS port, COALESCE(g.name, u.ip) AS name
            FROM user_room_online u
            LEFT JOIN game_servers g ON g.host = u.ip AND g.enabled = 1
            WHERE u.updated_at >= ?
            ORDER BY u.room ASC, u.ip ASC
        ");
        $serverStmt->execute([$threshold]);
        while ($row = $serverStmt->fetch()) {
            $ip = trim((string) $row['ip']);
            $room = trim((string) $row['room']);
            if ($ip === '' || $room === '') continue;
            $key = $ip . ':' . (int) $row['port'];
            if (!isset($serverRooms[$key])) {
                $serverRooms[$key] = [
                    'host' => $ip,
                    'port' => (int) $row['port'],
                    'name' => $row['name'] ?: $ip,
                    'rooms' => [],
                ];
            }
            if (!in_array($room, $serverRooms[$key]['rooms'], true)) {
                $serverRooms[$key]['rooms'][] = $room;
            }
        }
        $data = [
            'room_count'    => count($rooms),
            'rooms'         => $rooms,
            'servers'       => array_values($serverRooms),
            'ip_room_count' => $ipRoomCount,
        ];
        apiJsonResp(0, 'ok', $data);
    } catch (PDOException $e) {
        apiJsonResp(0, 'ok', ['room_count' => 0, 'rooms' => [], 'ip_room_count' => 0]);
    }
    break;

// ---------- 卡密激活 ----------
case 'activate_card':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        apiJsonResp(405, '请使用 POST');
        exit;
    }
    $username = isset($input['username']) ? trim($input['username']) : '';
    $card_code = isset($input['card_code']) ? trim($input['card_code']) : '';
    $client_ip = getClientIp();
    $bl = checkBlacklist($pdo, $client_ip, $username);
    if ($bl['blocked']) {
        apiJsonResp(403, $bl['reason']);
        exit;
    }
    if ($username === '' || $card_code === '') {
        apiJsonResp(400, $username === '' ? '请输入用户名' : '请输入卡密');
        exit;
    }
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if (!$user) {
        apiJsonResp(404, '用户名不存在，请先注册');
        exit;
    }
    $user_id = (int) $user['id'];
    $stmt = $pdo->prepare('SELECT id, status, paused, expires_at, card_type FROM card_keys WHERE card_code = ? LIMIT 1');
    $stmt->execute([$card_code]);
    $card = $stmt->fetch();
    if (!$card) {
        apiJsonResp(404, '卡密不存在');
        exit;
    }
    if ((int) $card['status'] === 1) {
        apiJsonResp(403, '该卡密已被使用');
        exit;
    }
    if (!empty($card['paused'])) {
        apiJsonResp(403, '该卡密已暂停');
        exit;
    }
    if ($card['expires_at'] !== null && strtotime($card['expires_at']) < time()) {
        apiJsonResp(403, '卡密已过期');
        exit;
    }
    $bound_at = date('Y-m-d H:i:s');
    $expires_at = $card['expires_at'];
    if (isset($card['card_type']) && ($computed = computeCardExpiry($card['card_type'], $bound_at))) {
        $expires_at = $computed;
    }
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('UPDATE card_keys SET status = 1, user_id = ?, bound_ip = ?, bound_at = ?, expires_at = ? WHERE id = ?');
        $stmt->execute([$user_id, $client_ip, $bound_at, $expires_at, $card['id']]);
        $stmt = $pdo->prepare('UPDATE users SET card_key_id = ? WHERE id = ?');
        $stmt->execute([$card['id'], $user_id]);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        apiJsonResp(500, '激活失败: ' . $e->getMessage());
        exit;
    }
    apiJsonResp(0, '激活成功，该账号有效期已更新', ['username' => $username, 'card_code' => $card_code, 'expires_at' => $expires_at, 'card_status' => '正常']);
    break;

// ---------- 卡密 ----------
case 'card':
    $card_code = isset($input['card_code']) ? trim($input['card_code']) : (isset($_GET['card_code']) ? trim($_GET['card_code']) : '');
    if ($action === 'check') {
        if ($card_code === '') {
            apiJsonResp(400, '请输入卡密', ['valid' => false]);
            exit;
        }
        $stmt = $pdo->prepare('SELECT id, status, paused, expires_at FROM card_keys WHERE card_code = ? LIMIT 1');
        $stmt->execute([$card_code]);
        $row = $stmt->fetch();
        if (!$row) {
            apiJsonResp(0, '卡密不存在', ['valid' => false]);
            exit;
        }
        if (!empty($row['paused'])) {
            apiJsonResp(0, '卡密已暂停', ['valid' => false]);
            exit;
        }
        if ((int) $row['status'] === 1) {
            apiJsonResp(0, '该卡密已被使用', ['valid' => false]);
            exit;
        }
        if ($row['expires_at'] !== null && strtotime($row['expires_at']) < time()) {
            apiJsonResp(0, '卡密已过期', ['valid' => false]);
            exit;
        }
        apiJsonResp(0, '卡密有效', ['valid' => true]);
        exit;
    }
    requireLogin();
    $adminId = (int) ($_SESSION['admin_user_id'] ?? 0);
    $isAgent = (isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'agent');
    switch ($action) {
        case 'list':
            try {
                $keyword = isset($input['keyword']) ? trim((string) $input['keyword']) : '';
                $status = isset($input['status']) ? $input['status'] : '';
                $paused = isset($input['paused']) ? $input['paused'] : '';
                $card_type = isset($input['card_type']) ? trim((string) $input['card_type']) : '';
                $page = max(1, (int) (isset($input['page']) ? $input['page'] : 1));
                $page_size = max(1, min(100, (int) (isset($input['page_size']) ? $input['page_size'] : 20)));
                $where = ['1=1'];
                $params = [];
                if ($isAgent) {
                    $where[] = 'c.creator_id = ?';
                    $params[] = $adminId;
                }
                if ($keyword !== '') {
                    $where[] = '(c.card_code LIKE ? OR c.remark LIKE ?)';
                    $params[] = '%' . $keyword . '%';
                    $params[] = '%' . $keyword . '%';
                }
                if ($status !== '' && $status !== 'all') {
                    $where[] = 'c.status = ?';
                    $params[] = (int) $status;
                }
                if ($paused !== '' && $paused !== 'all') {
                    $where[] = 'c.paused = ?';
                    $params[] = (int) $paused;
                }
                // 卡类型筛选改为前端在列表结果中本地过滤，避免不同排序规则字符串比较导致的 1267 错误
                $whereSql = implode(' AND ', $where);
                $fromTable = 'card_keys c';
                if (!$isAgent) {
                    $fromTable .= ' LEFT JOIN admin_users au ON au.id = c.creator_id';
                }
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM $fromTable WHERE $whereSql");
                $stmt->execute($params);
                $total = (int) $stmt->fetchColumn();
                $offset = ($page - 1) * $page_size;
                $sel = 'c.id, c.card_code, c.status, c.paused, c.expires_at, c.card_type, c.user_id, c.bound_ip, c.bound_at, c.remark, c.created_at, c.creator_id';
                if (!$isAgent) {
                    $sel .= ', au.username AS creator_username';
                }
                $sql = "SELECT $sel FROM $fromTable WHERE $whereSql ORDER BY c.id DESC LIMIT " . (int) $page_size . " OFFSET " . (int) $offset;
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $list = $stmt->fetchAll();
                $logStmt = $pdo->prepare('SELECT ip FROM user_login_log WHERE user_id = ? ORDER BY login_at DESC');
                foreach ($list as &$r) {
                    $r['status_text'] = (int) $r['status'] === 1 ? '已使用' : '未使用';
                    if ($r['expires_at'] && strtotime($r['expires_at']) < time()) $r['status_text'] = '已过期';
                    $r['paused'] = isset($r['paused']) ? (int) $r['paused'] : 0;
                    $r['card_type_text'] = isset($r['card_type']) ? cardTypeName($r['card_type']) : (empty($r['expires_at']) ? '永久' : '自定义');
                    $r['bound_at'] = $r['bound_at'] ?: null;
                    $r['expires_at'] = $r['expires_at'] ?: null;
                    $r['login_ips'] = [];
                    if (!empty($r['user_id'])) {
                        $logStmt->execute([$r['user_id']]);
                        $seen = [];
                        while ($row = $logStmt->fetch()) {
                            $ip = $row['ip'];
                            if (!isset($seen[$ip])) { $seen[$ip] = true; $r['login_ips'][] = $ip; }
                        }
                    }
                    // 默认试用设备信息为空，后面按需补充
                    $r['trial_device_id'] = null;
                    $r['trial_device_name'] = null;
                    $r['trial_ip'] = null;
                }
                unset($r);

                // 为试用卡补充领取设备信息（从 trial_card_claims 表按 card_code 反查），避免跨表不同字符集联表导致 1267 错误
                try {
                    $trialCodesMap = [];
                    foreach ($list as $row) {
                        if (!empty($row['card_type']) && $row['card_type'] === 'trial' && !empty($row['card_code'])) {
                            $trialCodesMap[$row['card_code']] = true;
                        }
                    }
                    if (!empty($trialCodesMap)) {
                        // 确保 trial_card_claims 表存在（与 trial_card / trial_card_admin 接口保持一致）
                        try {
                            $pdo->exec("
                                CREATE TABLE IF NOT EXISTS trial_card_claims (
                                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                                    device_id VARCHAR(128) NOT NULL,
                                    ip VARCHAR(64) DEFAULT NULL,
                                    card_code VARCHAR(64) NOT NULL,
                                    device_name VARCHAR(255) DEFAULT NULL,
                                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                    UNIQUE KEY uniq_device (device_id),
                                    KEY idx_created_at (created_at)
                                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                            ");
                        } catch (PDOException $e) {
                            // 表创建失败不影响主流程，只是不展示试用设备信息
                        }

                        $codes = array_keys($trialCodesMap);
                        $placeholders = implode(',', array_fill(0, count($codes), '?'));
                        $stmtTrial = $pdo->prepare('SELECT card_code, device_id, device_name, ip FROM trial_card_claims WHERE card_code IN (' . $placeholders . ')');
                        $stmtTrial->execute($codes);
                        $trialInfoByCode = [];
                        while ($tr = $stmtTrial->fetch()) {
                            $code = $tr['card_code'];
                            if ($code === null || $code === '') continue;
                            $trialInfoByCode[$code] = [
                                'device_id'   => $tr['device_id'] ?? null,
                                'device_name' => $tr['device_name'] ?? null,
                                'ip'          => $tr['ip'] ?? null,
                            ];
                        }
                        if (!empty($trialInfoByCode)) {
                            foreach ($list as &$r2) {
                                if (!empty($r2['card_code']) && isset($trialInfoByCode[$r2['card_code']])) {
                                    $info = $trialInfoByCode[$r2['card_code']];
                                    $r2['trial_device_id'] = $info['device_id'];
                                    $r2['trial_device_name'] = $info['device_name'];
                                    $r2['trial_ip'] = $info['ip'];
                                }
                            }
                            unset($r2);
                        }
                    }
                } catch (PDOException $e) {
                    // 忽略试用设备信息查询错误，主列表照常返回
                } catch (Throwable $e) {
                    // 同上
                }

                apiJsonResp(0, 'ok', ['list' => $list, 'total' => $total, 'page' => $page, 'page_size' => $page_size]);
            } catch (PDOException $e) {
                apiJsonResp(500, '卡密列表加载失败: ' . $e->getMessage());
            } catch (Throwable $e) {
                apiJsonResp(500, '卡密列表加载失败: ' . $e->getMessage());
            }
            break;
        case 'generate':
            $count = isset($input['count']) ? (int) $input['count'] : 1;
            $len = isset($input['length']) ? (int) $input['length'] : 16;
            $type = isset($input['type']) ? trim((string) $input['type']) : '';
            $count = max(1, min(100, $count));
            $len = max(8, min(32, $len));
            $expires_at = null;
            $card_type = null;
            if ($type === 'day') {
                $card_type = 'day';
            } elseif ($type === 'week') {
                $card_type = 'week';
            } elseif ($type === 'month') {
                $card_type = 'month';
            } elseif ($type === 'trial') {
                $card_type = 'trial';
            }
            $prefix = '';
            if ($card_type === 'day') {
                $prefix = 'TK';
            } elseif ($card_type === 'week') {
                $prefix = 'ZK';
            } elseif ($card_type === 'month') {
                $prefix = 'YK';
            } elseif ($card_type === 'trial') {
                $prefix = 'SY';
            }
            $randomLen = $len - strlen($prefix);
            if ($randomLen < 1) $randomLen = 1;
            $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            $generated = [];
            $creatorId = $isAgent ? $adminId : null;
            $insertStmt = $pdo->prepare('INSERT INTO card_keys (card_code, expires_at, card_type, creator_id) VALUES (?, ?, ?, ?)');
            for ($i = 0; $i < $count; $i++) {
                $code = $prefix;
                for ($j = 0; $j < $randomLen; $j++) $code .= $chars[random_int(0, strlen($chars) - 1)];
                try {
                    $insertStmt->execute([$code, $expires_at, $card_type, $creatorId]);
                    $generated[] = $code;
                } catch (PDOException $e) {
                    if (strpos($e->getMessage(), 'Duplicate') !== false) { $i--; continue; }
                    apiJsonResp(500, '生成失败: ' . $e->getMessage());
                    exit;
                }
            }
            $typeText = $card_type ? cardTypeName($card_type) : '';
            adminLog($pdo, '生成卡密', '数量=' . count($generated) . ' 类型=' . ($card_type ?: '无'));
            apiJsonResp(0, '生成成功', ['keys' => $generated, 'count' => count($generated), 'card_type' => $card_type, 'card_type_text' => $typeText]);
            break;
        case 'update_remark':
            $id = isset($input['id']) ? (int) $input['id'] : 0;
            $remark = isset($input['remark']) ? trim((string) $input['remark']) : '';
            if ($id <= 0) {
                apiJsonResp(400, '请提供卡密 id');
                exit;
            }
            $remark = mb_substr($remark, 0, 255);
            $creatorCond = $isAgent ? ' AND creator_id = ' . (int) $adminId : '';
            $stmt = $pdo->prepare('UPDATE card_keys SET remark = ? WHERE id = ?' . $creatorCond);
            $stmt->execute([$remark === '' ? null : $remark, $id]);
            if ($stmt->rowCount() > 0) {
                adminLog($pdo, '编辑卡密备注', 'id=' . $id);
                apiJsonResp(0, '更新成功');
            } else {
                apiJsonResp(404, '记录不存在');
            }
            break;
        case 'delete':
            $ids = isset($input['ids']) && is_array($input['ids']) ? array_filter(array_map('intval', $input['ids'])) : [];
            $id = isset($input['id']) ? (int) $input['id'] : 0;
            $card_code_del = isset($input['card_code']) ? trim($input['card_code']) : '';
            $creatorCond = $isAgent ? ' AND creator_id = ' . (int) $adminId : '';
            if (!empty($ids)) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $pdo->prepare("DELETE FROM card_keys WHERE id IN ($placeholders)" . $creatorCond);
                $stmt->execute($ids);
                adminLog($pdo, '删除卡密', '数量=' . $stmt->rowCount());
                apiJsonResp(0, '删除成功', ['affected' => $stmt->rowCount()]);
            } elseif ($id > 0) {
                $stmt = $pdo->prepare('DELETE FROM card_keys WHERE id = ?' . $creatorCond);
                $stmt->execute([$id]);
                if ($stmt->rowCount() > 0) {
                    adminLog($pdo, '删除卡密', 'id=' . $id);
                    apiJsonResp(0, '删除成功');
                } else {
                    apiJsonResp(404, '记录不存在');
                }
            } elseif ($card_code_del !== '') {
                $stmt = $pdo->prepare('DELETE FROM card_keys WHERE card_code = ?' . $creatorCond);
                $stmt->execute([$card_code_del]);
                if ($stmt->rowCount() > 0) {
                    adminLog($pdo, '删除卡密', 'card_code=' . $card_code_del);
                    apiJsonResp(0, '删除成功');
                } else {
                    apiJsonResp(404, '记录不存在');
                }
            } else {
                apiJsonResp(400, '请提供 id、ids 或 card_code');
            }
            break;
        case 'pause':
            $ids = isset($input['ids']) && is_array($input['ids']) ? array_filter(array_map('intval', $input['ids'])) : [];
            $id = (int) (isset($input['id']) ? $input['id'] : (isset($_GET['id']) ? $_GET['id'] : 0));
            $creatorCond = $isAgent ? ' AND creator_id = ' . (int) $adminId : '';
            if (!empty($ids)) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $pdo->prepare("UPDATE card_keys SET paused = 1 WHERE id IN ($placeholders)" . $creatorCond);
                $stmt->execute($ids);
                removeWhitelistByCardIds($pdo, $ids); // 踢出白名单
                adminLog($pdo, '暂停卡密', '数量=' . $stmt->rowCount());
                apiJsonResp(0, '已暂停', ['affected' => $stmt->rowCount()]);
            } elseif ($id > 0) {
                $stmt = $pdo->prepare('UPDATE card_keys SET paused = 1 WHERE id = ?' . $creatorCond);
                $stmt->execute([$id]);
                if ($stmt->rowCount() > 0) {
                    removeWhitelistByCardIds($pdo, [$id]); // 踢出白名单
                    adminLog($pdo, '暂停卡密', 'id=' . $id);
                    apiJsonResp(0, '已暂停');
                } else {
                    $chk = $pdo->prepare('SELECT id FROM card_keys WHERE id = ? LIMIT 1');
                    $chk->execute([$id]);
                    if (!$chk->fetch()) {
                        apiJsonResp(404, '卡密不存在或已删除');
                    } else {
                        apiJsonResp(0, '已暂停');
                    }
                }
            } else {
                apiJsonResp(400, '请提供卡密 id 或 ids');
            }
            break;
        case 'enable':
            $ids = isset($input['ids']) && is_array($input['ids']) ? array_filter(array_map('intval', $input['ids'])) : [];
            $id = (int) (isset($input['id']) ? $input['id'] : (isset($_GET['id']) ? $_GET['id'] : 0));
            $creatorCond = $isAgent ? ' AND creator_id = ' . (int) $adminId : '';
            if (!empty($ids)) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $pdo->prepare("UPDATE card_keys SET paused = 0 WHERE id IN ($placeholders)" . $creatorCond);
                $stmt->execute($ids);
                adminLog($pdo, '启用卡密', '数量=' . $stmt->rowCount());
                apiJsonResp(0, '已启用', ['affected' => $stmt->rowCount()]);
            } elseif ($id > 0) {
                $stmt = $pdo->prepare('UPDATE card_keys SET paused = 0 WHERE id = ?' . $creatorCond);
                $stmt->execute([$id]);
                if ($stmt->rowCount() > 0) {
                    adminLog($pdo, '启用卡密', 'id=' . $id);
                    apiJsonResp(0, '已启用');
                } else {
                    $chk = $pdo->prepare('SELECT id FROM card_keys WHERE id = ? LIMIT 1');
                    $chk->execute([$id]);
                    if (!$chk->fetch()) {
                        apiJsonResp(404, '卡密不存在或已删除');
                    } else {
                        apiJsonResp(0, '已启用');
                    }
                }
            } else {
                apiJsonResp(400, '请提供卡密 id 或 ids');
            }
            break;
        case 'list_agent_cards':
            if ($isAgent) {
                apiJsonResp(403, '仅总管理可查看');
                exit;
            }
            $page = max(1, (int) (isset($input['page']) ? $input['page'] : 1));
            $page_size = max(1, min(100, (int) (isset($input['page_size']) ? $input['page_size'] : 20)));
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM card_keys c WHERE c.creator_id IS NOT NULL");
            $stmt->execute();
            $total = (int) $stmt->fetchColumn();
            $offset = ($page - 1) * $page_size;
            $stmt = $pdo->prepare("SELECT c.id, c.card_code, c.status, c.paused, c.expires_at, c.card_type, c.user_id, c.bound_ip, c.bound_at, c.remark, c.created_at, c.creator_id, au.username AS creator_username FROM card_keys c LEFT JOIN admin_users au ON au.id = c.creator_id WHERE c.creator_id IS NOT NULL ORDER BY c.id DESC LIMIT " . (int) $page_size . " OFFSET " . (int) $offset);
            $stmt->execute();
            $list = $stmt->fetchAll();
            $logStmt = $pdo->prepare('SELECT ip FROM user_login_log WHERE user_id = ? ORDER BY login_at DESC');
            foreach ($list as &$r) {
                $r['status_text'] = (int) $r['status'] === 1 ? '已使用' : '未使用';
                if ($r['expires_at'] && strtotime($r['expires_at']) < time()) $r['status_text'] = '已过期';
                $r['paused'] = isset($r['paused']) ? (int) $r['paused'] : 0;
                $r['card_type_text'] = isset($r['card_type']) ? cardTypeName($r['card_type']) : (empty($r['expires_at']) ? '永久' : '自定义');
                $r['bound_at'] = $r['bound_at'] ?: null;
                $r['expires_at'] = $r['expires_at'] ?: null;
                $r['login_ips'] = [];
                if (!empty($r['user_id'])) {
                    $logStmt->execute([$r['user_id']]);
                    $seen = [];
                    while ($row = $logStmt->fetch()) {
                        $ip = $row['ip'];
                        if (!isset($seen[$ip])) { $seen[$ip] = true; $r['login_ips'][] = $ip; }
                    }
                }
                $r['creator_username'] = $r['creator_username'] ?? '-';
            }
            apiJsonResp(0, 'ok', ['list' => $list, 'total' => $total, 'page' => $page, 'page_size' => $page_size]);
            break;
        case 'deduct_expire':
            $card_id = isset($input['id']) ? (int) $input['id'] : 0;
            $days = isset($input['days']) ? (int) $input['days'] : 0;
            if ($card_id <= 0) {
                apiJsonResp(400, '请提供卡密 ID');
                exit;
            }
            if ($days <= 0) {
                apiJsonResp(400, '扣除天数必须为正整数');
                exit;
            }
            $creatorCond = $isAgent ? ' AND creator_id = ' . (int) $adminId : '';
            $stmt = $pdo->prepare('SELECT id, card_code, expires_at, creator_id FROM card_keys WHERE id = ?' . $creatorCond . ' LIMIT 1');
            $stmt->execute([$card_id]);
            $row = $stmt->fetch();
            if (!$row) {
                apiJsonResp(404, '卡密不存在或无权操作');
                exit;
            }
            if (empty($row['expires_at'])) {
                apiJsonResp(400, '该卡密为永久卡，无法扣除时间');
                exit;
            }
            $baseTime = strtotime($row['expires_at']);
            if ($baseTime === false) {
                apiJsonResp(400, '到期时间格式异常');
                exit;
            }
            $newTime = $baseTime - $days * 86400;
            $newExpiresAt = date('Y-m-d H:i:s', $newTime);
            $upd = $pdo->prepare('UPDATE card_keys SET expires_at = ? WHERE id = ?');
            $upd->execute([$newExpiresAt, $card_id]);
            adminLog($pdo, '卡密扣除时间', '卡密ID=' . $card_id . ' 卡号=' . ($row['card_code'] ?? '') . ' 扣除天数=' . $days . ' 新到期=' . $newExpiresAt);
            apiJsonResp(0, '扣除成功', ['new_expires_at' => $newExpiresAt]);
            break;
        default:
            apiJsonResp(400, '无效的 action，支持: check | list | list_agent_cards | generate | update_remark | delete | pause | enable | deduct_expire');
    }
    break;

// ---------- 试用卡领取（每个设备 ID 7 天只能领取一次） ----------
case 'trial_card':
    if ($action !== 'claim') {
        apiJsonResp(400, '无效的 action，支持: claim');
        break;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        apiJsonResp(405, '请使用 POST');
        break;
    }
    $client_ip = getClientIp();
    if ($client_ip === '') {
        apiJsonResp(500, '无法获取客户端 IP');
        break;
    }
    $device_id = isset($input['device_id']) ? trim((string) $input['device_id']) : '';
    $hasDeviceId = ($device_id !== '');
    $device_name = isset($input['device_name']) ? trim((string) $input['device_name']) : '';
    try {
        // 记录试用卡领取记录：按设备 ID 限制，每个设备 7 天只能领取一次
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS trial_card_claims (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                device_id VARCHAR(128) DEFAULT NULL,
                ip VARCHAR(64) DEFAULT NULL,
                card_code VARCHAR(64) NOT NULL,
                device_name VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                unlocked TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否已人工解锁',
                UNIQUE KEY uniq_device (device_id),
                KEY idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        // 兼容旧表结构：若已存在表但缺少相应字段，则尝试添加
        try {
            $pdo->exec("ALTER TABLE trial_card_claims ADD COLUMN device_id VARCHAR(128) DEFAULT NULL");
        } catch (PDOException $e) {}
        try {
            $pdo->exec("ALTER TABLE trial_card_claims ADD COLUMN device_name VARCHAR(255) DEFAULT NULL");
        } catch (PDOException $e) {}
        try {
            $pdo->exec("ALTER TABLE trial_card_claims ADD COLUMN unlocked TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否已人工解锁'");
        } catch (PDOException $e) {}

        // 7 天领取限制：
        // - 提供 device_id 时：同时按设备 + IP 限制（任一命中即拒绝），设备支持后台解锁（unlocked 标记）。
        // - 未提供 device_id 时：按 IP 限制 7 天内只能领取一次（不支持后台解锁）。
        // 若旧表仍无相关字段，则跳过对应限制，避免报错。
        if ($hasDeviceId) {
            $canCheckLimit = true;
            $needRejectByDevice = false;
            try {
                $stmt = $pdo->prepare('SELECT created_at, unlocked FROM trial_card_claims WHERE device_id = ? LIMIT 1');
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), "Unknown column \'device_id\'") !== false) {
                    $canCheckLimit = false;
                } else {
                    throw $e;
                }
            }
            if ($canCheckLimit) {
                $stmt->execute([$device_id]);
                $row = $stmt->fetch();
                if ($row && !empty($row['created_at'])) {
                    $lastTs = strtotime($row['created_at']);
                    $unlockedFlag = isset($row['unlocked']) ? (int)$row['unlocked'] : 0;
                    if ($unlockedFlag === 0 && $lastTs !== false && (time() - $lastTs) < 7 * 24 * 3600) {
                        $needRejectByDevice = true;
                    }
                }
            }
            // 设备没被限制，再按 IP 检查一次（同一 IP 7 天内也只能领取一次）
            $canCheckLimitIp = true;
            $needRejectByIp = false;
            try {
                $stmtIp = $pdo->prepare('SELECT created_at FROM trial_card_claims WHERE ip = ? ORDER BY created_at DESC LIMIT 1');
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), "Unknown column 'ip'") !== false) {
                    $canCheckLimitIp = false;
                } else {
                    throw $e;
                }
            }
            if ($canCheckLimitIp) {
                $stmtIp->execute([$client_ip]);
                $rowIp = $stmtIp->fetch();
                if ($rowIp && !empty($rowIp['created_at'])) {
                    $lastTsIp = strtotime($rowIp['created_at']);
                    if ($lastTsIp !== false && (time() - $lastTsIp) < 7 * 24 * 3600) {
                        $needRejectByIp = true;
                    }
                }
            }
            if ($needRejectByDevice || $needRejectByIp) {
                apiJsonResp(403, $needRejectByDevice ? '该设备最近 7 天已领取过试用卡，请稍后再试' : '该 IP 最近 7 天已领取过试用卡，请稍后再试');
                break;
            }
        } else {
            $canCheckLimitIp = true;
            try {
                $stmtIp = $pdo->prepare('SELECT created_at FROM trial_card_claims WHERE device_id IS NULL AND ip = ? ORDER BY created_at DESC LIMIT 1');
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), "Unknown column \'ip\'") !== false) {
                    $canCheckLimitIp = false;
                } else {
                    throw $e;
                }
            }
            if ($canCheckLimitIp) {
                $stmtIp->execute([$client_ip]);
                $rowIp = $stmtIp->fetch();
                if ($rowIp && !empty($rowIp['created_at'])) {
                    $lastTsIp = strtotime($rowIp['created_at']);
                    if ($lastTsIp !== false && (time() - $lastTsIp) < 7 * 24 * 3600) {
                        apiJsonResp(403, '该 IP 最近 7 天已领取过试用卡，请稍后再试');
                        break;
                    }
                }
            }
        }
        // 生成一张试用卡（card_type=trial），等待用户注册或激活时绑定，绑定后有效期 5 小时
        $len = 16;
        $prefix = 'SY';
        $randomLen = $len - strlen($prefix);
        if ($randomLen < 1) $randomLen = 1;
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        $insertStmt = $pdo->prepare('INSERT INTO card_keys (card_code, expires_at, card_type, remark) VALUES (?, NULL, ?, ?)');
        while (true) {
            $code = $prefix;
            for ($i = 0; $i < $randomLen; $i++) {
                $code .= $chars[random_int(0, strlen($chars) - 1)];
            }
            try {
                $insertStmt->execute([$code, 'trial', '试用卡（5 小时）']);
                break;
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate') !== false) {
                    // 卡号重复则重新生成
                    continue;
                }
                throw $e;
            }
        }
        // 记录领取记录。
        // 有 device_id 时：按设备维度唯一，解锁后再次领取会覆盖记录，并将 unlocked 重置为 0。
        // 没有 device_id 时：仅按 IP + 时间记录，不做 7 天限制。
        try {
            if ($hasDeviceId) {
                $stmt = $pdo->prepare('INSERT INTO trial_card_claims (device_id, ip, card_code, device_name, created_at, unlocked) VALUES (?, ?, ?, ?, NOW(), 0) ON DUPLICATE KEY UPDATE card_code = VALUES(card_code), device_name = VALUES(device_name), created_at = VALUES(created_at), unlocked = 0');
                $stmt->execute([$device_id, $client_ip, $code, $device_name ?: null]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO trial_card_claims (device_id, ip, card_code, device_name, created_at, unlocked) VALUES (NULL, ?, ?, ?, NOW(), 0)');
                $stmt->execute([$client_ip, $code, $device_name ?: null]);
            }
        } catch (PDOException $e) {
            // 兼容极旧表结构，放弃记录日志但不影响领取
        }
        apiJsonResp(0, '领取成功，试用卡有效期为 5 小时（自绑定起算）', ['card_code' => $code, 'hours' => 5]);
    } catch (PDOException $e) {
        apiJsonResp(500, '领取失败: ' . $e->getMessage());
    } catch (Throwable $e) {
        apiJsonResp(500, '领取失败: ' . $e->getMessage());
    }
    break;

// ---------- 后台试用卡领取记录 ----------
case 'trial_card_admin':
    requireLogin();
    // 支持 list / unlock 两种 action
    $subAction = $action !== '' ? $action : (isset($input['action']) ? trim((string) $input['action']) : 'list');

    // 解锁：标记对应设备的领取记录为已解锁（unlocked=1），使其可再次领取试用卡
    if ($subAction === 'unlock') {
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS trial_card_claims (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    device_id VARCHAR(128) NOT NULL,
                    ip VARCHAR(64) DEFAULT NULL,
                    card_code VARCHAR(64) NOT NULL,
                    device_name VARCHAR(255) DEFAULT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    unlocked TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否已人工解锁',
                    UNIQUE KEY uniq_device (device_id),
                    KEY idx_created_at (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (PDOException $e) {}
        // 确保 unlocked 字段存在
        try {
            $pdo->exec("ALTER TABLE trial_card_claims ADD COLUMN unlocked TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否已人工解锁'");
        } catch (PDOException $e) {}

        $id = isset($input['id']) ? (int) $input['id'] : 0;
        $deviceId = isset($input['device_id']) ? trim((string) $input['device_id']) : '';
        if ($id <= 0 && $deviceId === '') {
            apiJsonResp(400, '请提供记录ID或设备ID');
            break;
        }
        try {
            if ($deviceId === '') {
                $stmt = $pdo->prepare('SELECT device_id, card_code FROM trial_card_claims WHERE id = ? LIMIT 1');
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                if (!$row || empty($row['device_id'])) {
                    apiJsonResp(404, '试用卡记录不存在');
                    break;
                }
                $deviceId = (string) $row['device_id'];
                $cardCodeForLog = isset($row['card_code']) ? (string) $row['card_code'] : '';
            } else {
                $stmt = $pdo->prepare('SELECT card_code FROM trial_card_claims WHERE device_id = ? LIMIT 1');
                $stmt->execute([$deviceId]);
                $row = $stmt->fetch();
                $cardCodeForLog = $row && isset($row['card_code']) ? (string) $row['card_code'] : '';
            }
            $affected = 0;
            try {
                // 优先按 device_id 标记为已解锁
                $upd = $pdo->prepare('UPDATE trial_card_claims SET unlocked = 1 WHERE device_id = ?');
                $upd->execute([$deviceId]);
                $affected = (int)$upd->rowCount();
            } catch (PDOException $e) {
                // 兼容极旧结构：若不存在 device_id / unlocked 字段，则退回按 ID 或卡密更新
                $msg = $e->getMessage();
                if (strpos($msg, "Unknown column 'device_id'") !== false || strpos($msg, "Unknown column 'unlocked'") !== false) {
                    if ($id > 0) {
                        $upd = $pdo->prepare('UPDATE trial_card_claims SET created_at = created_at WHERE id = ?');
                        $upd->execute([$id]);
                        $affected = (int)$upd->rowCount();
                    } elseif (!empty($cardCodeForLog)) {
                        $upd = $pdo->prepare('UPDATE trial_card_claims SET created_at = created_at WHERE card_code = ?');
                        $upd->execute([$cardCodeForLog]);
                        $affected = (int)$upd->rowCount();
                    }
                } else {
                    throw $e;
                }
            }
            if ($affected <= 0) {
                apiJsonResp(404, '试用卡记录不存在或已删除');
                break;
            }
            try {
                $detail = 'device_id=' . $deviceId;
                if (!empty($cardCodeForLog)) {
                    $detail .= ' card_code=' . $cardCodeForLog;
                }
                adminLog($pdo, '解锁试用卡领取限制', $detail);
            } catch (Exception $e) {}
            apiJsonResp(0, '已解锁，该设备可再次领取试用卡');
        } catch (PDOException $e) {
            apiJsonResp(500, '解锁失败: ' . $e->getMessage());
        }
        break;
    }

    if ($subAction !== 'list') {
        apiJsonResp(400, '无效的 action，支持: list | unlock');
        break;
    }
    try {
        // 确保表存在（与 trial_card 接口保持一致结构）
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS trial_card_claims (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                device_id VARCHAR(128) NOT NULL,
                ip VARCHAR(64) DEFAULT NULL,
                card_code VARCHAR(64) NOT NULL,
                device_name VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_device (device_id),
                KEY idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (PDOException $e) {}
    $page = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : (isset($input['page']) ? $input['page'] : 1)));
    $page_size = max(1, min(100, (int) (isset($_GET['page_size']) ? $_GET['page_size'] : (isset($input['page_size']) ? $input['page_size'] : 20))));
    $keyword = isset($_GET['keyword']) ? trim((string) $_GET['keyword']) : (isset($input['keyword']) ? trim((string) $input['keyword']) : '');
    try {
        $where = ['1=1'];
        $params = [];
        if ($keyword !== '') {
            $where[] = '(tc.card_code LIKE ? OR tc.device_id LIKE ? OR tc.device_name LIKE ? OR u.username LIKE ?)';
            $kw = '%' . $keyword . '%';
            $params[] = $kw;
            $params[] = $kw;
            $params[] = $kw;
            $params[] = $kw;
        }
        $whereSql = implode(' AND ', $where);

        try {
            // 原始查询：联表 card_keys / users，获取卡密状态与绑定用户名
            $countSql = "SELECT COUNT(*) FROM trial_card_claims tc
                LEFT JOIN card_keys c ON c.card_code = tc.card_code
                LEFT JOIN users u ON u.id = c.user_id
                WHERE $whereSql";
            $stmt = $pdo->prepare($countSql);
            $stmt->execute($params);
            $total = (int) $stmt->fetchColumn();
            $offset = ($page - 1) * $page_size;
            $dataSql = "SELECT
                    tc.id,
                    tc.card_code,
                    tc.device_id,
                    tc.device_name,
                    tc.ip,
                    tc.created_at,
                    c.status AS card_status,
                    c.card_type,
                    c.expires_at AS card_expires_at,
                    c.user_id AS card_user_id,
                    u.username AS card_username
                FROM trial_card_claims tc
                LEFT JOIN card_keys c ON c.card_code = tc.card_code
                LEFT JOIN users u ON u.id = c.user_id
                WHERE $whereSql
                ORDER BY tc.id DESC
                LIMIT " . (int) $page_size . " OFFSET " . (int) $offset;
            $stmt = $pdo->prepare($dataSql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            // 情况1：旧表结构没有 device_id / device_name 字段
            if (strpos($msg, "Unknown column 'tc.device_id'") !== false
                || strpos($msg, "Unknown column 'tc.device_name'") !== false
                || strpos($msg, "Unknown column 'device_id'") !== false
            ) {
                // 只按 id / card_code / ip / created_at 返回，避免引用不存在的列
                $simpleWhere = ['1=1'];
                $simpleParams = [];
                if ($keyword !== '') {
                    $simpleWhere[] = '(tc.card_code LIKE ?)';
                    $kw2 = '%' . $keyword . '%';
                    $simpleParams[] = $kw2;
                }
                $simpleWhereSql = implode(' AND ', $simpleWhere);
                $countSql = "SELECT COUNT(*) FROM trial_card_claims tc WHERE $simpleWhereSql";
                $stmt = $pdo->prepare($countSql);
                $stmt->execute($simpleParams);
                $total = (int) $stmt->fetchColumn();
                $offset = ($page - 1) * $page_size;
                $dataSql = "SELECT
                        tc.id,
                        tc.card_code,
                        tc.ip,
                        tc.created_at
                    FROM trial_card_claims tc
                    WHERE $simpleWhereSql
                    ORDER BY tc.id DESC
                    LIMIT " . (int) $page_size . " OFFSET " . (int) $offset;
                $stmt = $pdo->prepare($dataSql);
                $stmt->execute($simpleParams);
                $rows = $stmt->fetchAll();
            }
            // 情况2：不同表排序规则不一致导致 1267 错误，退回单表查询
            elseif (strpos($msg, 'Illegal mix of collations') !== false) {
                $simpleWhere = ['1=1'];
                $simpleParams = [];
                if ($keyword !== '') {
                    $simpleWhere[] = '(tc.card_code LIKE ?)';
                    $kw2 = '%' . $keyword . '%';
                    $simpleParams[] = $kw2;
                }
                $simpleWhereSql = implode(' AND ', $simpleWhere);
                $countSql = "SELECT COUNT(*) FROM trial_card_claims tc WHERE $simpleWhereSql";
                $stmt = $pdo->prepare($countSql);
                $stmt->execute($simpleParams);
                $total = (int) $stmt->fetchColumn();
                $offset = ($page - 1) * $page_size;
                $dataSql = "SELECT
                        tc.id,
                        tc.card_code,
                        tc.device_id,
                        tc.device_name,
                        tc.ip,
                        tc.created_at
                    FROM trial_card_claims tc
                    WHERE $simpleWhereSql
                    ORDER BY tc.id DESC
                    LIMIT " . (int) $page_size . " OFFSET " . (int) $offset;
                $stmt = $pdo->prepare($dataSql);
                $stmt->execute($simpleParams);
                $rows = $stmt->fetchAll();
            } else {
                throw $e;
            }
            // 后面构造列表时，card_status / username 等字段保持为空或默认文案
        }

        $list = [];
        $nowTs = time();
        foreach ($rows as $r) {
            $statusText = '未生成/已删除';
            if (array_key_exists('card_status', $r)) {
                if ($r['card_status'] !== null) {
                    $status = (int) $r['card_status'];
                    $exp = $r['card_expires_at'];
                    if ($exp !== null && $exp !== '' && strtotime($exp) < $nowTs) {
                        $statusText = '已过期';
                    } elseif ($status === 1) {
                        $statusText = '已使用';
                    } else {
                        $statusText = '未使用';
                    }
                }
            }
            $list[] = [
                'id' => (int) $r['id'],
                'card_code' => $r['card_code'] ?? null,
                'device_id' => $r['device_id'] ?? null,
                'device_name' => $r['device_name'] ?? null,
                'ip' => $r['ip'] ?? null,
                'created_at' => $r['created_at'] ?? null,
                'card_status_text' => $statusText,
                'card_expires_at' => $r['card_expires_at'] ?? null,
                'card_username' => $r['card_username'] ?? null,
            ];
        }
        apiJsonResp(0, 'ok', [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'page_size' => $page_size,
        ]);
    } catch (PDOException $e) {
        apiJsonResp(500, '试用卡记录加载失败: ' . $e->getMessage());
    } catch (Throwable $e) {
        apiJsonResp(500, '试用卡记录加载失败: ' . $e->getMessage());
    }
    break;

// ---------- 后台用户管理 ----------
case 'admin_users':
    requireLogin();
    ensureFrontendUserTables($pdo);
    $adminId = (int) ($_SESSION['admin_user_id'] ?? 0);
    $isAgent = (isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'agent');
    if ($action === 'list') {
        try {
            $keyword = isset($_GET['keyword']) ? trim((string) $_GET['keyword']) : (isset($input['keyword']) ? trim((string) $input['keyword']) : '');
            $page = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : (isset($input['page']) ? $input['page'] : 1)));
            $page_size = max(1, min(100, (int) (isset($_GET['page_size']) ? $_GET['page_size'] : (isset($input['page_size']) ? $input['page_size'] : 20))));
            $where = ['1=1'];
            $params = [];
            // 代理只能查看「使用自己生成的卡密」的用户
            if ($isAgent) {
                $where[] = 'c.creator_id = ?';
                $params[] = $adminId;
            }
            if ($keyword !== '') {
                $where[] = 'u.username LIKE ?';
                $params[] = '%' . $keyword . '%';
            }
            $whereSql = implode(' AND ', $where);
            $fromUsers = 'users u LEFT JOIN card_keys c ON c.id = u.card_key_id';
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM $fromUsers WHERE $whereSql");
            $stmt->execute($params);
            $total = (int) $stmt->fetchColumn();
            $offset = ($page - 1) * $page_size;
            $registerServerSelect = dbHasColumn($pdo, 'users', 'register_server') ? 'u.register_server' : 'NULL AS register_server';
            $sql = "SELECT u.id, u.username, u.card_key_id, u.created_at, $registerServerSelect, c.card_code, c.expires_at, c.paused AS card_paused, c.status AS card_status FROM $fromUsers WHERE $whereSql ORDER BY u.id DESC LIMIT " . (int) $page_size . " OFFSET " . (int) $offset;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $list = $stmt->fetchAll();
            $logStmt = $pdo->prepare('SELECT ip, login_at FROM user_login_log WHERE user_id = ? ORDER BY login_at DESC');
            // 在线房间信息：根据 IP 关联到 user_room_online
            try {
                $roomStmt = $pdo->prepare('SELECT room FROM user_room_online WHERE ip = ? ORDER BY updated_at DESC LIMIT 1');
            } catch (PDOException $e) {
                $roomStmt = null;
            }

            foreach ($list as &$r) {
                $r['login_ips'] = [];
                $r['last_login_at'] = null;
                $r['last_login_ip'] = null;
                $r['online'] = false;
                $r['room'] = null;
                $logStmt->execute([$r['id']]);
                $seen = [];
                $isFirst = true;
                while ($row = $logStmt->fetch()) {
                    $ip = $row['ip'];
                    if ($isFirst && !empty($row['login_at'])) {
                        $r['last_login_at'] = $row['login_at'];
                        $r['last_login_ip'] = $ip;
                        $isFirst = false;
                    }
                    if (!isset($seen[$ip])) { $seen[$ip] = true; $r['login_ips'][] = $ip; }
                }
                // 最近登录用户尝试关联房间信息，不再依赖端口日志服务。
                if (!empty($r['last_login_at']) && !empty($r['last_login_ip'])) {
                    $ts = strtotime($r['last_login_at']);
                    if ($ts !== false && (time() - $ts) <= 3600) { // 最近 1 小时有登录
                        $ip = $r['last_login_ip'];
                        if ($ip && $roomStmt) {
                            try {
                                $roomStmt->execute([$ip]);
                                $roomRow = $roomStmt->fetch();
                                if ($roomRow && isset($roomRow['room'])) {
                                    $r['online'] = true;
                                    $r['room'] = $roomRow['room'];
                                }
                            } catch (PDOException $e) {
                                // 忽略房间查询错误，不影响主流程
                            }
                        }
                    }
                }
                $r['card_valid'] = true;
                if (empty($r['card_key_id'])) {
                    $r['card_code'] = '-';
                    $r['user_status'] = '未绑定';
                } else {
                    if (empty($r['card_code'])) {
                        $r['card_code'] = '(已删除)';
                        $r['card_valid'] = false;
                        $r['user_status'] = '已过期';
                    } elseif ($r['expires_at'] && strtotime($r['expires_at']) < time()) {
                        $r['card_valid'] = false;
                        $r['user_status'] = '已过期';
                    } else {
                        $r['user_status'] = !empty($r['card_paused']) ? '已暂停' : '正常';
                    }
                }
            }
            apiJsonResp(0, 'ok', ['list' => $list, 'total' => $total, 'page' => $page, 'page_size' => $page_size]);
        } catch (PDOException $e) {
            apiJsonResp(500, '用户列表加载失败: ' . $e->getMessage());
        } catch (Throwable $e) {
            apiJsonResp(500, '用户列表加载失败: ' . $e->getMessage());
        }
        exit;
    } elseif ($action === 'delete') {
        $id = isset($input['id']) ? (int) $input['id'] : 0;
        if ($id <= 0) {
            apiJsonResp(400, '请提供用户 ID');
            exit;
        }
        try {
            if ($isAgent) {
                $chk = $pdo->prepare('SELECT u.id FROM users u INNER JOIN card_keys c ON c.id = u.card_key_id WHERE u.id = ? AND c.creator_id = ? LIMIT 1');
                $chk->execute([$id, $adminId]);
                if (!$chk->fetch()) {
                    apiJsonResp(403, '只能删除使用自己卡密激活的用户');
                    exit;
                }
            }
            $pdo->prepare('DELETE FROM user_login_log WHERE user_id = ?')->execute([$id]);
            $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
            $stmt->execute([$id]);
            if ($stmt->rowCount() > 0) {
                adminLog($pdo, '删除用户', 'id=' . $id);
                apiJsonResp(0, '删除成功');
            } else {
                apiJsonResp(404, '用户不存在');
            }
        } catch (PDOException $e) {
            apiJsonResp(500, '删除失败: ' . $e->getMessage());
        }
        exit;
    } elseif ($action === 'extend_expire') {
        $user_id = isset($input['user_id']) ? (int) $input['user_id'] : 0;
        $days = isset($input['days']) ? (int) $input['days'] : 0;
        if ($user_id <= 0) {
            apiJsonResp(400, '请提供用户 ID');
            exit;
        }
        if ($days <= 0) {
            apiJsonResp(400, '加时天数必须为正整数');
            exit;
        }
        try {
            // 查询用户及其绑定的卡密（代理只能给「自己卡密」的用户加时）
            $stmt = $pdo->prepare('SELECT u.username, u.card_key_id, c.expires_at, c.creator_id FROM users u LEFT JOIN card_keys c ON c.id = u.card_key_id WHERE u.id = ? LIMIT 1');
            $stmt->execute([$user_id]);
            $row = $stmt->fetch();
            if (!$row) {
                apiJsonResp(404, '用户不存在');
                exit;
            }
            if (empty($row['card_key_id'])) {
                apiJsonResp(400, '该用户尚未绑定卡密，无法加时');
                exit;
            }
            if ($isAgent && (int) $row['creator_id'] !== $adminId) {
                apiJsonResp(403, '只能对使用自己卡密激活的用户加时');
                exit;
            }
            $baseTime = null;
            if (!empty($row['expires_at']) && strtotime($row['expires_at']) !== false) {
                $baseTime = strtotime($row['expires_at']);
            }
            $now = time();
            if ($baseTime === null || $baseTime < $now) {
                $baseTime = $now;
            }
            $newExpiresAt = date('Y-m-d H:i:s', $baseTime + $days * 86400);
            $upd = $pdo->prepare('UPDATE card_keys SET expires_at = ? WHERE id = ?');
            $upd->execute([$newExpiresAt, (int) $row['card_key_id']]);
            if ($upd->rowCount() <= 0) {
                // 如果受影响行数为 0，有两种可能：
                // 1）记录不存在  2）新旧到期时间相同
                // 这里再检查一次记录是否存在，不存在才视为错误，存在则视为加时成功（只是时间未变化）
                $chk = $pdo->prepare('SELECT id FROM card_keys WHERE id = ? LIMIT 1');
                $chk->execute([(int) $row['card_key_id']]);
                if (!$chk->fetch()) {
                    apiJsonResp(404, '卡密不存在或已删除');
                    exit;
                }
                // 记录存在但未修改，继续按成功处理
            }
            // 记录操作日志：使用用户名 + 加多少天
            $logUsername = isset($row['username']) ? (string)$row['username'] : ('ID=' . $user_id);
            adminLog($pdo, '用户加时', '用户名=' . $logUsername . ' 天数=' . $days);
            apiJsonResp(0, '加时成功', ['new_expires_at' => $newExpiresAt]);
        } catch (PDOException $e) {
            apiJsonResp(500, '加时失败: ' . $e->getMessage());
        }
        exit;
    } elseif ($action === 'batch_extend_expire') {
        if ($isAgent) { apiJsonResp(403, '代理无权执行批量加时'); exit; }
        $days = isset($input['days']) ? (int) $input['days'] : 0;
        $userIds = isset($input['user_ids']) ? $input['user_ids'] : null;
        $selectAll = !empty($input['select_all']);
        if ($days <= 0) { apiJsonResp(400, '加时天数必须为正整数'); exit; }
        if (!$selectAll && (empty($userIds) || !is_array($userIds))) { apiJsonResp(400, '请选择用户或勾选全选'); exit; }
        try {
            $now = date('Y-m-d H:i:s');
            $nowTs = time();
            if ($selectAll) {
                $stmt = $pdo->query('SELECT u.id AS user_id, u.username, u.card_key_id, c.expires_at FROM users u JOIN card_keys c ON c.id = u.card_key_id WHERE u.card_key_id IS NOT NULL AND u.card_key_id > 0');
            } else {
                $placeholders = implode(',', array_fill(0, count($userIds), '?'));
                $stmt = $pdo->prepare('SELECT u.id AS user_id, u.username, u.card_key_id, c.expires_at FROM users u JOIN card_keys c ON c.id = u.card_key_id WHERE u.id IN (' . $placeholders . ') AND u.card_key_id IS NOT NULL AND u.card_key_id > 0');
                $stmt->execute(array_map('intval', $userIds));
            }
            $rows = $stmt->fetchAll();
            if (empty($rows)) { apiJsonResp(400, '未找到可加时的用户（需已绑定卡密）'); exit; }
            $updStmt = $pdo->prepare('UPDATE card_keys SET expires_at = ? WHERE id = ?');
            $affected = 0;
            $updatedCardIds = [];
            foreach ($rows as $r) {
                $cardId = (int) $r['card_key_id'];
                if (isset($updatedCardIds[$cardId])) continue;
                $baseTime = null;
                if (!empty($r['expires_at']) && strtotime($r['expires_at']) !== false) {
                    $baseTime = strtotime($r['expires_at']);
                }
                if ($baseTime === null || $baseTime < $nowTs) {
                    $baseTime = $nowTs;
                }
                $newExp = date('Y-m-d H:i:s', $baseTime + $days * 86400);
                $updStmt->execute([$newExp, $cardId]);
                $updatedCardIds[$cardId] = true;
                $affected++;
            }
            adminLog($pdo, '批量加时', ($selectAll ? '全部用户' : count($userIds) . '个用户') . ' 天数=' . $days . ' 实际更新卡密=' . $affected);
            apiJsonResp(0, '批量加时成功', ['affected' => $affected, 'days' => $days]);
        } catch (PDOException $e) {
            apiJsonResp(500, '批量加时失败: ' . $e->getMessage());
        }
        exit;
    } else {
        apiJsonResp(400, '无效的 action，支持: list | delete | extend_expire | batch_extend_expire');
        exit;
    }
    break;

// ---------- 地域/IP 段分布统计（后台首页用） ----------
case 'region_stats':
    requireLogin();
    try {
        // 取最近 500 条登录记录的 IP，用于统计分布
        $limit = 500;
        $stmt = $pdo->prepare('SELECT ip FROM user_login_log WHERE ip IS NOT NULL AND TRIM(ip) != "" ORDER BY login_at DESC LIMIT ' . (int) $limit);
        $stmt->execute();
        $buckets = [];
        while ($row = $stmt->fetch()) {
            $ip = trim((string) ($row['ip'] ?? ''));
            if ($ip === '') continue;
            $label = $ip;
            // 内网 IP 单独归为「内网/局域网」
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                $label = '内网/局域网';
            } elseif (preg_match('/^(\d+\.\d+)\./', $ip, $m)) {
                // 公网 IP 按前两段聚合：如 123.45.x
                $label = $m[1] . '.x';
            }
            if (!isset($buckets[$label])) {
                $buckets[$label] = [
                    'count' => 0,
                    'sample_ip' => $ip,
                ];
            }
            $buckets[$label]['count']++;
        }
        // 根据数量倒序排序
        uasort($buckets, function ($a, $b) {
            return ($b['count'] ?? 0) <=> ($a['count'] ?? 0);
        });

        // 简单的 IP -> 中文省份解析（非常粗略），后续可接入专业 IP 库
        $regionCache = [];
        $resolveIpRegion = function ($ip) use (&$regionCache) {
            $ip = trim((string) $ip);
            if ($ip === '') return '';
            if (isset($regionCache[$ip])) return $regionCache[$ip];
            $label = '';
            // 常见内网/保留网段
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                $label = '内网/局域网';
            } else {
                // 这里只做一个非常粗略的映射：按首段，将 IP 归到「代表性省份」，并不完全准确
                if (preg_match('/^(\d+)\./', $ip, $m)) {
                    $first = (int) $m[1];
                    // 一些常见公网首段，粗略映射到代表性省份
                    if (in_array($first, [58, 59, 60, 61, 101, 103, 110, 111, 112, 113, 114, 115, 116, 117, 118, 119, 120], true)) {
                        // 沿海/华东运营商，示意映射到「江苏省」
                        $label = '江苏省';
                    } elseif (in_array($first, [121, 122, 123, 124, 125, 126, 36, 39], true)) {
                        // 东北/华北一带，示意映射到「辽宁省」
                        $label = '辽宁省';
                    } elseif (in_array($first, [14, 27, 42, 43], true)) {
                        // 华中一带，示意映射到「湖北省」
                        $label = '湖北省';
                    } elseif (in_array($first, [106, 183, 223], true)) {
                        // 西南/西北一带，示意映射到「四川省」
                        $label = '四川省';
                    }
                }
            }
            if ($label === '') {
                $label = '其他省份';
            }
            $regionCache[$ip] = $label;
            return $label;
        };

        // 先按省份再次汇总，确保「同省只出现一次」
        $provinceBuckets = [];
        foreach ($buckets as $label => $info) {
            $cnt = isset($info['count']) ? (int) $info['count'] : 0;
            $sampleIp = isset($info['sample_ip']) ? $info['sample_ip'] : '';
            $province = $resolveIpRegion($sampleIp);
            if ($province === '') {
                $province = '其他省份';
            }
            if (!isset($provinceBuckets[$province])) {
                $provinceBuckets[$province] = [
                    'count' => 0,
                ];
            }
            $provinceBuckets[$province]['count'] += $cnt;
        }
        // 按省份总数倒序排序
        uasort($provinceBuckets, function ($a, $b) {
            return ($b['count'] ?? 0) <=> ($a['count'] ?? 0);
        });

        $regions = [];
        foreach ($provinceBuckets as $province => $info) {
            $regions[] = [
                'label' => $province,
                'label_cn' => $province,
                'count' => isset($info['count']) ? (int) $info['count'] : 0,
            ];
            if (count($regions) >= 10) break;
        }
        apiJsonResp(0, 'ok', ['regions' => $regions]);
    } catch (PDOException $e) {
        apiJsonResp(500, '地域统计失败: ' . $e->getMessage());
    }
    break;

// ---------- 清理过期用户/卡密（后台使用：仅总管理可操作） ----------
case 'cleanup_expired':
    requireLogin();
    if (isAgent()) {
        apiJsonResp(403, '仅总管理可清理');
        exit;
    }
    $action = isset($input['action']) ? trim((string) $input['action']) : (isset($_GET['action']) ? trim((string) $_GET['action']) : '');
    $limit = isset($input['limit']) ? (int) $input['limit'] : (isset($_GET['limit']) ? (int) $_GET['limit'] : 2000);
    $dryRun = true;
    if ($action === 'run') $dryRun = false;
    if ($action === 'preview') $dryRun = true;
    $stats = runExpiredUsersCardsCleanup($pdo, [
        'limit' => $limit,
        'dry_run' => $dryRun,
    ]);
    if (!$dryRun) {
        $wlCleaned = cleanExpiredWhitelist($pdo);
        $stats['whitelist_cleaned'] = $wlCleaned;
        adminLog($pdo, '清理过期数据', '删除过期卡密=' . ($stats['expired_cards_deleted'] ?? 0) . ' 删除用户=' . ($stats['users_deleted'] ?? 0) . ' 白名单清理=' . $wlCleaned);
    }
    apiJsonResp(0, 'ok', $stats);
    break;

// ---------- 数据统计 ----------
case 'stats':
    requireLogin();
    $adminId = (int) ($_SESSION['admin_user_id'] ?? 0);
    $isAgent = (isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'agent');
    $cardWhere = $isAgent ? ' WHERE creator_id = ' . $adminId : '';
    $cardWhereAnd = $isAgent ? ' AND creator_id = ' . $adminId : '';
    $now = date('Y-m-d H:i:s');
    $todayStart = date('Y-m-d 00:00:00');
    $weekStart = date('Y-m-d 00:00:00', strtotime('-6 days'));
    // 总用户：根据用户管理里「最大 ID」来显示，同时保留真实总数
    $total_users_real = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $max_user_id = (int) $pdo->query('SELECT MAX(id) FROM users')->fetchColumn();
    // 用最大 ID 作为展示用总用户数
    $total_users = $max_user_id;
    // 可见卡密总数 & 最大 ID
    $total_cards_real = (int) $pdo->query('SELECT COUNT(*) FROM card_keys' . $cardWhere)->fetchColumn();
    $max_card_id = (int) $pdo->query('SELECT MAX(id) FROM card_keys' . $cardWhere)->fetchColumn();
    // 展示用总卡密（按你的要求：卡密管理里最大的 ID）
    $total_cards = $max_card_id;
    $cards_unused = (int) $pdo->query('SELECT COUNT(*) FROM card_keys WHERE status = 0' . $cardWhereAnd)->fetchColumn();
    $cards_used = (int) $pdo->query('SELECT COUNT(*) FROM card_keys WHERE status = 1' . $cardWhereAnd)->fetchColumn();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM card_keys WHERE status = 1 AND expires_at IS NOT NULL AND expires_at < ?' . $cardWhereAnd);
    $stmt->execute([$now]);
    $cards_expired = (int) $stmt->fetchColumn();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE created_at >= ?');
    $stmt->execute([$todayStart]);
    $today_users = (int) $stmt->fetchColumn();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE created_at >= ?');
    $stmt->execute([$weekStart]);
    $week_users = (int) $stmt->fetchColumn();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM card_keys WHERE status = 1 AND bound_at >= ?' . $cardWhereAnd);
    $stmt->execute([$todayStart]);
    $today_activations = (int) $stmt->fetchColumn();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM card_keys WHERE status = 1 AND bound_at >= ?' . $cardWhereAnd);
    $stmt->execute([$weekStart]);
    $week_activations = (int) $stmt->fetchColumn();
    $onlineWeb = 0;
    $onlineApp = 0;
    $onlineTotal = 0;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS client_online (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                client VARCHAR(16) NOT NULL,
                device_id VARCHAR(128) NOT NULL,
                username VARCHAR(64) DEFAULT NULL,
                ip VARCHAR(64) DEFAULT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_client_device (client, device_id),
                KEY idx_client_updated (client, updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $onlineThreshold = date('Y-m-d H:i:s', time() - 90);
        $stmt = $pdo->prepare("SELECT client, COUNT(*) AS total FROM client_online WHERE updated_at >= ? GROUP BY client");
        $stmt->execute([$onlineThreshold]);
        foreach ($stmt->fetchAll() as $row) {
            if ($row['client'] === 'app') $onlineApp = (int) $row['total'];
            if ($row['client'] === 'web') $onlineWeb = (int) $row['total'];
        }
        $onlineTotal = $onlineWeb + $onlineApp;
    } catch (PDOException $e) {}

    // 已删除卡密数量：根据「当前可见卡密总数」和「可见卡密最大 ID」估算
    // 公式：deleted = max_id - total_count（最小为 0）
    $deleted_cards = 0;
    if ($max_card_id > 0 && $total_cards_real >= 0) {
        $deleted_cards = $max_card_id - $total_cards_real;
        if ($deleted_cards < 0) $deleted_cards = 0;
    }
    apiJsonResp(0, 'ok', [
        'total_users' => $total_users,
        'total_users_real' => $total_users_real,
        'total_cards' => $total_cards,
        'total_cards_real' => $total_cards_real,
        'cards_unused' => $cards_unused,
        'cards_used' => $cards_used,
        'cards_expired' => $cards_expired,
        'today_users' => $today_users,
        'week_users' => $week_users,
        'today_activations' => $today_activations,
        'week_activations' => $week_activations,
        'deleted_cards' => $deleted_cards,
        'online_total' => $onlineTotal,
        'online_web' => $onlineWeb,
        'online_app' => $onlineApp,
    ]);
    break;

// ---------- 黑名单（仅总管理） ----------
case 'blacklist':
    requireLogin();
    if (isAgent()) {
        apiJsonResp(403, '仅总管理可操作黑名单');
        exit;
    }
    $hasTable = false;
    try {
        $pdo->query('SELECT 1 FROM blacklist LIMIT 1');
        $hasTable = true;
    } catch (PDOException $e) {}
    if (!$hasTable) {
        if ($action === 'list') {
            apiJsonResp(0, 'ok', ['list' => []]);
        } else {
            apiJsonResp(500, '黑名单功能未就绪，请先执行 auth/upgrade_blacklist.sql');
        }
        exit;
    }
    if ($action === 'list') {
        $stmt = $pdo->query('SELECT id, type, value, created_at FROM blacklist ORDER BY type ASC, id DESC');
        apiJsonResp(0, 'ok', ['list' => $stmt->fetchAll()]);
        exit;
    }
    if ($action === 'add') {
        $type = isset($input['type']) ? trim((string) $input['type']) : '';
        $value = isset($input['value']) ? trim((string) $input['value']) : '';
        if (!in_array($type, ['ip', 'user'], true)) {
            apiJsonResp(400, '类型须为 ip 或 user');
            exit;
        }
        if ($value === '') {
            apiJsonResp(400, $type === 'ip' ? '请输入IP' : '请输入用户名');
            exit;
        }
        try {
            $stmt = $pdo->prepare('INSERT INTO blacklist (type, value) VALUES (?, ?)');
            $stmt->execute([$type, $value]);
            adminLog($pdo, '添加黑名单', $type . '=' . $value);
            
            // 如果是IP类型，同时调用封禁IP功能
            if ($type === 'ip') {
                $blockResult = blockIPViaPortLogger($value, '黑名单封禁');
                if ($blockResult['success']) {
                    apiJsonResp(0, '添加成功并已封禁IP', ['id' => (int) $pdo->lastInsertId(), 'blocked' => true]);
                } else {
                    // 黑名单已添加，但封禁可能失败（不影响黑名单功能）
                    apiJsonResp(0, '添加成功（封禁IP失败: ' . $blockResult['error'] . '）', ['id' => (int) $pdo->lastInsertId(), 'blocked' => false, 'block_error' => $blockResult['error']]);
                }
            } else {
                apiJsonResp(0, '添加成功', ['id' => (int) $pdo->lastInsertId()]);
            }
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate') !== false) {
                apiJsonResp(400, '已存在相同记录');
            } else {
                apiJsonResp(500, '添加失败: ' . $e->getMessage());
            }
        }
        exit;
    }
    if ($action === 'delete') {
        $id = isset($input['id']) ? (int) $input['id'] : 0;
        if ($id <= 0) {
            apiJsonResp(400, '请提供 id');
            exit;
        }
        try {
            // 先查询记录信息
            $stmt = $pdo->prepare('SELECT type, value FROM blacklist WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) {
                apiJsonResp(404, '记录不存在');
                exit;
            }
            
            // 删除黑名单记录
            $stmt = $pdo->prepare('DELETE FROM blacklist WHERE id = ?');
            $stmt->execute([$id]);
            adminLog($pdo, '删除黑名单', $row['type'] . '=' . $row['value']);
            
            // 如果是IP类型，同时尝试解封该IP
            if ($row['type'] === 'ip') {
                $unblockResult = unblockIPViaPortLogger($row['value']);
                if ($unblockResult['success']) {
                    apiJsonResp(0, '删除成功并已解封IP', ['unblocked' => true]);
                } else {
                    // 黑名单已删除，但解封可能失败（不影响黑名单功能）
                    apiJsonResp(0, '删除成功（解封IP失败: ' . $unblockResult['error'] . '）', ['unblocked' => false, 'unblock_error' => $unblockResult['error']]);
                }
            } else {
                apiJsonResp(0, '删除成功');
            }
        } catch (PDOException $e) {
            apiJsonResp(500, '删除失败: ' . $e->getMessage());
        }
        exit;
    }
    apiJsonResp(400, '无效的 action，支持: list | add | delete');
    break;

// ---------- 页面链接配置 ----------
case 'app_settings':
    ensureAppSettingsTable($pdo);
    if ($action === 'public' || $action === '') {
        apiJsonResp(0, 'ok', getAppLinkSettings($pdo));
        break;
    }
    requireLogin();
    if (isAgent()) {
        apiJsonResp(403, '仅总管理可修改页面链接');
        exit;
    }
    if ($action === 'get') {
        apiJsonResp(0, 'ok', getAppLinkSettings($pdo));
        break;
    }
    if ($action === 'save') {
        $allowed = ['trial_url', 'buy_card_url', 'download_url', 'group_url'];
        $stmt = $pdo->prepare('REPLACE INTO app_settings (setting_key, setting_value) VALUES (?, ?)');
        foreach ($allowed as $key) {
            $value = isset($input[$key]) ? trim((string) $input[$key]) : '';
            $value = normalizeExternalLinkUrl($value);
            if (mb_strlen($value) > 1000) $value = mb_substr($value, 0, 1000);
            $stmt->execute([$key, $value]);
        }
        adminLog($pdo, '保存页面链接配置', 'login/register links');
        apiJsonResp(0, '保存成功', getAppLinkSettings($pdo));
        break;
    }
    apiJsonResp(400, '无效的 action，支持 public | get | save');
    break;

// ---------- APP 远程更新 / 公告配置 ----------
case 'app_remote_config':
    ensureAppSettingsTable($pdo);
    if ($action === 'public' || $action === '') {
        apiJsonResp(0, 'ok', getAppRemoteConfig($pdo));
        break;
    }
    requireLogin();
    if (isAgent()) {
        apiJsonResp(403, '仅总管理可修改 APP 配置');
        exit;
    }
    if ($action === 'get') {
        apiJsonResp(0, 'ok', getAppRemoteConfig($pdo, true));
        break;
    }
    if ($action === 'save') {
        $allowed = [
            'version_code', 'version_name', 'apk_url', 'apk_url_github', 'apk_url_gitee', 'update_title', 'update_message', 'force_update',
            'popup_enabled', 'popup_title', 'popup_message', 'popup_url', 'buy_card_url', 'group_url',
            'app_login_required', 'app_login_enabled', 'app_login_username', 'app_login_password', 'app_login_title', 'app_login_message'
        ];
        $stmt = $pdo->prepare('REPLACE INTO app_settings (setting_key, setting_value) VALUES (?, ?)');
        foreach ($allowed as $key) {
            $value = isset($input[$key]) ? trim((string) $input[$key]) : '';
            if ($key === 'version_code') $value = (string) max(1, (int) $value);
            if ($key === 'force_update' || $key === 'popup_enabled' || $key === 'app_login_required') $value = ((int) $value) ? '1' : '0';
            if (in_array($key, ['apk_url', 'apk_url_github', 'apk_url_gitee', 'popup_url', 'buy_card_url', 'group_url'], true)) $value = normalizeExternalLinkUrl($value);
            if (mb_strlen($value) > 2000) $value = mb_substr($value, 0, 2000);
            $stmt->execute([$key, $value]);
        }
        adminLog($pdo, '保存 APP 远程配置', 'app remote config');
        apiJsonResp(0, '保存成功', getAppRemoteConfig($pdo));
        break;
    }
    apiJsonResp(400, '无效的 action，支持 public | get | save');
    break;

// ---------- 游戏服务器配置 ----------
case 'game_servers':
    ensureGameServersTable($pdo);
    if ($action === 'public' || $action === 'list_public' || $action === '') {
        $publicOnly = isset($_GET['public_account']) && (int) $_GET['public_account'] === 1;
        $where = $publicOnly ? 'enabled = 1 AND public_account_visible = 1' : 'enabled = 1';
        $stmt = $pdo->query('SELECT id, name, host, port, last_check_status, last_check_at FROM game_servers WHERE ' . $where . ' ORDER BY sort_order ASC, id ASC');
        apiJsonResp(0, 'ok', ['list' => $stmt->fetchAll()]);
        break;
    }
    if ($action === 'app_report') {
        $host = normalizeGameServerHost($input['host'] ?? '');
        $port = isset($input['port']) ? (int) $input['port'] : 8888;
        $username = trim((string) ($input['username'] ?? ''));
        if ($host === '') {
            apiJsonResp(400, '请输入服务器 IP 或域名');
            exit;
        }
        if ($port <= 0 || $port > 65535) {
            apiJsonResp(400, '端口范围不正确');
            exit;
        }
        $result = testGameServerTcp($host, $port);
        if ($result['status'] !== 'online') {
            apiJsonResp(400, '服务器不通，未添加', ['result' => $result]);
            exit;
        }
        $reportName = 'APP上传' . ($username !== '' ? '-' . $username : '');
        if (mb_strlen($reportName) > 64) $reportName = mb_substr($reportName, 0, 64);
        try {
            $stmt = $pdo->prepare("INSERT INTO game_servers (name, host, port, enabled, sort_order, last_check_status, last_check_at, last_check_ms, last_check_error, source, reported_username) VALUES (?, ?, ?, 1, 0, ?, NOW(), ?, ?, 'app', ?)");
            $stmt->execute([$reportName, $host, $port, $result['status'], $result['ms'], $result['error'], $username === '' ? null : mb_substr($username, 0, 64)]);
            apiJsonResp(0, '服务器已上传后台', ['id' => (int) $pdo->lastInsertId(), 'result' => $result]);
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate') !== false) {
                $stmt = $pdo->prepare('SELECT id, source FROM game_servers WHERE host = ? AND port = ? LIMIT 1');
                $stmt->execute([$host, $port]);
                $row = $stmt->fetch();
                if ($row && $row['source'] === 'app') {
                    $upd = $pdo->prepare('UPDATE game_servers SET name = ?, reported_username = ?, last_check_status = ?, last_check_at = NOW(), last_check_ms = ?, last_check_error = ?, enabled = 1 WHERE id = ?');
                    $upd->execute([$reportName, $username === '' ? null : mb_substr($username, 0, 64), $result['status'], $result['ms'], $result['error'], (int) $row['id']]);
                } else {
                    $upd = $pdo->prepare('UPDATE game_servers SET last_check_status = ?, last_check_at = NOW(), last_check_ms = ?, last_check_error = ? WHERE host = ? AND port = ?');
                    $upd->execute([$result['status'], $result['ms'], $result['error'], $host, $port]);
                }
                apiJsonResp(0, '服务器已存在，已更新连通状态', ['result' => $result]);
            } else {
                apiJsonResp(500, '上传失败: ' . $e->getMessage());
            }
        }
        break;
    }
    requireLogin();
    if (isAgent()) {
        apiJsonResp(403, '仅总管理可管理服务器');
        exit;
    }
    if ($action === 'list') {
        $stmt = $pdo->query('SELECT id, name, host, port, enabled, public_account_visible, sort_order, last_check_status, last_check_at, last_check_ms, last_check_error, source, reported_username, created_at FROM game_servers ORDER BY sort_order ASC, id ASC');
        apiJsonResp(0, 'ok', ['list' => $stmt->fetchAll()]);
        break;
    }
    if ($action === 'add') {
        $host = normalizeGameServerHost($input['host'] ?? '');
        $port = isset($input['port']) ? (int) $input['port'] : 8888;
        $name = trim((string) ($input['name'] ?? ''));
        if ($host === '') {
            apiJsonResp(400, '请输入服务器 IP 或域名');
            exit;
        }
        if ($port <= 0 || $port > 65535) {
            apiJsonResp(400, '端口范围不正确');
            exit;
        }
        try {
            $stmt = $pdo->prepare("INSERT INTO game_servers (name, host, port, enabled, sort_order, source) VALUES (?, ?, ?, 1, 0, 'admin')");
            $stmt->execute([$name === '' ? '管理员添加' : mb_substr($name, 0, 64), $host, $port]);
            adminLog($pdo, '添加游戏服务器', $host . ':' . $port);
            apiJsonResp(0, '添加成功', ['id' => (int) $pdo->lastInsertId()]);
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate') !== false) {
                apiJsonResp(400, '该服务器已存在');
            } else {
                apiJsonResp(500, '添加失败: ' . $e->getMessage());
            }
        }
        break;
    }
    if ($action === 'delete') {
        $ids = [];
        if (isset($input['ids']) && is_array($input['ids'])) {
            foreach ($input['ids'] as $rawId) {
                $rawId = (int) $rawId;
                if ($rawId > 0) $ids[] = $rawId;
            }
            $ids = array_values(array_unique($ids));
        } else {
            $id = isset($input['id']) ? (int) $input['id'] : 0;
            if ($id > 0) $ids[] = $id;
        }
        if (!$ids) {
            apiJsonResp(400, '请提供服务器 ID');
            exit;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare('DELETE FROM game_servers WHERE id IN (' . $placeholders . ')');
        $stmt->execute($ids);
        adminLog($pdo, '删除游戏服务器', 'ids=' . implode(',', $ids));
        apiJsonResp(0, '删除成功', ['deleted' => $stmt->rowCount()]);
        break;
    }
    if ($action === 'toggle') {
        $id = isset($input['id']) ? (int) $input['id'] : 0;
        $enabled = isset($input['enabled']) ? ((int) $input['enabled'] ? 1 : 0) : 1;
        if ($id <= 0) {
            apiJsonResp(400, '请提供服务器 ID');
            exit;
        }
        $stmt = $pdo->prepare('UPDATE game_servers SET enabled = ? WHERE id = ?');
        $stmt->execute([$enabled, $id]);
        apiJsonResp(0, '更新成功');
        break;
    }
    if ($action === 'toggle_public') {
        $id = isset($input['id']) ? (int) $input['id'] : 0;
        $visible = isset($input['visible']) ? ((int) $input['visible'] ? 1 : 0) : 1;
        if ($id <= 0) {
            apiJsonResp(400, '请提供服务器 ID');
            exit;
        }
        $stmt = $pdo->prepare('UPDATE game_servers SET public_account_visible = ? WHERE id = ?');
        $stmt->execute([$visible, $id]);
        apiJsonResp(0, '更新成功');
        break;
    }
    if ($action === 'test') {
        $id = isset($input['id']) ? (int) $input['id'] : 0;
        if ($id <= 0) {
            apiJsonResp(400, '请提供服务器 ID');
            exit;
        }
        $stmt = $pdo->prepare('SELECT id, host, port FROM game_servers WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $server = $stmt->fetch();
        if (!$server) {
            apiJsonResp(404, '服务器不存在');
            exit;
        }
        $result = testGameServerTcp($server['host'], $server['port']);
        $stmt = $pdo->prepare('UPDATE game_servers SET last_check_status = ?, last_check_at = NOW(), last_check_ms = ?, last_check_error = ? WHERE id = ?');
        $stmt->execute([$result['status'], $result['ms'], $result['error'], $id]);
        apiJsonResp(0, '测试完成', ['id' => $id, 'result' => $result]);
        break;
    }
    if ($action === 'test_all') {
        $stmt = $pdo->query('SELECT id, host, port FROM game_servers ORDER BY sort_order ASC, id ASC');
        $rows = $stmt->fetchAll();
        $results = [];
        $online = 0;
        $offline = 0;
        foreach ($rows as $server) {
            $result = testGameServerTcp($server['host'], $server['port']);
            if ($result['status'] === 'online') $online++; else $offline++;
            $upd = $pdo->prepare('UPDATE game_servers SET last_check_status = ?, last_check_at = NOW(), last_check_ms = ?, last_check_error = ? WHERE id = ?');
            $upd->execute([$result['status'], $result['ms'], $result['error'], (int) $server['id']]);
            $results[] = ['id' => (int) $server['id'], 'host' => $server['host'], 'port' => (int) $server['port'], 'result' => $result];
        }
        apiJsonResp(0, '测试完成', ['list' => $results, 'online' => $online, 'offline' => $offline, 'total' => count($results)]);
        break;
    }
    apiJsonResp(400, '无效的 action，支持: public | list | add | delete | toggle | test | test_all');
    break;

// ---------- 代理管理（仅总管理） ----------
case 'agents':
    requireLogin();
    if (isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'agent') {
        apiJsonResp(403, '仅总管理可管理代理');
        exit;
    }
    if ($action === 'list') {
        try {
            $stmt = $pdo->query("SELECT id, username, created_at FROM admin_users WHERE role = 'agent' ORDER BY id ASC");
            $list = $stmt->fetchAll();
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'role') !== false) {
                apiJsonResp(0, 'ok', ['list' => []]);
                exit;
            }
            throw $e;
        }
        apiJsonResp(0, 'ok', ['list' => $list]);
        exit;
    }
    if ($action === 'add') {
        $username = isset($input['username']) ? trim((string) $input['username']) : '';
        $password = isset($input['password']) ? (string) $input['password'] : '';
        if ($username === '' || mb_strlen($username) < 2) {
            apiJsonResp(400, '用户名至少2位');
            exit;
        }
        if (strlen($password) < 4) {
            apiJsonResp(400, '密码至少4位');
            exit;
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        try {
            $stmt = $pdo->prepare("INSERT INTO admin_users (username, password, role) VALUES (?, ?, 'agent')");
            $stmt->execute([$username, $hash]);
            adminLog($pdo, '添加代理', 'username=' . $username);
            apiJsonResp(0, '添加成功', ['id' => (int) $pdo->lastInsertId(), 'username' => $username]);
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate') !== false) {
                apiJsonResp(400, '该用户名已存在');
            } else {
                apiJsonResp(500, '添加失败: ' . $e->getMessage());
            }
        }
        exit;
    }
    if ($action === 'delete') {
        $id = isset($input['id']) ? (int) $input['id'] : 0;
        if ($id <= 0) {
            apiJsonResp(400, '请提供代理 ID');
            exit;
        }
        $stmt = $pdo->prepare("SELECT id, username, current_session_id FROM admin_users WHERE id = ? AND role = 'agent' LIMIT 1");
        $stmt->execute([$id]);
        $agent = $stmt->fetch();
        if (!$agent) {
            apiJsonResp(404, '代理不存在或已删除');
            exit;
        }
        $agent_session_id = isset($agent['current_session_id']) ? trim((string) $agent['current_session_id']) : '';
        try {
            // 1) 删除使用该代理卡密的用户及其登录日志
            $stmt = $pdo->prepare('SELECT u.id FROM users u INNER JOIN card_keys c ON c.id = u.card_key_id AND c.creator_id = ?');
            $stmt->execute([$id]);
            $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $deletedUsers = 0;
            if (!empty($userIds)) {
                $placeholders = implode(',', array_fill(0, count($userIds), '?'));
                $pdo->prepare('DELETE FROM user_login_log WHERE user_id IN (' . $placeholders . ')')->execute($userIds);
                $delUsers = $pdo->prepare('DELETE FROM users WHERE id IN (' . $placeholders . ')');
                $delUsers->execute($userIds);
                $deletedUsers = $delUsers->rowCount();
            }
            // 2) 删除该代理生成的全部卡密
            $delCards = $pdo->prepare('DELETE FROM card_keys WHERE creator_id = ?');
            $delCards->execute([$id]);
            $deletedCards = $delCards->rowCount();
            // 3) 删除代理账号并踢下线
            $stmt = $pdo->prepare("DELETE FROM admin_users WHERE id = ? AND role = 'agent'");
            $stmt->execute([$id]);
            if ($stmt->rowCount() > 0) {
                if ($agent_session_id !== '') destroyAdminSessionById($agent_session_id);
                adminLog($pdo, '删除代理', 'username=' . $agent['username'] . ' 已删除其卡密 ' . $deletedCards . ' 条、对应用户 ' . $deletedUsers . ' 个');
                apiJsonResp(0, '删除成功', ['deleted_cards' => $deletedCards, 'deleted_users' => $deletedUsers]);
            } else {
                apiJsonResp(404, '代理不存在或已删除');
            }
        } catch (PDOException $e) {
            apiJsonResp(500, '删除失败: ' . $e->getMessage());
        }
        exit;
    }
    apiJsonResp(400, '无效的 action，支持: list | add | delete');
    break;

// ---------- 操作日志（仅总管理） ----------
case 'operation_log':
    requireLogin();
    if (isAgent()) {
        apiJsonResp(403, '仅总管理可查看操作日志');
        exit;
    }
    // 仅保留最近 3 天的操作日志，自动清理更早的记录，防止日志无限增长
    try {
        $keepFrom = date('Y-m-d H:i:s', time() - 3 * 86400);
        $cleanupStmt = $pdo->prepare('DELETE FROM admin_operation_log WHERE created_at < ?');
        $cleanupStmt->execute([$keepFrom]);
    } catch (PDOException $e) {
        // 清理失败不影响后续查询
    }
    $page = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1));
    $page_size = max(1, min(100, (int) (isset($_GET['page_size']) ? $_GET['page_size'] : 30)));
    $hasTable = false;
    try {
        $pdo->query('SELECT 1 FROM admin_operation_log LIMIT 1');
        $hasTable = true;
    } catch (PDOException $e) {}
    if (!$hasTable) {
        apiJsonResp(0, 'ok', ['list' => [], 'total' => 0, 'page' => $page, 'page_size' => $page_size]);
        exit;
    }
    if ($action !== 'list') {
        apiJsonResp(400, '无效的 action，支持: list');
        exit;
    }
    $total = (int) $pdo->query('SELECT COUNT(*) FROM admin_operation_log')->fetchColumn();
    $offset = ($page - 1) * $page_size;
    $stmt = $pdo->prepare('SELECT id, admin_username, action, detail, created_at FROM admin_operation_log ORDER BY id DESC LIMIT ' . (int) $page_size . ' OFFSET ' . (int) $offset);
    $stmt->execute();
    apiJsonResp(0, 'ok', ['list' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'page_size' => $page_size]);
    break;

// ---------- 导出 CSV ----------
case 'export':
    requireLogin();
    ensureFrontendUserTables($pdo);
    $adminId = (int) ($_SESSION['admin_user_id'] ?? 0);
    $isAgent = (isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'agent');
    $type = isset($_GET['type']) ? trim($_GET['type']) : '';
    if (!in_array($type, ['cards', 'users'], true)) {
        apiJsonResp(400, 'type 须为 cards 或 users');
        exit;
    }
    $input = $_GET;
    $keyword = isset($input['keyword']) ? trim((string) $input['keyword']) : '';
    $limit = 5000;
    if ($type === 'cards') {
        $where = ['1=1'];
        $params = [];
        if ($isAgent) {
            $where[] = 'creator_id = ?';
            $params[] = $adminId;
        }
        if ($keyword !== '') {
            $where[] = '(card_code LIKE ? OR remark LIKE ?)';
            $params[] = '%' . $keyword . '%';
            $params[] = '%' . $keyword . '%';
        }
        $status = isset($input['status']) ? $input['status'] : '';
        $paused = isset($input['paused']) ? $input['paused'] : '';
        $card_type = isset($input['card_type']) ? trim((string) $input['card_type']) : '';
        if ($status !== '' && $status !== 'all') { $where[] = 'status = ?'; $params[] = (int) $status; }
        if ($paused !== '' && $paused !== 'all') { $where[] = 'paused = ?'; $params[] = (int) $paused; }
        if ($card_type !== '' && $card_type !== 'all' && in_array($card_type, ['day', 'week', 'month', 'trial'], true)) {
            $where[] = 'card_type = ?'; $params[] = $card_type;
        }
        $whereSql = implode(' AND ', $where);
        $sql = "SELECT id, card_code, status, paused, expires_at, card_type, user_id, bound_ip, bound_at, remark, created_at FROM card_keys WHERE $whereSql ORDER BY id DESC LIMIT " . (int) $limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (ob_get_length()) ob_end_clean();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="card_keys_' . date('YmdHis') . '.csv"');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($out, ['ID', '卡密', '类型', '状态', '暂停', '到期时间', '绑定用户ID', '注册IP', '使用时间', '备注', '创建时间']);
        foreach ($rows as $r) {
            $statusText = (int) $r['status'] === 1 ? '已使用' : '未使用';
            if ($r['expires_at'] && strtotime($r['expires_at']) < time()) $statusText = '已过期';
            $typeText = isset($r['card_type']) ? cardTypeName($r['card_type']) : '';
            fputcsv($out, [$r['id'], $r['card_code'], $typeText, $statusText, (int) $r['paused'] ? '已暂停' : '启用', $r['expires_at'] ?: '', $r['user_id'] ?: '', $r['bound_ip'] ?: '', $r['bound_at'] ?: '', $r['remark'] ?: '', $r['created_at'] ?: '']);
        }
        fclose($out);
        exit;
    }
    $where = ['1=1'];
    $params = [];
    if ($keyword !== '') {
        $where[] = 'u.username LIKE ?';
        $params[] = '%' . $keyword . '%';
    }
    $whereSql = implode(' AND ', $where);
    $registerServerSelect = dbHasColumn($pdo, 'users', 'register_server') ? 'u.register_server' : 'NULL AS register_server';
    $sql = "SELECT u.id, u.username, u.card_key_id, u.created_at, $registerServerSelect, c.card_code, c.expires_at, c.paused AS card_paused FROM users u LEFT JOIN card_keys c ON c.id = u.card_key_id WHERE $whereSql ORDER BY u.id DESC LIMIT " . (int) $limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (ob_get_length()) ob_end_clean();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="users_' . date('YmdHis') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['ID', '用户名', '卡密', '卡密状态', '到期时间', '注册服务器', '注册时间']);
    foreach ($rows as $r) {
        $cardValid = true;
        if (!empty($r['card_key_id'])) {
            if (empty($r['card_code'])) $cardValid = false;
            elseif ($r['expires_at'] && strtotime($r['expires_at']) < time()) $cardValid = false;
        }
        $cardStatus = $cardValid ? '有效' : '已删除/已过期';
        fputcsv($out, [$r['id'], $r['username'], $r['card_code'] ?: '-', $cardStatus, $r['expires_at'] ?: '', isset($r['register_server']) ? ($r['register_server'] ?: '') : '', $r['created_at'] ?: '']);
    }
    fclose($out);
    exit;

default:
    apiJsonResp(400, 'Unknown API module: ' . API_MODULE);
}
