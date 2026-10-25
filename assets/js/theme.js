/* 主题/皮肤切换：auto(跟随系统) / light(日间) / dark(夜间)
 * - 默认 auto，跟随系统 prefers-color-scheme，系统变化时自动切换
 * - 用户手动选择后记忆在 localStorage['zq_theme']
 * - 兼容两种控件：
 *     1) `[data-theme-toggle]`：单按钮循环切换（后台/贡献页沿用）
 *     2) `[data-theme-switch]`：分段切换器（日间/跟随系统/夜间），由本脚本渲染
 * - 在 <head> 中同步加载，立即应用，避免首屏闪烁 */
(function () {
  'use strict';
  var KEY = 'zq_theme';
  var MODES = ['auto', 'light', 'dark'];
  var LABEL = { auto: '跟随系统', light: '日间', dark: '夜间' };
  var ICON = { auto: '🌓', light: '☀️', dark: '🌙' };
  var mql = window.matchMedia('(prefers-color-scheme: dark)');

  function getMode() {
    var m = localStorage.getItem(KEY);
    return MODES.indexOf(m) >= 0 ? m : 'auto';
  }
  function resolved(mode) {
    if (mode === 'dark') return 'dark';
    if (mode === 'light') return 'light';
    return mql.matches ? 'dark' : 'light';
  }
  function apply(mode) {
    document.documentElement.setAttribute('data-theme', resolved(mode));
    document.querySelectorAll('[data-theme-toggle]').forEach(function (b) {
      // 图标 + 当前模式文字，明确标识（如「☀️ 日间」），避免只有裸图标
      b.innerHTML = '<span class="tt-ico">' + ICON[mode] + '</span><span class="tt-txt">' + LABEL[mode] + '</span>';
      b.setAttribute('title', '主题：' + LABEL[mode] + '（点击切换）');
    });
    // 分段切换器：高亮当前模式
    document.querySelectorAll('[data-theme-switch]').forEach(function (box) {
      var btns = box.querySelectorAll('[data-theme-mode]');
      for (var i = 0; i < btns.length; i++) {
        btns[i].classList.toggle('active', btns[i].getAttribute('data-theme-mode') === mode);
      }
    });
  }
  function set(mode) {
    localStorage.setItem(KEY, mode);
    apply(mode);
  }
  function cycle() {
    set(MODES[(MODES.indexOf(getMode()) + 1) % MODES.length]);
  }

  // 渲染分段切换器（日间 / 跟随系统 / 夜间）
  function renderSwitchers() {
    document.querySelectorAll('[data-theme-switch]').forEach(function (box) {
      box.innerHTML = MODES.map(function (m) {
        return '<button type="button" class="ts-opt" data-theme-mode="' + m + '" title="' + LABEL[m] + '">' +
          '<span class="ts-ico">' + ICON[m] + '</span><span class="ts-txt">' + LABEL[m] + '</span></button>';
      }).join('');
      box.querySelectorAll('[data-theme-mode]').forEach(function (b) {
        b.addEventListener('click', function () {
          set(b.getAttribute('data-theme-mode'));
        });
      });
    });
  }

  // 系统主题变化时，若处于 auto 则实时跟随
  if (mql.addEventListener) {
    mql.addEventListener('change', function () {
      if (getMode() === 'auto') apply('auto');
    });
  } else if (mql.addListener) {
    mql.addListener(function () {
      if (getMode() === 'auto') apply('auto');
    });
  }

  renderSwitchers();
  apply(getMode());

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-theme-toggle]').forEach(function (b) {
      b.addEventListener('click', cycle);
    });
  });

  window.ZQTheme = { set: set, get: getMode, cycle: cycle };
})();