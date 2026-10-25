<?php
/**
 * 模块：开发记录 —— 按 IP 汇总（独立文件页）
 * 展示每个 IP 的提交次数与累计消耗 token，支持导出 CSV。
 */
if (!defined('ZQ_ADMIN')) {
    http_response_code(403);
    exit('Forbidden');
}
?>
<section class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px">
    <h2>按 IP 汇总</h2>
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
      <button class="btn" id="btn-iprefresh">刷新</button>
      <button class="btn" id="btn-ipexport" title="导出按 IP 汇总 CSV">⬇ 导出 CSV</button>
    </div>
  </div>
  <div class="stats" id="rec-ipstats">
    <div class="stat"><div class="num">—</div><div class="lbl">加载中…</div></div>
  </div>
</section>

<section class="card">
  <h2>IP 用量一览</h2>
  <div style="overflow-x:auto">
    <table>
      <thead>
        <tr><th>IP</th><th>提交次数</th><th>累计消耗 token</th><th>占比</th></tr>
      </thead>
      <tbody id="rec-iptbody"></tbody>
    </table>
  </div>
  <p class="section-sub" id="rec-itotal" style="margin-top:10px"></p>
  <p class="section-sub">按累计消耗 token 从高到低排序，便于发现异常来源。</p>
</section>

<script>
(function () {
  var $ = ZQAdmin.$, postTo = ZQAdmin.postTo, toast = ZQAdmin.toast, esc = ZQAdmin.esc;
  var API = '../api/records.php';

  function renderStats(by, totalCount, totalTokens) {
    $('#rec-ipstats').innerHTML =
      '<div class="stat"><div class="num" style="color:var(--green)">' + by.length + '</div><div class="lbl">独立 IP</div></div>' +
      '<div class="stat"><div class="num">' + totalCount + '</div><div class="lbl">提交次数</div></div>' +
      '<div class="stat"><div class="num" style="color:var(--amber)">' + totalTokens + '</div><div class="lbl">累计消耗 token</div></div>';
  }

  function render(list) {
    var tbody = $('#rec-iptbody');
    if (!list.length) {
      tbody.innerHTML = '<tr><td colspan="4" class="empty">暂无数据</td></tr>';
      return;
    }
    var totalCount = 0, totalTokens = 0;
    list.forEach(function (x) { totalCount += x.count; totalTokens += x.tokens; });
    tbody.innerHTML = list.map(function (x) {
      var pct = totalTokens > 0 ? Math.round((x.tokens / totalTokens) * 100) : 0;
      var bar = '<div style="height:4px;border-radius:999px;background:var(--surface-2);overflow:hidden;max-width:140px;margin-top:4px"><i style="display:block;height:100%;width:' + pct + '%;background:var(--primary)"></i></div>';
      return '<tr>' +
        '<td><code>' + esc(x.ip) + '</code></td>' +
        '<td>' + x.count + '</td>' +
        '<td>' + x.tokens + '</td>' +
        '<td>' + pct + '%' + bar + '</td>' +
        '</tr>';
    }).join('');
    renderStats(list, totalCount, totalTokens);
    $('#rec-itotal').textContent = '共 ' + list.length + ' 个 IP，累计消耗 ' + totalTokens + ' token，提交 ' + totalCount + ' 次';
  }

  function load() {
    // 用 list 的 stats.by_ip（已按 token 降序）作为数据源
    postTo(API, { action: 'list', limit: 1, offset: 0 }).then(function (res) {
      if (!res.ok) { toast(res.error || '加载失败', 'error'); return; }
      render(res.stats.by_ip || []);
    }).catch(function () { toast('网络异常', 'error'); });
  }

  function exportCsv() {
    toast('正在导出…', 'ok');
    fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ action: 'export', type: 'byip', ip: '' }),
    }).then(function (r) { return r.blob(); }).then(function (blob) {
      var a = document.createElement('a');
      var url = URL.createObjectURL(blob);
      a.href = url;
      a.download = 'records_byip.csv';
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
      toast('导出完成', 'ok');
    }).catch(function () { toast('导出失败', 'error'); });
  }

  $('#btn-iprefresh').addEventListener('click', load);
  $('#btn-ipexport').addEventListener('click', exportCsv);

  load();
})();
</script>