<?php
$adminSessionDir = __DIR__ . '/../data/php_sessions';
if (!is_dir($adminSessionDir)) {
    @mkdir($adminSessionDir, 0770, true);
}
if (is_dir($adminSessionDir) && is_writable($adminSessionDir)) {
    session_save_path($adminSessionDir);
}
session_start();
require_once __DIR__ . '/../api/auth_lib.php';

$message = '';
$error = '';

function admin_h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function admin_is_logged_in()
{
    return !empty($_SESSION['card_admin_login']);
}

function admin_redirect()
{
    header('Location: index.php');
    exit;
}

function admin_require_login()
{
    if (!admin_is_logged_in()) {
        admin_redirect();
    }
}

function admin_card_status_text($card)
{
    if (auth_is_card_expired($card)) {
        return '已到期';
    }
    return (isset($card['status']) && $card['status'] === 'disabled') ? '已禁用' : '正常';
}

if (isset($_GET['logout'])) {
    $_SESSION = array();
    session_destroy();
    admin_redirect();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_password'])) {
    if (hash_equals(AUTH_ADMIN_PASSWORD, (string) $_POST['login_password'])) {
        $_SESSION['card_admin_login'] = 1;
        admin_redirect();
    }
    $error = '后台密码错误';
}

if (admin_is_logged_in() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $cards = auth_get_cards();
    $action = $_POST['action'];

    if ($action === 'create') {
        $count = max(1, min(200, (int) (isset($_POST['count']) ? $_POST['count'] : 1)));
        $days = max(1, min(3650, (int) (isset($_POST['days']) ? $_POST['days'] : 30)));
        $prefix = strtoupper(trim((string) (isset($_POST['prefix']) ? $_POST['prefix'] : 'WZ')));
        $note = trim((string) (isset($_POST['note']) ? $_POST['note'] : ''));
        $created = array();

        for ($i = 0; $i < $count; $i++) {
            do {
                $key = auth_make_card_key($prefix);
            } while (auth_find_card_index($cards, $key) >= 0);

            $cards[] = array(
                'key' => $key,
                'status' => 'active',
                'note' => $note,
                'created_at' => auth_format_time(),
                'expire_at' => auth_format_time(auth_now() + $days * 86400),
                'used_at' => '',
                'last_login_at' => '',
                'last_ip' => '',
                'last_user_agent' => '',
            );
            $created[] = $key;
        }
        auth_save_cards($cards);
        $_SESSION['last_created_cards'] = $created;
        $message = '已生成 ' . count($created) . ' 张卡密';
    }

    if (in_array($action, array('disable', 'enable', 'delete', 'extend'), true)) {
        $key = strtoupper(trim((string) (isset($_POST['key']) ? $_POST['key'] : '')));
        $index = auth_find_card_index($cards, $key);
        if ($index < 0) {
            $error = '卡密不存在';
        } elseif ($action === 'delete') {
            array_splice($cards, $index, 1);
            auth_save_cards($cards);
            $message = '已删除卡密';
        } elseif ($action === 'disable') {
            $cards[$index]['status'] = 'disabled';
            auth_save_cards($cards);
            $message = '已禁用卡密';
        } elseif ($action === 'enable') {
            $cards[$index]['status'] = 'active';
            auth_save_cards($cards);
            $message = '已启用卡密';
        } elseif ($action === 'extend') {
            $days = max(1, min(3650, (int) (isset($_POST['days']) ? $_POST['days'] : 30)));
            $cardExpireAt = isset($cards[$index]['expire_at']) ? $cards[$index]['expire_at'] : '';
            $base = max(auth_now(), strtotime($cardExpireAt) ?: auth_now());
            $cards[$index]['expire_at'] = auth_format_time($base + $days * 86400);
            auth_save_cards($cards);
            $message = '已延长 ' . $days . ' 天';
        }
    }
}

