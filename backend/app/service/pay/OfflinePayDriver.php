<?php
// =====================================================================
// booking 后端 · 线下收款驱动（现金 / POS 刷卡）
//
// 线下收款无第三方异步回调：由后台「确认收款」（PayController::confirm）人工入账。
// 因此 verifyNotify 恒拒绝（防伪造回调），refund 交由后台人工处理（pending）。
// =====================================================================

namespace app\service\pay;

class OfflinePayDriver implements PayDriver
{
    /** @var array 渠道配置（enabled 等） */
    private array $cfg;

    public function __construct(array $cfg = [])
    {
        $this->cfg = $cfg;
    }

    public function channel(): string
    {
        return 'offline';
    }

    public function label(): string
    {
        return '线下收款';
    }

    public function configured(): bool
    {
        return true; // 线下收款无需商户凭证
    }

    public function create(array $tx, array $booking): array
    {
        return [
            'out_trade_no' => (string)$tx['out_trade_no'],
            'amount'       => (int)$tx['amount'],
            'channel'      => 'offline',
            'subject'      => (string)($tx['subject'] ?? ('预约服务 ' . (string)($booking['service'] ?? ''))),
            // 线下无在线收银台：前端提示到店付款，由后台确认收款
            'pay_url'      => '',
            'offline'      => true,
        ];
    }

    public function verifyNotify(array $payload, array $headers, string $rawBody = ''): array
    {
        return [
            'ok' => false, 'code' => 400,
            'reason' => '线下收款无异步回调，请由后台确认收款',
            'out_trade_no' => '', 'amount' => 0, 'paid_at' => '', 'trade_no' => '', 'raw' => $payload,
        ];
    }

    public function refund(array $refund): array
    {
        return [
            'ok'                => false,
            'status'            => '待人工处理',
            'channel_refund_no' => '',
            'reason'            => '线下退款需后台人工登记',
        ];
    }
}
