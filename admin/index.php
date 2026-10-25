<?php
/**
 * 后台管理外壳 / 路由
 *
 * 每个功能拆分为独立模块文件（modules/），通过 ?m=模块键 单独显示。
 * 共享头部、侧边导航、尾部位于 partials/；公共 JS/CSS 见 assets/。
 */
require_once __DIR__ . '/../lib/Auth.php';
auth_require(false);

if (isset($_GET['logout'])) {
    auth_logout();
    header('Location: login.php');
    exit;
}

define('ZQ_ADMIN', true);

// 模块注册表：key => [label, icon, file]
$modules = [
    'dashboard' => ['label' => '总览',     'icon' => '📊', 'file' => 'dashboard.php'],
    'records'   => ['label' => '提交明细', 'icon' => '📝', 'file' => 'records.php'],
    'records_ip'=> ['label' => '按 IP 汇总', 'icon' => '🌐', 'file' => 'records_ip.php'],
    'monitor'   => ['label' => 'Token 监控', 'icon' => '🛰️', 'file' => 'monitor.php'],
    'tokens'    => ['label' => 'Token 池', 'icon' => '🔑', 'file' => 'tokens.php'],
    'runtime'   => ['label' => '运行设置', 'icon' => '⚙️', 'file' => 'runtime.php'],
    'site'      => ['label' => '网站设置', 'icon' => '🌐', 'file' => 'site.php'],
    'persona'   => ['label' => '个性化',   'icon' => '🎨', 'file' => 'persona.php'],
    'security'  => ['label' => '安全设置', 'icon' => '🔒', 'file' => 'security.php'],
];

$activeModule = isset($_GET['m']) ? (string) $_GET['m'] : 'dashboard';
if (!isset($modules[$activeModule])) {
    $activeModule = 'dashboard';
}

$pageTitle  = $modules[$activeModule]['label'];
$moduleFile = __DIR__ . '/modules/' . $modules[$activeModule]['file'];

require __DIR__ . '/partials/head.php';
require __DIR__ . '/partials/header.php';
?>
<div class="admin-layout">
  <?php require __DIR__ . '/partials/nav.php'; ?>
  <main class="admin-content">
    <?php
    if (is_file($moduleFile)) {
        require $moduleFile;
    } else {
        echo '<section class="card"><h2>模块缺失</h2><p class="section-sub">未找到模块文件：'
            . htmlspecialchars($modules[$activeModule]['file'], ENT_QUOTES, 'UTF-8') . '</p></section>';
    }
    ?>
  </main>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
