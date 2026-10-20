<?php
// =====================================================================
// 【开源发行版·禁用桩 · DepositService】
// 打包时覆盖 backend/app/service/DepositService.php。开源版=定金功能关闭：
// 一切金额口径回到「全额一次付清」（与 deposit_enabled=0 时完全一致），零算法、零落库。
// =====================================================================

namespace app\service;

class DepositService
{
    // 常量保留（PayController 等以 DepositService::KIND_* 判定分期）——仅为标识，无算法
    const KIND_DEPOSIT = '定金';
    const KIND_BALANCE = '尾款';
    const ST_UNPAID   = '待支付';
    const ST_PAID     = '已支付';
    const ST_REFUNDED = '已退款';

    public function enabled(): bool
    {
        return false;
    }

    public function rate(): int
    {
        return 0;
    }

    public function split(array $booking): array
    {
        $actual = (int)($booking['actual'] ?? 0);
        return ['enabled' => false, 'rate' => 0, 'actual' => $actual, 'deposit' => 0, 'balance' => 0];
    }

    public function createForOrder(array $booking): array
    {
        return $this->split($booking); // 开源版不落任何定金行
    }

    public function payableFor(string $orderNo, int $actual): array
    {
        return ['kind' => '服务费', 'amount' => $actual]; // 全额口径
    }

    public function settle(string $orderNo, string $kind, string $channel, string $paidAt): array
    {
        return ['ok' => false, 'amount' => 0];
    }

    public function afterDepositPaid(array $booking): void
    {
    }

    public function paidSum(string $orderNo): int
    {
        return 0;
    }

    public function settled(string $orderNo): bool
    {
        return true; // 无分期 → 视为已结清，不阻断完成
    }

    public function refundAll(string $orderNo): int
    {
        return 0;
    }

    public function summary(array $booking): array
    {
        $actual = (int)($booking['actual'] ?? 0);
        return [
            'deposit_enabled' => false,
            'rate'            => 0,
            'deposit'         => 0,
            'balance'         => 0,
            'deposit_status'  => '',
            'balance_status'  => '',
            'paid_sum'        => 0,
            'payable'         => $actual,
            'payable_kind'    => '服务费',
            'settled'         => true,
        ];
    }

    public function rowOf(string $orderNo, string $kind): ?array
    {
        return null;
    }
}
