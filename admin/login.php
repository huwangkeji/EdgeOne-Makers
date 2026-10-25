<?php
require_once __DIR__ . '/../lib/Auth.php';

// 已登录直接进后台
if (auth_logged_in()) {
    header('Location: index.php');
    exit;
}

$error  = '';
$first  = !auth_has_password();
$action = $_POST['action'] ?? '';

if ($first && $action === 'setup') {
    $pwd  = (string) ($_POST['password'] ?? '');
    $pwd2 = (string) ($_POST['password2'] ?? '');
    if (strlen($pwd) < 6) {
        $error = '密码长度至少 6 位';
    } elseif ($pwd !== $pwd2) {
        $error = '两次输入的密码不一致';
    } else {
        auth_set_password($pwd);
        $_SESSION['zq_admin'] = true;
        header('Location: index.php');
        exit;
    }
}

if (!$first && $action === 'login') {
    $pwd = (string) ($_POST['password'] ?? '');
    if (auth_login($pwd)) {
        header('Location: index.php');
        exit;
    }
    $error = '密码错误，请重试';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>后台登录 · 云雀 AI 检测助手</title>
  <link rel="stylesheet" href="../assets/css/admin.css">
  <script src="../assets/js/theme.js"></script>
</head>
<body class="login-body">
  <div class="login-card">
    <div style="display:flex;align-items:center;justify-content:space-between">
      <span></span>
      <button class="btn btn-sm" data-theme-toggle title="主题切换"></button>
    </div>
    <h1>🦜 云雀检测 · 后台</h1>
    <p class="sub"><?php echo $first ? '首次使用，请先设置后台登录密码' : '请输入后台密码登录'; ?></p>

    <?php if ($error !== ''): ?>
      <div class="alert error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <form method="post" class="login-form">
      <input type="hidden" name="action" value="<?php echo $first ? 'setup' : 'login'; ?>">
      <?php if ($first): ?>
        <label>设置新密码</label>
        <input type="password" name="password" placeholder="至少 6 位" required>
        <label>确认密码</label>
        <input type="password" name="password2" placeholder="再次输入" required>
      <?php else: ?>
        <label>后台密码</label>
        <input type="password" name="password" placeholder="请输入密码" required autofocus>
      <?php endif; ?>
      <button class="btn-primary" type="submit"><?php echo $first ? '设置密码并进入' : '登 录'; ?></button>
    </form>

    <a class="back-link" href="../index.php">← 返回检测面板</a>
  </div>
</body>
</html>