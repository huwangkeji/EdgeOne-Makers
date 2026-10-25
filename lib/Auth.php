<?php
/**
 * 后台登录鉴权（PHP Session，无数据库）
 *
 * 密码以 bcrypt 哈希存在 data/settings.json 的 admin_password 字段。
 * 首次使用未设置密码时，hasPassword() 返回 false，登录页引导设置密码。
 */

require_once __DIR__ . '/TokenPool.php';

if (session_status() === PHP_SESSION_NONE) {
    // 同站 session cookie，增强一点安全性
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/**
 * 是否已设置后台密码
 */
function auth_has_password(): bool
{
    $s = tp_settings();
    return !empty($s['admin_password']);
}

/**
 * 设置 / 更新后台密码（明文传入，内部 bcrypt 哈希）
 */
function auth_set_password(string $plain): bool
{
    $plain = trim($plain);
    if ($plain === '') {
        return false;
    }
    $hash = password_hash($plain, PASSWORD_BCRYPT);
    return tp_save_settings(['admin_password' => $hash]);
}

/**
 * 校验密码是否正确
 */
function auth_verify(string $plain): bool
{
    $s = tp_settings();
    $hash = $s['admin_password'] ?? '';
    return $hash !== '' && password_verify($plain, $hash);
}

/**
 * 是否已登录
 */
function auth_logged_in(): bool
{
    return !empty($_SESSION['zq_admin']) && $_SESSION['zq_admin'] === true;
}

/**
 * 尝试登录
 */
function auth_login(string $plain): bool
{
    if (!auth_has_password()) {
        return false;
    }
    if (auth_verify($plain)) {
        session_regenerate_id(true);
        $_SESSION['zq_admin'] = true;
        return true;
    }
    return false;
}

/**
 * 登出
 */
function auth_logout(): void
{
    unset($_SESSION['zq_admin']);
    session_regenerate_id(true);
}

/**
 * 强制登录：供后台页面/接口调用。未登录时跳转登录页（网页）或返回 401（api）。
 * @param bool $isApi 是否 API 模式（返回 JSON 而非跳转）
 */
function auth_require(bool $isApi = false): void
{
    if (auth_logged_in()) {
        return;
    }

    if ($isApi) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        zq_flush_output();
        echo json_encode(['ok' => false, 'error' => '未登录或会话已过期，请重新登录'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Location: login.php');
    exit;
}