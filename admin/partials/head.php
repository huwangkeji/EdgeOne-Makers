<?php
/**
 * 后台公共 <head> 与 <body> 起始。
 * 引入前需定义常量 ZQ_ADMIN；可选设置 $pageTitle。
 */
if (!defined('ZQ_ADMIN')) {
    http_response_code(403);
    exit('Forbidden');
}
$__title = (isset($pageTitle) && $pageTitle !== '')
    ? $pageTitle . ' · 后台管理 · 云雀 AI 检测助手'
    : '后台管理 · 云雀 AI 检测助手';
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($__title, ENT_QUOTES, 'UTF-8'); ?></title>
  <link rel="stylesheet" href="../assets/css/admin.css">
  <script src="../assets/js/theme.js"></script>
  <!-- 公共工具需同步加载，供各模块内联脚本直接使用 -->
  <script src="../assets/js/admin.js"></script>
</head>
<body>
