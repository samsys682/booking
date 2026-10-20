<?php
// =====================================================================
// booking 后端 · 容器绑定（ThinkPHP8 约定：app/provider.php）
// 替换框架默认异常处理为 API 统一 JSON 异常处理
// =====================================================================

return [
    'think\exception\Handle' => \app\exception\Handle::class,
];
