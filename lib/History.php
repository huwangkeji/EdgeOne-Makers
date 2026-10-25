<?php
/**
 * 本地历史记录存储（JSON 文件）
 *
 * 说明：仅作演示用途的轻量本地存储，单机部署够用。
 * 生产环境建议替换为数据库（如 SQLite / MySQL）。
 */

require_once __DIR__ . '/../config.php';

/**
 * 由检测响应构建一条历史记录
 * @param string $text
 * @param array  $data  检测接口原始响应
 * @param bool   $isMerge
 * @return array
 */
function zhuque_build_history_entry(string $text, array $data, bool $isMerge): array
{
    $labels = [
        '0' => '人工内容',
        '1' => 'AI 生成',
        '2' => '疑似 AI',
    ];

    $labelsRatio = $data['labels_ratio'] ?? [];
    $segments = [];
    foreach (($data['segment_labels'] ?? []) as $seg) {
        if (!is_array($seg)) {
            continue;
        }
        $segments[] = [
            'text'  => (string) ($seg['text'] ?? ''),
            'label' => (int) ($seg['label'] ?? -1),
            'label_name' => $labels[(string) ($seg['label'] ?? '')] ?? '未知',
            'conf'  => (float) ($seg['conf'] ?? 0),
            'order' => (int) ($seg['order'] ?? 0),
            'position' => $seg['position'] ?? [],
        ];
    }

    return [
        'time'     => date('Y-m-d H:i:s'),
        'text'     => $text,
        'is_merge' => $isMerge,
        'softmax_confidence' => (float) ($data['softmax_confidence'] ?? 0),
        'ratio_confidence'   => (float) ($data['ratio_confidence'] ?? 0),
        'labels_ratio'       => $labelsRatio,
        'labels_ratio_text'  => $labels,
        'segment_labels'     => $segments,
        'usage_sequence'     => (int) ($data['makers_models_usage']['total_tokens'] ?? 0),
        'verdict' => zhuque_verdict($data['softmax_confidence'] ?? 0, $data['ratio_confidence'] ?? 0),
    ];
}

/**
 * 依置信度给出判定文案
 */
function zhuque_verdict(float $softmax, float $ratio): string
{
    $score = max($softmax, $ratio);
    if ($score < 0.5) {
        return '倾向人工创作';
    }
    if ($score < 0.7) {
        return '疑似 AI 生成';
    }
    return '高度疑似 AI 生成';
}

/**
 * 读取历史记录（新记录在前）
 * @return array[]
 */
function zhuque_history_all(): array
{
    $file = __DIR__ . '/../data/history.json';
    if (!is_file($file)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

/**
 * 追加一条历史记录并裁剪超限旧记录
 */
function zhuque_history_append(array $entry): void
{
    $all = zhuque_history_all();
    array_unshift($all, $entry);
    $all = array_slice($all, 0, HISTORY_LIMIT);

    $dir = __DIR__ . '/../data';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents("$dir/history.json", json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}

/**
 * 清空历史记录
 */
function zhuque_history_clear(): void
{
    $file = __DIR__ . '/../data/history.json';
    if (is_file($file)) {
        @file_put_contents($file, '[]');
    }
}