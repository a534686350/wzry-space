<?php
/**
 * 管理后台入口：登录页、登出、修改密码、未登录跳转登录/已登录跳转仪表盘
 */
require_once __DIR__ . '/../auth/bootstrap.php';

$action = isset($_GET['action']) ? trim($_GET['action']) : '';

// 后台：紧急停止/恢复（安全方式：只切换数据库中的停机标记，不删除任何代码/数据）
if ($action === 'emergency_stop') {
    requireLogin('dashboard.php');

    $state = isset($_REQUEST['state']) ? trim((string)$_REQUEST['state']) : 'toggle'; // on | off | toggle
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
    $host = preg_replace('/:\d+$/', '', trim((string)$host)); // 去掉端口
    if ($host === '') $host = 'unknown';

    // 懒加载：确保表存在（不会影响其它业务）
    $pdo->exec('CREATE TABLE IF NOT EXISTS emergency_stop_sites (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        host VARCHAR(255) NOT NULL UNIQUE,
        name VARCHAR(128) DEFAULT NULL,
        active TINYINT NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    $currentActive = 0;
    $stmt = $pdo->prepare('SELECT active FROM emergency_stop_sites WHERE host = ? LIMIT 1');
    $stmt->execute([$host]);
    $row = $stmt->fetch();
    if ($row) $currentActive = (int)$row['active'];

    if ($state === 'toggle') {
        $newActive = $currentActive === 1 ? 0 : 1;
    } elseif ($state === 'on') {
        $newActive = 1;
    } elseif ($state === 'off') {
        $newActive = 0;
    } else {
        $newActive = $currentActive === 1 ? 0 : 1;
    }

    if ($row) {
        $stmt = $pdo->prepare('UPDATE emergency_stop_sites SET active = ? WHERE host = ?');
        $stmt->execute([(int)$newActive, $host]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO emergency_stop_sites (host, name, active) VALUES (?, ?, ?)');
        $stmt->execute([$host, $host, (int)$newActive]);
    }

    $detail = $newActive ? '触发紧急停止' : '恢复服务';
    adminLog($pdo, 'emergency_stop', 'host=' . $host . ', state=' . $state . ', detail=' . $detail);
    header('Location: dashboard.php');
    exit;
}

// 登出
if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: index.php');
    exit;
}

// 修改密码（需已登录）
if ($action === 'change_password') {
    requireLogin();
    $username = $_SESSION['admin_username'] ?? '';
    $err = '';
    $ok = false;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $current = isset($_POST['current_password']) ? $_POST['current_password'] : '';
        $newPass = isset($_POST['new_password']) ? $_POST['new_password'] : '';
        $confirm = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
        if ($newPass !== $confirm) {
            $err = '两次输入的新密码不一致';
        } elseif (strlen($newPass) < 4) {
            $err = '新密码至少4位';
        } else {
            $stmt = $pdo->prepare('SELECT id, password FROM admin_users WHERE username = ? LIMIT 1');
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            if (!$user || !password_verify($current, $user['password'])) {
                $err = '当前密码错误';
            } else {
                $hash = password_hash($newPass, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('UPDATE admin_users SET password = ? WHERE id = ?');
                $stmt->execute([$hash, $user['id']]);
                $ok = true;
            }
        }
    }
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>修改密码 - 验证系统</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: "Microsoft YaHei", sans-serif; background: linear-gradient(135deg, #1a1f3a 0%, #0a0e27 100%); min-height: 100vh; color: #e0e0e0; padding: 20px; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        .header h1 { font-size: 22px; color: #4a9eff; }
        .header a { color: #4a9eff; text-decoration: none; }
        .box { background: rgba(47,54,60,0.95); border: 1px solid rgba(74,158,255,0.3); border-radius: 16px; padding: 40px; max-width: 400px; }
        h2 { font-size: 18px; margin-bottom: 20px; color: #4a9eff; }
        .form-group { margin-bottom: 18px; }
        label { display: block; margin-bottom: 6px; font-size: 14px; color: #aaa; }
        input { width: 100%; padding: 12px 14px; border: 1px solid rgba(74,158,255,0.3); border-radius: 8px; background: rgba(26,31,36,0.8); color: #fff; font-size: 14px; }
        input:focus { outline: none; border-color: #4a9eff; }
        .btn { width: 100%; padding: 12px; border: none; border-radius: 8px; background: linear-gradient(135deg, #4a9eff 0%, #258DF2 100%); color: #fff; font-size: 15px; cursor: pointer; margin-top: 8px; }
        .btn:hover { opacity: 0.9; }
        .msg { margin-top: 12px; font-size: 14px; }
        .msg.err { color: #ff6b6b; }
        .msg.ok { color: #51cf66; }
    </style>
</head>
<body>
    <div class="header">
        <h1>修改密码</h1>
        <div style="display:flex;gap:12px;">
            <a href="dashboard.php">返回管理</a>
            <a href="index.php?action=logout">退出</a>
        </div>
    </div>
    <div class="box">
        <?php if ($ok): ?>
            <p class="msg ok">密码已修改，请使用新密码重新登录。</p>
            <p style="margin-top:12px;"><a href="index.php?action=logout" style="color:#4a9eff;">重新登录</a></p>
        <?php else: ?>
            <h2>当前登录：<?php echo htmlspecialchars($username); ?></h2>
            <?php if ($err !== ''): ?><p class="msg err"><?php echo htmlspecialchars($err); ?></p><?php endif; ?>
            <form method="post" action="index.php?action=change_password">
                <div class="form-group">
                    <label>当前密码</label>
                    <input type="password" name="current_password" required placeholder="请输入当前密码">
                </div>
                <div class="form-group">
                    <label>新密码（至少4位）</label>
                    <input type="password" name="new_password" required minlength="4" placeholder="请输入新密码">
                </div>
                <div class="form-group">
                    <label>确认新密码</label>
                    <input type="password" name="confirm_password" required minlength="4" placeholder="再次输入新密码">
                </div>
                <button type="submit" class="btn">确认修改</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
    <?php
    exit;
}

// 已登录：跳转仪表盘
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

// 未登录：显示登录页
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理后台 - 登录</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: "Microsoft YaHei", sans-serif; background: linear-gradient(135deg, #1a1f3a 0%, #0a0e27 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; color: #e0e0e0; }
        .box { background: rgba(47,54,60,0.95); border: 1px solid rgba(74,158,255,0.3); border-radius: 16px; padding: 40px; width: 100%; max-width: 360px; }
        h1 { font-size: 22px; margin-bottom: 24px; color: #4a9eff; text-align: center; }
        .form-group { margin-bottom: 16px; }
        label { display: block; margin-bottom: 6px; font-size: 14px; color: #aaa; }
        input { width: 100%; padding: 12px 14px; border: 1px solid rgba(74,158,255,0.3); border-radius: 8px; background: rgba(26,31,36,0.8); color: #fff; font-size: 14px; }
        input:focus { outline: none; border-color: #4a9eff; }
        .btn { width: 100%; padding: 12px; border: none; border-radius: 8px; background: linear-gradient(135deg, #4a9eff 0%, #258DF2 100%); color: #fff; font-size: 15px; cursor: pointer; margin-top: 8px; }
        .btn:hover { opacity: 0.9; }
        .msg { margin-top: 12px; font-size: 13px; text-align: center; min-height: 20px; }
        .msg.err { color: #ff6b6b; }
        .msg.ok { color: #51cf66; }
        .reset-link { margin-top: 20px; text-align: center; font-size: 13px; }
        .reset-link a { color: #74c0fc; text-decoration: none; }
        .reset-link a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="box">
        <h1>验证系统 - 管理登录</h1>
        <form id="loginForm">
            <div class="form-group">
                <label>用户名</label>
                <input type="text" name="username" required autocomplete="username" placeholder="请输入用户名">
            </div>
            <div class="form-group">
                <label>密码</label>
                <input type="password" name="password" required autocomplete="current-password" placeholder="请输入密码">
            </div>
            <button type="submit" class="btn">登录</button>
        </form>
        <div id="msg" class="msg"></div>
        <p class="reset-link"><a href="../auth/reset_admin.php">忘记密码？重置需输入密钥</a></p>
    </div>
    <script>
        (function() {
            var m = document.getElementById('msg');
            if (/[?&]msg=relogin\b/.test(location.search || '')) {
                m.className = 'msg err';
                m.textContent = '请重新登录';
            }
        })();
        document.getElementById('loginForm').onsubmit = function(e) {
            e.preventDefault();
            var msg = document.getElementById('msg');
            msg.className = 'msg';
            msg.textContent = '';
            var fd = new FormData(this);
            fetch('../api/index.php?module=admin_login', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username: fd.get('username'), password: fd.get('password') })
            }).then(function(r) {
                return r.text().then(function(text) {
                    try {
                        return { ok: r.ok, data: JSON.parse(text) };
                    } catch (e) {
                        return { ok: false, data: null, raw: text };
                    }
                });
            }).then(function(result) {
                if (result.data && result.data.code === 0) {
                    msg.className = 'msg ok';
                    msg.textContent = '登录成功，跳转中...';
                    location.href = 'dashboard.php';
                } else if (result.data && result.data.msg) {
                    msg.className = 'msg err';
                    msg.textContent = result.data.msg;
                } else if (!result.ok && result.raw) {
                    msg.className = 'msg err';
                    msg.textContent = result.raw.indexOf('数据库') !== -1 ? result.raw.replace(/<[^>]+>/g, '').trim() : ('请求失败: ' + (result.raw.length > 80 ? result.raw.slice(0, 80) + '...' : result.raw));
                } else {
                    msg.className = 'msg err';
                    msg.textContent = '网络错误或服务异常，请检查 API 地址与数据库连接（F12 查看控制台）';
                }
            }).catch(function(err) {
                msg.className = 'msg err';
                msg.textContent = '网络错误，请检查能否访问 ../api/index.php（F12 网络面板查看）';
            });
        };
    </script>
</body>
</html>
