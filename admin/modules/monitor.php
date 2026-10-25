<?php
/**
 * 模块：Token 监控
 * - Token 健康状态表（累计用量 / 上次测试 / 禁用原因）
 * - 手动「立即检测」触发自动监控
 * - 最近运行日志
 * - cron 触发 URL 与示例
 */
if (!defined('ZQ_ADMIN')) {
    http_response_code(403);
    exit('Forbidden');
}

$_proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$_host  = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
$_key   = zq_monitor_key();
$_cronUrl = $_proto . '://' . $_host . '/cron/monitor.php?key=' . ($_key !== '' ? urlencode($_key) : '__密钥未设置__');
?>
<section class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px">
    <h2>Token 自动监控</h2>
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
      <span class="muted" id="m-run-msg" style="font-size:13px"></span>
      <button class="btn" id="btn-mt-run">🛰️ 立即检测</button>
      <button class="btn btn-ghost" id="btn-mt-clear">清空日志</button>
    </div>
  </div>

  <p class="section-sub" id="mt-summary"></p>

  <div class="mt-cfg-grid" id="mt-cfg">
    <div class="setting">
      <label>监控总开关</label>
      <label class="checkbox-row" style="padding-top:0"><input type="checkbox" id="cfg-enabled"> 启用自动监控（立即检测 / cron 均执行）</label>
    </div>
    <div class="setting">
      <label>运行间隔（分钟，cron 未到间隔则跳过）</label>
      <input type="number" id="cfg-interval" min="1" max="1440" value="30">
    </div>
    <div class="setting">
      <label>单 Token 每月免费额度（token，0 = 不设限）</label>
      <input type="number" id="cfg-quota" min="0" step="1000" value="0">
    </div>
    <div class="setting">
      <label>自动删除无效 Token</label>
      <label class="checkbox-row" style="padding-top:0"><input type="checkbox" id="cfg-autodel"> 持续 401/403 超阈值自动删除</label>
    </div>
    <div class="setting">
      <label>删除阈值（连续鉴权无效次数）</label>
      <input type="number" id="cfg-delafter" min="1" max="20" value="4">
    </div>
    <div class="form-footer" style="grid-column:1/-1">
      <button class="btn btn-primary" id="btn-mt-save-cfg">保存监控设置</button>
      <span class="muted" id="cfg-save-msg" style="font-size:12px;margin-left:8px"></span>
    </div>
  </div>

  <div class="setting" style="margin:6px 0 16px;grid-column:1/-1">
    <label>监控密钥（用于 cron URL 鉴权，后台可视化设置）——当前状态：<?php echo $_key !== '' ? '<b style="color:var(--green)">已设置</b>' : '<span style="color:var(--red)">未设置</span>'; ?></label>
    <div class="color-row">
      <input type="text" id="mt-key" placeholder="粘贴监控密钥，或点击「随机」生成" autocomplete="off" style="flex:1">
      <button class="btn" id="btn-mt-gen" title="随机生成一个高强度密钥">🎲 随机</button>
      <button class="btn btn-primary" id="btn-mt-save-key">保存密钥</button>
    </div>
    <p class="section-sub" style="margin-top:6px">优先级：本设置 &gt; <code>data/config.json</code> 的 monitor_key &gt; 环境变量 <code>ZHUQUE_MONITOR_KEY</code> &gt; <code>config.php</code> 常量。保存后 cron URL 立即使用新密钥。</p>
  </div>

  <div class="setting" style="margin:6px 0 16px;grid-column:1/-1">
    <label>cron 触发 URL（GET 访问即执行一轮监控；密钥即下方「监控密钥」）</label>
    <input type="text" value="<?php echo htmlspecialchars($_cronUrl, ENT_QUOTES, 'UTF-8'); ?>" readonly onclick="this.select()" style="width:100%;padding:9px 10px;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text);font-size:12px">
    <p class="section-sub" style="margin-top:6px">示例 crontab：<code>*/30 * * * * curl -s "<?php echo htmlspecialchars($_proto . '://' . $_host . '/cron/monitor.php?key=__密钥__'); ?>"</code></p>
  </div>

  <div style="overflow-x:auto">
    <table>
      <thead>
        <tr><th>名称 / Token</th><th>状态</th><th>累计用量</th><th>额度上限</th><th>最近测试</th><th>禁用原因 / 错误</th></tr>
      </thead>
      <tbody id="mt-tbody"></tbody>
    </table>
  </div>
</section>

<section class="card">
  <h2>最近运行日志</h2>
  <div id="mt-log"></div>
</section>

