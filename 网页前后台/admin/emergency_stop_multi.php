<?php
require_once __DIR__ . '/../auth/bootstrap.php';
requireLogin('dashboard.php');

// 数据库：站点维度紧急停止开关
$pdo->exec('CREATE TABLE IF NOT EXISTS emergency_stop_sites (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    host VARCHAR(255) NOT NULL UNIQUE,
    name VARCHAR(128) DEFAULT NULL,
    active TINYINT NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

function normalizeHost($host) {
    $host = preg_replace('/:\d+$/', '', trim((string)$host));
    return $host !== '' ? $host : '';
}

$msg = '';
$err = '';

$action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';

if ($action === 'set') {
    $host = normalizeHost($_POST['host'] ?? '');
    $active = isset($_POST['active']) ? ((int)$_POST['active'] === 1 ? 1 : 0) : 0;
    if ($host === '') {
        $err = 'host 不能为空';
    } else {
        try {
            $stmt = $pdo->prepare('SELECT id FROM emergency_stop_sites WHERE host = ? LIMIT 1');
            $stmt->execute([$host]);
            $row = $stmt->fetch();
            if ($row) {
                $stmt = $pdo->prepare('UPDATE emergency_stop_sites SET active = ? WHERE host = ?');
                $stmt->execute([$active, $host]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO emergency_stop_sites (host, name, active) VALUES (?, ?, ?)');
                $stmt->execute([$host, $host, $active]);
            }
            adminLog($pdo, 'emergency_stop_multi_set', 'host=' . $host . ', active=' . $active);
            $msg = '已更新：' . htmlspecialchars($host, ENT_QUOTES, 'UTF-8');
        } catch (Exception $e) {
            $err = '更新失败：' . $e->getMessage();
        }
    }
}

if ($action === 'add') {
    $host = normalizeHost($_POST['host'] ?? '');
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') $name = $host;
    if ($host === '') {
        $err = 'host 不能为空';
    } else {
        try {
            $stmt = $pdo->prepare('INSERT IGNORE INTO emergency_stop_sites (host, name, active) VALUES (?, ?, 0)');
            $stmt->execute([$host, $name]);
            adminLog($pdo, 'emergency_stop_multi_add', 'host=' . $host . ', name=' . $name);
            $msg = '已添加站点：' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        } catch (Exception $e) {
            $err = '添加失败：' . $e->getMessage();
        }
    }
}

if ($action === 'delete') {
    $host = normalizeHost($_POST['host'] ?? '');
    if ($host === '') {
        $err = 'host 不能为空';
    } else {
        try {
            $stmt = $pdo->prepare('DELETE FROM emergency_stop_sites WHERE host = ?');
            $stmt->execute([$host]);
            adminLog($pdo, 'emergency_stop_multi_delete', 'host=' . $host);
            $msg = '已删除站点记录：' . htmlspecialchars($host, ENT_QUOTES, 'UTF-8');
        } catch (Exception $e) {
            $err = '删除失败：' . $e->getMessage();
        }
    }
}

// 读取列表
$sites = [];
try {
    $stmt = $pdo->query('SELECT host, name, active, updated_at FROM emergency_stop_sites ORDER BY id DESC');
    $sites = $stmt->fetchAll();
} catch (Exception $e) {
    $sites = [];
}

$currentHost = normalizeHost($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>紧急停止控制 - 多站点</title>
    <style>
        body { font-family: "Microsoft YaHei", sans-serif; background: #000; color: #f9fafb; padding: 20px; }
        .box { max-width: 980px; margin: 0 auto; }
        .topbar { display:flex; justify-content: space-between; align-items: center; margin-bottom: 16px; gap: 10px; flex-wrap: wrap; }
        .topbar h1 { font-size: 22px; color: #4a9eff; }
        a { color: #4a9eff; text-decoration: none; }
        .msg { margin: 12px 0; padding: 10px 12px; border-radius: 10px; background: rgba(74,158,255,0.15); border: 1px solid rgba(74,158,255,0.35); }
        .err { background: rgba(224,49,49,0.15); border-color: rgba(224,49,49,0.35); }
        table { width: 100%; border-collapse: collapse; background: rgba(47,54,60,0.6); border-radius: 12px; overflow: hidden; }
        th, td { padding: 12px 14px; border-bottom: 1px solid rgba(255,255,255,0.06); text-align: left; font-size: 14px; }
        th { background: rgba(74,158,255,0.15); color: #4a9eff; font-weight: 600; }
        tr:hover td { background: rgba(255,255,255,0.03); }
        .host { font-family: ui-monospace, monospace; }
        .badge { display:inline-block; padding: 4px 10px; border-radius: 999px; font-size: 12px; border: 1px solid rgba(148,163,206,0.35); color: rgba(229,231,235,0.9); }
        .badge.on { border-color: rgba(239,68,68,0.5); color: #ff6b6b; }
        .badge.off { border-color: rgba(81,207,102,0.5); color: #51cf66; }
        .form-row { display:flex; gap: 10px; flex-wrap: wrap; margin: 14px 0; }
        .form-row input { padding: 10px 12px; border-radius: 8px; border: 1px solid rgba(74,158,255,0.3); background: rgba(26,31,36,0.8); color: #fff; }
        .btn { padding: 10px 16px; border-radius: 10px; border: none; cursor: pointer; font-size: 14px; font-weight: 700; }
        .btn-danger { background: #e03131; color:#fff; }
        .btn-primary { background: linear-gradient(135deg, #4a9eff 0%, #258DF2 100%); color:#fff; }
        .btn-secondary { background: rgba(74,158,255,0.2); color:#74c0fc; border:1px solid rgba(74,158,255,0.4); }
        .btn-secondary:hover { background: rgba(74,158,255,0.3); color:#fff; }
        .btn-danger:hover { filter: brightness(1.03); }
        .small { font-size: 12px; color: rgba(156,163,175,0.95); line-height: 1.7; margin: 8px 0 0; }
    </style>
</head>
<body>
<div class="box">
    <div class="topbar">
        <h1>紧急停止控制（多站点）</h1>
        <div>
            <a href="dashboard.php">返回控制面板</a>
        </div>
    </div>

    <?php if ($msg !== ''): ?>
        <div class="msg"><?php echo $msg; ?></div>
    <?php endif; ?>
    <?php if ($err !== ''): ?>
        <div class="msg err"><?php echo htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <div class="small">
        当前请求 host：<span class="host" style="color:#74c0fc;"><?php echo htmlspecialchars($currentHost, ENT_QUOTES, 'UTF-8'); ?></span><br>
        停机逻辑：主站 `index.php` / API `api/core.php` 会按当前 host 在数据库读取对应状态。
    </div>

    <h2 style="margin: 18px 0 10px; font-size: 16px; color: #94a3b8;">添加站点（建议填 3 个域名）</h2>
    <form method="post">
        <div class="form-row">
            <input type="hidden" name="action" value="add">
            <input type="text" name="name" placeholder="站点名称（可选）" style="min-width: 220px;">
            <input type="text" name="host" placeholder="host（例如 example.com，不要带端口）" style="min-width: 320px;">
            <button type="submit" class="btn btn-primary">添加</button>
        </div>
    </form>

    <h2 style="margin: 22px 0 10px; font-size: 16px; color: #94a3b8;">站点列表与控制</h2>
    <table>
        <thead>
            <tr>
                <th style="width: 220px;">名称</th>
                <th style="width: 280px;">Host</th>
                <th style="width: 140px;">状态</th>
                <th style="width: 260px;">操作</th>
            </tr>
        </thead>
        <tbody>
        <?php if (count($sites) === 0): ?>
            <tr><td colspan="4" style="text-align:center; color:#9ca3af;">暂无站点配置，请先添加。</td></tr>
        <?php endif; ?>
        <?php foreach ($sites as $s): ?>
            <?php
                $host = (string)$s['host'];
                $name = (string)($s['name'] ?: $s['host']);
                $active = (int)$s['active'];
                $isCurrent = ($currentHost !== '' && $host === $currentHost);
            ?>
            <tr>
                <td><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?><?php echo $isCurrent ? '（当前）' : ''; ?></td>
                <td class="host"><?php echo htmlspecialchars($host, ENT_QUOTES, 'UTF-8'); ?></td>
                <td>
                    <?php if ($active === 1): ?>
                        <span class="badge on">已停机</span>
                    <?php else: ?>
                        <span class="badge off">运行中</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div style="display:flex; gap:10px; flex-wrap:wrap;">
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="action" value="set">
                            <input type="hidden" name="host" value="<?php echo htmlspecialchars($host, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="active" value="<?php echo $active === 1 ? 0 : 1; ?>">
                            <button type="submit" class="btn <?php echo $active === 1 ? 'btn-secondary' : 'btn-danger'; ?>"
                                onclick="return confirm(<?php echo json_encode($active === 1 ? '确认恢复该站点？' : '确认停机该站点？', JSON_UNESCAPED_UNICODE); ?>);">
                                <?php echo $active === 1 ? '恢复' : '停机'; ?>
                            </button>
                        </form>

                        <form method="post" style="display:inline;">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="host" value="<?php echo htmlspecialchars($host, ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit" class="btn btn-secondary"
                                onclick="return confirm(<?php echo json_encode('确认删除该站点记录？删除后将不再受停机控制（等同于恢复运行）。', JSON_UNESCAPED_UNICODE); ?>);">
                                删除
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
</body>
</html>

