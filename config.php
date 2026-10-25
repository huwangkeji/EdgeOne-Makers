<?php
/**
 * 云雀 AI 检测助手 —— 全局配置
 *
 * API Key 获取方式（官方文档）：
 *   登录 EdgeOne 控制台 -> Makers -> Models -> API Key -> 创建 API Key
 *
 * 配置优先级（从高到低）：
 *   1. 运行目录下 data/config.json 中保存的密钥（可在界面上填写保存）
 *   2. 环境变量 ZHUQUE_API_KEY
 *   3. 本文件中的 ZHUQUE_API_KEY（兜底）
 */

// ---- 兜底配置：建议将密钥放入 data/config.json 或环境变量，不要提交到代码仓库 ----
const ZHUQUE_API_KEY   = '';
const ZHUQUE_API_URL   = 'https://ai-gateway.edgeone.link/v1/providers/zhuque-text/classify';
const ZHUQUE_TIMEOUT   = 60;      // 请求超时（秒）
const HISTORY_LIMIT    = 200;     // 本地历史记录最多保存条数
const RECORD_LIMIT     = 5000;    // 开发记录（按 IP 提交）最多保存条数
// ---- Token 自动监控 ----
const MONITOR_KEY        = '';       // cron 触发密钥（优先级：data/config.json 的 monitor_key > 环境变量 ZHUQUE_MONITOR_KEY > 此处）
const FREE_MONTHLY_QUOTA = 500000;   // 单 Token 每月免费额度（token），累计用量达此值即自动禁用
const MONITOR_AUTO_DELETE        = true;  // 监控发现「无效 token」（持续 401/403）时是否自动删除
const MONITOR_DELETE_AFTER_FAILS = 4;    // 连续多少次鉴权无效（401/403）后自动删除（429 限流与网络错误不计入）
// ---- Token 贡献服务（防滥用限制） ----
const CONTRIB_POOL_LIMIT   = 30;     // 池子中 Token 最大数量，达到后拒绝新贡献
const CONTRIB_MAX_PER_IP   = 3;      // 单个 IP 最多可贡献的 Token 数量
const CONTRIB_COOLDOWN_SEC = 300;    // 同一 IP 两次贡献提交的最小间隔（秒），防止频繁刷提交

// ---- SSL / 网络证书校验 ----
const ZHUQUE_SSL_VERIFY_PEER       = true;                // 是否校验对端 HTTPS 证书（建议保持 true）
const ZHUQUE_CA_BUNDLE             = __DIR__ . '/cacert.pem'; // 项目内 CA 证书包路径（放 cacert.pem 即启用）
const ZHUQUE_SSL_INSECURE_FALLBACK = true;                // 证书校验失败(60/77)且加载不到 CA 包时，是否降级为不校验并重试一次

/**
 * 获取有效的 API Key
 * @return string 密钥（未配置时返回空字符串）
 */
function zhuque_get_api_key(): string
{
    // 1. data/config.json 中用户自定义密钥优先
    $configFile = __DIR__ . '/data/config.json';
    if (is_file($configFile)) {
        $cfg = json_decode((string) file_get_contents($configFile), true);
        if (is_array($cfg) && !empty($cfg['api_key'])) {
            return trim((string) $cfg['api_key']);
        }
    }

    // 2. 环境变量
    $env = getenv('ZHUQUE_API_KEY');
    if ($env !== false && $env !== '') {
        return trim($env);
    }

    // 3. 本文件兜底
    return trim(ZHUQUE_API_KEY);
}

/**
 * 保存 API Key 到 data/config.json
 * @param string $key
 * @return array{ok:bool,msg:string}
 */
function zhuque_save_api_key(string $key): array
{
    $key = trim($key);
    if ($key === '') {
        return ['ok' => false, 'msg' => 'API Key 不能为空'];
    }

    $dir = __DIR__ . '/data';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['ok' => false, 'msg' => '无法创建 data 目录，请检查目录权限'];
    }

    $cfg = is_file("$dir/config.json") ? json_decode((string) file_get_contents("$dir/config.json"), true) : [];
    if (!is_array($cfg)) {
        $cfg = [];
    }
    $cfg['api_key'] = $key;

    if (@file_put_contents("$dir/config.json", json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) {
        return ['ok' => false, 'msg' => '写入 config.json 失败，请检查目录权限'];
    }

    return ['ok' => true, 'msg' => '已保存'];
}

/* ================================================================
 * 集中的 curl 封装（含 HTTPS 证书校验处理）
 * 供 ZhuqueClient / ImageClient 复用，避免重复且行为一致。
 * ================================================================ */

/**
 * 返回 cURL 的 SSL 校验选项（依据配置）
 * @return array
 */
