<?php
/**
 * 前台 Token 贡献页
 * 用户按官方开发文档获取 Makers API Key，提交后由系统自动测试并入池。
 * 是否开放由后台「运行设置」的 token_contrib_enabled 控制。
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/TokenPool.php';

$settings = tp_settings();
$contribEnabled = !empty($settings['token_contrib_enabled']);
$logoEmoji = trim((string) ($settings['logo_emoji'] ?? '')) !== '' ? $settings['logo_emoji'] : '🦜';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>贡献 Token · <?php echo htmlspecialchars($settings['site_title'], ENT_QUOTES, 'UTF-8'); ?></title>
  <link rel="stylesheet" href="assets/css/style.css">
  <script src="assets/js/theme.js"></script>
  <style>
    .contrib-input {
      width: 100%; padding: 11px 14px; border: 1px solid var(--border); border-radius: 10px;
      font-size: 14px; color: var(--text); background: var(--surface); outline: none;
    }
    .contrib-input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-soft); }
    .contrib-field { margin-bottom: 14px; }
    .contrib-field label { display: block; font-size: 13px; color: var(--muted); margin-bottom: 6px; }
    .contrib-steps { margin: 6px 0 18px; padding-left: 20px; font-size: 14px; color: var(--text); }
    .contrib-steps li { margin-bottom: 6px; line-height: 1.7; }
    .doc-link { color: var(--primary); text-decoration: none; font-weight: 600; }
    .doc-link:hover { text-decoration: underline; }
    .contrib-note { font-size: 13px; color: var(--muted); margin-top: 12px; }
  </style>
</head>
<body>
  <header class="site-header">
    <div class="header-inner">
      <div class="brand" onclick="location.href='index.php'" style="cursor:pointer">
        <span class="brand-badge"><?php echo htmlspecialchars($logoEmoji, ENT_QUOTES, 'UTF-8'); ?></span>
        <span class="brand-name"><?php echo htmlspecialchars($settings['site_logo_text'], ENT_QUOTES, 'UTF-8'); ?></span>
      </div>
      <div class="header-actions" style="margin-left:auto">
        <button class="btn-ghost" data-theme-toggle title="主题切换"></button>
        <a class="btn-ghost" href="index.php">← 返回检测面板</a>
      </div>
    </div>
  </header>

  <main class="detect-wrap" style="max-width:640px">
    <section class="hero" style="margin-bottom:20px">
      <h1>🤝 贡献 Token</h1>
      <p class="hero-sub">按官方开发文档获取 Makers API Key，提交后由系统自动测试并入池，共同分担检测额度。</p>
    </section>

    <section class="panel">
      <?php if (!$contribEnabled): ?>
        <p class="status error" style="margin:0">「Token 贡献服务」暂未开放，请联系管理员开启。</p>
      <?php else: ?>
        <p class="contrib-note">获取步骤：</p>
        <ol class="contrib-steps">
          <li>打开官方文档：<a class="doc-link" href="https://cloud.tencent.com/document/product/1552/137539" target="_blank" rel="noopener">腾讯云 · 使用云雀模型</a></li>
          <li>登录 EdgeOne 控制台 → <b>Makers → Models → API Key</b> → 创建 API Key</li>
          <li>将 Key 填入下方并提交，系统会先做一次连通性测试，通过后自动加入 Token 池。</li>
        </ol>
        <p class="contrib-note">防滥用限制：Key 须为 <code>sk-</code> 开头的 <b>48 位十六进制</b>；单个 IP 最多贡献 <code><?php echo (int) CONTRIB_MAX_PER_IP; ?></code> 个 Token；两次提交至少间隔 <code><?php echo (int) CONTRIB_COOLDOWN_SEC; ?></code> 秒；池子总量上限 <code><?php echo (int) CONTRIB_POOL_LIMIT; ?></code> 个。</p>

        <div class="contrib-field">
          <label>备注名称（可选）</label>
          <input class="contrib-input" id="c-name" placeholder="例如：贡献者昵称" maxlength="30">
        </div>
        <div class="contrib-field">
          <label>Makers API Key</label>
          <input class="contrib-input" id="c-key" type="password" placeholder="sk- + 48 位十六进制" autocomplete="off">
        </div>

        <div class="detect-actions">
          <button class="btn-detect" id="btn-contribute">贡献 Token</button>
        </div>
        <p class="status" id="c-status"></p>
      <?php endif; ?>
    </section>
  </main>

  <footer class="site-footer">
    <span><?php echo htmlspecialchars($settings['footer_text'], ENT_QUOTES, 'UTF-8'); ?></span>
  </footer>

  <script>
  (function () {
    var $ = function (s) { return document.querySelector(s); };
    var btn = $('#btn-contribute');
    if (!btn) return;
    var status = $('#c-status');
    function setStatus(msg, type) {
      status.textContent = msg || '';
      status.className = 'status' + (type ? ' ' + type : '');
    }
    btn.addEventListener('click', function () {
      var key = ($('#c-key').value || '').trim().toLowerCase();
      var name = ($('#c-name').value || '').trim();
      if (!key) { setStatus('请填写 Makers API Key', 'error'); return; }
      btn.disabled = true;
      setStatus('测试并提交中…', 'ok');
      fetch('api/contribute.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ key: key, name: name }),
      }).then(function (r) { return r.json(); }).then(function (res) {
        btn.disabled = false;
        setStatus((res.ok ? '✅ ' : '❌ ') + (res.msg || res.error || ''), res.ok ? 'ok' : 'error');
        if (res.ok) { $('#c-key').value = ''; }
      }).catch(function () {
        btn.disabled = false;
        setStatus('网络请求异常', 'error');
      });
    });
  })();
  </script>
</body>
</html>