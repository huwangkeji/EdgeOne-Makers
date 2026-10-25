<?php
/**
 * 云雀 AI 检测助手 —— 前端检测面板（腾讯矩阵风格 / 响应式）
 *
 * 使用说明：文本检测 + 图像检测；图像检测依赖后台「网站设置」中的接口地址。
 * app.js 依赖以下结构中的 id，改动务必同步。
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/TokenPool.php';

$hasAnyToken = count(tp_available()) > 0;
$s = array_merge([
    'site_title' => '云雀 AI 检测助手',
    'site_logo_text' => '云雀AI检测助手',
    'site_subtitle' => 'AI 生成内容识别 · 文本 / 图像检测',
    'site_intro' => '输入文本或上传图片，一键识别内容是否由 AI 生成。检测结果仅供参考。',
    'footer_text' => '© 云雀 AI 检测助手 · 检测结果仅供辅助参考',
    'image_detect_url' => '',
    'image_detect_max' => 5,
    // 个性化默认值（tp_settings 已含，这里兜底）
    'logo_emoji' => '🦜',
    'theme_primary' => '#0052d9',
    'theme_bg' => 'default',
    'announcement' => '',
    'footer_extra' => '',
    'custom_css' => '',
    'show_batch' => true,
    'show_merge' => true,
    'show_usage' => true,
    'show_ratio' => true,
    'show_segment' => true,
], tp_settings());

/* ---------- 颜色工具（用于把后台主题色注入前台 CSS 变量） ---------- */
function zq_hex_rgb(string $hex): ?array
{
    $hex = ltrim(trim($hex), '#');
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
        return null;
    }
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}
/** 调整明度：$pct 为负数变暗，正数变亮 */
function zq_adjust_hex(string $hex, int $pct): string
{
    $rgb = zq_hex_rgb($hex);
    if ($rgb === null) {
        return $hex;
    }
    $out = '#';
    foreach ($rgb as $c) {
        $delta = ($pct / 100) * ($pct >= 0 ? (255 - $c) : $c);
        $c = max(0, min(255, (int) round($c + $delta)));
        $out .= str_pad(dechex($c), 2, '0', STR_PAD_LEFT);
    }
    return $out;
}
function zq_hex_rgba(string $hex, float $alpha): string
{
    $rgb = zq_hex_rgb($hex);
    if ($rgb === null) {
        return 'rgba(0,82,217,' . $alpha . ')';
    }
    return 'rgba(' . $rgb[0] . ',' . $rgb[1] . ',' . $rgb[2] . ',' . $alpha . ')';
}

$primary      = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($s['theme_primary'] ?? '')) ? (string) $s['theme_primary'] : '#0052d9';
$primaryHover = zq_adjust_hex($primary, -18);
$primaryDark  = zq_adjust_hex($primary, 22);
$primaryDarkHover = zq_adjust_hex($primary, 32);
$primary2     = zq_adjust_hex($primary, 14);
$bgStyle      = in_array(($s['theme_bg'] ?? 'default'), ['default', 'gradient', 'aurora', 'plain'], true) ? $s['theme_bg'] : 'default';
$logoEmoji    = trim((string) ($s['logo_emoji'] ?? '')) !== '' ? (string) $s['logo_emoji'] : '🦜';
$announcement = trim((string) ($s['announcement'] ?? ''));
$footerExtra  = trim((string) ($s['footer_extra'] ?? ''));
$customCss    = str_ireplace('</style', '', (string) ($s['custom_css'] ?? ''));
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($s['site_title'], ENT_QUOTES, 'UTF-8'); ?></title>
  <meta name="description" content="<?php echo htmlspecialchars($s['site_intro'], ENT_QUOTES, 'UTF-8'); ?>">
  <link rel="stylesheet" href="assets/css/style.css">
  <style id="zq-theme-vars">
    :root {
      --primary: <?php echo $primary; ?>;
      --primary-hover: <?php echo $primaryHover; ?>;
      --primary-soft: <?php echo zq_hex_rgba($primary, 0.08); ?>;
    }
    html[data-theme="dark"] {
      --primary: <?php echo $primaryDark; ?>;
      --primary-hover: <?php echo $primaryDarkHover; ?>;
      --primary-soft: <?php echo zq_hex_rgba($primaryDark, 0.14); ?>;
    }
    .brand-badge {
      background: linear-gradient(135deg, <?php echo $primary; ?>, <?php echo $primary2; ?>);
      box-shadow: 0 4px 10px <?php echo zq_hex_rgba($primary, 0.25); ?>;
    }
    .btn-detect { box-shadow: 0 6px 16px <?php echo zq_hex_rgba($primary, 0.28); ?>; }
  </style>
  <style id="zq-bg-style">
  <?php if ($bgStyle === 'gradient'): ?>
    body { background: linear-gradient(135deg, <?php echo zq_hex_rgba($primary, 0.18); ?> 0%, <?php echo zq_hex_rgba($primary2, 0.10); ?> 32%, var(--bg) 72%) !important; }
    html[data-theme="dark"] body { background: linear-gradient(135deg, <?php echo zq_hex_rgba($primaryDark, 0.22); ?> 0%, var(--bg) 62%) !important; }
  <?php elseif ($bgStyle === 'aurora'): ?>
    body { background: radial-gradient(900px 520px at 15% -10%, <?php echo zq_hex_rgba($primary, 0.28); ?>, transparent 60%), radial-gradient(820px 520px at 90% 6%, <?php echo zq_hex_rgba($primary2, 0.20); ?>, transparent 60%), var(--bg) !important; }
    html[data-theme="dark"] body { background: radial-gradient(900px 520px at 15% -10%, <?php echo zq_hex_rgba($primaryDark, 0.32); ?>, transparent 60%), radial-gradient(820px 520px at 90% 6%, <?php echo zq_hex_rgba($primaryDark, 0.20); ?>, transparent 60%), var(--bg) !important; }
  <?php elseif ($bgStyle === 'plain'): ?>
    body { background: var(--bg) !important; }
  <?php endif; ?>
  </style>
  <?php if ($customCss !== ''): ?>
  <style id="zq-custom-css"><?php echo $customCss; ?></style>
  <?php endif; ?>
  <script src="assets/js/theme.js"></script>
