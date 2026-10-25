<?php
/**
 * 模块：运行设置（失败阈值 / 冷却时长 / 默认合并段落）
 */
if (!defined('ZQ_ADMIN')) {
    http_response_code(403);
    exit('Forbidden');
}
?>
<section class="card">
  <h2>运行设置</h2>
  <p class="section-sub">调整 Token 自动切换与冷却策略。</p>
  <div class="settings-grid" id="settings-form">
    <div class="setting">
      <label>失败阈值（连续失败几次进入冷却）</label>
      <input type="number" id="set-threshold" min="1" max="20">
    </div>
    <div class="setting">
      <label>冷却时长（秒）</label>
      <input type="number" id="set-cooldown" min="10" max="86400">
    </div>
    <div class="setting checkbox-row">
      <input type="checkbox" id="set-merge">
      <label for="set-merge" style="margin:0">默认合并段落（整体输出单一置信度）</label>
    </div>
    <div class="setting checkbox-row">
      <input type="checkbox" id="set-contrib">
      <label for="set-contrib" style="margin:0">开放「贡献 Token」服务（用户可在前台提交自己的 API Key 自动入库）</label>
    </div>
  </div>
  <div class="form-footer">
    <button class="btn btn-primary" id="btn-save-settings">保存设置</button>
  </div>
</section>

<script>
(function () {
  var $ = ZQAdmin.$, post = ZQAdmin.post, toast = ZQAdmin.toast;

  post({ action: 'settings_get' }).then(function (res) {
    if (!res.ok) { toast(res.error || '加载失败', 'error'); return; }
    var s = res.settings;
    $('#set-threshold').value = s.fail_threshold;
    $('#set-cooldown').value = s.cooldown_seconds;
    $('#set-merge').checked = !!s.default_is_merge;
    $('#set-contrib').checked = s.token_contrib_enabled !== false;
  }).catch(function () { toast('网络异常', 'error'); });

  $('#btn-save-settings').addEventListener('click', function () {
    post({
      action: 'settings_set',
      settings: {
        fail_threshold: parseInt($('#set-threshold').value, 10),
        cooldown_seconds: parseInt($('#set-cooldown').value, 10),
        default_is_merge: $('#set-merge').checked,
        token_contrib_enabled: $('#set-contrib').checked,
      },
    }).then(function (r) { toast(r.msg || r.error, r.ok ? 'ok' : 'error'); });
  });
})();
</script>
