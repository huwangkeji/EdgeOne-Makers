<?php
/**
 * 图像检测接口
 *
 * POST /api/detect_image.php
 * 请求体(JSON):
 *   { "images": [ {"url":"https://...","name":"a.jpg"},
 *                 {"data":"<base64>","name":"b.png"} ] }
 * 响应:
 *   { "ok":true, "results":[ {ok:true,name,confidence,verdict,raw} | {ok:false,name,error} ] }
 *
 * 依赖后台「网站设置」中的 image_detect_url；未配置时返回明确提示。
 */

require_once __DIR__ . '/../lib/ImageClient.php';
require_once __DIR__ . '/../lib/Records.php';

ob_start(); // 捕获任何杂散输出，确保最终 JSON 纯净
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

$settings = tp_settings();
if (empty($settings['image_detect_url'])) {
    respond(['ok' => false, 'error' => '尚未配置图像检测接口：请到后台「网站设置」填写图像检测接口地址，否则请在前台关闭图像检测']);
}

$body = json_decode((string) file_get_contents('php://input'), true);
$images = (is_array($body) && !empty($body['images']) && is_array($body['images'])) ? $body['images'] : null;
if ($images === null) {
    respond(['ok' => false, 'error' => '请求缺少 images 数组']);
}

$max = max(1, (int) ($settings['image_detect_max'] ?? 5));
if (count($images) > $max) {
    $images = array_slice($images, 0, $max);
}

$client = new ImageClient();
$results = $client->detectMany($images, $max);

// 记录本次图像检测提交（按 IP；图像检测不消耗 Makers 文本额度）
$okCount = 0;
$firstPreview = '';
foreach ($results as $i => $r) {
    if (!empty($r['ok'])) {
        $okCount++;
    }
    if ($i === 0) {
        $firstPreview = (string) ($r['name'] ?? '');
    }
}
$failCount = count($results) - $okCount;
rec_append([
    'type'       => 'image',
    'item_count' => count($results),
    'text_len'   => 0,
    'preview'    => $firstPreview,
    'tokens'     => 0,
    'ok'         => $okCount > 0,
    'error'      => $failCount > 0 ? ('部分失败：' . $failCount . ' 张') : '',
]);

respond(['ok' => true, 'results' => $results, 'max' => $max]);