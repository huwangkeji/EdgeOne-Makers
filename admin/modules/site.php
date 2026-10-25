<?php
/**
 * 模块：网站设置（前台展示信息 + 图像检测接口）
 */
if (!defined('ZQ_ADMIN')) {
    http_response_code(403);
    exit('Forbidden');
}
?>
<section class="card">
  <h2>网站设置（前台展示信息）</h2>
  <div class="settings-grid" style="grid-template-columns:repeat(auto-fit,minmax(280px,1fr))">
    <div class="setting">
      <label>站点标题（浏览器标签 / 页面标题）</label>
      <input type="text" id="set-site-title">
    </div>
    <div class="setting">
      <label>顶部品牌文字（LOGO 文字）</label>
      <input type="text" id="set-site-logo">
    </div>
    <div class="setting" style="grid-column:1/-1">
      <label>主页副标题</label>
      <input type="text" id="set-site-subtitle">
    </div>
    <div class="setting" style="grid-column:1/-1">
      <label>主页简介（一行）</label>
      <input type="text" id="set-site-intro">
    </div>
    <div class="setting" style="grid-column:1/-1">
      <label>页脚文字</label>
      <input type="text" id="set-footer">
    </div>
    <div class="setting" style="grid-column:1/-1">
      <label>图像检测接口地址（留空 = 前台关闭图像检测）</label>
      <input type="text" id="set-img-url" placeholder="https://… 目标接口接收 JSON：{image_url|image_base64, name}">
    </div>
    <div class="setting">
      <label>单次最多检测图片数</label>
      <input type="number" id="set-img-max" min="1" max="20">
    </div>
  </div>
  <div class="form-footer">
    <button class="btn btn-primary" id="btn-save-site">保存网站设置</button>
    <span class="muted" style="margin-left:10px;font-size:12px">保存后可刷新前台面板查看效果</span>
  </div>
</section>

<script>
(function () {
  var $ = ZQAdmin.$, post = ZQAdmin.post, toast = ZQAdmin.toast;

  post({ action: 'settings_get' }).then(function (res) {
    if (!res.ok) { toast(res.error || '加载失败', 'error'); return; }
    var s = res.settings;
    $('#set-site-title').value = s.site_title || '';
    $('#set-site-logo').value = s.site_logo_text || '';
    $('#set-site-subtitle').value = s.site_subtitle || '';
    $('#set-site-intro').value = s.site_intro || '';
    $('#set-footer').value = s.footer_text || '';
    $('#set-img-url').value = s.image_detect_url || '';
    $('#set-img-max').value = s.image_detect_max || 5;
  }).catch(function () { toast('网络异常', 'error'); });

  $('#btn-save-site').addEventListener('click', function () {
    post({
      action: 'settings_set',
      settings: {
        site_title: $('#set-site-title').value.trim(),
        site_logo_text: $('#set-site-logo').value.trim(),
        site_subtitle: $('#set-site-subtitle').value.trim(),
        site_intro: $('#set-site-intro').value.trim(),
        footer_text: $('#set-footer').value.trim(),
        image_detect_url: $('#set-img-url').value.trim(),
        image_detect_max: parseInt($('#set-img-max').value, 10) || 5,
      },
    }).then(function (r) { toast(r.msg || r.error, r.ok ? 'ok' : 'error'); });
  });
})();
</script>
