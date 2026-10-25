<?php
/**
 * 后台 Token 管理接口（需登录）
 *
 * POST /api/tokens.php
 *   { action: "list" }                                   获取列表/总览
 *   { action: "add",    key, name? }                     新增 token
 *   { action: "update", id, name?, key? }                更新名称/key
 *   { action: "toggle", id }                             启用/停用
 *   { action: "poke",   id }                             手动解除冷却
 *   { action: "remove", id }                             删除
 *   { action: "test",   id }                             用该 token 做一次真实探测
 *   { action: "settings_get" }                           读取设置
 *   { action: "settings_set", settings:{...} }           保存设置
 *   { action: "password_set", password }                 设置/修改后台密码
 */

require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/ZhuqueClient.php';

ob_start(); // 捕获任何杂散输出，确保最终 JSON 纯净
header('Content-Type: application/json; charset=utf-8');
auth_require(true); // 未登录返回 401 JSON

function respond(array $payload): void
{
    zq_flush_output();
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
        respond(['ok' => true, 'summary' => tp_summary(), 'settings' => tp_settings()]);
        break;

    case 'add':
        $res = tp_add((string) ($body['key'] ?? ''), (string) ($body['name'] ?? ''));
        if ($res['ok']) {
            respond(['ok' => true, 'msg' => 'Token 已添加', 'id' => $res['id']]);
        }
        respond(['ok' => false, 'error' => $res['msg']]);
        break;

    case 'update':
        $id = (string) ($body['id'] ?? '');
        if ($id === '') {
            respond(['ok' => false, 'error' => '缺少 id']);
        }
        $patch = [];
        if (array_key_exists('name', $body)) {
            $patch['name'] = (string) $body['name'];
        }
        if (array_key_exists('key', $body) && trim((string) $body['key']) !== '') {
            $patch['key'] = (string) $body['key'];
        }
        tp_update($id, $patch);
        respond(['ok' => true, 'msg' => '已更新']);
        break;

    case 'toggle':
        tp_toggle((string) ($body['id'] ?? ''));
        respond(['ok' => true, 'msg' => '已切换启用状态']);
        break;

    case 'poke':
        tp_poke((string) ($body['id'] ?? ''));
        respond(['ok' => true, 'msg' => '已解除冷却']);
        break;

    case 'remove':
        tp_remove((string) ($body['id'] ?? ''));
        respond(['ok' => true, 'msg' => '已删除']);
        break;

    case 'test':
        $id = (string) ($body['id'] ?? '');
        $all = tp_tokens();
        $found = null;
        foreach ($all as $t) {
            if ($t['id'] === $id) {
                $found = $t;
                break;
            }
        }
        if ($found === null) {
            respond(['ok' => false, 'error' => 'Token 不存在']);
        }
        // 用该 token 直接探测一段短文本
        $client = new ZhuqueClient();
        try {
            $data = $client->testToken((string) $found['key']);
            tp_mark_success($id, (int) ($data['makers_models_usage']['total_tokens'] ?? 0));
            respond(['ok' => true, 'msg' => '连接成功', 'used' => $data['makers_models_usage']['total_tokens'] ?? 0]);
        } catch (ZhuqueAuthException $e) {
            tp_mark_fail($id, $e->getMessage());
            respond(['ok' => false, 'error' => '鉴权/限流失败：' . $e->getMessage()]);
        } catch (ZhuqueException $e) {
            respond(['ok' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'test_all':
        $client = new ZhuqueClient();
        $all = tp_tokens();
        $results = [];
        $okCount = 0;
        foreach ($all as $t) {
            try {
                $td = $client->testToken((string) $t['key']);
                // 测试也会实际消耗少量额度，一并累计
                tp_mark_success((string) $t['id'], (int) ($td['makers_models_usage']['total_tokens'] ?? 0));
                $results[] = ['id' => $t['id'], 'name' => $t['name'], 'ok' => true, 'msg' => '可用'];
                $okCount++;
            } catch (ZhuqueAuthException $e) {
                // 鉴权/限流类失败：记入冷却统计
                tp_mark_fail((string) $t['id'], $e->getMessage());
                $results[] = ['id' => $t['id'], 'name' => $t['name'], 'ok' => false, 'msg' => $e->getMessage()];
            } catch (ZhuqueException $e) {
                $results[] = ['id' => $t['id'], 'name' => $t['name'], 'ok' => false, 'msg' => $e->getMessage()];
            }
        }
        respond(['ok' => true, 'results' => $results, 'ok_count' => $okCount, 'total' => count($all)]);
        break;

    case 'settings_get':
        respond(['ok' => true, 'settings' => tp_settings()]);
        break;

    case 'test_key':
        $k = trim((string) ($body['key'] ?? ''));
        if ($k === '') {
            respond(['ok' => false, 'error' => '请输入要测试的 Token']);
        }
        $client = new ZhuqueClient();
        try {
            $td = $client->testToken($k);
            respond(['ok' => true, 'msg' => '连接成功', 'used' => $td['makers_models_usage']['total_tokens'] ?? 0]);
        } catch (ZhuqueAuthException $e) {
            respond(['ok' => false, 'error' => '鉴权/限流失败：' . $e->getMessage()]);
        } catch (ZhuqueException $e) {
            respond(['ok' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'settings_set':
        $set = $body['settings'] ?? [];
        if (!is_array($set)) {
            respond(['ok' => false, 'error' => 'settings 格式错误']);
        }
        // 数值型（≥1）
        $ints = ['fail_threshold', 'cooldown_seconds', 'image_detect_max',
                 'monitor_interval_min', 'monitor_delete_after'];
        // 数值型（允许 0 = 不限额度）
        $zeroableInts = ['monitor_quota'];
        // 布尔型
        $bools = ['default_is_merge', 'show_batch', 'show_merge', 'show_usage', 'show_ratio', 'show_segment',
            'token_contrib_enabled', 'monitor_enabled', 'monitor_auto_delete'];        // 字符串型（网站信息 + 个性化）
        $strings = ['site_title', 'site_logo_text', 'site_subtitle', 'site_intro',
                    'footer_text', 'image_detect_url',
                    'logo_emoji', 'theme_primary', 'theme_bg', 'announcement',
                    'footer_extra', 'custom_css', 'monitor_key'];
        $save = [];
        foreach ($set as $k => $v) {
            if (in_array($k, $ints, true)) {
                $save[$k] = max(1, (int) $v);
            } elseif (in_array($k, $zeroableInts, true)) {
                $save[$k] = max(0, (int) $v);
            } elseif (in_array($k, $bools, true)) {
                $save[$k] = (bool) $v;
            } elseif (in_array($k, $strings, true)) {
                $save[$k] = (string) $v;
            }
            // 其余字段忽略，避免写入脏数据
        }
        tp_save_settings($save);
        respond(['ok' => true, 'msg' => '设置已保存']);
        break;

    case 'password_set':
        $pwd = (string) ($body['password'] ?? '');
        if (strlen($pwd) < 6) {
            respond(['ok' => false, 'error' => '密码长度至少 6 位']);
        }
        auth_set_password($pwd);
        $_SESSION['zq_admin'] = true;
        respond(['ok' => true, 'msg' => '密码已设置']);
        break;

    default:
        respond(['ok' => false, 'error' => '未知操作: ' . $action]);
}