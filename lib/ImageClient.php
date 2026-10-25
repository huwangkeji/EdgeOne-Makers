<?php
/**
 * 图像检测客户端
 *
 * 说明：当前官方 EdgeOne Makers 网关仅支持文本检测，图像检测需要一个
 * 支持图像的能力接口。本客户端将图片以 URL 或 base64 形式 POST 到后台
 * 「网站设置」中配置的 图像检测接口地址（image_detect_url）。
 *
 * 请求格式（发给目标接口）：
 *   { "image_url": "...", "name": "x.png" }    或  { "image_base64": "...", "name": "x.png" }
 * 返回解析：尽量从响应中提取 AI 置信度，解析失败则原样返回 raw。
 */

require_once __DIR__ . '/TokenPool.php';

class ImageException extends Exception
{
}

final class ImageClient
{
    private string $endpoint;
    private int $timeout;

    public function __construct(?string $endpoint = null, int $timeout = 30)
    {
        $s = tp_settings();
        $this->endpoint = $endpoint ?? (string) ($s['image_detect_url'] ?? '');
        $this->timeout  = $timeout;
    }

    public function hasEndpoint(): bool
    {
        return $this->endpoint !== '';
    }

    /**
     * 检测单张图片
     * @param array $img [url|data, name]
     * @return array {ok:bool, name:string, confidence:?float, verdict:string, raw:mixed}
     * @throws ImageException
     */
    public function detectOne(array $img): array
    {
        if (!$this->hasEndpoint()) {
            throw new ImageException('未配置图像检测接口地址');
        }
        if (!function_exists('curl_init')) {
            throw new ImageException('当前 PHP 环境未启用 cURL 扩展（php-curl），请安装启用后重试');
        }
        $name = (string) ($img['name'] ?? '图片');
        $payload = ['name' => $name];

        if (!empty($img['url'])) {
            if (!filter_var($img['url'], FILTER_VALIDATE_URL)) {
                throw new ImageException('图片地址无效');
            }
            $payload['image_url'] = $img['url'];
        } elseif (!empty($img['data'])) {
            $payload['image_base64'] = $img['data'];
        } else {
            throw new ImageException('缺少图片内容（url 或 data）');
        }

        $r = zq_curl_post(
            $this->endpoint,
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            ['Content-Type: application/json', 'Accept: application/json'],
            $this->timeout
        );

        if ($r['errno'] !== 0) {
            $tag = $r['insecure_retry'] ? '（已在不校验证书模式下自动重试）' : '';
            throw new ImageException('网络请求失败（' . $r['error'] . '）' . $tag);
        }

        $data = json_decode($r['raw'], true);
        if (!is_array($data)) {
            throw new ImageException('无法解析图像检测响应（HTTP ' . $r['http'] . '）');
        }

        $conf = $this->extractConfidence($data);
        return [
            'ok'         => true,
            'name'       => $name,
            'confidence' => $conf,
            'verdict'    => $this->verdict($conf),
            'raw'        => $data,
        ];
    }

    /**
     * 从响应中启发式提取 AI 置信度（0~1 或 0~100 会归一化到 0~1）。
     * 若找不到则返回 null。
     */
    private function extractConfidence(array $data, int $depth = 0): ?float
    {
        if ($depth > 4) {
            return null;
        }
        $keys = ['ai_confidence', 'ai_prob', 'ai_score', 'confidence', 'prob', 'probability',
                 'ai_wm', 'ai_percent', 'percent', 'score', 'label_score', 'fake'];
        foreach ($keys as $k) {
            if (array_key_exists($k, $data)) {
                $v = $data[$k];
                if (is_numeric($v)) {
                    return $this->normalize($v);
                }
                if (is_array($v)) {
                    foreach ($v as $vv) {
                        if (is_numeric($vv)) {
                            return $this->normalize($vv);
                        }
                    }
                }
            }
        }
        // 递归进对象数组
        foreach ($data as $v) {
            if (is_array($v)) {
                $r = $this->extractConfidence($v, $depth + 1);
                if ($r !== null) {
                    return $r;
                }
            }
        }
        return null;
    }

    /** 归一化到 0~1 */
    private function normalize($v): float
    {
        $v = (float) $v;
        if ($v > 1) {
            $v = $v / 100.0;
        }
        return max(0.0, min(1.0, $v));
    }

    private function verdict(?float $conf): string
    {
        if ($conf === null) {
            return '未知';
        }
        if ($conf < 0.5) {
            return '倾向人工创作';
        }
        if ($conf < 0.7) {
            return '疑似 AI 生成';
        }
        return '高度疑似 AI 生成';
    }

    /**
     * 批量检测多张图片
     */
    public function detectMany(array $images, int $max = 5): array
    {
        $results = [];
        $count = 0;
        foreach ($images as $img) {
            if (!is_array($img)) {
                continue;
            }
            if ($count >= $max) {
                break;
            }
            $count++;
            try {
                $results[] = $this->detectOne($img);
            } catch (ImageException $e) {
                $results[] = [
                    'ok' => false, 'name' => (string) ($img['name'] ?? '图片'),
                    'error' => $e->getMessage(),
                ];
            }
        }
        return $results;
    }
}