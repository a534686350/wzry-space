<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

$cfg = require __DIR__ . '/config.php';
$dataFile = $cfg['data_file'];

function json_out($code, $msg, $data = null) {
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
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
    if (!is_file($file)) {
        return default_config();
    }
    $raw = file_get_contents($file);
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return default_config();
    }
    return array_replace_recursive(default_config(), $data);
}

$action = isset($_GET['action']) ? trim($_GET['action']) : 'config';
if ($action !== 'config') {
    json_out(404, 'unknown action');
}

json_out(0, 'ok', read_config($dataFile));
