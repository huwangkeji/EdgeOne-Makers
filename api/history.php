<?php
/**
 * 历史记录接口
 *
 * GET  /api/history.php  -> { ok:true, list:[...] }
 * POST /api/history.php  (action=clear) -> { ok:true }
 */

require_once __DIR__ . '/../lib/History.php';
require_once __DIR__ . '/../lib/Auth.php';

ob_start(); // 捕获任何杂散输出，确保最终 JSON 纯净
header('Content-Type: application/json; charset=utf-8');

function ph_respond(array $payload, int $code = 200): void
{
    if ($code !== 200) {
        http_response_code($code);
    }
    zq_flush_output();
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $list = zhuque_history_all();
    ph_respond(['ok' => true, 'list' => $list]);
}

if ($method === 'POST') {
    $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
    if (($body['action'] ?? '') === 'clear') {
        auth_require(true); // 清空历史为管理操作，需后台登录，防匿名恶意清空
        zhuque_history_clear();
        ph_respond(['ok' => true, 'msg' => '历史记录已清空']);
    }
}

ph_respond(['ok' => false, 'error' => '不支持的请求'], 405);