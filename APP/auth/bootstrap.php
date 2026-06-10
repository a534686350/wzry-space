<?php
/**
 * 统一引导：配置、数据库、Session、公共辅助函数
 * 替代原 db.php + session.php + helpers.php
 */
$cfg = require __DIR__ . '/config.php';

// 数据库
$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
    $cfg['db_host'],
    $cfg['db_port'],
    $cfg['db_name'],
    $cfg['db_charset']
);
try {
    $pdo = new PDO($dsn, $cfg['db_user'], $cfg['db_password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    if (ob_get_length()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => 500, 'msg' => '数据库连接失败: ' . $e->getMessage()]);
    exit;
}

// Session
if (session_status() === PHP_SESSION_NONE) {
    session_name($cfg['session_name']);
    session_start();
}

function isLoggedIn() {
    return !empty($_SESSION['admin_user_id']);
}

/** 当前登录是否为代理（否则为总管理） */
function isAgent() {
    return isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'agent';
}

/** 当前后台角色：admin | agent */
function getAdminRole() {
    return isset($_SESSION['admin_role']) ? (string) $_SESSION['admin_role'] : 'admin';
}

function isUserLoggedIn() {
    return !empty($_SESSION['user_id']);
}

/**
 * 要求已登录，否则 401。若传入 $redirectUrl 或当前请求为后台页面（/admin/），则重定向到登录页；否则输出 JSON（供 API 使用）。
 * @param string|null $redirectUrl 未登录或账号失效时重定向地址，如 'index.php?msg=relogin'；不传则根据请求路径判断（/admin/ 下则重定向到同目录 index.php）
 */
function requireLogin($redirectUrl = null) {
    $script = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';
    if (($redirectUrl === null || $redirectUrl === '') && strpos($script, '/admin/') !== false) {
        $redirectUrl = rtrim(dirname($script), '/') . '/index.php?msg=relogin';
    }
    $doRedirect = ($redirectUrl !== null && $redirectUrl !== '');
    if ($doRedirect && strpos($redirectUrl, 'http') !== 0) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
        $path = (strpos($redirectUrl, '/') === 0) ? $redirectUrl : rtrim(dirname($script), '/') . '/' . ltrim($redirectUrl, '/');
        $redirectUrl = $scheme . '://' . $host . $path;
    }
    if (!isLoggedIn()) {
        if (ob_get_length()) ob_end_clean();
        if ($doRedirect) {
            header('Location: ' . $redirectUrl);
            exit;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['code' => 401, 'msg' => '请先登录'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // 校验管理员/代理是否仍存在（删除代理后其 session 仍在，下次请求在此处踢下线）
    $adminId = (int) ($_SESSION['admin_user_id'] ?? 0);
    if ($adminId > 0) {
        global $pdo;
        try {
            $stmt = $pdo->prepare('SELECT id FROM admin_users WHERE id = ? LIMIT 1');
            $stmt->execute([$adminId]);
            if (!$stmt->fetch()) {
                $_SESSION = [];
                if (ini_get('session.use_cookies')) {
                    $p = session_get_cookie_params();
                    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
                }
                session_destroy();
                if (ob_get_length()) ob_end_clean();
                if ($doRedirect) {
                    header('Location: ' . $redirectUrl);
                    exit;
                }
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['code' => 401, 'msg' => '账号已失效，请重新登录'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } catch (Exception $e) {
            // 数据库异常时不踢人，仅记录
        }
    }
}

function getClientIp() {
    $ip = '';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }
    if ($ip === '' && !empty($_SERVER['HTTP_X_REAL_IP'])) {
        $ip = trim($_SERVER['HTTP_X_REAL_IP']);
    }
    if ($ip === '' && !empty($_SERVER['REMOTE_ADDR'])) {
        $ip = trim($_SERVER['REMOTE_ADDR']);
    }
    return $ip === '' ? '0.0.0.0' : $ip;
}

function checkBlacklist($pdo, $ip, $username = '') {
    try {
        $pdo->query('SELECT 1 FROM blacklist LIMIT 1');
    } catch (PDOException $e) {
        return ['blocked' => false, 'reason' => ''];
    }
    $ip = trim($ip);
    $username = trim($username);
    if ($ip !== '') {
        $stmt = $pdo->prepare('SELECT id FROM blacklist WHERE type = ? AND value = ? LIMIT 1');
        $stmt->execute(['ip', $ip]);
        if ($stmt->fetch()) {
            return ['blocked' => true, 'reason' => '您的IP已被禁止访问'];
        }
    }
    if ($username !== '') {
        $stmt = $pdo->prepare('SELECT id FROM blacklist WHERE type = ? AND value = ? LIMIT 1');
        $stmt->execute(['user', $username]);
        if ($stmt->fetch()) {
            return ['blocked' => true, 'reason' => '该账号已被禁止'];
        }
    }
    return ['blocked' => false, 'reason' => ''];
}

function adminLog($pdo, $action, $detail = '') {
    $username = isset($_SESSION['admin_username']) ? (string) $_SESSION['admin_username'] : '';
    if ($username === '') return;
    try {
        $pdo->prepare('INSERT INTO admin_operation_log (admin_username, action, detail) VALUES (?, ?, ?)')
            ->execute([$username, $action, mb_substr($detail, 0, 500)]);
    } catch (PDOException $e) {}
}

/**
 * 根据 session_id 使该会话失效（踢下线），用于删除代理时强制其登出。
 * 仅对 PHP 默认文件 session 有效；若使用 Redis/DB 等需自行扩展。
 */
function destroyAdminSessionById($session_id) {
    $session_id = trim((string) $session_id);
    if ($session_id === '' || preg_match('/[^a-zA-Z0-9,-]/', $session_id)) return;
    $path = session_save_path();
    if ($path === '' || $path === false) $path = sys_get_temp_dir();
    $path = rtrim(str_replace('\\', '/', $path), '/');
    $file = $path . '/sess_' . $session_id;
    if (file_exists($file) && is_file($file)) @unlink($file);
}
