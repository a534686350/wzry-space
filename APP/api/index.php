<?php
/**
 * API 统一入口：通过 module 参数路由到 core 逻辑
 * 用法：api/index.php?module=card&action=list 或 POST 时 URL 带 module=card，body 带 action 等
 */
$module = isset($_GET['module']) ? trim($_GET['module']) : '';
if ($module === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $module = isset($input['module']) ? trim((string) $input['module']) : '';
}
if ($module === '') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => 400, 'msg' => '缺少 module 参数']);
    exit;
}
define('API_MODULE', $module);
// 容错：不同服务器部署路径可能被误改（出现 /api/api/core.php 之类），这里做多路径回退。
$coreCandidates = [
    __DIR__ . '/core.php',
    __DIR__ . '/api/core.php',                 // 若被错误加了一层 api/
    dirname(__DIR__) . '/api/core.php',        // 若 index.php 被放到更深层
];
$core = null;
foreach ($coreCandidates as $p) {
    if (is_file($p)) { $core = $p; break; }
}
if (!$core) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => 500, 'msg' => 'API core.php 不存在'], JSON_UNESCAPED_UNICODE);
    exit;
}
require $core;
