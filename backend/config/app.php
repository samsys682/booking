<?php
// =====================================================================
// booking 后端 · 应用配置
// 统一返回 JSON（接口规范：{code, msg, data}），不做模板渲染
// =====================================================================

return [
    // 应用名称
    'app_name'          => 'booking-backend',
    // 应用调试（生产必须 false；单源 .env 的 [app] debug，键名规则见 .env.example 注释）
    'app_debug'         => env('app.debug', false),
    // 默认返回类型（API 全量 JSON）
    'default_return_type' => 'json',
    // 默认时区（订单/预约时间计算基准）
    'default_timezone'  => 'Asia/Shanghai',
    // 默认分页条数（接口规范：统一分页）
    'default_per_page'  => 20,
    // 分页最大条数（防拖库：单页上限）
    'max_per_page'      => 100,
    // 异常页面模板（API 场景返回 JSON，不渲染 HTML）
    'exception_tmpl'    => '',
    // 错误显示信息（生产不泄露堆栈）
    'show_error_msg'    => false,
    // 时区与语言
    'lang_display'      => false,
];
