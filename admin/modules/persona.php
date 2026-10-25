<?php
/**
 * 模块：个性化设置（品牌外观 + 展示开关）
 */
if (!defined('ZQ_ADMIN')) {
    http_response_code(403);
    exit('Forbidden');
}
?>
<section class="card">
  <h2>个性化设置（前台外观与展示）</h2>
  <div class="settings-grid" style="grid-template-columns:repeat(auto-fit,minmax(260px,1fr))">
    <div class="setting">
      <label>品牌徽标 Emoji（顶部 LOGO 图标）</label>
      <input type="text" id="set-logo-emoji" maxlength="4" placeholder="🦜">
    </div>
    <div class="setting">
      <label>主题主色</label>
      <div class="color-row">
        <input type="color" id="set-theme-primary" value="#0052d9">
        <input type="text" id="set-theme-primary-text" placeholder="#0052d9">
      </div>
    </div>
    <div class="setting">
      <label>背景样式</label>
      <select id="set-theme-bg">
        <option value="default">默认（柔和渐变）</option>
        <option value="gradient">彩色渐变</option>
        <option value="aurora">极光暗色</option>
        <option value="plain">纯色简洁</option>
      </select>
    </div>
    <div class="setting" style="grid-column:1/-1">
      <label>顶部公告横幅（留空则不显示）</label>
      <input type="text" id="set-announcement" placeholder="例如：免费额度每月 50 万 token，请合理使用">
    </div>
    <div class="setting" style="grid-column:1/-1">
      <label>页脚附加信息（第二行，可放备案号 / 联系方式 / 链接）</label>
      <input type="text" id="set-footer-extra" placeholder="例如：京ICP备00000000号 · 联系我们">
    </div>
    <div class="setting" style="grid-column:1/-1">
      <label>自定义 CSS（注入前台，谨慎使用）</label>
      <textarea id="set-custom-css" rows="5" placeholder=".hero h1 { letter-spacing: 2px; }"></textarea>
    </div>
    <div class="setting checkbox-row" style="grid-column:1/-1">
      <input type="checkbox" id="set-show-batch">
      <label for="set-show-batch" style="margin:0">显示「批量（每行一条）」选项</label>
    </div>
    <div class="setting checkbox-row" style="grid-column:1/-1">
      <input type="checkbox" id="set-show-merge">
      <label for="set-show-merge" style="margin:0">显示「合并段落」选项</label>
    </div>
    <div class="setting checkbox-row" style="grid-column:1/-1">
      <input type="checkbox" id="set-show-usage">
      <label for="set-show-usage" style="margin:0">结果显示「本次用量 token」</label>
    </div>
    <div class="setting checkbox-row" style="grid-column:1/-1">
      <input type="checkbox" id="set-show-ratio">
      <label for="set-show-ratio" style="margin:0">显示「风险占比」指标与占比条</label>
    </div>
    <div class="setting checkbox-row" style="grid-column:1/-1">
      <input type="checkbox" id="set-show-segment">
      <label for="set-show-segment" style="margin:0">显示「分段明细」</label>
    </div>
  </div>
  <div class="form-footer">
    <button class="btn btn-primary" id="btn-save-persona">保存个性化设置</button>
    <span class="muted" style="margin-left:10px;font-size:12px">保存后刷新前台查看效果</span>
  </div>
</section>

<script>
(function () {
  var $ = ZQAdmin.$, post = ZQAdmin.post, toast = ZQAdmin.toast;

  post({ action: 'settings_get' }).then(function (res) {
    if (!res.ok) { toast(res.error || '加载失败', 'error'); return; }
    var s = res.settings;
    $('#set-logo-emoji').value = s.logo_emoji || '🦜';
    var pc = s.theme_primary || '#0052d9';
    $('#set-theme-primary').value = pc;
    $('#set-theme-primary-text').value = pc;
    $('#set-theme-bg').value = s.theme_bg || 'default';
    $('#set-announcement').value = s.announcement || '';
    $('#set-footer-extra').value = s.footer_extra || '';
    $('#set-custom-css').value = s.custom_css || '';
    $('#set-show-batch').checked = s.show_batch !== false;
    $('#set-show-merge').checked = s.show_merge !== false;
    $('#set-show-usage').checked = s.show_usage !== false;
    $('#set-show-ratio').checked = s.show_ratio !== false;
    $('#set-show-segment').checked = s.show_segment !== false;
  }).catch(function () { toast('网络异常', 'error'); });

  $('#btn-save-persona').addEventListener('click', function () {
    var primary = ($('#set-theme-primary-text').value || '').trim() || $('#set-theme-primary').value || '#0052d9';
    post({
      action: 'settings_set',
      settings: {
        logo_emoji: ($('#set-logo-emoji').value || '').trim() || '🦜',
        theme_primary: primary,
        theme_bg: $('#set-theme-bg').value,
        announcement: $('#set-announcement').value.trim(),
        footer_extra: $('#set-footer-extra').value.trim(),
        custom_css: $('#set-custom-css').value,
        show_batch: $('#set-show-batch').checked,
        show_merge: $('#set-show-merge').checked,
        show_usage: $('#set-show-usage').checked,
        show_ratio: $('#set-show-ratio').checked,
        show_segment: $('#set-show-segment').checked,
      },
    }).then(function (r) { toast(r.msg || r.error, r.ok ? 'ok' : 'error'); });
  });

  // 主题色：颜色选择器与文本输入双向联动
  $('#set-theme-primary').addEventListener('input', function () {
    $('#set-theme-primary-text').value = this.value;
  });
  $('#set-theme-primary-text').addEventListener('input', function () {
    var v = this.value.trim();
    if (/^#[0-9a-fA-F]{6}$/.test(v)) $('#set-theme-primary').value = v;
  });
})();
</script>
