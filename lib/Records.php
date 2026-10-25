<?php
/**
 * 开发记录存储（按 IP 记录每次提交与 token 消耗）
 *
 * - 每次检测提交写入一条记录：时间 / IP / 类型 / 条数 / 文本长度与预览 /
 *   判定 / 消耗 token / 使用 Token 名称 / 成功或失败。
 * - 存储为 data/records.json（新记录在前），最多保留 RECORD_LIMIT 条。
 * - 提供按 IP、按日的汇总统计，用于监控免费额度消耗。
 */

require_once __DIR__ . '/../config.php';

/** 记录文件路径 */
function rec_file(): string
{
    return __DIR__ . '/../data/records.json';
}

/** 确保 data 目录存在并返回其路径 */
function rec_data_dir(): string
{
    $dir = __DIR__ . '/../data';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

/**
 * 解析客户端 IP：
 * - 优先使用 REMOTE_ADDR；
 * - 若来源为本地 / 内网（常见于反向代理），则尝试 X-Forwarded-For / X-Real-IP。
 */
function rec_client_ip(): string
{
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $isPrivate = ($remote === '')
        || ($remote === '::1')
        || (strpos($remote, '127.') === 0)
        || (strpos($remote, '10.') === 0)
        || (strpos($remote, '192.168.') === 0)
        || (bool) preg_match('/^172\.(1[6-9]|2\d|3[01])\./', $remote);

    if ($isPrivate) {
        $xff = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff !== '') {
            foreach (explode(',', $xff) as $part) {
                $cand = trim($part);
                if ($cand !== '' && filter_var($cand, FILTER_VALIDATE_IP)) {
                    return $cand;
                }
            }
        }
        $real = (string) ($_SERVER['HTTP_X_REAL_IP'] ?? '');
        if ($real !== '' && filter_var($real, FILTER_VALIDATE_IP)) {
            return $real;
        }
    }

    return $remote !== '' ? $remote : 'unknown';
}

/** 读取全部记录（新记录在前） */
function rec_all(): array
{
    $file = rec_file();
    if (!is_file($file)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

/**
 * 追加一条记录并裁剪超限旧记录。
 * @param array $entry 已包含要覆盖的字段（未提供的字段使用默认值）
 */
function rec_append(array $entry): void
{
    $all = rec_all();

    $entry = array_merge([
        'id'         => bin2hex(random_bytes(6)),
        'time'       => date('Y-m-d H:i:s'),
        'ip'         => rec_client_ip(),
        'type'       => 'text',      // text | batch | image
        'item_count' => 1,           // 本次提交的条数（图片数 / 批量条数）
        'text_len'   => 0,           // 文本总字数
        'preview'    => '',          // 文本前若干字符预览
        'is_merge'   => true,
        'verdict'    => '',
        'softmax'    => 0.0,
        'ratio'      => 0.0,
        'tokens'     => 0,           // 本次消耗的免费额度 token
        'token_name' => '',          // 使用的 Token 名称
        'ok'         => true,
        'error'      => '',
    ], $entry);

    array_unshift($all, $entry);

    $limit = defined('RECORD_LIMIT') ? (int) RECORD_LIMIT : 5000;
    if ($limit > 0) {
        $all = array_slice($all, 0, $limit);
    }

    rec_data_dir();
    @file_put_contents(
        rec_file(),
        json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
    );
}

/**
 * 汇总统计。
 * @param array|null $records 传入记录数组可避免重复读取；为 null 时读取全部
 * @return array{
 *   total_records:int,total_tokens:int,today_tokens:int,unique_ips:int,
 *   ok_count:int,fail_count:int,
 *   by_ip:array<int,array{ip:string,count:int,tokens:int}>,
 *   by_day:array<int,array{day:string,count:int,tokens:int}>
 * }
 */
function rec_stats(?array $records = null): array
{
    $records = $records ?? rec_all();
    $today = date('Y-m-d');

    $totalTokens = 0;
    $todayTokens = 0;
    $okCount = 0;
    $failCount = 0;
    $ips = [];
    $byIp = [];
    $byDay = [];

    foreach ($records as $r) {
        if (!is_array($r)) {
            continue;
        }
        $tokens = (int) ($r['tokens'] ?? 0);
        $ip     = (string) ($r['ip'] ?? 'unknown');
        $day    = substr((string) ($r['time'] ?? ''), 0, 10);

        $totalTokens += $tokens;
        if ($day === $today) {
            $todayTokens += $tokens;
        }
        if (!empty($r['ok'])) {
            $okCount++;
        } else {
            $failCount++;
        }

        $ips[$ip] = true;

        if (!isset($byIp[$ip])) {
            $byIp[$ip] = ['ip' => $ip, 'count' => 0, 'tokens' => 0];
        }
        $byIp[$ip]['count']++;
        $byIp[$ip]['tokens'] += $tokens;

        if (!isset($byDay[$day])) {
            $byDay[$day] = ['day' => $day, 'count' => 0, 'tokens' => 0];
        }
        $byDay[$day]['count']++;
        $byDay[$day]['tokens'] += $tokens;
    }

    $byIpList = array_values($byIp);
    usort($byIpList, function ($a, $b) {
        return $b['tokens'] <=> $a['tokens'];
    });

    $byDayList = array_values($byDay);
    usort($byDayList, function ($a, $b) {
        return strcmp($b['day'], $a['day']);
    });

    return [
        'total_records' => count($records),
        'total_tokens'  => $totalTokens,
        'today_tokens'  => $todayTokens,
        'unique_ips'    => count($ips),
        'ok_count'      => $okCount,
        'fail_count'    => $failCount,
        'by_ip'         => $byIpList,
        'by_day'        => $byDayList,
    ];
}

/** 清空全部记录 */
function rec_clear(): void
{
    rec_data_dir();
    @file_put_contents(rec_file(), '[]');
}
