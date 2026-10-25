<?php
/**
 * 云雀（Zhuque）AI 检测客户端
 *
 * 支持多 Token 池：在调用前自动轮询选取可用 token，当遇到鉴权/限流类错误
 * （HTTP 401 / 403 / 403 / 429）时自动切换到下一个 token 重试，直到成功或
 * 所有 token 均不可用。业务类错误（status != success）直接抛出不重试。
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/TokenPool.php';

class ZhuqueException extends Exception
{
}

/** 鉴权/限流类异常：需要切换 token */
class ZhuqueAuthException extends ZhuqueException
{
    public int $httpCode;

    public function __construct(string $message, int $httpCode = 0)
    {
        parent::__construct($message);
        $this->httpCode = $httpCode;
    }
}

/** 额度不足/余额不足类异常：应停止使用该 token 并禁用 */
class ZhuqueQuotaException extends ZhuqueException
{
}

final class ZhuqueClient
{
    private string $apiUrl;
    private int $timeout;

    /** 最近一次实际使用的 token 名称（供前台展示） */
    public string $lastUsedTokenName = '';

    public function __construct(?string $apiUrl = null, int $timeout = 0)
    {
        $this->apiUrl = $apiUrl ?? ZHUQUE_API_URL;
        $this->timeout = $timeout > 0 ? $timeout : ZHUQUE_TIMEOUT;
    }

    /** 是否有可用 token */
    public function hasAvailableToken(): bool
    {
        return count(tp_available()) > 0;
    }

    /**
     * 检测单条文本（自动换 token 重试）
     *
     * @param string $text
     * @param bool   $isMerge
     * @return array 检测结果
     * @throws ZhuqueAuthException 所有 token 均不可用时抛出
     * @throws ZhuqueException      业务/网络类错误
     */
    public function detect(string $text, bool $isMerge = true): array
    {
        $text = trim($text);
        if ($text === '') {
            throw new ZhuqueException('检测文本不能为空');
        }

        $available = tp_available();
        if (count($available) === 0) {
            throw new ZhuqueException('当前没有可用的 Token：请在后台添加或检查 Token 是否处于冷却期');
        }

        // 最多尝试次数 = 可用 token 数（避免无意义地重复）
        $tried = 0;
        $lastAuthErr = null;
        $maxTries = min(count($available), 20);

        while ($tried < $maxTries) {
            $pick = tp_next();
            if ($pick['token'] === null) {
                break;
            }
            $t = $pick['token'];
            $tried++;

            try {
                $data = $this->request((string) $t['key'], $text, $isMerge);
                // 成功：记录已用 token，并累计本次调用消耗的免费额度（官方 makers_models_usage.total_tokens）
                tp_mark_success((string) $t['id'], (int) ($data['makers_models_usage']['total_tokens'] ?? 0));
                $this->lastUsedTokenName = $t['name'];
                return $data;
            } catch (ZhuqueAuthException $e) {
                // 鉴权/限流：记失败并切换下一个
                tp_mark_fail((string) $t['id'], $e->getMessage());
                $lastAuthErr = $e;
            }
            // 其它异常（业务/网络）直接上抛，不再切换
        }

        // 走到这说明全是鉴权类失败
        if ($lastAuthErr !== null) {
            throw new ZhuqueException('所有可用 Token 均无法完成检测（最后错误：' . $lastAuthErr->getMessage() . '）');
        }
        throw new ZhuqueException('没有可用的 Token');
    }

    /**
     * 批量检测多条文字（逐条调用，返回带原始索引的结果）
     */
    public function detectBatch(array $texts, bool $isMerge = true): array
    {
        $results = [];
        $errors  = [];
        foreach ($texts as $i => $text) {
            try {
                $results[] = [
                    'index' => $i,
                    'ok'    => true,
                    'text'  => (string) $text,
                    'detect'=> $this->detect((string) $text, $isMerge),
                ];
            } catch (ZhuqueException $e) {
                $errors[] = [
                    'index' => $i,
                    'ok'    => false,
                    'text'  => (string) $text,
                    'error' => $e->getMessage(),
                ];
            }
        }
        return ['results' => $results, 'errors' => $errors];
    }

    /**
     * 用指定 token 直接探测一次（后台“测试连接”用，不进入轮询）
     */
    public function testToken(string $tokenKey, string $text = '你好，测试连接', bool $isMerge = true): array
    {
        return $this->request($tokenKey, $text, $isMerge);
    }

    /* ---------------- 底层 HTTP ---------------- */

    private function request(string $tokenKey, string $text, bool $isMerge): array
    {
        if (!function_exists('curl_init')) {
            throw new ZhuqueException('当前 PHP 环境未启用 cURL 扩展（php-curl），请安装启用后重试');
        }
        $payload = json_encode([
            'text'     => $text,
            'is_merge' => $isMerge,
        ], JSON_UNESCAPED_UNICODE);

        $r = zq_curl_post(
            $this->apiUrl,
            $payload,
            [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $tokenKey,
                'Accept: application/json',
            ],
            $this->timeout
        );

        if ($r['errno'] !== 0) {
            $tag = $r['insecure_retry'] ? '（已在不校验证书模式下自动重试）' : '';
            throw new ZhuqueException('网络请求失败（错误码 ' . $r['errno'] . '）：' . $r['error'] . $tag);
        }

        // 鉴权 / 限流类错误：交给上层切换 token
        if ($r['http'] === 401 || $r['http'] === 403 || $r['http'] === 429) {
            $msg = $r['http'] === 429 ? '触发限流（HTTP 429）' : 'Token 鉴权失败（HTTP ' . $r['http'] . '）';
            throw new ZhuqueAuthException($msg, $r['http']);
        }

        $data = json_decode($r['raw'], true);
        if (!is_array($data)) {
            throw new ZhuqueException('无法解析服务端响应（HTTP ' . $r['http'] . '）');
        }

        if (($data['status'] ?? '') !== 'success') {
            // 官方约定：status != success 时 msg 携带具体错误（额度用尽 / 引擎异常等）
            $st = (string) ($data['status'] ?? 'unknown');
            $rawMsg = (string) ($data['msg'] ?? '');
            $msg = $rawMsg;
            if ($msg === '') {
                $msg = '服务端返回异常状态：' . $st;
            } elseif ($st !== 'error' && $st !== 'failed') {
                $msg = $msg . '（status=' . $st . '）';
            }
            if ($r['http'] >= 400) {
                $msg .= '（HTTP ' . $r['http'] . '）';
            }
            // 额度不足类：抛出专用异常，供监控模块自动禁用该 token
            if (zq_is_quota_error($rawMsg)) {
                throw new ZhuqueQuotaException($msg);
            }
            throw new ZhuqueException($msg);
        }

        return $data;
    }
}