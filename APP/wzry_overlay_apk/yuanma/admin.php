<?php
session_start();
$cfg = require __DIR__ . '/config.php';
$dataFile = $cfg['data_file'];
$dataDir = dirname($dataFile);
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

function default_config() {
    return [
        'update' => [
            'version_code' => 2,
            'version_name' => '1.1',
            'apk_url' => '',
            'title' => '发现新版本',
            'message' => '检测到新版本，请下载更新。',
            'force_update' => false,
        ],
        'popup' => [
            'enabled' => false,
            'title' => '公告',
            'message' => '',
            'url' => '',
        ],
        'links' => [
            'trial_url' => '',
            'buy_card_url' => '',
            'download_url' => '',
        ],
        'updated_at' => date('Y-m-d H:i:s'),
    ];
}

function read_config($file) {
    if (!is_file($file)) return default_config();
    $data = json_decode((string) file_get_contents($file), true);
    return is_array($data) ? array_replace_recursive(default_config(), $data) : default_config();
}

function save_config($file, $data) {
    $data['updated_at'] = date('Y-m-d H:i:s');
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function h($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

$msg = '';
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: admin.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_password'])) {
    if (hash_equals((string) $cfg['admin_password'], (string) $_POST['login_password'])) {
        $_SESSION['alin_app_admin'] = 1;
        header('Location: admin.php');
        exit;
    }
    $msg = '密码错误';
}

$logged = !empty($_SESSION['alin_app_admin']);
$data = read_config($dataFile);

if ($logged && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_config'])) {
    $data = [
        'update' => [
            'version_code' => max(1, (int) ($_POST['version_code'] ?? 1)),
            'version_name' => trim((string) ($_POST['version_name'] ?? '1.0')),
            'apk_url' => trim((string) ($_POST['apk_url'] ?? '')),
            'title' => trim((string) ($_POST['update_title'] ?? '发现新版本')),
            'message' => trim((string) ($_POST['update_message'] ?? '')),
            'force_update' => !empty($_POST['force_update']),
        ],
        'popup' => [
            'enabled' => !empty($_POST['popup_enabled']),
            'title' => trim((string) ($_POST['popup_title'] ?? '公告')),
            'message' => trim((string) ($_POST['popup_message'] ?? '')),
            'url' => trim((string) ($_POST['popup_url'] ?? '')),
        ],
        'links' => [
            'trial_url' => trim((string) ($_POST['trial_url'] ?? '')),
            'buy_card_url' => trim((string) ($_POST['buy_card_url'] ?? '')),
            'download_url' => trim((string) ($_POST['download_url'] ?? '')),
        ],
    ];
    save_config($dataFile, $data);
    $msg = '保存成功';
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ALin雷达 APK 远程管理</title>
    <style>
        body{margin:0;background:#07111f;color:#e5e7eb;font-family:Arial,"Microsoft YaHei",sans-serif}
        .wrap{max-width:960px;margin:0 auto;padding:24px}
        h1{font-size:24px;margin:0 0 18px}
        .card{background:#111827;border:1px solid #284b7a;border-radius:12px;padding:18px;margin:14px 0}
        label{display:block;color:#93c5fd;margin:12px 0 6px;font-size:14px}
        input,textarea{width:100%;box-sizing:border-box;background:#0b1220;color:#fff;border:1px solid #27496d;border-radius:8px;padding:10px;font-size:14px}
        textarea{min-height:96px;resize:vertical}
        button,.btn{display:inline-block;border:0;border-radius:8px;background:#2563eb;color:#fff;padding:10px 18px;text-decoration:none;cursor:pointer}
        .row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
        .msg{color:#facc15;margin:8px 0}
        .muted{color:#94a3b8;font-size:13px}
        .top{display:flex;justify-content:space-between;align-items:center;gap:12px}
        @media(max-width:720px){.row{grid-template-columns:1fr}.wrap{padding:14px}}
    </style>
</head>
<body>
<div class="wrap">
    <div class="top">
        <h1>ALin雷达 APK 远程管理</h1>
        <?php if ($logged): ?><a class="btn" href="?logout=1">退出</a><?php endif; ?>
    </div>
    <?php if ($msg): ?><div class="msg"><?=h($msg)?></div><?php endif; ?>

    <?php if (!$logged): ?>
        <form class="card" method="post">
            <label>后台密码</label>
            <input type="password" name="login_password" autofocus>
            <p><button type="submit">登录</button></p>
            <p class="muted">默认密码在 config.php 修改。</p>
        </form>
    <?php else: ?>
        <form method="post">
            <input type="hidden" name="save_config" value="1">

            <div class="card">
                <h2>远程更新</h2>
                <div class="row">
                    <div><label>版本号 version_code</label><input name="version_code" value="<?=h($data['update']['version_code'])?>"></div>
                    <div><label>版本名 version_name</label><input name="version_name" value="<?=h($data['update']['version_name'])?>"></div>
                </div>
                <label>新版 APK 下载地址</label>
                <input name="apk_url" value="<?=h($data['update']['apk_url'])?>" placeholder="https://.../ALin.apk">
                <label>更新标题</label>
                <input name="update_title" value="<?=h($data['update']['title'])?>">
                <label>更新说明</label>
                <textarea name="update_message"><?=h($data['update']['message'])?></textarea>
                <label><input style="width:auto" type="checkbox" name="force_update" value="1" <?=!empty($data['update']['force_update'])?'checked':''?>> 强制更新</label>
            </div>

            <div class="card">
                <h2>打开弹窗/公告</h2>
                <label><input style="width:auto" type="checkbox" name="popup_enabled" value="1" <?=!empty($data['popup']['enabled'])?'checked':''?>> 开启弹窗</label>
                <label>弹窗标题</label>
                <input name="popup_title" value="<?=h($data['popup']['title'])?>">
                <label>弹窗内容</label>
                <textarea name="popup_message"><?=h($data['popup']['message'])?></textarea>
                <label>弹窗按钮链接（可选，比如QQ群链接）</label>
                <input name="popup_url" value="<?=h($data['popup']['url'])?>">
            </div>

            <div class="card">
                <h2>APP 内链接</h2>
                <label>领取卡密链接</label>
                <input name="trial_url" value="<?=h($data['links']['trial_url'])?>">
                <label>购买卡密链接</label>
                <input name="buy_card_url" value="<?=h($data['links']['buy_card_url'])?>">
                <label>下载客户端链接</label>
                <input name="download_url" value="<?=h($data['links']['download_url'])?>">
            </div>

            <p><button type="submit">保存配置</button></p>
            <p class="muted">接口地址：/yuanma/api.php?action=config，更新时间：<?=h($data['updated_at'] ?? '-')?></p>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
