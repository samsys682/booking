<?php
// =====================================================================
// 【开源发行版·禁用桩 · MarketingSchedulerService】
// 打包时覆盖 backend/app/service/MarketingSchedulerService.php。开源版=自动化营销关闭。
// runRule/runDue 直接返回"未启用"结果，不圈人、不发送、无任何营销逻辑。
// =====================================================================

namespace app\service;

class MarketingSchedulerService
{
    public function runRule(array $rule): array
    {
        return ['status' => 'skipped', 'matched' => 0, 'sent' => 0, 'reason' => '自动化营销为授权版功能，升级后即可使用'];
    }

    public function runDue(?string $today = null): array
    {
        return ['status' => 'skipped', 'rules' => 0, 'sent' => 0, 'reason' => '自动化营销为授权版功能，升级后即可使用'];
    }

    public function isTimeBased(string $trigger): bool
    {
        return false;
    }
}
