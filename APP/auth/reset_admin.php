<?php
/**
 * 重置后台管理员密码（忘记密码时使用）
 * 从管理登录页点击「重置密码」进入，需先输入 config.php 中的 reset_admin_key 方可重置
 * 也支持旧方式：auth/reset_admin.php?key=密钥
 */
require_once __DIR__ . '/bootstrap.php';
$cfg = require __DIR__ . '/config.php';
$resetKey = isset($cfg['reset_admin_key']) ? $cfg['reset_admin_key'] : '';

// 从 URL 传入的密钥（旧方式）
$keyFromUrl = isset($_GET['key']) ? trim($_GET['key']) : '';
// 从表单提交的密钥
$keyFromPost = isset($_POST['reset_key']) ? trim($_POST['reset_key']) : '';
$key = $keyFromUrl !== '' ? $keyFromUrl : $keyFromPost;

$allowed = ($resetKey !== '' && $key !== '' && $key === $resetKey);

// 仅验证密钥的表单：POST reset_key 且无 step
$isVerifyKeyPost = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_key']) && !isset($_POST['step']));
if ($isVerifyKeyPost) {
    if ($allowed) {
        $_SESSION['reset_admin_verified'] = true;
        header('Location: ' . (isset($_SERVER['SCRIPT_NAME']) ? dirname($_SERVER['SCRIPT_NAME']) . '/' : '') . 'reset_admin.php');
        exit;
    }
    $keyError = true;
}

// 通过 URL 带密钥访问时，验证通过后写入 session 并重定向到无参数地址（避免密钥留在地址栏）
if ($keyFromUrl !== '' && $allowed) {
    $_SESSION['reset_admin_verified'] = true;
    header('Location: reset_admin.php');
    exit;
}

// 已通过「输入密钥」验证（session）或本次请求带正确密钥，均可进入重置流程
$canReset = !empty($_SESSION['reset_admin_verified']) || $allowed;

