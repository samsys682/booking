<?php
// =====================================================================
// booking 预约上门服务系统 · PHP 内置服务器路由脚本（php think run 用）
// 站点请求统一交给 index.php（TP 单入口），静态文件直接放行
// =====================================================================

if (is_file($_SERVER['DOCUMENT_ROOT'] . $_SERVER['SCRIPT_NAME'])) {
    return false;
}
require __DIR__ . '/index.php';
