<?php
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    exit;
}

$module = isset($_GET['module']) ? trim((string) $_GET['module']) : '';
if ($module === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw ?: '{}', true);
    if (is_array($input) && isset($input['module'])) {
        $module = trim((string) $input['module']);
    }
}

function api_json($payload, $status = 200)
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function api_read_json_file($relativePath, $fallback)
{
    $path = dirname(__DIR__) . '/' . ltrim($relativePath, '/');
    if (!is_file($path)) {
        return $fallback;
    }

    $raw = file_get_contents($path);
    $data = json_decode($raw ?: '', true);
    return is_array($data) ? $data : $fallback;
}

function api_default_game_server()
{
    $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
    $host = preg_replace('/:\d+$/', '', $host);
    if ($host === '') {
        $host = isset($_SERVER['SERVER_NAME']) ? (string) $_SERVER['SERVER_NAME'] : '127.0.0.1';
    }

    return array(
        'id' => 1,
        'name' => '默认服务器',
        'host' => $host . ':8888',
        'port' => 8888,
        'enabled' => 1,
    );
}

switch ($module) {
    case 'hero_list':
        api_json(array('code' => 0, 'msg' => 'ok', 'data' => api_read_json_file('herolist.json', array())));

    case 'summoner_list':
        api_json(array('code' => 0, 'msg' => 'ok', 'data' => api_read_json_file('summoner.json', array())));

    case 'game_servers':
        api_json(array('code' => 0, 'msg' => 'ok', 'data' => array(api_default_game_server())));

    case 'site_announcement':
        api_json(array('code' => 0, 'msg' => 'ok', 'data' => array('enabled' => 0)));

    case 'online_ip_count':
        api_json(array('code' => 0, 'msg' => 'ok', 'data' => array('total' => 0, 'ip_count' => 0)));

    case 'online_heartbeat':
    case 'client_online_heartbeat':
    case 'room_report':
        api_json(array('code' => 0, 'msg' => 'ok', 'data' => new stdClass()));

    case 'ip_username':
        api_json(array('code' => 0, 'msg' => 'ok', 'data' => array('username' => '')));

    case 'check_access':
        api_json(array('code' => 0, 'allowed' => true, 'msg' => 'ok'));

    case 'user_profile':
        api_json(array('code' => 0, 'msg' => 'ok', 'data' => new stdClass()));

    case 'user_logout':
        api_json(array('code' => 0, 'msg' => 'ok'));

    case 'share_token_generate':
        api_json(array('code' => 1, 'msg' => '卡密版未启用分享链接'));

    default:
        api_json(array('code' => 0, 'msg' => 'ok', 'data' => new stdClass()));
}
?>