function ensureAdminUsersTableForReset(PDO $pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `admin_users` (
          `id` int unsigned NOT NULL AUTO_INCREMENT,
          `username` varchar(64) NOT NULL,
          `password` varchar(255) NOT NULL,
          `role` varchar(16) NOT NULL DEFAULT 'admin',
          `current_session_id` varchar(128) DEFAULT NULL,
          `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uk_username` (`username`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

// 提交新密码
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step']) && $_POST['step'] === 'reset' && $canReset) {
    $username = isset($_POST['username']) ? trim($_POST['username']) : 'admin';
    $newPassword = isset($_POST['new_password']) ? $_POST['new_password'] : '';
    if ($username === '') {
        $err = '请填写管理员用户名';
    } elseif (strlen($newPassword) < 4) {
        $err = '新密码至少4位';
    } else {
        ensureAdminUsersTableForReset($pdo);
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('UPDATE admin_users SET password = ? WHERE username = ?');
        $stmt->execute([$hash, $username]);
        if ($stmt->rowCount() > 0) {
            unset($_SESSION['reset_admin_verified']);
            $ok = true;
            $msg = '密码已重置，请使用新密码登录后台。';
        } else {
            // 可能该用户不存在，尝试创建（方便首次或表空时恢复）
            $check = $pdo->prepare('SELECT id FROM admin_users WHERE username = ? LIMIT 1');
            $check->execute([$username]);
            if ($check->fetch()) {
                $err = '密码未变更，请换一个不同的新密码再试。';
            } else {
                $pdo->prepare('INSERT INTO admin_users (username, password) VALUES (?, ?)')->execute([$username, $hash]);
                unset($_SESSION['reset_admin_verified']);
                $ok = true;
                $msg = '该用户不存在，已为您创建管理员账号：' . htmlspecialchars($username) . '，请使用新密码登录后台。';
            }
        }
    }
}

// 未验证且未带正确密钥：显示「请输入密钥」页
if (!$canReset && !isset($ok)) {
    header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>重置管理员密码 - 验证密钥</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: "Microsoft YaHei", sans-serif; background: linear-gradient(135deg, #1a1f3a 0%, #0a0e27 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; color: #e0e0e0; padding: 20px; }
        .box { background: rgba(47,54,60,0.95); border: 1px solid rgba(74,158,255,0.3); border-radius: 16px; padding: 40px; width: 100%; max-width: 400px; }
        h1 { font-size: 20px; margin-bottom: 24px; color: #4a9eff; text-align: center; }
        .form-group { margin-bottom: 18px; }
        label { display: block; margin-bottom: 6px; font-size: 14px; color: #aaa; }
        input { width: 100%; padding: 12px 14px; border: 1px solid rgba(74,158,255,0.3); border-radius: 8px; background: rgba(26,31,36,0.8); color: #fff; font-size: 14px; }
        input:focus { outline: none; border-color: #4a9eff; }
        .btn { width: 100%; padding: 12px; border: none; border-radius: 8px; background: linear-gradient(135deg, #4a9eff 0%, #258DF2 100%); color: #fff; font-size: 15px; cursor: pointer; margin-top: 8px; }
        .btn:hover { opacity: 0.9; }
        .msg.err { margin-top: 12px; font-size: 14px; text-align: center; color: #ff6b6b; }
        .hint { font-size: 12px; color: #666; margin-top: 16px; }
        .back { margin-top: 16px; text-align: center; }
        .back a { color: #74c0fc; text-decoration: none; }
        .back a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="box">
        <h1>重置管理员密码</h1>
        <p class="hint">请输入 config.php 中配置的 <code>reset_admin_key</code> 密钥，验证通过后可设置新密码。</p>
        <?php if (!empty($keyError)): ?><p class="msg err">密钥错误，请重试。</p><?php endif; ?>
        <form method="post">
            <div class="form-group">
                <label>重置密钥</label>
                <input type="password" name="reset_key" required placeholder="请输入重置密钥" autocomplete="off">
            </div>
            <button type="submit" class="btn">验证密钥</button>
        </form>
        <p class="back"><a href="../admin/">返回登录</a></p>
    </div>
</body>
</html>
<?php
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>重置管理员密码</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: "Microsoft YaHei", sans-serif; background: linear-gradient(135deg, #1a1f3a 0%, #0a0e27 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; color: #e0e0e0; padding: 20px; }
        .box { background: rgba(47,54,60,0.95); border: 1px solid rgba(74,158,255,0.3); border-radius: 16px; padding: 40px; width: 100%; max-width: 400px; }
        h1 { font-size: 20px; margin-bottom: 24px; color: #4a9eff; text-align: center; }
        .form-group { margin-bottom: 18px; }
        label { display: block; margin-bottom: 6px; font-size: 14px; color: #aaa; }
        input { width: 100%; padding: 12px 14px; border: 1px solid rgba(74,158,255,0.3); border-radius: 8px; background: rgba(26,31,36,0.8); color: #fff; font-size: 14px; }
        input:focus { outline: none; border-color: #4a9eff; }
        .btn { width: 100%; padding: 12px; border: none; border-radius: 8px; background: linear-gradient(135deg, #4a9eff 0%, #258DF2 100%); color: #fff; font-size: 15px; cursor: pointer; margin-top: 8px; }
        .btn:hover { opacity: 0.9; }
        .msg { margin-top: 12px; font-size: 14px; text-align: center; }
        .msg.err { color: #ff6b6b; }
        .msg.ok { color: #51cf66; }
        .hint { font-size: 12px; color: #666; margin-top: 16px; }
        .back { margin-top: 16px; text-align: center; }
        .back a { color: #74c0fc; text-decoration: none; }
        .back a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="box">
        <?php if (!empty($ok)): ?>
            <h1>重置成功</h1>
            <p class="msg ok"><?php echo htmlspecialchars($msg); ?></p>
            <p class="back"><a href="../admin/">去登录</a></p>
        <?php else: ?>
            <h1>设置新密码</h1>
            <?php if (!empty($err)): ?><p class="msg err"><?php echo htmlspecialchars($err); ?></p><?php endif; ?>
            <form method="post">
                <input type="hidden" name="step" value="reset">
                <div class="form-group">
                    <label>管理员用户名</label>
                    <input type="text" name="username" value="admin" placeholder="必填，默认 admin">
                    <p class="hint" style="margin-top:6px;">一键安装创建的管理员用户名为 <strong>admin</strong>，请勿填错。</p>
                </div>
                <div class="form-group">
                    <label>新密码（至少4位）</label>
                    <input type="password" name="new_password" required minlength="4" placeholder="请输入新密码">
                </div>
                <button type="submit" class="btn">确认重置</button>
            </form>
            <p class="back"><a href="../admin/">返回登录</a></p>
        <?php endif; ?>
    </div>
</body>
</html>
