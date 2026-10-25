<?php
/**
 * 模块：安全设置（修改后台登录密码）
 */
if (!defined('ZQ_ADMIN')) {
    http_response_code(403);
    exit('Forbidden');
}
?>
<section class="card">
  <h2>安全设置</h2>
  <p class="section-sub">修改后台登录密码（至少 6 位）。修改后当前会话仍然有效。</p>
  <div class="settings-grid" style="grid-template-columns:1fr;max-width:520px">
    <div class="setting">
      <label>新密码</label>
      <input type="password" id="new-pwd" placeholder="至少 6 位" autocomplete="new-password">
    </div>
    <div class="setting">
      <label>确认新密码</label>
      <input type="password" id="new-pwd2" placeholder="再次输入" autocomplete="new-password">
    </div>
  </div>
  <div class="form-footer">
    <button class="btn btn-primary" id="btn-change-pwd">保存新密码</button>
  </div>
</section>

<script>
(function () {
  var $ = ZQAdmin.$, post = ZQAdmin.post, toast = ZQAdmin.toast;

  $('#btn-change-pwd').addEventListener('click', function () {
    var p1 = $('#new-pwd').value;
    var p2 = $('#new-pwd2').value;
    if (p1.length < 6) { toast('密码长度至少 6 位', 'error'); return; }
    if (p1 !== p2) { toast('两次输入不一致', 'error'); return; }
    post({ action: 'password_set', password: p1 }).then(function (r) {
      toast(r.msg || r.error, r.ok ? 'ok' : 'error');
      if (r.ok) { $('#new-pwd').value = ''; $('#new-pwd2').value = ''; }
    });
  });
})();
</script>
