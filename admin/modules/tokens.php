<?php
/**
 * 模块：Token 池管理（列表 / 添加 / 测试 / 批量测试 / 编辑 / 启停 / 解锁 / 删除）
 */
if (!defined('ZQ_ADMIN')) {
    http_response_code(403);
    exit('Forbidden');
}
?>
<section class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px">
    <h2>Token 池管理</h2>
    <div style="display:flex;align-items:center;gap:10px">
      <span class="muted" id="test-msg" style="font-size:13px"></span>
      <button class="btn" id="btn-test-all">🔍 一键检测可用性</button>
    </div>
  </div>

  <div class="add-row">
    <div class="key-input-wrap">
      <input type="password" id="new-key" placeholder="粘贴新的 Makers API Key" autocomplete="off">
      <button type="button" class="k-toggle" id="new-key-toggle" title="显示 / 隐藏">👁</button>
      <button type="button" class="k-toggle" id="new-key-paste" title="从剪贴板粘贴">📋</button>
    </div>
    <input type="text" id="new-name" placeholder="备注名称（可选）" style="flex:0 0 200px">
    <label class="checkbox-row" style="white-space:nowrap"><input type="checkbox" id="new-key-test"> 添加前测试</label>
    <button class="btn btn-primary" id="btn-add">添加 Token</button>
  </div>

  <div style="overflow-x:auto">
    <table>
      <thead>
        <tr>
          <th>名称</th><th>Token</th><th>状态</th><th>调用</th><th>用量(Tokens)</th><th>最近使用</th><th>操作</th>
        </tr>
      </thead>
      <tbody id="token-tbody"></tbody>
    </table>
  </div>

  <p class="section-sub" style="margin-top:10px">自动切换策略：请求遇 <code>401/403/429</code> 时自动换下一个可用 Token；连续失败超阈值进入冷却，冷却期后自动恢复。</p>
</section>