$cards = auth_get_cards();
$keyword = trim((string) (isset($_GET['q']) ? $_GET['q'] : ''));
if ($keyword !== '') {
    $cards = array_values(array_filter($cards, function ($card) use ($keyword) {
        $key = isset($card['key']) ? $card['key'] : '';
        $note = isset($card['note']) ? $card['note'] : '';
        return stripos($key, $keyword) !== false || stripos($note, $keyword) !== false;
    }));
}
usort($cards, function ($a, $b) {
    $bCreated = isset($b['created_at']) ? $b['created_at'] : '';
    $aCreated = isset($a['created_at']) ? $a['created_at'] : '';
    return strcmp($bCreated, $aCreated);
});

$lastCreated = isset($_SESSION['last_created_cards']) && is_array($_SESSION['last_created_cards']) ? $_SESSION['last_created_cards'] : array();
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>卡密后台 - WZ雷达</title>
    <style>
        *{box-sizing:border-box}body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",sans-serif;background:#0d1422;color:#e8f0ff}a{color:#8bc2ff;text-decoration:none}.wrap{max-width:1180px;margin:0 auto;padding:24px}.top{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:18px}.top h1{margin:0;font-size:24px}.card{background:#151f31;border:1px solid rgba(100,160,255,.22);border-radius:10px;padding:18px;margin-bottom:16px;box-shadow:0 14px 40px rgba(0,0,0,.24)}.grid{display:grid;grid-template-columns:repeat(5,1fr);gap:12px}.grid .wide{grid-column:span 2}label{display:block;font-size:13px;color:#9fb2d2;margin-bottom:6px}input{width:100%;height:40px;border:1px solid rgba(120,170,255,.25);border-radius:8px;background:#0a101d;color:#fff;padding:0 11px;outline:none}button{height:40px;border:0;border-radius:8px;padding:0 14px;background:#348ee8;color:#fff;font-weight:700;cursor:pointer}.btn-muted{background:#26354e}.btn-danger{background:#b83c48}.btn-warn{background:#9b6a19}.msg{padding:10px 12px;border-radius:8px;margin-bottom:12px}.ok{background:rgba(22,183,119,.16);color:#a8f1ce}.err{background:rgba(255,80,80,.16);color:#ffadad}.toolbar{display:flex;gap:10px;align-items:flex-end}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;min-width:980px}th,td{padding:10px;border-bottom:1px solid rgba(255,255,255,.08);font-size:13px;text-align:left;vertical-align:top}th{color:#9fb2d2;font-weight:600;background:#111a2a}.key{font-family:Consolas,monospace;color:#dff0ff}.status{display:inline-block;padding:3px 8px;border-radius:999px;background:rgba(22,183,119,.16);color:#9ff0ca}.status.bad{background:rgba(255,80,80,.16);color:#ffadad}.actions{display:flex;gap:6px;flex-wrap:wrap}.actions form{display:inline-flex;gap:6px;align-items:center}.actions input{width:72px;height:32px}.actions button{height:32px;font-size:12px}.created textarea{width:100%;min-height:82px;border:1px solid rgba(120,170,255,.25);border-radius:8px;background:#0a101d;color:#dff0ff;padding:10px;font-family:Consolas,monospace}.login{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}.login .card{width:min(420px,100%)}@media(max-width:820px){.grid{grid-template-columns:1fr}.grid .wide{grid-column:auto}.top{display:block}.toolbar{display:block}.toolbar button{margin-top:8px;width:100%}}
    </style>
</head>
<body>
<?php if (!admin_is_logged_in()): ?>
    <main class="login">
        <section class="card">
            <h1>卡密后台登录</h1>
            <p style="color:#9fb2d2">默认密码在根目录 auth_config.php 修改。</p>
            <?php if ($error): ?><div class="msg err"><?php echo admin_h($error); ?></div><?php endif; ?>
            <form method="post">
                <label>后台密码</label>
                <input type="password" name="login_password" autofocus>
                <button style="width:100%;margin-top:12px">登录</button>
            </form>
        </section>
    </main>
