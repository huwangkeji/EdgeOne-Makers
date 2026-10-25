<?php
/**
 * cron 触发器：GET /cron/monitor.php?key=xxx
 *
 * 供系统计划任务（crontab / 运维脚本）定时访问，自动检查 Token 池状态：
 * 额度用尽的 Token 会被自动禁用，鉴权失败进入冷却，并写入监控日志。
 *
 * 校验密钥：
 *   请求携带的 key 需与 zq_monitor_key() 一致
 *   （优先级：data/config.json 的 monitor_key > 环境变量 ZHUQUE_MONITOR_KEY > config.php 的 MONITOR_KEY）。
 * key 亦可放置于请求头 X-Monitor-Key。
 */

require_once __DIR__ . '/../lib/Monitor.php';

header('Content-Type: application/json; charset=utf-8');

function mt_out($data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// 仅允许 GET（计划任务友好）
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    mt_out(['ok' => false, 'error' => '仅支持 GET']);
}

$key = (string) ($_GET['key'] ?? $_SERVER['HTTP_X_MONITOR_KEY'] ?? '');
$expected = zq_monitor_key();
if ($expected === '' || !hash_equals($expected, $key)) {
    http_response_code(403);
    mt_out(['ok' => false, 'error' => '监控密钥无效']);
}

// 监控总开关：后台关闭时不再执行
$cfg = mt_config();
if (!$cfg['enabled']) {
    mt_out(['ok' => false, 'skipped' => 'disabled', 'msg' => '自动监控未启用（可在后台「Token 自动监控」开启）', 'config' => $cfg]);
}

// 最小间隔：未到间隔时跳过，避免 cron 频繁触发
$interval = max(1, (int) $cfg['interval_min']) * 60;
$last = mt_last_run_unix();
if ($last > 0 && (time() - $last) < $interval) {
    mt_out([
        'ok' => false, 'skipped' => 'interval',
        'msg' => '距上次运行不足间隔（' . $cfg['interval_min'] . ' 分钟），跳过',
        'last_run' => date('Y-m-d H:i:s', $last),
        'next_run' => date('Y-m-d H:i:s', $last + $interval),
        'interval_min' => (int) $cfg['interval_min'],
    ]);
}

$entry = mt_run();
mt_out(['ok' => true, 'run' => $entry]);