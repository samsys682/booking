<?php
// =====================================================================
// booking 预约上门服务系统 · HTTP 入口（ThinkPHP8 标准入口）
// 站点运行目录应指向本 public 目录
// =====================================================================

namespace think;

require __DIR__ . '/../vendor/autoload.php';

// 实例化应用（自动加载 .env 与 config）
$app = new App();
$http = $app->http;

// 执行请求（异常由框架接管，API 场景统一返回 JSON 错误体）
$response = $http->run();
$response->send();
$http->end($response);
