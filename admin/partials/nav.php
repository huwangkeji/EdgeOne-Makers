<?php
/**
 * 后台侧边模块导航。依赖 index.php 中的 $modules 与 $activeModule。
 * 每个模块通过 ?m=模块键 独立展示。
 */
if (!defined('ZQ_ADMIN')) {
    http_response_code(403);
    exit('Forbidden');
}
$__modules = isset($modules) && is_array($modules) ? $modules : [];
$__active  = isset($activeModule) ? $activeModule : 'dashboard';
?>
  <aside class="admin-sidebar">
    <nav class="admin-nav" aria-label="后台模块导航">
      <?php foreach ($__modules as $__key => $__mod): ?>
      <a class="admin-nav-item<?php echo $__key === $__active ? ' active' : ''; ?>"
         href="?m=<?php echo urlencode((string) $__key); ?>"
         <?php echo $__key === $__active ? 'aria-current="page"' : ''; ?>>
        <span class="nav-ico" aria-hidden="true"><?php echo $__mod['icon']; ?></span>
        <span class="nav-label"><?php echo htmlspecialchars($__mod['label'], ENT_QUOTES, 'UTF-8'); ?></span>
      </a>
      <?php endforeach; ?>
    </nav>
  </aside>
