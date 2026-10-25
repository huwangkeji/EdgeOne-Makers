<?php
/**
 * 模块：总览（统计卡片 + 策略说明）
 */
if (!defined('ZQ_ADMIN')) {
    http_response_code(403);
    exit('Forbidden');
}
?>
<section class="card">
  <h2>总览</h2>
  <p class="section-sub">Token 池实时统计；每次调用会累计消耗的免费额度。</p>
  <div class="stats" id="stats">
    <div class="stat"><div class="num">—</div><div class="lbl">加载中…</div></div>
  </div>
</section>

<section class="card">
  <h2>切换策略</h2>
  <p class="section-sub">请求遇 <code>401/403/429</code> 时自动切换到下一个可用 Token；连续失败超过阈值进入冷却，冷却期结束后自动恢复。相关参数可在「运行设置」中调整。</p>
  <p class="section-sub">免费额度：每月 <b>50 万 token</b>，实际扣减以接口返回的 <code>makers_models_usage.total_tokens</code> 为准。</p>
</section>

<script>
(function () {
  var $ = ZQAdmin.$, post = ZQAdmin.post, esc = ZQAdmin.esc;
  post({ action: 'list' }).then(function (res) {
    if (!res.ok) {
      $('#stats').innerHTML = '<div class="stat"><div class="num">!</div><div class="lbl">' + esc(res.error || '加载失败') + '</div></div>';
      return;
    }
    var s = res.summary;
    $('#stats').innerHTML =
      '<div class="stat"><div class="num">' + s.total + '</div><div class="lbl">Token 总数</div></div>' +
      '<div class="stat"><div class="num" style="color:var(--green)">' + s.available + '</div><div class="lbl">可用数（含未启用/冷却）</div></div>' +
      '<div class="stat"><div class="num" style="color:var(--amber)">' + s.enabled + '</div><div class="lbl">启用数</div></div>' +
      '<div class="stat"><div class="num">' + s.total_calls + '</div><div class="lbl">累计调用次数</div></div>';
  }).catch(function () {
    ZQAdmin.toast('网络异常', 'error');
  });
})();
</script>
