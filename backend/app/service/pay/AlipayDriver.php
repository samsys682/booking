<?php
// =====================================================================
// booking 后端 · 支付宝驱动（电脑网站支付 alipay.trade.page.pay）
//
// 签名口径：参数按 key 升序拼 k=v&（剔除 sign/sign_type/空值）→ RSA2(SHA256withRSA) 私钥签名
// 回调验签：异步通知为 form 表单，用「支付宝公钥」对同口径原文验签；trade_status 须为
//           TRADE_SUCCESS / TRADE_FINISHED 才视为支付成功
//
// 说明：网关/应用私钥/支付宝公钥由后台「在线支付」面板配置（加密存储），
//       未配置时 PayService 回落沙箱驱动，本类 create 不会被调用。
// =====================================================================

namespace app\service\pay;

class AlipayDriver implements PayDriver
{
    /** @var array 渠道配置（解密后） */
    private array $cfg;

    public function __construct(array $cfg = [])
    {
        $this->cfg = $cfg;
    }

    public function channel(): string
    {
        return 'alipay';
    }

    public function label(): string
    {
        return '支付宝';
    }

    public function configured(): bool
    {
        return trim((string)($this->cfg['app_id'] ?? '')) !== ''
            && trim((string)($this->cfg['private_key'] ?? '')) !== ''
            && trim((string)($this->cfg['public_key'] ?? '')) !== '';
    }

    public function create(array $tx, array $booking): array
    {
        if (!$this->configured()) {
            throw new \RuntimeException('支付宝商户参数未配置完整');
        }
        $gateway = trim((string)($this->cfg['gateway'] ?? '')) ?: 'https://openapi.alipay.com/gateway.do';
        $notifyUrl = trim((string)($this->cfg['notify_url'] ?? ''));
        $returnUrl = trim((string)($this->cfg['return_url'] ?? ''));

        $biz = [
            'out_trade_no' => (string)$tx['out_trade_no'],
            'total_amount' => number_format(((int)$tx['amount']) / 100, 2, '.', ''),
            'subject'      => (string)($tx['subject'] ?? ('预约服务 ' . (string)($booking['service'] ?? ''))),
            'product_code' => 'FAST_INSTANT_TRADE_PAY',
        ];
        $params = [
            'app_id'      => (string)$this->cfg['app_id'],
            'method'      => 'alipay.trade.page.pay',
            'format'      => 'JSON',
            'charset'     => 'utf-8',
            'sign_type'   => 'RSA2',
            'timestamp'   => date('Y-m-d H:i:s'),
            'version'     => '1.0',
            'biz_content' => json_encode($biz, JSON_UNESCAPED_UNICODE),
        ];
        if ($notifyUrl !== '') {
            $params['notify_url'] = $notifyUrl;
        }
        if ($returnUrl !== '') {
            $params['return_url'] = $returnUrl;
        }
        $params['sign'] = PayCrypto::rsaSign(PayCrypto::sortQuery($params), (string)$this->cfg['private_key']);

        return [
            'out_trade_no' => (string)$tx['out_trade_no'],
            'amount'       => (int)$tx['amount'],
            'channel'      => 'alipay',
            'subject'      => $biz['subject'],
            'pay_url'      => $gateway . '?' . http_build_query($params),
            'pay_params'   => $params,
        ];
    }

    public function verifyNotify(array $payload, array $headers, string $rawBody = ''): array
    {
        $base = [
            'ok' => false, 'code' => 400, 'reason' => '', 'out_trade_no' => '',
            'amount' => 0, 'paid_at' => '', 'trade_no' => '', 'raw' => $payload,
        ];
        $sign = (string)($payload['sign'] ?? '');
        $outTradeNo = clean_input((string)($payload['out_trade_no'] ?? ''), 64);
        if ($outTradeNo === '' || $sign === '') {
            $base['reason'] = '回调参数不完整';
            return $base;
        }
        $publicKey = (string)($this->cfg['public_key'] ?? '');
        if ($publicKey === '') {
            $base['reason'] = '支付宝公钥未配置';
            $base['code'] = 500;
            return $base;
        }
        // 验签：剔除 sign/sign_type 后按 key 升序拼串
        if (!PayCrypto::rsaVerify(PayCrypto::sortQuery($payload), $sign, $publicKey)) {
            $base['reason'] = '验签失败';
            $base['code'] = 401;
            return $base;
        }
        // 交易状态：仅成功态入账
        $tradeStatus = (string)($payload['trade_status'] ?? '');
        if (!in_array($tradeStatus, ['TRADE_SUCCESS', 'TRADE_FINISHED'], true)) {
            $base['reason'] = '交易未成功（' . $tradeStatus . '）';
            return $base;
        }
        // 校验 app_id 归属（防跨应用回调）
        $expectAppId = trim((string)($this->cfg['app_id'] ?? ''));
        $gotAppId = trim((string)($payload['app_id'] ?? ''));
        if ($expectAppId !== '' && $gotAppId !== '' && $expectAppId !== $gotAppId) {
            $base['reason'] = '回调 app_id 不匹配';
            $base['code'] = 401;
            return $base;
        }

        return [
            'ok'           => true,
            'code'         => 200,
            'reason'       => '',
            'out_trade_no' => $outTradeNo,
            'amount'       => (int)round(((float)($payload['total_amount'] ?? 0)) * 100),
            'paid_at'      => (string)($payload['gmt_payment'] ?? date('Y-m-d H:i:s')),
            'trade_no'     => (string)($payload['trade_no'] ?? ''),
            'raw'          => $payload,
        ];
    }

    public function refund(array $refund): array
    {
        // 未接入 alipay.trade.refund 在线接口：返回待人工，由后台退款单流程处理
        return [
            'ok'                => false,
            'status'            => '待人工处理',
            'channel_refund_no' => '',
            'reason'            => '支付宝退款需后台人工发起（在线退款接口未启用）',
        ];
    }
}
