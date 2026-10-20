<?php
// =====================================================================
// booking 后端 · 沙箱/模拟支付驱动（本地开发 + E2E 回归）
//
// 口径与历史实现逐字节一致（既有 400+ 断言依赖）：
//   · 下单：返回模拟收银台地址 /pay/cashier?out_trade_no=...
//   · 回调验签：sign = hmac_sha256(out_trade_no&amount&paid_at&nonce, security.pay_notify_key)
//   · 密钥仅存 .env，不入库不出参
//
// 真实渠道（微信/支付宝）未配置凭证时，PayService 自动回落到本驱动，
// 保证「开源版 / 未接入商户」也能跑通支付闭环（模拟到账）。
// =====================================================================

namespace app\service\pay;

class SandboxPayDriver implements PayDriver
{
    /** @var string 请求渠道（wechat|alipay|offline），仅用于回显，不改变沙箱验签口径 */
    private string $requestedChannel;

    public function __construct(string $requestedChannel = 'sandbox')
    {
        $this->requestedChannel = $requestedChannel;
    }

    public function channel(): string
    {
        return 'sandbox';
    }

    public function label(): string
    {
        return '沙箱支付';
    }

    public function configured(): bool
    {
        // 沙箱恒可用：仅需 .env 的 pay_notify_key（缺失时验签会明确报错）
        return true;
    }

    public function create(array $tx, array $booking): array
    {
        return [
            'out_trade_no' => (string)$tx['out_trade_no'],
            'amount'       => (int)$tx['amount'],
            'channel'      => (string)($tx['channel'] ?? $this->requestedChannel),
            'subject'      => (string)($tx['subject'] ?? ('预约服务 ' . (string)($booking['service'] ?? ''))),
            // 模拟支付跳转地址（真实接入时替换为收银台/小程序跳转参数）
            'pay_url'      => '/pay/cashier?out_trade_no=' . (string)$tx['out_trade_no'],
            'sandbox'      => true,
        ];
    }

    public function verifyNotify(array $payload, array $headers, string $rawBody = ''): array
    {
        $base = [
            'ok' => false, 'code' => 400, 'reason' => '', 'out_trade_no' => '',
            'amount' => 0, 'paid_at' => '', 'trade_no' => '', 'raw' => $payload,
        ];
        $outTradeNo = clean_input((string)($payload['out_trade_no'] ?? ''), 64);
        $amount = (int)($payload['amount'] ?? 0);
        $paidAt = clean_input((string)($payload['paid_at'] ?? ''), 20);
        $nonce = clean_input((string)($payload['nonce'] ?? ''), 64);
        $sign = clean_input((string)($payload['sign'] ?? ''), 128);

        if ($outTradeNo === '' || $sign === '') {
            $base['reason'] = '回调参数不完整';
            return $base;
        }

        $key = (string)env('security.pay_notify_key', '');
        if ($key === '') {
            $base['reason'] = '支付回调未配置密钥';
            $base['code'] = 500;
            return $base;
        }
        $expect = hash_hmac('sha256', $outTradeNo . '&' . $amount . '&' . $paidAt . '&' . $nonce, $key);
        if (!hash_equals($expect, $sign)) {
            $base['reason'] = '验签失败';
            $base['code'] = 401;
            return $base;
        }

        return [
            'ok'           => true,
            'code'         => 200,
            'reason'       => '',
            'out_trade_no' => $outTradeNo,
            'amount'       => $amount,
            'paid_at'      => $paidAt,
            'trade_no'     => $outTradeNo,
            'raw'          => $payload,
        ];
    }

    public function refund(array $refund): array
    {
        return [
            'ok'                => true,
            'status'            => '已退款',
            'channel_refund_no' => 'SB' . date('YmdHis') . mt_rand(1000, 9999),
            'reason'            => '',
        ];
    }
}
