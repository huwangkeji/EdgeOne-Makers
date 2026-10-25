<?php
/**
 * 检测接口
 *
 * POST /api/detect.php
 * 请求体(JSON):
 *   {
 *     "text": "单条文本",            // 与 texts 二选一(优先 texts)
 *     "texts": ["文本1","文本2"],    // 批量检测
 *     "is_merge": true               // 可选, 默认 true
 *   }
 * 响应:
 *   成功 { ok:true, data:{...} } / 批量 { ok:true, results:[...], errors:[...] }
 *   失败 { ok:false, error:"..." }
 */

require_once __DIR__ . '/../lib/ZhuqueClient.php';
require_once __DIR__ . '/../lib/History.php';
require_once __DIR__ . '/../lib/Records.php';

ob_start(); // 捕获任何杂散输出，确保最终 JSON 纯净
header('Content-Type: application/json; charset=utf-8');

function respond(array $payload): void
{
    zq_flush_output();
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** 生成文本预览：压缩空白并截断 */
function zq_preview(string $text, int $len = 60): string
{
    $t = trim((string) preg_replace('/\s+/u', ' ', $text));
    return function_exists('mb_substr') ? mb_substr($t, 0, $len) : substr($t, 0, $len);
}

/** 字符长度（无 mbstring 时退化为字节数） */
function zq_strlen(string $text): int
{
    return function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
}

// 仅允许 POST
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond(['ok' => false, 'error' => '仅支持 POST 请求']);
}

$rawBody = file_get_contents('php://input');
$body = json_decode((string) $rawBody, true);
if (!is_array($body)) {
    respond(['ok' => false, 'error' => '请求体不是有效的 JSON']);
}

$isMerge = isset($body['is_merge']) ? (bool) $body['is_merge'] : (bool) tp_settings()['default_is_merge'];

$client = new ZhuqueClient();

// Token 池可用性校验
if (!$client->hasAvailableToken()) {
    respond(['ok' => false, 'error' => '还没有可用的 Token：请到「后台管理」添加，或检查是否有 Token 处于冷却期']);
}

// 批量检测优先
if (!empty($body['texts']) && is_array($body['texts'])) {
    $texts = array_values(array_filter((array) $body['texts'], function ($t) {
        return is_string($t) && trim($t) !== '';
    }));
    if (count($texts) === 0) {
        respond(['ok' => false, 'error' => '待检测文本不能为空']);
    }
    $out = $client->detectBatch($texts, $isMerge);

    // 记录本次批量提交（按 IP + 消耗 token 汇总）
    $sumTokens = 0;
    foreach ($out['results'] as $r) {
        $sumTokens += (int) ($r['detect']['makers_models_usage']['total_tokens'] ?? 0);
    }
    $sumLen = 0;
    foreach ($texts as $t) {
        $sumLen += zq_strlen((string) $t);
    }
    rec_append([
        'type'       => 'batch',
        'item_count' => count($texts),
        'text_len'   => $sumLen,
        'preview'    => zq_preview((string) $texts[0]),
        'is_merge'   => $isMerge,
        'tokens'     => $sumTokens,
        'token_name' => $client->lastUsedTokenName,
        'ok'         => count($out['results']) > 0,
        'error'      => count($out['errors']) > 0 ? ('部分失败：' . count($out['errors']) . ' 条') : '',
    ]);

    // 批量模式不写历史（由前端决定）
    respond(['ok' => true, 'batch' => true, 'used_token' => $client->lastUsedTokenName, 'results' => $out['results'], 'errors' => $out['errors']]);
}

// 单条检测
$text = isset($body['text']) ? trim((string) $body['text']) : '';
if ($text === '') {
    respond(['ok' => false, 'error' => '待检测文本不能为空']);
}

try {
    $data  = $client->detect($text, $isMerge);
    $entry = zhuque_build_history_entry($text, $data, $isMerge);
    zhuque_history_append($entry);

    // 记录本次提交（按 IP + 消耗 token）
    rec_append([
        'type'       => 'text',
        'item_count' => 1,
        'text_len'   => zq_strlen($text),
        'preview'    => zq_preview($text),
        'is_merge'   => $isMerge,
        'verdict'    => (string) ($entry['verdict'] ?? ''),
        'softmax'    => (float) ($data['softmax_confidence'] ?? 0),
        'ratio'      => (float) ($data['ratio_confidence'] ?? 0),
        'tokens'     => (int) ($data['makers_models_usage']['total_tokens'] ?? 0),
        'token_name' => $client->lastUsedTokenName,
        'ok'         => true,
    ]);

    respond(['ok' => true, 'data' => $data, 'entry' => $entry, 'used_token' => $client->lastUsedTokenName]);
} catch (ZhuqueException $e) {
    // 记录失败提交，便于排查
    rec_append([
        'type'       => 'text',
        'item_count' => 1,
        'text_len'   => zq_strlen($text),
        'preview'    => zq_preview($text),
        'is_merge'   => $isMerge,
        'token_name' => $client->lastUsedTokenName,
        'ok'         => false,
        'error'      => $e->getMessage(),
    ]);
    respond(['ok' => false, 'error' => $e->getMessage()]);
}