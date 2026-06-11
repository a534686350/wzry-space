<?php
require_once __DIR__ . '/../auth_config.php';

if (!function_exists('hash_equals')) {
    function hash_equals($known, $user)
    {
        $known = (string) $known;
        $user = (string) $user;
        if (strlen($known) !== strlen($user)) {
            return false;
        }
        $result = 0;
        for ($i = 0; $i < strlen($known); $i++) {
            $result |= ord($known[$i]) ^ ord($user[$i]);
        }
        return $result === 0;
    }
}

function auth_now()
{
    return time();
}

function auth_json_response($payload, $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function auth_ensure_files()
{
    if (!is_dir(AUTH_DATA_DIR)) {
        mkdir(AUTH_DATA_DIR, 0755, true);
    }
    if (!file_exists(AUTH_CARDS_FILE)) {
        file_put_contents(AUTH_CARDS_FILE, AUTH_DATA_PREFIX . json_encode(array('cards' => array()), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    if (!file_exists(AUTH_SESSIONS_FILE)) {
        file_put_contents(AUTH_SESSIONS_FILE, AUTH_DATA_PREFIX . json_encode(array('sessions' => (object) array()), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}

function auth_read_store($file, $default)
{
    auth_ensure_files();
    $raw = @file_get_contents($file);
    $raw = is_string($raw) ? $raw : '';
    if (strpos($raw, AUTH_DATA_PREFIX) === 0) {
        $raw = substr($raw, strlen(AUTH_DATA_PREFIX));
    }
    $data = json_decode($raw ?: '', true);
    return is_array($data) ? $data : $default;
}

function auth_write_store($file, $data)
{
    auth_ensure_files();
    $json = AUTH_DATA_PREFIX . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $fp = fopen($file, 'c+');
    if (!$fp) {
        return false;
    }
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, $json);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

function auth_get_cards()
{
    $store = auth_read_store(AUTH_CARDS_FILE, array('cards' => array()));
    return isset($store['cards']) && is_array($store['cards']) ? $store['cards'] : array();
}

function auth_save_cards($cards)
{
    return auth_write_store(AUTH_CARDS_FILE, array('cards' => array_values($cards)));
}

function auth_get_sessions()
{
    $store = auth_read_store(AUTH_SESSIONS_FILE, array('sessions' => array()));
    return isset($store['sessions']) && is_array($store['sessions']) ? $store['sessions'] : array();
}

function auth_save_sessions($sessions)
{
    return auth_write_store(AUTH_SESSIONS_FILE, array('sessions' => (object) $sessions));
}

function auth_client_ip()
{
    foreach (array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR') as $key) {
        if (!empty($_SERVER[$key])) {
            $value = explode(',', $_SERVER[$key])[0];
            return trim($value);
        }
    }
    return '';
}

function auth_user_agent()
{
    return isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : '';
}

function auth_find_card_index($cards, $key)
{
    foreach ($cards as $index => $card) {
        if (isset($card['key']) && hash_equals((string) $card['key'], (string) $key)) {
            return $index;
        }
    }
    return -1;
}

function auth_is_card_expired($card)
{
    return empty($card['expire_at']) || strtotime($card['expire_at']) < auth_now();
}

function auth_card_public($card)
{
    $expireAt = isset($card['expire_at']) ? $card['expire_at'] : '';
    $expireTs = $expireAt ? strtotime($expireAt) : 0;
    $remaining = max(0, $expireTs - auth_now());

    return array(
        'key' => isset($card['key']) ? $card['key'] : '',
        'status' => isset($card['status']) ? $card['status'] : 'active',
        'note' => isset($card['note']) ? $card['note'] : '',
        'created_at' => isset($card['created_at']) ? $card['created_at'] : '',
        'expire_at' => $expireAt,
        'used_at' => isset($card['used_at']) ? $card['used_at'] : '',
        'last_login_at' => isset($card['last_login_at']) ? $card['last_login_at'] : '',
        'last_ip' => isset($card['last_ip']) ? $card['last_ip'] : '',
        'remaining_seconds' => $remaining,
        'remaining_days' => (int) ceil($remaining / 86400),
    );
}

function auth_make_token()
{
    if (function_exists('random_bytes')) {
        return bin2hex(random_bytes(32));
    }
    return sha1(uniqid('', true) . mt_rand());
}

function auth_make_card_key($prefix = 'WZ')
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $parts = array();
    for ($g = 0; $g < 4; $g++) {
        $part = '';
        for ($i = 0; $i < 4; $i++) {
            $part .= $alphabet[mt_rand(0, strlen($alphabet) - 1)];
        }
        $parts[] = $part;
    }
    $prefix = preg_replace('/[^A-Z0-9]/', '', strtoupper($prefix));
    return ($prefix ?: 'WZ') . '-' . implode('-', $parts);
}

function auth_format_time($timestamp = null)
{
    return date('Y-m-d H:i:s', $timestamp === null ? auth_now() : $timestamp);
}
?>
