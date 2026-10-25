<?php
/**
 * Token 池自动监控（供 cron 与后台手动触发）
 *
 * 对每个启用中的 Token：
 *   1. 若累计用量已达本月免费额度上限（FREE_MONTHLY_QUOTA），直接自动禁用；
 *   2. 否则做一次实况探测（testToken）：
 *      - 成功：更新累计用量；若因此达到上限则自动禁用；
 *      - 官方返回「额度不足」类错误（ZhuqueQuotaException）：自动禁用；
 *      - 鉴权/限流（ZhuqueAuthException）：记入失败/冷却，不禁用；
 *      - 其它业务/网络错误：仅标记最近测试失败。
 * 每次运行写入 data/monitor_log.json（保留最近 MT_LOG_KEEP 条）。
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/TokenPool.php';
require_once __DIR__ . '/ZhuqueClient.php';

/** 保留的最近监控日志条数 */
if (!defined('MT_LOG_KEEP')) {
    define('MT_LOG_KEEP', 50);
}

/** 探测用的短文本 */
function mt_probe_text(): string
{
    return '监控连通性测试：请判断以下文本是否由 AI 生成。';
}

/** 无 mbstring 时安全截断 */
function mt_cut(string $s, int $len): string
{
    return function_exists('mb_substr') ? mb_substr($s, 0, $len) : substr($s, 0, $len);
}

/** 监控日志文件路径 */
function mt_log_file(): string
{
    return __DIR__ . '/../data/monitor_log.json';
}

/**
 * 读取监控配置（可视化设置优先，缺失时回退 config.php 常量默认）。
 * @return array{enabled:bool, interval_min:int, quota:int, auto_delete:bool, delete_after:int}
 */
function mt_config(): array
{
    $s = tp_settings();
    return [
        'enabled'      => (bool) ($s['monitor_enabled'] ?? true),
        'interval_min' => max(1, (int) ($s['monitor_interval_min'] ?? 30)),
        'quota'        => (int) ($s['monitor_quota'] ?? 0),
        'auto_delete'  => (bool) ($s['monitor_auto_delete'] ?? true),
        'delete_after' => max(1, (int) ($s['monitor_delete_after'] ?? 4)),
    ];
}

/**
 * 汇总 Token 池健康状态（供后台「监控」模块展示）。
 */
function mt_statuses(): array
{
    $tokens = tp_tokens();
    $now = time();
    $out = [];
    foreach ($tokens as $t) {
        $cooldown = (int) $t['cooldown_until'] > $now;
        $state = !empty($t['enabled'])
            ? ($cooldown ? 'cooldown' : 'healthy')
            : 'disabled';
        $out[] = [
            'id'              => $t['id'],
            'name'            => $t['name'],
            'key_masked'      => tp_mask_key($t['key']),
            'enabled'         => (bool) $t['enabled'],
            'in_cooldown'     => $cooldown,
            'state'           => $state,
            'disabled_reason' => (string) ($t['disabled_reason'] ?? ''),
            'disabled_at'     => (string) ($t['disabled_at'] ?? ''),
            'total_tokens'    => (int) ($t['total_tokens'] ?? 0),
            'total_calls'     => (int) ($t['total_calls'] ?? 0),
            'last_test_at'    => (string) ($t['last_test_at'] ?? ''),
            'last_test_ok'    => $t['last_test_ok'] ?? null,
            'last_error'      => (string) ($t['last_error'] ?? ''),
            'created_at'      => (string) ($t['created_at'] ?? ''),
        ];
    }
    return $out;
}

/** 读取监控日志（新记录在前） */
function mt_all(): array
{
    $f = mt_log_file();
    if (!is_file($f)) {
        return [];
    }
    $d = json_decode((string) file_get_contents($f), true);
    return is_array($d) ? $d : [];
}

