<?php
// =====================================================================
// booking 后端 · 全局中间件（ThinkPHP8 约定位置：app/middleware.php）
// 框架加载点：think\Http::load() → $app->middleware->import(include app/middleware.php)
// 顺序敏感：CORS 跨域（OPTIONS 预检先放行）→ JsonBody 解析 JSON 请求体 → Auth 鉴权 → Audit 审计
// 白名单：login / install / pay-notify 在 AuthMiddleware 内放行
// =====================================================================

return [
    \app\middleware\CorsMiddleware::class,
    \app\middleware\JsonBodyMiddleware::class,
    \app\middleware\AuthMiddleware::class,
    \app\middleware\AuditMiddleware::class,
];
