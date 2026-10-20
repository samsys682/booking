<?php
// =====================================================================
// booking 后端 · 路由配置
// 接口规范：RESTful + /api/v1 前缀，路由定义见 route/app.php
// =====================================================================

return [
    // pathinfo 分隔符
    'pathinfo_depr'         => '/',
    // URL 后缀（API 不需要 html 等后缀）
    'url_html_suffix'       => false,
    // 合并路由规则（不同定义文件同名路由不覆盖）
    'route_rule_merge'      => true,
    // 路由缓存（生产开启加速解析）
    'route_check_cache'     => false,
    // 默认请求方式（RESTful 全量支持）
    'default_method'        => 'get',
    // 强制路由（true 时未定义路由直接 404，防未授权入口）
    'url_route_must'        => true,
    // 域名部署
    'url_domain_deploy'     => false,
    // 是否自动转换 URL 中的控制器和操作名
    'url_convert'           => false,
    // 是否检查路由调度
    'url_route_on'          => true,
];
