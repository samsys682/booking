<?php
// =====================================================================
// booking 后端 · 商业模块接缝解析器（做法 A「物理拆分」配套 · 属开源 base）
//
// 背景：商业件已按 EditionGate 清单物理搬迁至 app\plus\。开源发行版整体剥离 app/plus，
//       此时开源核心链路（下单/计价…）不能因找不到商业类而致命。
// 约定：开源侧对商业能力的调用一律经 Module 解析——
//         · 钱版/源码版：app/plus 存在 → 返回真实实例，行为不变；
//         · 开源版：app/plus 缺失 → 返回 null，调用方走「开源安全默认值」（如路费 0）。
// 单一事实源：哪些类属商业，由 EditionGate + 物理目录 app/plus 界定，本类不重复维护清单。
// =====================================================================

namespace app\service;

class Module
{
    /** 解析一个已搬迁到 app\plus 的商业服务实例；开源版缺失时返回 null */
    public static function service(string $shortClass): ?object
    {
        $fq = 'app\\plus\\service\\' . $shortClass;
        return class_exists($fq) ? new $fq() : null;
    }

    /** 商业模块是否存在（钱版/源码版为 true，开源版 false） */
    public static function has(string $shortClass): bool
    {
        return class_exists('app\\plus\\service\\' . $shortClass);
    }
}
