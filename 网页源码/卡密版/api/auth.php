<?php
require_once __DIR__ . '/auth_lib.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : '';
$input = array();
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw ?: '{}', true);
    if (!is_array($input)) {
        $input = $_POST;
    }
    if (!$action && isset($input['action'])) {
        $action = $input['action'];
    }
}

function auth_get_bearer_token($input)
{
    if (!empty($input['token'])) {
        return trim($input['token']);
    }
    $header = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $header = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    if (preg_match('/Bearer\s+(.+)/i', $header, $m)) {
        return trim($m[1]);
    }
    return '';
}

function auth_validate_token($token)
{
    if ($token === '') {
        return array(false, null, null);
    }

    $sessions = auth_get_sessions();
    if (empty($sessions[$token])) {
        return array(false, null, null);
    }

    $session = $sessions[$token];
    if (empty($session['expires_at']) || strtotime($session['expires_at']) < auth_now()) {
        unset($sessions[$token]);
        auth_save_sessions($sessions);
        return array(false, null, null);
    }

    $cards = auth_get_cards();
    $index = auth_find_card_index($cards, isset($session['card_key']) ? $session['card_key'] : '');
    if ($index < 0) {
        return array(false, null, null);
    }

    $card = $cards[$index];
    if ((isset($card['status']) ? $card['status'] : 'active') !== 'active' || auth_is_card_expired($card)) {
        return array(false, null, null);
    }

    return array(true, $card, $session);
}

if ($action === 'login') {
    if ($method !== 'POST') {
        auth_json_response(array('ok' => false, 'message' => 'Method not allowed'), 405);
    }

    $key = isset($input['key']) ? strtoupper(trim($input['key'])) : '';
    if ($key === '') {
        auth_json_response(array('ok' => false, 'message' => '请输入卡密'), 400);
    }

    $cards = auth_get_cards();
    $index = auth_find_card_index($cards, $key);
    if ($index < 0) {
        auth_json_response(array('ok' => false, 'message' => '卡密不存在'), 401);
    }

    $card = $cards[$index];
    if ((isset($card['status']) ? $card['status'] : 'active') !== 'active') {
        auth_json_response(array('ok' => false, 'message' => '卡密已被禁用'), 403);
    }
    if (auth_is_card_expired($card)) {
        auth_json_response(array('ok' => false, 'message' => '卡密已到期'), 403);
    }

    $nowText = auth_format_time();
    if (empty($card['used_at'])) {
        $card['used_at'] = $nowText;
    }
    $card['last_login_at'] = $nowText;
    $card['last_ip'] = auth_client_ip();
    $card['last_user_agent'] = auth_user_agent();
    $cards[$index] = $card;
    auth_save_cards($cards);

    $token = auth_make_token();
    $expireTs = min(strtotime($card['expire_at']), auth_now() + AUTH_SESSION_TTL);
    $sessions = auth_get_sessions();
    $sessions[$token] = array(
        'card_key' => $card['key'],
        'created_at' => $nowText,
        'expires_at' => auth_format_time($expireTs),
        'ip' => auth_client_ip(),
        'user_agent' => auth_user_agent(),
    );
    auth_save_sessions($sessions);

    auth_json_response(array('ok' => true, 'token' => $token, 'card' => auth_card_public($card)));
}

if ($action === 'me') {
    list($ok, $card) = auth_validate_token(auth_get_bearer_token($input));
    if (!$ok) {
        auth_json_response(array('ok' => false, 'message' => '请先登录'), 401);
    }
    auth_json_response(array('ok' => true, 'card' => auth_card_public($card)));
}

if ($action === 'logout') {
    $token = auth_get_bearer_token($input);
    if ($token !== '') {
        $sessions = auth_get_sessions();
        unset($sessions[$token]);
        auth_save_sessions($sessions);
    }
    auth_json_response(array('ok' => true));
}

auth_json_response(array('ok' => false, 'message' => 'Unknown action'), 404);
?>
