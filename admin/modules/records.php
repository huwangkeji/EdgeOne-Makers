<?php
/**
 * 模块：开发记录 —— 提交明细（分页 / 清空 / 导出 CSV）
 * 按 IP 汇总见独立模块 records_ip.php。
 */
if (!defined('ZQ_ADMIN')) {
    http_response_code(403);
    exit('Forbidden');
}
?>
<section class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px">
    <h2>提交明细</h2>
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
      <input type="text" id="rec-ip" class="rec-filter" placeholder="按 IP 过滤（留空=全部）">
      <button class="btn" id="btn-rec-refresh">刷新</button>
      <button class="btn" id="btn-rec-export" title="导出当前（含 IP 过滤）提交明细为 CSV">⬇ 导出 CSV</button>
      <button class="btn btn-danger" id="btn-rec-clear">清空记录</button>
    </div>
  </div>
  <div class="stats" id="rec-stats">
    <div class="stat"><div class="num">—</div><div class="lbl">加载中…</div></div>
  </div>
</section>

<section class="card">
  <h2>记录列表</h2>
  <div style="overflow-x:auto">
    <table>
      <thead>
        <tr>
          <th>时间</th><th>IP</th><th>类型</th><th>条数</th><th>字数</th>
          <th>判定</th><th>消耗 token</th><th>使用 Token</th><th>内容预览</th><th>状态</th>
        </tr>
      </thead>
      <tbody id="rec-tbody"></tbody>
    </table>
  </div>
  <div class="rec-pager" id="rec-pager"></div>
  <p class="section-sub" id="rec-total" style="margin-top:6px"></p>
</section>

<style>
  .rec-pager { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-top: 12px; }
  .rec-page-num { font-size: 13px; color: var(--text); }
  .rec-pager .btn:disabled { opacity: .45; cursor: not-allowed; }
</style>

<script>
(function () {
  var $ = ZQAdmin.$, postTo = ZQAdmin.postTo, toast = ZQAdmin.toast, esc = ZQAdmin.esc;
  var API = '../api/records.php';
  var page = 1, limit = 50, currentIp = '';

  function fmtType(t) {
    if (t === 'batch') return '批量';
    if (t === 'image') return '图像';
    return '文本';
  }

  function renderStats(st) {
    $('#rec-stats').innerHTML =
      '<div class="stat"><div class="num">' + st.total_records + '</div><div class="lbl">提交次数</div></div>' +
      '<div class="stat"><div class="num" style="color:var(--amber)">' + st.total_tokens + '</div><div class="lbl">累计消耗 token</div></div>' +
      '<div class="stat"><div class="num" style="color:var(--primary)">' + st.today_tokens + '</div><div class="lbl">今日消耗 token</div></div>' +
      '<div class="stat"><div class="num" style="color:var(--green)">' + st.unique_ips + '</div><div class="lbl">独立 IP</div></div>' +
      '<div class="stat"><div class="num">' + st.ok_count + ' / ' + st.fail_count + '</div><div class="lbl">成功 / 失败</div></div>';
  }

  function renderRecords(list) {
    var tbody = $('#rec-tbody');
    if (!list.length) {
      tbody.innerHTML = '<tr><td colspan="10" class="empty">暂无记录</td></tr>';
      return;
    }
    tbody.innerHTML = list.map(function (r) {
      var status = r.ok
        ? '<span class="status-pill pill-green">成功</span>'
        : '<span class="status-pill pill-red" title="' + esc(r.error || '') + '">失败</span>';
      var verdict = r.verdict ? esc(r.verdict) : '-';
      var preview = (r.preview || '').slice(0, 40);
      return '<tr>' +
        '<td class="muted" style="white-space:nowrap">' + esc(r.time) + '</td>' +
        '<td><code>' + esc(r.ip) + '</code></td>' +
        '<td>' + fmtType(r.type) + '</td>' +
        '<td>' + (r.item_count || 1) + '</td>' +
        '<td>' + (r.text_len || 0) + '</td>' +
        '<td>' + verdict + '</td>' +
        '<td>' + (r.tokens || 0) + '</td>' +
        '<td class="muted">' + (r.token_name ? esc(r.token_name) : '-') + '</td>' +
        '<td class="muted">' + (preview ? esc(preview) : '-') + '</td>' +
        '<td>' + status + '</td>' +
        '</tr>';
    }).join('');
  }

  function renderPager(total, last) {
    var p = $('#rec-pager');
    p.innerHTML =
      '<button class="btn btn-sm" id="pg-prev"' + (page <= 1 ? ' disabled' : '') + '>◀ 上一页</button>' +
      '<span class="rec-page-num">第 ' + page + ' / ' + last + ' 页（共 ' + total + ' 条）</span>' +
      '<button class="btn btn-sm" id="pg-next"' + (page >= last ? ' disabled' : '') + '>下一页 ▶</button>';
    var prev = $('#pg-prev'), next = $('#pg-next');
    if (prev && page > 1) prev.addEventListener('click', function () { page--; load(); });
    if (next && page < last) next.addEventListener('click', function () { page++; load(); });
  }

  function load() {
    currentIp = ($('#rec-ip').value || '').trim();
    var offset = (page - 1) * limit;
    postTo(API, { action: 'list', ip: currentIp, limit: limit, offset: offset }).then(function (res) {
      if (!res.ok) { toast(res.error || '加载失败', 'error'); return; }
      renderStats(res.stats);
      renderRecords(res.records || []);
      var last = Math.max(1, Math.ceil(res.total / limit));
      if (page > last) { page = last; }
      renderPager(res.total, last);
      var tip = currentIp ? '（已按 IP 过滤：' + currentIp + '）' : '';
      $('#rec-total').textContent = '过滤' + tip;
    }).catch(function () { toast('网络异常', 'error'); });
  }

  function exportCsv() {
    toast('正在导出…', 'ok');
    fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ action: 'export', type: 'detail', ip: currentIp }),
    }).then(function (r) { return r.blob(); }).then(function (blob) {
      var a = document.createElement('a');
      var url = URL.createObjectURL(blob);
      a.href = url;
      a.download = 'records_detail.csv';
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
      toast('导出完成', 'ok');
    }).catch(function () { toast('导出失败', 'error'); });
  }

  $('#btn-rec-refresh').addEventListener('click', function () { page = 1; load(); });
  $('#rec-ip').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { page = 1; load(); }
  });
  $('#btn-rec-export').addEventListener('click', exportCsv);
  $('#btn-rec-clear').addEventListener('click', function () {
    if (!confirm('确定清空全部开发记录（含按 IP 汇总）？此操作不可恢复。')) return;
    postTo(API, { action: 'clear' }).then(function (r) {
      toast(r.msg || r.error, r.ok ? 'ok' : 'error');
      if (r.ok) { page = 1; load(); }
    });
  });

  load();
})();
</script>