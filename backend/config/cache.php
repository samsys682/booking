<?php
// =====================================================================
// booking 后端 · 缓存配置（file 驱动，落 runtime/cache）
// =====================================================================

return [
    'default' => 'file',
    'stores'  => [
        'file' => [
            'type'        => 'file',
            'path'        => runtime_path() . 'cache',
            'prefix'      => '',
            'expire'      => 0,
            'gc_probably' => 100,
        ],
    ],
];
