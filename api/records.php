<?php
/**
 * 开发记录接口（需登录）
 *
 * POST /api/records.php
 *   { action: "list", limit?, offset?, ip? }               获取记录（分页）+ 汇总统计
 *   { action: "export", type: "detail"|"byip", ip? }      导出 CSV（下载）
 *   { action: "clear" }                                    清空全部记录
 *
 * 记录由 api/detect.php 与 api/detect_image.php 在每次提交时写入。
 */

require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/Records.php';

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
    case 'list':
        $all = rec_all();
        $stats = rec_stats($all);

        // 可选按 IP 过滤
        $ip = isset($body['ip']) ? trim((string) $body['ip']) : '';
        if ($ip !== '') {
            $all = array_values(array_filter($all, function ($r) use ($ip) {
                return is_array($r) && (string) ($r['ip'] ?? '') === $ip;
            }));
        }

        // 分页：默认返回最近 200 条
        $limit = isset($body['limit']) ? (int) $body['limit'] : 200;
        $limit = max(1, min(1000, $limit));
        $offset = isset($body['offset']) ? max(0, (int) $body['offset']) : 0;

        $total = count($all);
        $list = array_slice($all, $offset, $limit);

        respond([
            'ok'      => true,
            'stats'   => $stats,
            'records' => $list,
            'total'   => $total,
            'limit'   => $limit,
            'offset'  => $offset,
        ]);
        break;

    case 'export':
        $exportType = ($body['type'] ?? 'detail') === 'byip' ? 'byip' : 'detail';
        $ip = isset($body['ip']) ? trim((string) $body['ip']) : '';
        $csv = fopen('php://output', 'w');

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="records_' . $exportType . '_' . date('Ymd_His') . '.csv"');
        echo "\xEF\xBB\xBF"; // UTF-8 BOM，Excel 中文兼容

        if ($exportType === 'byip') {
            fputcsv($csv, ['IP', '提交次数', '累计消耗 token']);
            $rows = rec_stats()['by_ip'];
            foreach ($rows as $x) {
                if ($ip !== '' && $x['ip'] !== $ip) {
                    continue;
                }
                fputcsv($csv, [$x['ip'], $x['count'], $x['tokens']]);
            }
        } else {
            fputcsv($csv, ['时间', 'IP', '类型', '条数', '字数', '判定', '消耗token', '使用Token', '内容预览', '状态']);
            $all = rec_all();
            if ($ip !== '') {
                $all = array_values(array_filter($all, function ($r) use ($ip) {
                    return is_array($r) && (string) ($r['ip'] ?? '') === $ip;
                }));
            }
            foreach ($all as $r) {
                if (!is_array($r)) {
                    continue;
                }
                fputcsv($csv, [
                    (string) ($r['time'] ?? ''),
                    (string) ($r['ip'] ?? ''),
                    (string) ($r['type'] ?? 'text'),
                    (int) ($r['item_count'] ?? 1),
                    (int) ($r['text_len'] ?? 0),
                    (string) ($r['verdict'] ?? ''),
                    (int) ($r['tokens'] ?? 0),
                    (string) ($r['token_name'] ?? ''),
                    (string) ($r['preview'] ?? ''),
                    empty($r['ok']) ? '失败' : '成功',
                ]);
            }
        }
        fclose($csv);
        exit;

    case 'clear':
        rec_clear();
        respond(['ok' => true, 'msg' => '记录已清空']);
        break;

    default:
        respond(['ok' => false, 'error' => '未知操作']);
}
