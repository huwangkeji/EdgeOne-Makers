<?php
/**
 * Token 监控接口（需登录）
 *
 * POST /api/monitor.php
 *   { action: "run" }             立即执行一轮监控
 *   { action: "status" }          获取 Token 健康状态
 *   { action: "log" }             获取最近监控运行日志
 *   { action: "clear_log" }       清空监控日志
 */

require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/Monitor.php';

header('Content-Type: application/json; charset=utf-8');
auth_require(true); // 未登录返回 401 JSON

function respond(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond(['ok' => false, 'error' => '仅支持 POST']);
}

$body = json_decode((string) file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';

switch ($action) {
    case 'run':
        $entry = mt_run();
        respond(['ok' => true, 'run' => $entry]);
        break;

    case 'status':
        respond(['ok' => true, 'statuses' => mt_statuses()]);
        break;

    case 'log':
        respond(['ok' => true, 'logs' => mt_all()]);
        break;

    case 'clear_log':
        mt_clear();
        respond(['ok' => true, 'msg' => '监控日志已清空']);
        break;

    case 'overview':
        $cfg = mt_config();
        $statuses = mt_statuses();
        $dist = ['healthy' => 0, 'cooldown' => 0, 'disabled' => 0];
        foreach ($statuses as $st) {
            $dist[$st['state']] = ($dist[$st['state']] ?? 0) + 1;
        }
        $last = mt_last_run_unix();
        $interval = max(1, (int) $cfg['interval_min']) * 60;
        $now = time();
        respond([
            'ok' => true,
            'overview' => [
                'enabled'       => (bool) $cfg['enabled'],
                'interval_min'  => (int) $cfg['interval_min'],
                'quota'         => (int) $cfg['quota'],
                'auto_delete'   => (bool) $cfg['auto_delete'],
                'delete_after'  => (int) $cfg['delete_after'],
                'total'         => count($statuses),
                'dist'          => $dist,
                'last_run'      => $last ? date('Y-m-d H:i:s', $last) : '',
                'last_run_unix' => $last,
                'next_run'      => $last ? date('Y-m-d H:i:s', $last + $interval) : '',
                'due'           => $last === 0 || ($now - $last) >= $interval,
                'since_min'     => $last ? (int) floor(($now - $last) / 60) : null,
            ],
        ]);
        break;

    default:
        respond(['ok' => false, 'error' => '未知操作']);
}