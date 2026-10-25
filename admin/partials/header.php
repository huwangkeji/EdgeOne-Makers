<?php
/**
 * 后台顶部栏 + Toast 容器。
 */
if (!defined('ZQ_ADMIN')) {
    http_response_code(403);
    exit('Forbidden');
}
?>
  <header class="admin-top">
    <div class="admin-top-inner">
      <h1>🦜 云雀检测 · 后台管理</h1>
      <div class="top-actions">
        <a class="btn" href="../index.php">检测面板</a>
        <button class="btn" data-theme-toggle title="主题切换"></button>
        <a class="btn" href="?logout=1">退出登录</a>
      </div>
    </div>
  </header>

  <div class="toast" id="toast"></div>
