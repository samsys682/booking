<?php
// =====================================================================
// booking 后端 · 中间件配置
// ⚠️ 全局中间件注册在 app/middleware.php（ThinkPHP8 约定，框架 import 的是那个文件）
// 本文件只提供 alias / priority 等配置键，'global' 键不会被框架读取
// =====================================================================

return [
    // 中间件别名（可在路由里用短名引用）
    'alias' => [
        'auth'  => \app\middleware\AuthMiddleware::class,
        'perm'  => \app\middleware\PermissionMiddleware::class,
        'audit' => \app\middleware\AuditMiddleware::class,
        'json'  => \app\middleware\JsonBodyMiddleware::class,
    ],
];