<?php else: ?>
    <main class="wrap">
        <div class="top">
            <h1>WZ雷达卡密后台</h1>
            <div><a href="../index.html" target="_blank">打开前台</a> · <a href="?logout=1">退出</a></div>
        </div>

        <?php if ($message): ?><div class="msg ok"><?php echo admin_h($message); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="msg err"><?php echo admin_h($error); ?></div><?php endif; ?>

        <section class="card">
            <form method="post">
                <input type="hidden" name="action" value="create">
                <div class="grid">
                    <div>
                        <label>生成数量</label>
                        <input type="number" name="count" min="1" max="200" value="1">
                    </div>
                    <div>
                        <label>有效天数</label>
                        <input type="number" name="days" min="1" max="3650" value="30">
                    </div>
                    <div>
                        <label>卡密前缀</label>
                        <input name="prefix" value="WZ" maxlength="12">
                    </div>
                    <div class="wide">
                        <label>备注</label>
                        <input name="note" placeholder="例如：客户名、渠道、套餐">
                    </div>
                    <div style="display:flex;align-items:flex-end">
                        <button style="width:100%">生成卡密</button>
                    </div>
                </div>
            </form>
        </section>

        <?php if ($lastCreated): ?>
            <section class="card created">
                <label>本次生成结果</label>
                <textarea readonly onclick="this.select()"><?php echo admin_h(implode("\n", $lastCreated)); ?></textarea>
            </section>
        <?php endif; ?>

        <section class="card">
            <form class="toolbar" method="get">
                <div style="flex:1">
                    <label>搜索卡密 / 备注</label>
                    <input name="q" value="<?php echo admin_h($keyword); ?>" placeholder="输入关键字">
                </div>
                <button>搜索</button>
                <a class="btn-muted" style="height:40px;display:inline-flex;align-items:center;padding:0 14px;border-radius:8px;color:#fff" href="index.php">清空</a>
            </form>
        </section>

        <section class="card table-wrap">
            <table>
                <thead>
                <tr>
                    <th>卡密</th>
                    <th>状态</th>
                    <th>到期时间</th>
                    <th>备注</th>
                    <th>首次使用</th>
                    <th>最后登录</th>
                    <th>登录IP</th>
                    <th>操作</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$cards): ?>
                    <tr><td colspan="8" style="color:#9fb2d2">暂无卡密</td></tr>
                <?php endif; ?>
                <?php foreach ($cards as $card): $status = admin_card_status_text($card); ?>
                    <tr>
                        <td class="key"><?php echo admin_h(isset($card['key']) ? $card['key'] : ''); ?></td>
                        <td><span class="status <?php echo $status === '正常' ? '' : 'bad'; ?>"><?php echo admin_h($status); ?></span></td>
                        <td><?php echo admin_h(isset($card['expire_at']) ? $card['expire_at'] : ''); ?></td>
                        <td><?php echo admin_h(isset($card['note']) ? $card['note'] : ''); ?></td>
                        <td><?php echo admin_h(isset($card['used_at']) ? $card['used_at'] : ''); ?></td>
                        <td><?php echo admin_h(isset($card['last_login_at']) ? $card['last_login_at'] : ''); ?></td>
                        <td><?php echo admin_h(isset($card['last_ip']) ? $card['last_ip'] : ''); ?></td>
                        <td class="actions">
                            <form method="post">
                                <input type="hidden" name="key" value="<?php echo admin_h(isset($card['key']) ? $card['key'] : ''); ?>">
                                <input type="hidden" name="action" value="<?php echo ((isset($card['status']) ? $card['status'] : 'active') === 'disabled') ? 'enable' : 'disable'; ?>">
                                <button class="btn-warn"><?php echo ((isset($card['status']) ? $card['status'] : 'active') === 'disabled') ? '启用' : '禁用'; ?></button>
                            </form>
                            <form method="post">
                                <input type="hidden" name="key" value="<?php echo admin_h(isset($card['key']) ? $card['key'] : ''); ?>">
                                <input type="hidden" name="action" value="extend">
                                <input type="number" name="days" value="30" min="1" max="3650">
                                <button class="btn-muted">延长</button>
                            </form>
                            <form method="post" onsubmit="return confirm('确定删除这张卡密？')">
                                <input type="hidden" name="key" value="<?php echo admin_h(isset($card['key']) ? $card['key'] : ''); ?>">
                                <input type="hidden" name="action" value="delete">
                                <button class="btn-danger">删除</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    </main>
<?php endif; ?>
</body>
</html>
