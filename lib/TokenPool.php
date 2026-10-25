<?php
/**
 * 多 Token 池 —— 存储 / 轮询 / 故障冷却 / 用量统计
 *
 * 数据存储（无数据库，全部为 JSON 文件）：
 *   - data/tokens.json      token 列表
 *   - data/settings.json    应用设置（含轮询游标、故障阈值、冷却时长）
 */

require_once __DIR__ . '/../config.php';

/* ---------------- 通用读写 ---------------- */

function tp_data_dir(): string
{
    $dir = __DIR__ . '/../data';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

function tp_json_read(string $file, $default)
{
    if (!is_file($file)) {
        return $default;
    }
    $raw = file_get_contents($file);
    $data = json_decode((string) $raw, true);
    return is_array($data) ? $data : $default;
}

function tp_json_write(string $file, array $data): bool
{
    $tmp = $file . '.tmp';
    if (@file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) {
        return false;
    }
    return @rename($tmp, $file) || @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false;
}

/* ---------------- 设置 ---------------- */

function tp_settings(): array
{
    $def = [
        'admin_password'   => '',    // 后台登录密码（hash）
        'fail_threshold'   => 3,     // 连续失败多少次要进入冷却
        'cooldown_seconds' => 300,   // 冷却时长（秒）
        'default_is_merge' => true,  // 默认是否合并段落
        'tp_cursor'        => 0,     // 轮询游标
        'monitor_key'      => '',    // cron 监控触发密钥（后台可视化设置，可留空走 data/config.json / 环境变量 / 常量）
        // ---- Token 自动监控配置（后台可视化设置，默认取自 config.php 常量） ----
        'monitor_enabled'      => true,
        'monitor_interval_min' => 30,
        'monitor_quota'        => (defined('FREE_MONTHLY_QUOTA') ? (int) FREE_MONTHLY_QUOTA : 0),
        'monitor_auto_delete'  => (defined('MONITOR_AUTO_DELETE') ? (bool) MONITOR_AUTO_DELETE : true),
        'monitor_delete_after' => (defined('MONITOR_DELETE_AFTER_FAILS') ? (int) MONITOR_DELETE_AFTER_FAILS : 4),
        // ---- 网站设置（前台展示信息） ----
        'site_title'       => '云雀 AI 检测助手',   // 浏览器/顶部标题
        'site_logo_text'   => '云雀AI检测助手',     // 顶部品牌文字
        'site_subtitle'    => 'AI 生成内容识别 · 文本 / 图像检测',
        'site_intro'       => '输入文本或上传图片，一键识别内容是否由 AI 生成。检测结果仅供参考。',
        'footer_text'      => '© 云雀 AI 检测助手 · 基于腾讯云技术能力，检测结果仅供辅助参考',
        // ---- 个性化：品牌外观 ----
        'logo_emoji'       => '🦜',       // 顶部品牌徽标 emoji
        'theme_primary'    => '#0052d9',  // 前台主题主色（十六进制）
        'theme_bg'         => 'default',  // 背景样式：default / gradient / plain / aurora
        'announcement'     => '',         // 顶部公告横幅（留空不显示）
        'footer_extra'     => '',         // 页脚第二行（备案号 / 链接 / 联系方式）
        'custom_css'       => '',         // 自定义 CSS（注入前台，谨慎使用）
        // ---- 个性化：检测选项与展示开关 ----
        'show_batch'       => true,       // 显示「批量」选项
        'show_merge'       => true,       // 显示「合并段落」选项
        'show_usage'       => true,       // 结果显示本次用量 token
        'show_ratio'       => true,       // 显示风险占比指标与占比条
        'show_segment'     => true,       // 显示分段明细
        // ---- 贡献服务 ----
        'token_contrib_enabled' => true,   // 是否开放前台 Token 贡献服务
        // ---- 图像检测配置 ----
        'image_detect_url' => '',    // 图像检测接口地址（空=关闭）
        'image_detect_max' => 5,     // 单次最多上传图片数量
    ];
    $s = tp_json_read(tp_data_dir() . '/settings.json', []);
    return array_merge($def, is_array($s) ? $s : []);
}

function tp_save_settings(array $settings): bool
{
    $s = tp_settings();
    foreach ($settings as $k => $v) {
        $s[$k] = $v;
    }
    return tp_json_write(tp_data_dir() . '/settings.json', $s);
}

/* ---------------- Token 池 ---------------- */

function tp_tokens(): array
{
    $tokens = tp_json_read(tp_data_dir() . '/tokens.json', []);
    $out = [];
    foreach ($tokens as $t) {
        if (is_array($t) && !empty($t['key'])) {
            $out[] = array_merge([
                'id'            => tp_uid(),
                'name'          => '未命名',
                'key'           => '',
                'enabled'       => true,
                'invalid_streak'=> 0,   // 连续鉴权无效（401/403）计数，用于自动删除
                'fail_streak'   => 0,
                'cooldown_until'=> 0,
                'total_calls'   => 0,
                'total_tokens'  => 0, // 累计消耗的 Makers 免费额度 token 数（来自 makers_models_usage.total_tokens）
                'last_used'     => '',
                'last_error'    => '',
                'disabled_reason'=> '',   // 自动/手动禁用原因
                'disabled_at'   => '',    // 禁用时间
                'last_test_at'  => '',    // 最近一次监控测试时间
                'last_test_ok'  => null,  // 最近一次监控测试结果：true/false/null
                'created_at'    => date('Y-m-d H:i:s'),
            ], $t);
        }
    }
    return $out;
}

function tp_save_tokens(array $tokens): bool
{
    return tp_json_write(tp_data_dir() . '/tokens.json', $tokens);
}

function tp_uid(): string
{
    return bin2hex(random_bytes(8));
}

/**
 * 当前真正可用（启用且未在冷却期）的 token
 */
function tp_available(): array
{
    $now = time();
    return array_values(array_filter(tp_tokens(), function ($t) use ($now) {
        return $t['enabled'] && (int) $t['cooldown_until'] <= $now;
    }));
}

/**
 * 按轮询游标取下一个可用 token，并推进游标。
 * @return array{token:?array, index:int} token 为 null 表示无可用
 */
function tp_next(): array
{
    $all = tp_tokens();
    if (empty($all)) {
        return ['token' => null, 'index' => -1];
    }

    $settings = tp_settings();
    $cursor = (int) $settings['tp_cursor'];
    $count = count($all);
    $now = time();

    // 找出第一个可用项的偏移；若游标指向不可用项则顺序向后找
    for ($i = 0; $i < $count; $i++) {
        $idx = ($cursor + $i) % $count;
        $t = $all[$idx];
        if ($t['enabled'] && (int) $t['cooldown_until'] <= $now) {
            // 顺带推进游标，实现轮询
            tp_save_settings(['tp_cursor' => ($idx + 1) % $count]);
            return ['token' => $t, 'index' => $idx];
        }
    }

    return ['token' => null, 'index' => -1];
}

/** 记录一次成功；累计 calls 并累加官方返回的用量（makers_models_usage.total_tokens） */
function tp_mark_success(string $id, int $usedTokens = 0): void
{
    $all = tp_tokens();
    foreach ($all as &$t) {
        if ($t['id'] === $id) {
            $t['fail_streak']    = 0;
            $t['cooldown_until'] = 0;
            $t['last_error']     = '';
            $t['total_calls']    = (int) $t['total_calls'] + 1;
            $t['total_tokens']   = (int) ($t['total_tokens'] ?? 0) + max(0, $usedTokens);
            $t['invalid_streak'] = 0;
            $t['last_used']      = date('Y-m-d H:i:s');
        }
    }
    tp_save_tokens($all);
}

/** 记录失败；达到阈值时进入冷却 */
function tp_mark_fail(string $id, string $error = ''): void
{
    $settings = tp_settings();
    $threshold = (int) $settings['fail_threshold'];
    $cooldown  = (int) $settings['cooldown_seconds'];

    $all = tp_tokens();
    foreach ($all as &$t) {
        if ($t['id'] === $id) {
            $t['fail_streak'] = (int) $t['fail_streak'] + 1;
            $t['last_error']  = $error;
            if ($t['fail_streak'] >= $threshold) {
                $t['cooldown_until'] = time() + $cooldown;
                $t['fail_streak']    = 0;
            }
        }
    }
    tp_save_tokens($all);
}

/** 手动停用 / 启用 */
function tp_toggle(string $id): void
{
    $all = tp_tokens();
    foreach ($all as &$t) {
        if ($t['id'] === $id) {
            $t['enabled'] = !$t['enabled'];
            // 重新启用时清空禁用标记
            if ($t['enabled']) {
                $t['disabled_reason'] = '';
                $t['disabled_at']     = '';
                $t['invalid_streak']  = 0;
            }
        }
    }
    tp_save_tokens($all);
}

/** 禁用某 token（自动监控/额度不足时调用），并记录原因与时间 */
function tp_disable(string $id, string $reason = '已禁用'): void
{
    $all = tp_tokens();
    foreach ($all as &$t) {
        if ($t['id'] === $id) {
            $t['enabled']        = false;
            $t['disabled_reason'] = $reason;
            $t['disabled_at']     = date('Y-m-d H:i:s');
        }
    }
    tp_save_tokens($all);
}

/** 记录某 token 的最近一次监控测试结果 */
function tp_mark_test(string $id, bool $ok): void
{
    $all = tp_tokens();
    foreach ($all as &$t) {
        if ($t['id'] === $id) {
            $t['last_test_at'] = date('Y-m-d H:i:s');
            $t['last_test_ok'] = $ok;
        }
    }
    tp_save_tokens($all);
}

/** 累加「鉴权无效」计数（401/403），返回累加后的值 */
function tp_bump_invalid(string $id): int
{
    $all = tp_tokens();
    $n = 0;
    foreach ($all as &$t) {
        if ($t['id'] === $id) {
            $n = (int) ($t['invalid_streak'] ?? 0) + 1;
            $t['invalid_streak'] = $n;
        }
    }
    tp_save_tokens($all);
    return $n;
}

/** 移出冷却（手动解锁） */
function tp_poke(string $id): void
{
    $all = tp_tokens();
    foreach ($all as &$t) {
        if ($t['id'] === $id) {
            $t['cooldown_until'] = 0;
            $t['fail_streak']    = 0;
        }
    }
    tp_save_tokens($all);
}

/** 删除 token */
function tp_remove(string $id): void
{
    $all = array_values(array_filter(tp_tokens(), function ($t) use ($id) {
        return $t['id'] !== $id;
    }));
    tp_save_tokens($all);
}

/** 新增 token，返回新 id；key 为空则拒绝 */
function tp_add(string $key, string $name = ''): array
{
    $key = trim($key);
    if ($key === '') {
        return ['ok' => false, 'msg' => 'Token 值不能为空'];
    }
    $all = tp_tokens();
    // 重复检测
    foreach ($all as $t) {
        if (hash_equals((string) $t['key'], $key)) {
            return ['ok' => false, 'msg' => '该 Token 已存在'];
        }
    }
    $all[] = [
        'id'            => tp_uid(),
        'name'          => trim($name) !== '' ? trim($name) : ('Token #' . (count($all) + 1)),
        'key'           => $key,
        'enabled'       => true,
        'invalid_streak'=> 0,
        'fail_streak'   => 0,
        'cooldown_until'=> 0,
        'total_calls'   => 0,
        'total_tokens'  => 0,
        'last_used'     => '',
        'last_error'    => '',
        'disabled_reason'=> '',
        'disabled_at'   => '',
        'last_test_at'  => '',
        'last_test_ok'  => null,
        'created_at'    => date('Y-m-d H:i:s'),
    ];
    tp_save_tokens($all);
    return ['ok' => true, 'id' => $all[count($all) - 1]['id']];
}

/** 更新 token 名称 / key */
function tp_update(string $id, array $patch): void
{
    $all = tp_tokens();
    foreach ($all as &$t) {
        if ($t['id'] === $id) {
            if (array_key_exists('name', $patch) && trim($patch['name']) !== '') {
                $t['name'] = trim($patch['name']);
            }
            if (array_key_exists('key', $patch) && trim((string) $patch['key']) !== '') {
                $t['key'] = trim($patch['key']);
            }
        }
    }
    tp_save_tokens($all);
}

/** 统计信息（后台总览用），会隐去 key 明文只保留前后若干字符 */
function tp_summary(): array
{
    $tokens = tp_tokens();
    $now = time();
    $available = 0;
    $totalCalls = 0;
    $list = [];
    foreach ($tokens as $t) {
        $avail = $t['enabled'] && (int) $t['cooldown_until'] <= $now;
        if ($avail && $t['enabled']) {
            $available++;
        }
        $totalCalls += (int) $t['total_calls'];
        $list[] = [
            'id'            => $t['id'],
            'name'          => $t['name'],
            'key_masked'    => tp_mask_key($t['key']),
            'key'           => (string) $t['key'], // 完整 key（仅后台登录接口返回，供查看/复制）
            'enabled'       => (bool) $t['enabled'],
            'in_cooldown'   => (int) $t['cooldown_until'] > $now,
            'fail_streak'   => (int) $t['fail_streak'],
            'total_calls'   => (int) $t['total_calls'],
            'total_tokens'  => (int) ($t['total_tokens'] ?? 0),
            'last_used'     => $t['last_used'],
            'last_error'    => $t['last_error'],
            'disabled_reason'=> (string) ($t['disabled_reason'] ?? ''),
            'disabled_at'   => (string) ($t['disabled_at'] ?? ''),
            'last_test_at'  => (string) ($t['last_test_at'] ?? ''),
            'last_test_ok'  => $t['last_test_ok'] ?? null,
            'invalid_streak'=> (int) ($t['invalid_streak'] ?? 0),
            'created_at'    => $t['created_at'],
        ];
    }
    return [
        'total'       => count($tokens),
        'enabled'     => $tokens ? count(array_filter($tokens, fn($t) => $t['enabled'])) : 0,
        'available'   => $available,
        'total_calls' => $totalCalls,
        'list'        => $list,
    ];
}

function tp_mask_key(string $key): string
{
    $len = mb_strlen($key);
    if ($len <= 8) {
        return str_repeat('•', $len);
    }
    return mb_substr($key, 0, 4) . str_repeat('•', 6) . mb_substr($key, -4);
}