function zq_curl_ssl_options(): array
{
    $opts = [CURLOPT_SSL_VERIFYPEER => (bool) ZHUQUE_SSL_VERIFY_PEER];

    if (ZHUQUE_SSL_VERIFY_PEER) {
        $opts[CURLOPT_SSL_VERIFYHOST] = 2;
        // 优先使用项目内 CA 包；否则用 php.ini 的 curl.cainfo（若已配置则已生效）
        $bundle = (string) ZHUQUE_CA_BUNDLE;
        if ($bundle !== '' && is_file($bundle)) {
            $opts[CURLOPT_CAINFO] = $bundle;
        } elseif (($c = ini_get('curl.cainfo')) && is_file($c)) {
            $opts[CURLOPT_CAINFO] = $c;
        }
    } else {
        $opts[CURLOPT_SSL_VERIFYHOST] = 0;
    }

    return $opts;
}

/**
 * 执行一次 curl JSON POST，遇 SSL 校验失败(60/77)且允许降级时，自动不校验重试一次。
 *
 * @param string $url        请求地址
 * @param string $jsonPayload JSON 请求体
 * @param array  $headers    HTTP 头（含 Authorization 等）
 * @param int    $timeout    超时秒数
 * @return array{raw:string, errno:int, error:string, http:int, insecure_retry:bool}
 */
function zq_curl_post(string $url, string $jsonPayload, array $headers, int $timeout): array
{
    $make = function (array $extra) use ($url, $jsonPayload, $headers, $timeout): array {
        $ch = curl_init($url);
        // 注意：curl 选项是整型常量键，array_merge 会重排整数键破坏它们，
        // 因此这里用联合运算符 "+"（保留整数键，且左侧优先）。
        // 顺序：$extra 需在 zq_curl_ssl_options() 之前，才能用降级项覆盖其校验开关。
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonPayload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => $headers,
        ] + $extra + zq_curl_ssl_options());

        $raw = curl_exec($ch);
        $out = [
            'raw'    => (string) $raw,
            'errno'  => curl_errno($ch),
            'error'  => curl_error($ch),
            'http'   => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
        ];
        curl_close($ch);
        return $out;
    };

    $result = $make([]);

    // 校验失败（60=无法获取本地签发者/自签，77=CA 文件问题）且允许不安全降级：重试一次
    if (in_array($result['errno'], [60, 77], true)
        && ZHUQUE_SSL_VERIFY_PEER && ZHUQUE_SSL_INSECURE_FALLBACK) {
        $result = $make([CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0]);
        $result['insecure_retry'] = true;
        return $result;
    }
    $result['insecure_retry'] = false;
    return $result;
}

/**
 * 丢弃此前缓冲的任何无关输出（PHP 警告/提示等），确保后续 echo 为纯 JSON。
 * 需配合调用方在最外层先 ob_start() 使用。
 */
function zq_flush_output(): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}

/**
 * 获取 cron 监控触发密钥。
 * 优先级：后台「settings.json」的 monitor_key > data/config.json > 环境变量 > config.php 常量。
 * @return string 密钥（未配置时返回空字符串）
 */
function zq_monitor_key(): string
{
    // 1. 后台可视化设置的监控密钥（data/settings.json）优先
    $sf = __DIR__ . '/data/settings.json';
    if (is_file($sf)) {
        $ss = json_decode((string) file_get_contents($sf), true);
        if (is_array($ss) && !empty($ss['monitor_key'])) {
            return trim((string) $ss['monitor_key']);
        }
    }

    // 2. data/config.json
    $cfgFile = __DIR__ . '/data/config.json';
    if (is_file($cfgFile)) {
        $cfg = json_decode((string) file_get_contents($cfgFile), true);
        if (is_array($cfg) && !empty($cfg['monitor_key'])) {
            return trim((string) $cfg['monitor_key']);
        }
    }

    // 3. 环境变量
    $env = getenv('ZHUQUE_MONITOR_KEY');
    if ($env !== false && $env !== '') {
        return trim($env);
    }

    // 4. 常量兜底
    return trim(MONITOR_KEY);
}

/**
 * 启发式判断错误信息是否属于「额度不足 / 余额不足」类（大小写不敏感）。
 */
function zq_is_quota_error(string $text): bool
{
    if ($text === '') {
        return false;
    }
    $needles = [
        '额度', '余额', '配额', 'quota', 'quota exceeded', 'quota exhausted',
        'insufficient', 'balance', 'limit reached', 'usage limit',
    ];
    foreach ($needles as $n) {
        if (stripos($text, $n) !== false) {
            return true;
        }
    }
    return false;
}