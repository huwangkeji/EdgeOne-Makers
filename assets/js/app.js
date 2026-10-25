/* 云雀 AI 检测助手 —— 前端交互（文本 + 图像检测 / 响应式）
 * 依赖 index.php 注入的 window.ZQ_SITE、window.ZHUQUE_HAS_TOKEN */
(function () {
  'use strict';
  var $ = function (s) { return document.querySelector(s); };
  var $$ = function (s) { return Array.prototype.slice.call(document.querySelectorAll(s)); };
  // 取复选框状态；元素可能因后台开关被隐藏，隐藏时返回默认值
  var chk = function (sel, dft) { var el = $(sel); return el ? el.checked : dft; };

  var SITE = window.ZQ_SITE || {};
  var IMG_MAX = Math.max(1, SITE.image_detect_max || 5);
  var imageItems = [];       // { name, base64 }
  var currentMode = 'text';  // text | image

  /* ============ 顶栏：检测类型切换 ============ */
  function switchMode(mode) {
    currentMode = mode;
    $$('.main-nav .nav-tab').forEach(function (t) {
      t.classList.toggle('active', t.getAttribute('data-mode') === mode);
    });
    $('#panel-text').classList.toggle('hidden', mode !== 'text');
    $('#panel-image').classList.toggle('hidden', mode !== 'image');
    resetStatus();
  }
  $$('.main-nav .nav-tab').forEach(function (t) {
    t.addEventListener('click', function () { switchMode(t.getAttribute('data-mode')); });
  });

  /* ============ 状态提示 ============ */
  function resetStatus() {
    var s = $('#status'); s.textContent = ''; s.className = 'status';
  }
  function setStatus(msg, type) {
    var s = $('#status');
    s.textContent = msg || '';
    s.className = 'status' + (type ? ' ' + type : '');
  }
  function esc(s) {
    var d = document.createElement('div'); d.textContent = String(s == null ? '' : s); return d.innerHTML;
  }
  function post(url, data) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data || {}),
    }).then(function (r) {
      return r.text().then(function (txt) {
        try {
          return JSON.parse(txt);
        } catch (e) {
          // 后端返回了非 JSON（如 PHP 警告/致命泄漏），给出可读提示而非笼统的“网络请求异常”
          return { ok: false, error: '服务端返回非 JSON（HTTP ' + r.status + '）：' + txt.trim().slice(0, 120) };
        }
      });
    }).catch(function (err) {
      // 真正的网络层失败（断网/域名解析失败等）
      return { ok: false, error: '网络请求失败：' + (err && err.message ? err.message : String(err)) };
    });
  }

  /* ============ 文本检测 ============ */
  var EXAMPLES = [
    '今天天气晴朗，阳光明媚，我和朋友们一起去了公园，度过了一个愉快的下午。',
    '深度学习是机器学习的一个分支，它通过多层神经网络来学习数据的表示和特征。',
    '基于上述分析，我们认为该方案具备较强的可行性与推广价值，建议尽快立项实施。',
  ];
  var exIdx = 0;

  var input = $('#input-text');
  if (input) {
    input.addEventListener('input', function () {
      $('#char-count').textContent = input.value.length + ' 字';
    });
  }
  $('#btn-clear').addEventListener('click', function () { input.value = ''; input.dispatchEvent(new Event('input')); });
  $('#btn-example').addEventListener('click', function () {
    input.value = EXAMPLES[exIdx % EXAMPLES.length]; exIdx++;
    input.dispatchEvent(new Event('input'));
  });

  $('#btn-detect-text').addEventListener('click', function () {
    var btn = this;
    var isMerge = chk('#is-merge', true);
    var isBatch = chk('#is-batch', false);
    var raw = input.value.trim();
    if (!raw) { setStatus('请输入待检测文本', 'error'); return; }

    btn.disabled = true;
    if (isBatch) {
      var lines = raw.split('\n').map(function (s) { return s.trim(); }).filter(function (s) { return s !== ''; });
      if (!lines.length) { btn.disabled = false; setStatus('请输入至少一行文本', 'error'); return; }
      setStatus('批量检测中（' + lines.length + ' 条）…', 'ok');
      post('api/detect.php', { texts: lines, is_merge: isMerge }).then(function (res) {
        btn.disabled = false;
        if (res.ok) { renderBatch(res.results || [], res.errors || []); setStatus('批量完成 · 使用 Token：' + (res.used_token || '未知'), 'ok'); }
        else { setStatus(res.error || '批量检测失败', 'error'); }
        loadHistory();
      }).catch(function () { btn.disabled = false; setStatus('网络请求异常', 'error'); });
      return;
    }

    setStatus('检测中…', 'ok');
    post('api/detect.php', { text: raw, is_merge: isMerge }).then(function (res) {
      btn.disabled = false;
      if (res.ok) { renderTextResult(res.data); setStatus('检测完成 · 使用 Token：' + (res.used_token || '未知'), 'ok'); loadHistory(); }
      else { hideResult(); setStatus(res.error || '检测失败', 'error'); }
    }).catch(function () { btn.disabled = false; setStatus('网络请求异常', 'error'); });
  });

  /* ============ 图像检测 ============ */
  var imgDrop = $('#img-drop');
  function imgHasEndpoint() { return !!(SITE.image_detect_url || '').trim(); }
  function refreshImageHint() {
    var hint = $('#image-hint');
    if (!imgHasEndpoint()) {
      hint.textContent = '⚠️ 尚未配置图像检测接口：请到后台 →「网站设置」填写图像检测接口地址，或关闭图像检测功能。';
      hint.style.color = 'var(--amber)';
    } else {
      hint.textContent = '';
    }
  }

  imgDrop.addEventListener('click', function () { $('#img-file').click(); });
  imgDrop.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); $('#img-file').click(); } });
  ['dragenter', 'dragover'].forEach(function (ev) {
    imgDrop.addEventListener(ev, function (e) { e.preventDefault(); imgDrop.classList.add('dragover'); });
  });
  ['dragleave', 'drop'].forEach(function (ev) {
    imgDrop.addEventListener(ev, function (e) { e.preventDefault(); imgDrop.classList.remove('dragover'); });
  });
  imgDrop.addEventListener('drop', function (e) { addFiles(e.dataTransfer.files); });
  $('#img-file').addEventListener('change', function () { addFiles(this.files); this.value = ''; });

  function addFiles(fileList) {
    var files = Array.prototype.slice.call(fileList || []).filter(function (f) { return /^image\//.test(f.type); });
    var room = IMG_MAX - imageItems.length;
    if (room <= 0) { setImageHint('最多只能添加 ' + IMG_MAX + ' 张', true); return; }
    files = files.slice(0, room);
    var pending = files.length;
    files.forEach(function (f) {
      var reader = new FileReader();
      reader.onload = function () {
        imageItems.push({ name: f.name, base64: String(reader.result).split(',')[1] });
        pending--;
        if (pending <= 0) renderThumbs();
      };
      reader.readAsDataURL(f);
    });
    if (!files.length) { setImageHint('仅支持图片文件', true); }
  }
  function setImageHint(msg, isErr) {
    var h = $('#image-hint');
    h.textContent = msg || '';
    h.style.color = isErr ? 'var(--red)' : 'var(--muted)';
  }
  function renderThumbs() {
    var box = $('#img-thumbs');
    box.innerHTML = imageItems.map(function (it, i) {
      return '<div class="img-thumb"><img src="data:image/*;base64,' + it.base64 + '" alt=""><button class="rm" data-i="' + i + '">✕</button><span class="nm">' + esc(it.name) + '</span></div>';
    }).join('');
  }
  $('#img-thumbs').addEventListener('click', function (e) {
    var b = e.target.closest('.rm'); if (!b) return;
    imageItems.splice(Number(b.dataset.i), 1); renderThumbs();
  });

  $('#btn-detect-img').addEventListener('click', function () {
    var btn = this;
    if (!imgHasEndpoint()) { setImageHint('请先在后台配置图像检测接口地址', true); return; }
    var images = imageItems.map(function (it) { return { name: it.name, data: it.base64 }; });
    var urls = $('#img-url').value.split('\n').map(function (s) { return s.trim(); }).filter(function (s) { return s !== ''; });
    urls.forEach(function (u) { images.push({ name: u.replace(/^.*[\\/]/, '').slice(0, 30), url: u }); });
    if (!images.length) { setImageHint('请先上传图片或填写图片 URL', true); return; }
    if (images.length > IMG_MAX) { setImageHint('最多检测 ' + IMG_MAX + ' 张', true); return; }

    btn.disabled = true;
    setImageHint('检测中…');
    post('api/detect_image.php', { images: images }).then(function (res) {
      btn.disabled = false;
      if (res.ok) { renderImageResult(res.results || []); setImageHint(''); }
      else { setImageHint(res.error || '图像检测失败', true); }
    }).catch(function () { btn.disabled = false; setImageHint('网络请求异常', true); });
  });

  /* ============ 结果渲染：文本 ============ */
  var PILLS = { 0: ['green', '人工内容'], 1: ['red', 'AI 生成'], 2: ['amber', '疑似 AI'] };
  function pillFor(label) {
    var p = PILLS[String(label)] || ['amber', '未知'];
    return '<span class="pill ' + p[0] + '">' + p[1] + '</span>';
  }
  function verdictText(d) {
    var s = Math.max(d.softmax_confidence || 0, d.ratio_confidence || 0);
    if (s < 0.5) return '倾向人工创作';
    if (s < 0.7) return '疑似 AI 生成';
    return '高度疑似 AI 生成';
  }
  function verdictBadge(d) {
    var s = Math.max(d.softmax_confidence || 0, d.ratio_confidence || 0);
    if (s < 0.5) return '<span class="badge green">' + verdictText(d) + '</span>';
    if (s < 0.7) return '<span class="badge amber">' + verdictText(d) + '</span>';
    return '<span class="badge red">' + verdictText(d) + '</span>';
  }
  function verdictHero(d) {
    var s = Math.max(d.softmax_confidence || 0, d.ratio_confidence || 0);
    var pct = (s * 100).toFixed(1);
    if (s < 0.5) return '<div class="verdict verdict-good"><span class="v-ico">✅</span><div class="v-txt"><b>倾向人工创作</b><span>置信度 ' + pct + '%</span></div></div>';
    if (s < 0.7) return '<div class="verdict verdict-warn"><span class="v-ico">⚠️</span><div class="v-txt"><b>疑似 AI 生成</b><span>置信度 ' + pct + '%</span></div></div>';
    return '<div class="verdict verdict-bad"><span class="v-ico">🚨</span><div class="v-txt"><b>高度疑似 AI 生成</b><span>置信度 ' + pct + '%</span></div></div>';
  }
  function metricsHtml(d) {
    var sm = (Number(d.softmax_confidence || 0) * 100).toFixed(2);
    var html = '<div class="metrics">'
      + '<div class="metric m-sm"><span class="m-ico">📈</span><div class="num" style="color:var(--amber)">' + sm + '%</div><div class="lbl">整体置信度</div></div>';
    if (SITE.show_ratio !== false) {
      var rt = (Number(d.ratio_confidence || 0) * 100).toFixed(2);
      html += '<div class="metric m-rt"><span class="m-ico">⚠️</span><div class="num" style="color:var(--red)">' + rt + '%</div><div class="lbl">风险占比</div></div>';
    }
    if (SITE.show_usage !== false) {
      var tokens = d.makers_models_usage ? d.makers_models_usage.total_tokens : 0;
      html += '<div class="metric m-tk"><span class="m-ico">🔢</span><div class="num">' + tokens + '</div><div class="lbl">本次用量 token</div></div>';
    }
    return html + '</div>';
  }
  function ratioBars(labelsRatio) {
    if (!labelsRatio || typeof labelsRatio !== 'object') return '';
    var defs = [['0', '人工内容', 'green'], ['1', 'AI 内容', 'red'], ['2', '疑似 AI 内容', 'amber']];
    var h = '<div class="ratio-bars">';
    defs.forEach(function (it) {
      var pct = Math.round((Number(labelsRatio[it[0]]) || 0) * 100);
      h += '<div class="ratio-row"><div class="cap"><span>' + it[1] + '</span><span>' + pct + '%</span></div>'
        + '<div class="bar ' + it[2] + '"><i style="width:' + pct + '%"></i></div></div>';
    });
    return h + '</div>';
  }
  function segmentsHtml(segments) {
    if (!segments || !segments.length) return '';
    var h = '<div class="segments"><h3>分段明细</h3>';
    segments.forEach(function (seg) {
      var lbl = PILLS[String(seg.label)];
      var cls = lbl ? ('seg-' + lbl[0]) : 'seg-muted';
      h += '<div class="seg ' + cls + '"><div class="meta"><span>段落 ' + (seg.order || '') + '</span>' + pillFor(seg.label)
        + '<span>置信度 ' + (Number(seg.conf) * 100).toFixed(2) + '%</span></div><div class="txt">' + esc(seg.text) + '</div></div>';
    });
    return h + '</div>';
  }
  function copyLastResult() {
    var d = window.__lastResult;
    if (!d) { return; }
    var sm = (Number(d.softmax_confidence || 0) * 100).toFixed(2);
    var rt = (Number(d.ratio_confidence || 0) * 100).toFixed(2);
    var tokens = d.makers_models_usage ? d.makers_models_usage.total_tokens : 0;
    var txt = '判定：' + verdictText(d)
      + '\n整体置信度：' + sm + '%'
      + (SITE.show_ratio !== false ? '\n风险占比：' + rt + '%' : '')
      + (SITE.show_usage !== false ? '\n本次用量 token：' + tokens : '')
      + '\n分段：' + ((d.segment_labels || []).length) + ' 段';
    var done = function () { setStatus('已复制结果摘要', 'ok'); };
    var fail = function () { setStatus('复制失败', 'error'); };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(txt).then(done, fail);
    } else {
      var ta = document.createElement('textarea');
      ta.value = txt; document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); done(); } catch (e) { fail(); }
      document.body.removeChild(ta);
    }
  }
  function renderTextResult(d) {
    window.__lastResult = d;
    showCard();
    var html = '<div class="result-head">' + verdictHero(d)
      + '<button class="btn-copy" id="btn-copy-result" title="复制结果摘要">📋 复制</button></div>'
      + metricsHtml(d);
    if (SITE.show_ratio !== false) html += ratioBars(d.labels_ratio);
    if (SITE.show_segment !== false) html += segmentsHtml(d.segment_labels);
    $('#result-card').innerHTML = html;
    var cb = $('#btn-copy-result');
    if (cb) cb.addEventListener('click', copyLastResult);
  }
  function renderBatch(results, errors) {
    var html = '';
    (results || []).forEach(function (r) {
      html += '<div class="seg"><div class="meta"><span># ' + (r.index + 1) + '</span>' + verdictBadge(r.detect) + '</div>'
        + '<div class="txt">' + esc(r.text) + '</div>' + (SITE.show_ratio !== false ? ratioBars(r.detect.labels_ratio) : '') + '</div>';
    });
    (errors || []).forEach(function (r) {
      html += '<div class="seg"><div class="meta"><span># ' + (r.index + 1) + '</span><span class="pill red">失败</span></div>'
        + '<div class="txt">' + esc(r.text) + '</div><div class="hint" style="color:var(--red)">' + esc(r.error) + '</div></div>';
    });
    showCard();
    $('#result-card').innerHTML = '<h3 style="margin-bottom:12px">批量结果（' + (results || []).length + ' 成功 / ' + (errors || []).length + ' 失败）</h3>' + html;
  }
  function renderImageResult(results) {
    var html = '<h3 style="margin-bottom:14px">图像检测结果</h3>';
    (results || []).forEach(function (r) {
      if (r.ok) {
        var pct = r.confidence == null ? '—' : (Math.round(r.confidence * 100)) + '%';
        var badge = r.confidence == null ? '<span class="badge amber">响应未知</span>'
          : (r.confidence < 0.5 ? '<span class="badge green">' + esc(r.verdict) + '</span>'
            : (r.confidence < 0.7 ? '<span class="badge amber">' + esc(r.verdict) + '</span>'
              : '<span class="badge red">' + esc(r.verdict) + '</span>'));
        html += '<div class="img-result">'
          + (r.name && r.raw && r.raw.image_base64_thumb ? '' : '')
          + '<div class="ir-info"><div class="ir-name">' + esc(r.name) + '</div>'
          + '<div style="margin-top:6px">' + badge + ' <span class="hint">AI 置信度：' + pct + '</span></div>'
          + '</div></div>';
      } else {
        html += '<div class="img-result"><div class="ir-info"><div class="ir-name">' + esc(r.name) + '</div>'
          + '<div class="hint" style="color:var(--red)">' + esc(r.error || '检测失败') + '</div></div></div>';
      }
    });
    if (!(results || []).length) html = '<div class="empty-line">没有可显示的检测结果</div>';
    showCard();
    $('#result-card').innerHTML = html;
  }

  function showCard() {
    $('#result-empty').classList.add('hidden');
    $('#result-card').classList.remove('hidden');
  }
  function hideResult() {
    if ($('#result-card').textContent.trim() === '') return;
    $('#result-card').classList.add('hidden');
    $('#result-empty').classList.remove('hidden');
  }

  /* ============ 历史记录 ============ */
  var drawer = $('#history-drawer');
  function openDrawer() { drawer.classList.remove('hidden'); $('#drawer-mask').classList.remove('hidden'); }
  function closeDrawer() { drawer.classList.add('hidden'); $('#drawer-mask').classList.add('hidden'); }
  $('#btn-history').addEventListener('click', openDrawer);
  $('#btn-close-drawer').addEventListener('click', closeDrawer);
  $('#drawer-mask').addEventListener('click', closeDrawer);

  $('#btn-clear-history').addEventListener('click', function () {
    if (!confirm('确定清空全部历史记录？')) return;
    post('api/history.php', { action: 'clear' }).then(function (r) { if (r.ok) loadHistory(); });
  });
  function loadHistory() {
    fetch('api/history.php').then(function (r) { return r.json(); }).then(function (res) {
      if (!res.ok) return;
      var list = res.list || [];
      var h = !list.length ? '<div class="empty-line">暂无检测历史</div>'
        : list.map(function (it) {
            return '<div class="history-item"><div class="h-time">' + esc(it.time) + ' · ' + (it.is_merge ? '合并' : '分段') + '</div>'
              + '<div class="h-text">' + esc(it.text) + '</div>'
              + '<div class="h-verdict">' + esc(it.verdict) + '（' + (Number(it.softmax_confidence || 0) * 100).toFixed(1) + '%）</div></div>';
          }).join('');
      $('#history-list').innerHTML = h;
    });
  }

  /* ============ 初始化 ============ */
  loadHistory();
  refreshImageHint();
  switchMode('text');
  if (window.ZHUQUE_HAS_TOKEN === false) {
    setStatus('还没有可用的 Token，请到右上角「后台管理」添加', 'error');
  }
})();