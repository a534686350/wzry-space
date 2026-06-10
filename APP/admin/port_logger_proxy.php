<?php
/**
 * 端口日志监控 API 代理
 * 用于在 dashboard.php 中通过 PHP 后端代理 8899 端口的 API 请求，避免跨域问题
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

$action = $_GET['action'] ?? '';
$hostname = $_SERVER['HTTP_HOST'] ?? 'localhost';
$port = 8899;

// 解析主机名（去除端口号）
if (strpos($hostname, ':') !== false) {
    $hostname = explode(':', $hostname)[0];
}

$base_url = "http://{$hostname}:{$port}";

function proxyRequest($url, $method = 'GET', $data = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
            ]);
        }
    }
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        http_response_code(500);
        echo json_encode(['error' => '代理请求失败: ' . $error, 'success' => false], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    http_response_code($http_code);
    echo $response;
}

try {
    switch ($action) {
        case 'logs':
            proxyRequest($base_url . '/api/logs', 'GET');
            break;
            
        case 'blocked':
            proxyRequest($base_url . '/api/blocked', 'GET');
            break;
            
        case 'block-history':
            proxyRequest($base_url . '/api/block-history', 'GET');
            break;
            
        case 'geo':
            $ip = $_GET['ip'] ?? '';
            if ($ip) {
                proxyRequest($base_url . '/api/geo?ip=' . urlencode($ip), 'GET');
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'IP address required'], JSON_UNESCAPED_UNICODE);
            }
            break;
            
        case 'block':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input || !isset($input['ip'])) {
                http_response_code(400);
                echo json_encode(['error' => 'IP address required'], JSON_UNESCAPED_UNICODE);
                break;
            }
            proxyRequest($base_url . '/api/block', 'POST', $input);
            break;
            
        case 'unblock':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input || !isset($input['ip'])) {
                http_response_code(400);
                echo json_encode(['error' => 'IP address required'], JSON_UNESCAPED_UNICODE);
                break;
            }
            proxyRequest($base_url . '/api/unblock', 'POST', $input);
            break;
            
        case 'clear-logs':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
                break;
            }
            proxyRequest($base_url . '/api/clear-logs', 'POST');
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action'], JSON_UNESCAPED_UNICODE);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage(), 'success' => false], JSON_UNESCAPED_UNICODE);
}