<script>
(function () {
  var $ = ZQAdmin.$, postTo = ZQAdmin.postTo, toast = ZQAdmin.toast, esc = ZQAdmin.esc;
  var API = '../api/monitor.php';
  var QUOTA = <?php echo (int) FREE_MONTHLY_QUOTA; ?>;
  var curStatuses = [];

  function renderSummary(ov) {
    var el = $('#mt-summary');
    if (!ov) { el.innerHTML = '<span class="muted">—</span>'; return; }
    var d = ov.dist || {};
    var state = ov.enabled ? '<b style="color:var(--green)">已启用</b>' : '<b style="color:var(--red)">已关闭</b>';
    var due = ov.last_run
      ? (ov.due ? ' <b style="color:var(--amber)">已可运行</b>' : ' <span class="muted">距上次 ' + (ov.since_min == null ? '' : ov.since_min + ' 分钟') + '</span>')
      : ' <span class="muted">尚未运行</span>';
    el.innerHTML = '状态：' + state +
      ' 健康 <b style="color:var(--green)">' + (d.healthy || 0) + '</b>' +
      ' 冷却 <b style="color:var(--amber)">' + (d.cooldown || 0) + '</b>' +
      ' 停用 <b style="color:var(--red)">' + (d.disabled || 0) + '</b>' +
      ' · 上次运行：' + (ov.last_run || '—') + due +
      ' · 下次预计：' + (ov.next_run || '—');
  }

  function fillCfg(s) {
    if (!s) return;
    $('#cfg-enabled').checked = !!s.monitor_enabled;
    $('#cfg-interval').value = s.monitor_interval_min || 30;
    $('#cfg-quota').value = s.monitor_quota != null ? s.monitor_quota : 0;
    $('#cfg-autodel').checked = !!s.monitor_auto_delete;
    $('#cfg-delafter').value = s.monitor_delete_after || 4;
    if (s.monitor_quota > 0) { QUOTA = s.monitor_quota; if (curStatuses.length) renderStatuses(curStatuses); }
  }

  function statePill(s, r) {
    if (s === 'healthy')  return '<span class="status-pill pill-green">健康</span>';
    if (s === 'cooldown') return '<span class="status-pill pill-amber">冷却</span>';
    return '<span class="status-pill pill-red">已停用</span>';
  }

  function testPill(v) {
    if (v === true)  return '<span class="status-pill pill-green">成功</span>';
    if (v === false) return '<span class="status-pill pill-red">失败</span>';
    return '<span class="muted">—</span>';
  }

  function renderStatuses(list) {
    var tbody = $('#mt-tbody');
    if (!list.length) {
      tbody.innerHTML = '<tr><td colspan="6" class="empty">暂无 Token</td></tr>';
      return;
    }
    tbody.innerHTML = list.map(function (t) {
      var reason = t.state === 'disabled'
        ? (t.disabled_reason ? esc(t.disabled_reason) : '已停用')
        : (t.last_error ? esc(t.last_error) : '-');
      return '<tr>' +
        '<td><b>' + esc(t.name) + '</b><br><code>' + esc(t.key_masked) + '</code></td>' +
        '<td>' + statePill(t.state, t.disabled_reason) + '</td>' +
        '<td>' + t.total_tokens + '</td>' +
        '<td>' + QUOTA + '</td>' +
        '<td>' + (t.last_test_at ? esc(t.last_test_at) + '<br>' + testPill(t.last_test_ok) : '<span class="muted">—</span>') + '</td>' +
        '<td class="muted">' + reason + '</td>' +
        '</tr>';
    }).join('');
  }

  function renderLogs(logs) {
    var box = $('#mt-log');
    if (!logs || !logs.length) {
      box.innerHTML = '<p class="empty">暂无运行记录</p>';
      return;
    }
    var html = logs.map(function (e) {
      var detail = (e.results || []).map(function (r) {
        var tag = r.action === 'ok' ? 'green' : (r.action === 'delete' || r.action === 'disable' ? 'red' : (r.action === 'cooldown' ? 'amber' : 'muted'));
        return '<div class="mt-detail"><span class="mt-tag ' + tag + '">' + esc(r.action) + '</span>' +
          '<b>' + esc(r.name) + '</b><span class="muted">· ' + esc(r.msg) + '</span></div>';
      }).join('');
      return '<div class="mt-log-item">' +
        '<div class="mt-summary">🕐 ' + esc(e.time) + ' · 共 ' + e.total + ' · 健康 ' + e.ok +
          ' · <span style="color:var(--red)">禁用 ' + e.disabled + '</span>' +
          ' · <span style="color:var(--red)">删除 ' + (e.deleted || 0) + '</span>' +
          ' · <span style="color:var(--amber)">冷却 ' + e.cooldown + '</span>' +
          ' · 错误 ' + e.errors + '</div>' + detail +
        '</div>';
    }).join('');
    box.innerHTML = html;
  }

  function load() {
    postTo(API, { action: 'status' }).then(function (r) {
      if (r.ok) { curStatuses = r.statuses || []; renderStatuses(curStatuses); }
      else toast(r.error || '加载失败', 'error');
    });
    postTo(API, { action: 'log' }).then(function (r) {
      if (r.ok) renderLogs(r.logs || []);
    });
    postTo(API, { action: 'overview' }).then(function (r) {
      if (r.ok) renderSummary(r.overview);
    });
    ZQAdmin.post({ action: 'list' }).then(function (r) {
      if (r.ok && r.settings) fillCfg(r.settings);
    });
  }

  $('#btn-mt-run').addEventListener('click', function () {
    var btn = this;
    var msg = $('#m-run-msg');
    btn.disabled = true;
    msg.textContent = '检测中…';
    postTo(API, { action: 'run' }).then(function (r) {
      btn.disabled = false;
      if (!r.ok) {
        msg.textContent = '';
        toast(r.error || '检测失败', 'error');
        return;
      }
      var e = r.run;
      msg.innerHTML = '健康 <b style="color:var(--green)">' + e.ok + '</b> · 禁用 <b style="color:var(--red)">' + e.disabled + '</b> · 删除 <b style="color:var(--red)">' + (e.deleted || 0) + '</b> · 冷却 <b style="color:var(--amber)">' + e.cooldown + '</b> · 错误 ' + e.errors;
      toast('监控检测完成', 'ok');
      load();
    }).catch(function () { btn.disabled = false; msg.textContent = ''; toast('网络异常', 'error'); });
  });

  $('#btn-mt-clear').addEventListener('click', function () {
    if (!confirm('确定清空监控日志？')) return;
    postTo(API, { action: 'clear_log' }).then(function (r) {
      toast(r.msg || r.error, r.ok ? 'ok' : 'error');
      if (r.ok) load();
    });
  });

  // 保存监控设置
  $('#btn-mt-save-cfg').addEventListener('click', function () {
    var s = {
      monitor_enabled: $('#cfg-enabled').checked,
      monitor_interval_min: parseInt($('#cfg-interval').value, 10) || 30,
      monitor_quota: Math.max(0, parseInt($('#cfg-quota').value, 10) || 0),
      monitor_auto_delete: $('#cfg-autodel').checked,
      monitor_delete_after: Math.max(1, parseInt($('#cfg-delafter').value, 10) || 4),
    };
    ZQAdmin.post({ action: 'settings_set', settings: s }).then(function (r) {
      toast(r.msg || r.error, r.ok ? 'ok' : 'error');
      $('#cfg-save-msg').textContent = r.ok ? '已保存，cron 与监控立即按新设置生效' : '';
      if (r.ok) load();
    });
  });

  // 随机生成监控密钥：crypto 调用封装 try/catch，任何异常（如 http 非安全上下文调 getRandomValues 抛 SecurityError）都回退 Math.random
  $('#btn-mt-gen').addEventListener('click', function () {
    var bytes = 32;
    var hex = '';
    var ok = false;
    try {
      if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
        var arr = new Uint8Array(bytes);
        window.crypto.getRandomValues(arr);
        for (var i = 0; i < bytes; i++) { hex += ('0' + arr[i].toString(16)).slice(-2); }
        ok = true;
      }
    } catch (e) { hex = ''; ok = false; }
    if (!ok) {
      var chars = '0123456789abcdef';
      for (var j = 0; j < bytes * 2; j++) { hex += chars[Math.floor(Math.random() * 16)]; }
    }
    $('#mt-key').value = hex;
    $('#mt-key').focus();
  });

  // 保存监控密钥（写入 settings.json，最高优先级；空值不提交，避免误清）
  $('#btn-mt-save-key').addEventListener('click', function () {
    var v = ($('#mt-key').value || '').trim();
    if (v === '') { toast('请粘贴密钥或点击「随机」生成后再保存', 'error'); return; }
    if (!/^[A-Za-z0-9_-]{6,128}$/.test(v)) {
      toast('密钥应为 6~128 位字母 / 数字 / _ -', 'error');
      return;
    }
    ZQAdmin.post({ action: 'settings_set', settings: { monitor_key: v } }).then(function (r) {
      toast(r.msg || r.error, r.ok ? 'ok' : 'error');
      if (r.ok) { setTimeout(function () { window.location.reload(); }, 600); }
    });
  });

  load();
})();
</script>

<style>
  .mt-log-item { border: 1px solid var(--border); border-radius: 10px; padding: 10px 12px; margin-bottom: 10px; background: var(--surface-2); }
  .mt-summary { font-size: 13px; margin-bottom: 6px; }
  .mt-detail { font-size: 12px; color: var(--text); padding: 2px 0; display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
  .mt-tag { font-size: 10px; font-weight: 600; padding: 1px 6px; border-radius: 999px; }
  .mt-tag.green { background: rgba(34,197,94,.15); color: var(--green); }
  .mt-tag.red { background: rgba(239,68,68,.15); color: var(--red); }
  .mt-tag.amber { background: rgba(245,158,11,.15); color: var(--amber); }
  .mt-tag.muted { background: rgba(148,163,184,.15); color: var(--muted); }
  .mt-cfg-grid { display: grid; grid-template-columns: repeat(auto-fit,minmax(220px,1fr)); gap: 14px; margin: 6px 0 16px; }
</style>