<script>
(function () {
  var $ = ZQAdmin.$, post = ZQAdmin.post, toast = ZQAdmin.toast, esc = ZQAdmin.esc;
  window.__editId = null;

  function renderTokens(list) {
    var tbody = $('#token-tbody');
    if (!list.length) {
      tbody.innerHTML = '<tr><td colspan="7" class="empty">还没有 Token，请在上方添加</td></tr>';
      return;
    }
    tbody.innerHTML = list.map(function (t) {
      var statePill;
      if (!t.enabled) statePill = '<span class="status-pill pill-gray">已停用</span>';
      else if (t.in_cooldown) statePill = '<span class="status-pill pill-red">冷却中</span>';
      else statePill = '<span class="status-pill pill-green">可用</span>';

      // 编辑态
      if (t.id === window.__editId) {
        return '<tr>' +
          '<td><input data-edit-name data-id="' + esc(t.id) + '" value="' + esc(t.name) + '" style="width:140px"></td>' +
          '<td><div class="key-input-wrap"><input data-edit-key data-id="' + esc(t.id) + '" placeholder="不修改则留空" style="width:200px" type="password"><button type="button" class="k-toggle" data-eyeu="' + esc(t.id) + '" title="显示 / 隐藏">👁</button></div></td>' +
          '<td>' + statePill + '</td>' +
          '<td>' + t.total_calls + '</td>' +
          '<td>' + (t.total_tokens || 0) + '</td>' +
          '<td class="muted">' + (t.last_used || '-') + '</td>' +
          '<td class="actions-cell">' +
            '<button class="btn btn-sm btn-primary" data-save="' + esc(t.id) + '">保存</button>' +
            '<button class="btn btn-sm" data-cancel>取消</button>' +
          '</td></tr>';
      }

      var lastErr = t.last_error ? '<div class="muted" style="font-size:11px;margin-top:3px">' + esc(t.last_error) + '</div>' : '';
      return '<tr>' +
        '<td><b>' + esc(t.name) + '</b><br><span class="muted" style="font-size:11px">' + esc(t.created_at) + '</span></td>' +
        '<td><div class="key-view"><code class="k-masked" data-full="' + esc(t.key) + '" data-masked="' + esc(t.key_masked) + '">' + esc(t.key_masked) + '</code>'
          + '<button class="btn btn-sm" data-reveal="' + esc(t.id) + '" title="显示 / 隐藏完整 key">👁</button>'
          + '<button class="btn btn-sm" data-copy="' + esc(t.id) + '" title="复制完整 key">📋</button></div></td>' +
        '<td>' + statePill + '</td>' +
        '<td>' + t.total_calls + lastErr + '</td>' +
        '<td>' + (t.total_tokens || 0) + '</td>' +
        '<td class="muted">' + (t.last_used || '-') + '</td>' +
        '<td class="actions-cell">' +
          '<button class="btn btn-sm" data-test="' + esc(t.id) + '" title="用该 Token 测试连接">测试</button>' +
          '<button class="btn btn-sm" data-toggle="' + esc(t.id) + '">' + (t.enabled ? '停用' : '启用') + '</button>' +
          (t.in_cooldown ? '<button class="btn btn-sm" data-poke="' + esc(t.id) + '">解锁</button>' : '') +
          '<button class="btn btn-sm" data-edit="' + esc(t.id) + '">编辑</button>' +
          '<button class="btn btn-sm btn-danger" data-remove="' + esc(t.id) + '">删除</button>' +
        '</td></tr>';
    }).join('');
  }

  function load() {
    post({ action: 'list' }).then(function (res) {
      if (!res.ok) { toast(res.error || '加载失败', 'error'); return; }
      renderTokens(res.summary.list);
    }).catch(function () { toast('网络异常', 'error'); });
  }

  // 行内操作（事件委托）
  $('#token-tbody').addEventListener('click', function (e) {
    var btn = e.target.closest('button');
    if (!btn) return;
    var act = Object.keys(btn.dataset)[0];
    var id = btn.dataset[act];

    // 完整 key 显示 / 隐藏
    if (act === 'reveal') {
      var code = btn.closest('.key-view').querySelector('.k-masked');
      var full = code.getAttribute('data-full') || '';
      if (code.textContent === full) {
        code.textContent = code.getAttribute('data-masked') || '';
        btn.textContent = '👁';
      } else {
        code.textContent = full;
        btn.textContent = '🙈';
      }
      return;
    }
    // 复制完整 key
    if (act === 'copy') {
      var fk = btn.closest('.key-view').querySelector('.k-masked').getAttribute('data-full') || '';
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(fk).then(function () { toast('已复制完整 key', 'ok'); })
          .catch(function () { toast('复制失败', 'error'); });
      } else { toast('浏览器不支持剪贴板', 'error'); }
      return;
    }
    // 编辑态 key 显示 / 隐藏
    if (act === 'eyeu') {
      var row = btn.closest('tr');
      var inp = row.querySelector('[data-edit-key]');
      var isPw = inp.type === 'password';
      inp.type = isPw ? 'text' : 'password';
      btn.textContent = isPw ? '🙈' : '👁';
      return;
    }

    if (act === 'test') {
      btn.disabled = true;
      post({ action: 'test', id: id }).then(function (res) {
        toast((res.ok ? '✅ ' : '❌ ') + (res.msg || res.error || ''), res.ok ? 'ok' : 'error');
        load(); btn.disabled = false;
      });
      return;
    }
    if (act === 'toggle') { post({ action: 'toggle', id: id }).then(function (r) { r.ok && toast(r.msg); load(); }); return; }
    if (act === 'poke')   { post({ action: 'poke', id: id }).then(function (r) { r.ok && toast(r.msg); load(); }); return; }
    if (act === 'remove') {
      if (!confirm('确定删除该 Token？删除后不可恢复。')) return;
      post({ action: 'remove', id: id }).then(function (r) { r.ok && toast(r.msg); load(); });
      return;
    }
    if (act === 'edit')   { window.__editId = id; load(); return; }
    if (act === 'save')   {
      var row = btn.closest('tr');
      var name = row.querySelector('[data-edit-name]').value.trim();
      var key = row.querySelector('[data-edit-key]').value.trim();
      post({ action: 'update', id: id, name: name, key: key }).then(function (r) {
        r.ok && toast(r.msg); window.__editId = null; load();
      });
      return;
    }
    if (act === 'cancel') { window.__editId = null; load(); return; }
  });

  // 添加输入框：显示/隐藏 + 粘贴
  var newKeyInput = $('#new-key');
  $('#new-key-toggle').addEventListener('click', function () {
    var isPw = newKeyInput.type === 'password';
    newKeyInput.type = isPw ? 'text' : 'password';
    this.textContent = isPw ? '🙈' : '👁';
  });
  $('#new-key-paste').addEventListener('click', function () {
    if (navigator.clipboard && navigator.clipboard.readText) {
      navigator.clipboard.readText().then(function (t) { if (t) newKeyInput.value = t.trim(); })
        .catch(function () { toast('读取剪贴板失败', 'error'); });
    } else { toast('浏览器不支持剪贴板', 'error'); }
  });

  // 添加（可选“添加前测试”）
  $('#btn-add').addEventListener('click', function () {
    var btn = this;
    var key = newKeyInput.value.trim();
    var name = $('#new-name').value.trim();
    if (!key) { toast('请粘贴 Token 值', 'error'); return; }
    var proceed = function () {
      btn.disabled = true;
      post({ action: 'add', key: key, name: name }).then(function (r) {
        btn.disabled = false;
        toast((r.ok ? '✅ ' : '❌ ') + (r.msg || r.error || ''), r.ok ? 'ok' : 'error');
        if (r.ok) { newKeyInput.value = ''; $('#new-name').value = ''; }
        load();
      });
    };
    if ($('#new-key-test').checked) {
      btn.disabled = true;
      post({ action: 'test_key', key: key }).then(function (r) {
        btn.disabled = false;
        if (r.ok) { toast('连接成功，正在添加…', 'ok'); proceed(); }
        else { toast('测试失败：' + (r.error || ''), 'error'); }
      });
      return;
    }
    proceed();
  });

  // 一键检测
  $('#btn-test-all').addEventListener('click', function () {
    var btn = this;
    var msg = $('#test-msg');
    btn.disabled = true;
    msg.innerHTML = '<span style="color:var(--muted)">检测中…</span>';
    post({ action: 'test_all' }).then(function (r) {
      btn.disabled = false;
      if (!r.ok) {
        msg.textContent = '';
        toast(r.error || '检测失败', 'error');
        return;
      }
      var bad = (r.results || []).filter(function (x) { return !x.ok; });
      var html = '可用 <b style="color:var(--green)">' + r.ok_count + '/' + r.total + '</b>';
      if (bad.length) {
        html += ' &nbsp;<span style="color:var(--red)">不可用：' + bad.map(function (x) {
          return esc(x.name) + '(' + esc(x.msg) + ')';
        }).join('、') + '</span>';
      }
      msg.innerHTML = html;
      load();
    }).catch(function () {
      btn.disabled = false;
      msg.textContent = '';
      toast('检测异常', 'error');
    });
  });

  load();
})();
</script>
