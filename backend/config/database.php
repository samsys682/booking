<?php
// =====================================================================
// booking 后端 · 数据库配置（值由安装向导写入 .env，此处仅默认值）
// 建表脚本统一放 data/sql/（01_system → 06_seed 顺序执行）
// =====================================================================

return [
    // 默认连接
    'default'         => env('database.driver', 'mysql'),
    // 连接定义
    'connections'     => [
        'mysql' => [
            // 数据库类型
            'type'            => 'mysql',
            // 服务器地址
            'hostname'        => env('database.hostname', '127.0.0.1'),
            // 数据库名
            'database'        => env('database.database', 'booking'),
            // 用户名
            'username'        => env('database.username', 'root'),
            // 密码
            'password'        => env('database.password', ''),
            // 端口
            'hostport'        => env('database.hostport', '3306'),
            // 数据库编码
            'charset'         => env('database.charset', 'utf8mb4'),
            // 表前缀（隔离同库多应用）
            'prefix'          => env('database.prefix', ''),
            // 断线重连
            'break_reconnect' => true,
            // 严格模式（金额/时间字段写入更安全）
            'fields_strict'   => true,
        ],
    ],
];
