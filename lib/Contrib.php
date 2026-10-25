<?php
/**
 * Token 贡献服务的「防滥用」工具
 *
 * - 严格格式校验：Makers API Key 应为 `sk-` 后接 48 位小写十六进制。
 * - 频率/配额限流：按 IP 记录提交次数与时间，实施单 IP 配额与同 IP 冷却间隔。
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/TokenPool.php';
require_once __DIR__ . '/Records.php';   // rec_client_ip

/** Makers API Key 严格格式 */
function ct_key_pattern(): string
{
    return '/^sk-[a-f0-9]{48}$/';
}

/** Key 归一化：转小写并去首尾空白（十六进制大小写不敏感） */
function ct_normalize_key(string $key): string
{
    return strtolower(trim($key));
}

/** 严格格式校验：`sk-` + 48 位十六进制 */
function ct_valid_key(string $key): bool
{
    return (bool) preg_match(ct_key_pattern(), ct_normalize_key($key));
}

/** 频率/配额记录文件 */
function ct_rate_file(): string
{
    return __DIR__ . '/../data/contrib_rate.json';
}

/** 读取频率记录 map：ip => ['count'=>int, 'last'=>int(时间戳)] */
function ct_rate_read(): array
{
    $f = ct_rate_file();
    if (!is_file($f)) {
        return [];
    }
    $d = json_decode((string) file_get_contents($f), true);
    return is_array($d) ? $d : [];
}

/** 控制内存占用：条目过多时仅保留最近记录的 $max 个 IP */
function ct_rate_prune(array &$map): void
{
    $max = 3000;
    if (count($map) > $max) {
        uksort($map, function ($a, $b) use ($map) {
            return (int) ($map[$a]['last'] ?? 0) <=> (int) ($map[$b]['last'] ?? 0);
        });
        $map = array_slice($map, -$max, null, true);
    }
}

/** 写回频率记录 */
function ct_rate_save(array $map): void
{
    $dir = __DIR__ . '/../data';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents(
        ct_rate_file(),
        json_encode($map, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    );
}

/**
 * 校验当前 IP 是否允许提交：同 IP 冷却间隔 + 单 IP 配额上限。
 * @return array{ok:bool, error?:string}
 */
function ct_rate_check(string $ip): array
{
    if ($ip === '' || $ip === 'unknown') {
        return ['ok' => false, 'error' => '无法识别来源 IP，本次提交被拦截'];
    }
    $map = ct_rate_read();
    $now = time();
    $e = $map[$ip] ?? ['count' => 0, 'last' => 0];

    $cooldown = (int) CONTRIB_COOLDOWN_SEC;
    $wait = $cooldown - ($now - (int) $e['last']);
    if ($wait > 0) {
        return ['ok' => false, 'error' => '操作过于频繁，请 ' . $wait . ' 秒后再试'];
    }

    $maxPerIp = (int) CONTRIB_MAX_PER_IP;
    if ($maxPerIp > 0 && (int) $e['count'] >= $maxPerIp) {
        return ['ok' => false, 'error' => '单个 IP 最多可贡献 ' . $maxPerIp . ' 个 Token'];
    }

    return ['ok' => true];
}

/** 记录一次成功提交（累加计数、刷新时间） */
function ct_rate_commit(string $ip): void
{
    $map = ct_rate_read();
    $now = time();
    $e = $map[$ip] ?? ['count' => 0, 'last' => 0];
    $e['count'] = (int) $e['count'] + 1;
    $e['last']  = $now;
    $map[$ip] = $e;
    ct_rate_prune($map);
    ct_rate_save($map);
}

/** 池子是否已达贡献上限（CONTRIB_POOL_LIMIT） */
function ct_pool_full(): bool
{
    $limit = (int) CONTRIB_POOL_LIMIT;
    if ($limit <= 0) {
        return false;
    }
    return count(tp_tokens()) >= $limit;
}