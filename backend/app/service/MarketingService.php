<?php
// =====================================================================
// 【开源发行版·禁用桩 · MarketingService】
// 此文件在 build-open-release.js 打包时覆盖 backend/app/service/MarketingService.php。
// 仅用于开源包：保留全部公开方法签名（保证 OrderController/MyController 等调用点不致命），
// 方法体一律返回"未启用营销"的开源默认值——不含任何计价/券/拼团/分销算法。
// 授权版/源码版不使用本桩（用 app/plus 关闭前的完整实现），故不影响付费功能。
// =====================================================================

namespace app\service;

class MarketingService
{
    /** 营销活动列表（开源版恒空） */
    public function activities(int $serviceId = 0): array
    {
        return [];
    }

    /** 匹配活动（开源版无活动） */
    public function matchActivity(int $serviceId): ?array
    {
        return null;
    }

    /** 活动可用性断言（开源版调用即拒绝） */
    public function assertActivityUsable(array $activity, int $memberId = 0): void
    {
        throw new \RuntimeException('营销活动为授权版功能，升级后即可使用');
    }

    /** 活动应付金额（开源版不可用） */
    public function activityPayable(array $activity, int $serviceId, int $minutes): int
    {
        throw new \RuntimeException('营销活动为授权版功能，升级后即可使用');
    }

    /** 占库存（下单仅在 activityId>0 时调用，开源版下单已被门禁挡在此前）→ 放行 true */
    public function holdStock(int $activityId): bool
    {
        return true;
    }

    /** 释放库存（开源版空操作） */
    public function releaseStock(int $activityId): void
    {
    }

    /** 试算券（开源版无券） */
    public function quoteCoupon(string $code, int $total, int $memberId = 0): array
    {
        return ['valid' => false, 'discount' => 0, 'amount' => $total, 'code' => $code];
    }

    /** 核销券（开源版恒 false） */
    public function consumeCoupon(string $code, string $orderNo): bool
    {
        return false;
    }

    /**
     * 统一下单计价（开源版：原价直通，不打折、不叠加活动/券）
     * 返回形状与完整版一致，供 OrderController 读取 total/discount/actual/activity。
     */
    public function priceQuote(int $serviceId, int $minutes, int $total, int $activityId = 0, string $couponCode = '', int $memberId = 0): array
    {
        return [
            'service_id' => $serviceId,
            'minutes'    => $minutes,
            'total'      => $total,
            'discount'   => 0,
            'actual'     => $total,
            'activity'   => null,
            'coupon'     => null,
        ];
    }

    /** 开团（开源版拒绝） */
    public function openGroup(int $activityId, int $memberId, string $memberName, string $orderNo): array
    {
        throw new \RuntimeException('拼团活动为授权版功能，升级后即可使用');
    }

    /** 参团（开源版拒绝） */
    public function joinGroup(string $groupNo, int $memberId, string $memberName, string $orderNo): array
    {
        throw new \RuntimeException('拼团活动为授权版功能，升级后即可使用');
    }

    /** 成团结算（开源版拒绝） */
    public function settleGroup(int $groupId): array
    {
        throw new \RuntimeException('拼团活动为授权版功能，升级后即可使用');
    }

    /** 过期团单处理（开源版无团单） */
    public function expireGroups(): int
    {
        return 0;
    }

    /** 分销提现申请（开源版拒绝） */
    public function applyWithdraw(int $distributorId, int $amount, string $channel = '微信'): array
    {
        throw new \RuntimeException('分销提现为加购功能，需单独购买后开启');
    }

    /** 分销提现审核（开源版拒绝） */
    public function auditWithdraw(int $id, bool $pass, string $remark = ''): array
    {
        throw new \RuntimeException('分销提现为加购功能，需单独购买后开启');
    }
}