/** 追加一条监控日志并裁剪超限 */
function mt_log(array $entry): void
{
    $dir = __DIR__ . '/../data';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $all = mt_all();
    array_unshift($all, $entry);
    $all = array_slice($all, 0, MT_LOG_KEEP);
    @file_put_contents($f = mt_log_file(), json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}

/** 清空监控日志 */
function mt_clear(): void
{
    $dir = __DIR__ . '/../data';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents(mt_log_file(), '[]');
}

/** 最近一次监控运行的时间戳（unix），无记录返回 0 */
function mt_last_run_unix(): int
{
    $all = mt_all();
    if (empty($all) || empty($all[0]['time'])) {
        return 0;
    }
    return (int) strtotime((string) $all[0]['time']);
}

/**
 * 执行一轮监控。
 * @param array|null $tokens 传入可避免重复读取；为 null 时读取全部 token
 * @return array 本次运行摘要含 results
 */
function mt_run(?array $tokens = null): array
{
    $tokens = $tokens ?? tp_tokens();
    $cfg   = mt_config();
    $quota = (int) $cfg['quota'];
    $client = new ZhuqueClient();

    $ok       = 0;
    $disabled = 0;
    $deleted  = 0;
    $cooldown = 0;
    $errors   = 0;
    $results  = [];

    foreach ($tokens as $t) {
        if (!is_array($t)) {
            continue;
        }
        $id   = (string) ($t['id'] ?? '');
        $name = (string) ($t['name'] ?? '未命名');

        if (empty($t['enabled'])) {
            $results[] = ['id' => $id, 'name' => $name, 'action' => 'skip', 'msg' => '已停用，跳过'];
            continue;
        }

        $cumulative = (int) ($t['total_tokens'] ?? 0);

        // 1) 累计用量已达上限 -> 直接禁用（不再消耗探测额度）
        if ($quota > 0 && $cumulative >= $quota) {
            tp_disable($id, '累计用量达每月免费额度上限（' . $quota . ' token），已由监控自动禁用');
            tp_mark_test($id, false);
            $disabled++;
            $results[] = ['id' => $id, 'name' => $name, 'action' => 'disable', 'msg' => '累计用量 ' . $cumulative . ' 已达上限'];
            continue;
        }

        // 2) 实况探测
        try {
            $data = $client->testToken((string) $t['key'], mt_probe_text(), true);
            $used = (int) ($data['makers_models_usage']['total_tokens'] ?? 0);
            tp_mark_success($id, $used);
            tp_mark_test($id, true);

            $newTotal = $cumulative + $used;
            if ($quota > 0 && $newTotal >= $quota) {
                tp_disable($id, '本次探测后累计用量达每月免费额度上限（' . $quota . ' token），已由监控自动禁用');
                tp_mark_test($id, false);
                $disabled++;
                $results[] = ['id' => $id, 'name' => $name, 'action' => 'disable', 'msg' => '探测后累计 ' . $newTotal . ' 达上限'];
            } else {
                $ok++;
                $results[] = ['id' => $id, 'name' => $name, 'action' => 'ok', 'msg' => '正常（本次 ' . $used . ' token）'];
            }
        } catch (ZhuqueQuotaException $e) {
            tp_disable($id, '官方返回额度不足：' . $e->getMessage());
            tp_mark_test($id, false);
            $disabled++;
            $results[] = ['id' => $id, 'name' => $name, 'action' => 'disable', 'msg' => mt_cut($e->getMessage(), 120)];
        } catch (ZhuqueAuthException $e) {
            $http      = (int) ($e->httpCode ?? 0);
            $isInvalid = in_array($http, [401, 403], true); // 401/403 视为 key 无效；429 属限流
            $removed   = false;

            if ($isInvalid) {
                $streak = tp_bump_invalid($id);
                $autoDelete = (bool) $cfg['auto_delete'];
                $after = (int) $cfg['delete_after'];
                if ($autoDelete && $after > 0 && $streak >= $after) {
                    tp_remove($id);
                    $deleted++;
                    $removed = true;
                    $results[] = [
                        'id' => $id, 'name' => $name, 'action' => 'delete',
                        'msg' => '连续 ' . $streak . ' 次鉴权无效（HTTP ' . $http . '），已自动删除',
                    ];
                }
            }

            if (!$removed) {
                tp_mark_fail($id, $e->getMessage());
                tp_mark_test($id, false);
                $cooldown++;
                $results[] = ['id' => $id, 'name' => $name, 'action' => 'cooldown', 'msg' => $e->getMessage()];
            }
        } catch (ZhuqueException $e) {
            tp_mark_test($id, false);
            $errors++;
            $results[] = ['id' => $id, 'name' => $name, 'action' => 'error', 'msg' => mt_cut($e->getMessage(), 120)];
        }
    }

    $entry = [
        'time'     => date('Y-m-d H:i:s'),
        'total'    => count($tokens),
        'ok'       => $ok,
        'disabled' => $disabled,
        'deleted'  => $deleted,
        'cooldown' => $cooldown,
        'errors'   => $errors,
        'results'  => $results,
    ];
    mt_log($entry);

    return $entry;
}