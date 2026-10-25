<?php
/**
 * API Key 配置接口
 *
 * POST /api/config.php
 *   保存: { action: "save", api_key: "..." }
 *   回显是否有 Key: { action: "status" }
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/Auth.php';

ob_start(); // 捕获任何杂散输出，确保最终 JSON 纯净
header('Content-Type: application/json; charset=utf-8');
auth_require(true); // 保存/查询 API Key 属管理操作，需后台登录

function cfg_respond(array $payload, int $code = 200): void
{
    if ($code !== 200) {
        http_response_code($code);
    }
    zq_flush_output();
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    cfg_respond(['ok' => false, 'error' => '仅支持 POST'], 405);
}

$body = json_decode((string) file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';

if ($action === 'save') {
    $res = zhuque_save_api_key((string) ($body['api_key'] ?? ''));
    cfg_respond($res);
}

if ($action === 'status') {
    cfg_respond(['ok' => true, 'has_key' => zhuque_get_api_key() !== '']);
}

cfg_respond(['ok' => false, 'error' => '未知操作']);