</head>
<body>
  <!-- 顶部导航 -->
  <header class="site-header">
    <div class="header-inner">
      <div class="brand" id="btn-home">
        <span class="brand-badge"><?php echo htmlspecialchars($logoEmoji, ENT_QUOTES, 'UTF-8'); ?></span>
        <span class="brand-name" id="site-logo-text"><?php echo htmlspecialchars($s['site_logo_text'], ENT_QUOTES, 'UTF-8'); ?></span>
      </div>

      <nav class="main-nav" aria-label="检测类型">
        <button class="nav-tab active" data-mode="text" id="tab-text">✍️ 文本检测</button>
        <button class="nav-tab" data-mode="image" id="tab-image">🖼️ 图像检测</button>
      </nav>

      <div class="header-actions">
        <div class="theme-switch" data-theme-switch title="日间 / 夜间皮肤切换" aria-label="皮肤切换"></div>
        <button class="btn-ghost" id="btn-history">📜 历史</button>
        <a class="btn-ghost" href="contribute.php">🤝 贡献</a>
        <a class="btn-ghost" href="admin/index.php">⚙️ 后台管理</a>
      </div>
    </div>
  </header>

  <?php if ($announcement !== ''): ?>
  <div class="announcement" role="status"><span class="announcement-icon">📢</span><?php echo htmlspecialchars($announcement, ENT_QUOTES, 'UTF-8'); ?></div>
  <?php endif; ?>

  <main class="detect-wrap">
    <!-- 主标题区 -->
    <section class="hero">
      <span class="hero-eyebrow">✨ 基于腾讯云 EdgeOne Makers · 云雀模型</span>
      <h1 id="site-subtitle"><?php echo htmlspecialchars($s['site_subtitle'], ENT_QUOTES, 'UTF-8'); ?></h1>
      <p class="hero-sub" id="site-intro"><?php echo htmlspecialchars($s['site_intro'], ENT_QUOTES, 'UTF-8'); ?></p>
    </section>

    <!-- 特性速览 -->
    <section class="band band-features" aria-label="产品特性">
      <div class="feat"><span class="feat-ico">🔑</span><div><b>多 Token 自动切换</b><span>401/403/429 自动换下一个，稳定可用</span></div></div>
      <div class="feat"><span class="feat-ico">🤖</span><div><b>官方云雀模型</b><span>腾讯云 EdgeOne Makers 置信度识别</span></div></div>
      <div class="feat"><span class="feat-ico">📊</span><div><b>多维度解析</b><span>整体 / 风险占比 + 分段明细</span></div></div>
      <div class="feat"><span class="feat-ico">🛡️</span><div><b>本地留存</b><span>历史记录本地保存，无数据库</span></div></div>
    </section>

    <!-- ============ 文本检测面板 ============ -->
    <section class="panel" id="panel-text">
      <div class="input-box">
        <textarea id="input-text" rows="7" placeholder="请输入待检测的文本内容…，支持整段粘贴"></textarea>
        <div class="input-toolbar">
          <span class="char-count" id="char-count">0 字</span>
          <div class="toolbar-right">
            <button class="btn-link" id="btn-example">换一段示例</button>
            <button class="btn-link" id="btn-clear">清空</button>
          </div>
        </div>
      </div>
      <div class="detect-actions">
        <div class="action-opts">
          <?php if (!empty($s['show_batch'])): ?>
          <label class="checkbox lbl-batch"><input type="checkbox" id="is-batch">批量（每行一条）</label>
          <?php endif; ?>
          <?php if (!empty($s['show_merge'])): ?>
          <label class="checkbox"><input type="checkbox" id="is-merge" checked>合并段落</label>
          <?php endif; ?>
        </div>
        <button class="btn-detect" id="btn-detect-text">开始检测</button>
      </div>
      <p class="status" id="status"></p>
    </section>

    <!-- ============ 图像检测面板 ============ -->
    <section class="panel hidden" id="panel-image">
      <div class="img-drop" id="img-drop" tabindex="0">
        <input type="file" id="img-file" accept="image/*" multiple hidden>
        <div class="img-drop-hint">
          <span class="drop-icon">🖼️</span>
          <p class="drop-main">点击选择或拖拽图片到这里</p>
          <p class="drop-sub">支持 JPG / PNG / WebP，最多 <?php echo (int) $s['image_detect_max']; ?> 张</p>
        </div>
      </div>
      <div class="img-url-row">
        <input type="text" id="img-url" placeholder="或粘贴图片 URL（每行一张）">
      </div>
      <div class="img-thumbs" id="img-thumbs"></div>
      <div class="detect-actions">
        <button class="btn-detect" id="btn-detect-img">检测图片</button>
      </div>
      <p class="hint" id="image-hint"></p>
    </section>

    <!-- ============ 结果区 ============ -->
    <section class="result-area" id="result-area">
      <div class="result-placeholder" id="result-empty">
        <span class="ph-icon">🔍</span>
        <p>检测结果将显示在这里</p>
      </div>
      <div id="result-card" class="hidden"></div>
    </section>

    <!-- 使用流程与信任 -->
    <section class="band band-steps" aria-label="使用步骤">
      <h2 class="band-title">三步开始检测</h2>
      <div class="steps">
        <div class="step"><span class="step-num">1</span><div><b>输入内容</b><span>粘贴文本或上传图片</span></div></div>
        <div class="step"><span class="step-num">2</span><div><b>一键检测</b><span>自动匹配可用 Token</span></div></div>
        <div class="step"><span class="step-num">3</span><div><b>查看结果</b><span>置信度 / 占比 / 分段明细</span></div></div>
      </div>
      <div class="trust">
        <span>✅ 腾讯云能力支持</span>
        <span>🔐 数据本地留存</span>
        <span>⚡ 实时返回</span>
        <span>🔄 多 Key 容灾</span>
      </div>
    </section>
  </main>

  <footer class="site-footer">
    <span id="site-footer-text"><?php echo htmlspecialchars($s['footer_text'], ENT_QUOTES, 'UTF-8'); ?></span>
    <?php if ($footerExtra !== ''): ?>
    <div class="footer-extra"><?php echo htmlspecialchars($footerExtra, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
  </footer>

  <!-- 历史抽屉 -->
  <div class="drawer-mask hidden" id="drawer-mask"></div>
  <aside class="drawer hidden" id="history-drawer">
    <div class="drawer-head">
      <h2>历史记录</h2>
      <div>
        <button class="btn-ghost btn-sm" id="btn-clear-history">清空</button>
        <button class="btn-ghost btn-sm" id="btn-close-drawer">✕</button>
      </div>
    </div>
    <div class="drawer-body" id="history-list"></div>
  </aside>

  <script src="assets/js/app.js"></script>
  <script>
    window.ZHUQUE_HAS_TOKEN = <?php echo $hasAnyToken ? 'true' : 'false'; ?>;
    window.ZQ_SITE = <?php echo json_encode([
        'image_detect_url' => $s['image_detect_url'],
        'image_detect_max' => (int) $s['image_detect_max'],
        'site_subtitle' => $s['site_subtitle'],
        'site_intro' => $s['site_intro'],
        'show_usage' => (bool) ($s['show_usage'] ?? true),
        'show_ratio' => (bool) ($s['show_ratio'] ?? true),
        'show_segment' => (bool) ($s['show_segment'] ?? true),
        'show_batch' => (bool) ($s['show_batch'] ?? true),
        'show_merge' => (bool) ($s['show_merge'] ?? true),
    ], JSON_UNESCAPED_UNICODE); ?>;
  </script>
</body>
</html>