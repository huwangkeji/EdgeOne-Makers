<?php
/**
 * 前台 Token 贡献接口（公开）
 *
 * POST /api/contribute.php
 *   { "key": "Makers API Key", "name": "备注（可选）" }
 *
 * 用户按官方开发文档获取 Makers API Key 后在此提交；
 * 后端先做连通性测试，成功则自动加入 Token 池。
 * 是否开放由后台配置 token_contrib_enabled 控制。
 *
 * 防滥用限制：严格格式校验、同 IP 冷却、单 IP 配额、池子总量上限、幂等去重。
 */

require_once __DIR__ . '/../lib/ZhuqueClient.php';
require_once __DIR__ . '/../lib/Contrib.php';

ob_start(); // 捕获杂散输出，保证 JSON 纯净
header('Content-Type: application/json; charset=utf-8');

function respond(array $payload): void
{
    zq_flush_output();
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond(['ok' => false, 'error' => '仅支持 POST']);
}

$s = tp_settings();
if (empty($s['token_contrib_enabled'])) {
    respond(['ok' => false, 'error' => 'Token 贡献服务暂未开放，请在后台「运行设置」中开启']);
}

$ip = rec_client_ip();

// 1) 频率 / 配额限流（在真实消耗网络测试前先拦截，防刷）
$rate = ct_rate_check($ip);
if (!$rate['ok']) {
    respond(['ok' => false, 'error' => $rate['error']]);
}

$body = json_decode((string) file_get_contents('php://input'), true) ?: [];
$key = trim((string) ($body['key'] ?? ''));

// 2) 必填校验
if ($key === '') {
    respond(['ok' => false, 'error' => '请填写 Makers API Key']);
}

// 3) 严格格式校验：sk- + 48 位十六进制（样本格式）
if (!ct_valid_key($key)) {
    respond(['ok' => false, 'error' => 'Key 格式不合法：应为 sk- 开头的 48 位十六进制，请检查是否完整复制']);
}
$key = ct_normalize_key($key);

// 4) 池子总量上限
if (ct_pool_full()) {
    respond(['ok' => false, 'error' => 'Token 池已满（上限 ' . (int) CONTRIB_POOL_LIMIT . '），暂不接受新贡献']);
}

$name = trim((string) ($body['name'] ?? ''));
$name = $name === '' ? '贡献 Token' : (function_exists('mb_substr') ? mb_substr($name, 0, 30) : substr($name, 0, 30));

// 5) 连通性测试（同时校验 key 是否有效）
$client = new ZhuqueClient();
try {
    $client->testToken($key);
} catch (ZhuqueException $e) {
    respond(['ok' => false, 'error' => 'Token 测试失败，未加入池子：' . $e->getMessage()]);
}

// 6) 加入池子（tp_add 内部会做去重，重复 key 会被拒绝）
$res = tp_add($key, $name);
if (!$res['ok']) {
    respond(['ok' => false, 'error' => $res['msg']]);
}

// 7) 记录本次成功贡献，计入该 IP 的配额
ct_rate_commit($ip);

respond(['ok' => true, 'msg' => 'Token 已通过测试并加入池子', 'name' => $name, 'id' => $res['id']]);