/* 后台公共工具：AJAX / Toast / HTML 转义
 * 在所有后台模块共用；于 partials/head.php 中同步加载，
 * 保证模块内联脚本执行时 window.ZQAdmin 已就绪。 */
(function () {
  'use strict';

  var TOKENS_API = '../api/tokens.php';

  /** 向指定接口 POST JSON；返回解析后的响应体（附带 __status） */
  function postTo(url, data) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(data || {}),
    }).then(function (resp) {
      return resp.json().then(function (body) {
        body.__status = resp.status;
        return body;
      });
    }).then(function (res) {
      // 会话过期：统一跳回登录页
      if (res.ok === false && res.__status === 401) {
        window.location.href = 'login.php';
      }
      return res;
    });
  }

  /** 默认向 tokens 接口 POST（保持既有模块调用不变） */
  function post(data) {
    return postTo(TOKENS_API, data);
  }

  function toast(msg, type) {
    var t = document.getElementById('toast');
    if (!t) { return; }
    t.textContent = msg || '';
    t.className = 'toast show ' + (type || 'ok');
    clearTimeout(t._h);
    t._h = setTimeout(function () { t.className = 'toast'; }, 2500);
  }

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function $(s) { return document.querySelector(s); }

  window.ZQAdmin = { post: post, postTo: postTo, toast: toast, esc: esc, $: $ };
})();
