<?php
// =====================================================================
// booking 后端 · 微信支付驱动（APIv3 · Native 扫码下单）
//
// 请求签名：Authorization: WECHATPAY2-SHA256-RSA2048
//           待签名串 = method\nurl_path\ntimestamp\nnonce_str\nbody\n
//           用「商户 API 证书私钥」RSA-SHA256 签名（serial_no = 商户证书序列号）
// 回调验签：wechatpay-timestamp / wechatpay-nonce / wechatpay-signature 三个请求头
//           待验签串 = timestamp\nnonce\nbody\n，用「微信支付平台证书/公钥」验签
// 回调解密：resource 为 AES-256-GCM 密文（key = APIv3 密钥，nonce/associated_data 随报文）
//
// 说明：商户号/AppID/APIv3 密钥/证书序列号/商户私钥/平台证书均由后台「在线支付」面板配置
//       （加密存储），未配置时 PayService 回落沙箱驱动，本类 create 不会被调用。
// =====================================================================

namespace app\service\pay;

class WechatPayDriver implements PayDriver
{
    /** @var array 渠道配置（解密后） */
    private array $cfg;

    public function __construct(array $cfg = [])
    {
        $this->cfg = $cfg;
    }

    public function channel(): string
    {
        return 'wechat';
    }

    public function label(): string
    {
        return '微信支付';
    }

    public function configured(): bool
    {
        foreach (['mch_id', 'app_id', 'api_v3_key', 'private_key', 'serial_no'] as $f) {
            if (trim((string)($this->cfg[$f] ?? '')) === '') {
                return false;
            }
        }
        // 平台证书用于回调解密/验签；下单本身不强制，但缺失则无法安全验签 → 视为未配置
        return trim((string)($this->cfg['platform_cert'] ?? '')) !== '';
    }

    public function create(array $tx, array $booking): array
    {
        if (!$this->configured()) {
            throw new \RuntimeException('微信支付商户参数未配置完整');
        }
        $base = rtrim(trim((string)($this->cfg['gateway'] ?? '')) ?: 'https://api.mch.weixin.qq.com', '/');
        $path = '/v3/pay/transactions/native';
        $notifyUrl = trim((string)($this->cfg['notify_url'] ?? ''));
        if ($notifyUrl === '') {
            throw new \RuntimeException('微信支付回调地址未配置（notify_url）');
        }
        $body = json_encode([
            'appid'        => (string)$this->cfg['app_id'],
            'mchid'        => (string)$this->cfg['mch_id'],
            'description'  => (string)($tx['subject'] ?? ('预约服务 ' . (string)($booking['service'] ?? ''))),
            'out_trade_no' => (string)$tx['out_trade_no'],
            'notify_url'   => $notifyUrl,
            'amount'       => ['total' => (int)$tx['amount'], 'currency' => 'CNY'],
        ], JSON_UNESCAPED_UNICODE);

        $authorization = $this->buildAuthorization('POST', $path, $body);
        $resp = $this->httpPost($base . $path, $body, [
            'Authorization: ' . $authorization,
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: booking-pay/1.0',
        ]);
        $json = json_decode($resp, true);
        if (!is_array($json) || empty($json['code_url'])) {
            $msg = is_array($json) ? (string)($json['message'] ?? '未知错误') : '响应解析失败';
            throw new \RuntimeException('微信下单失败：' . $msg);
        }
        return [
            'out_trade_no' => (string)$tx['out_trade_no'],
            'amount'       => (int)$tx['amount'],
            'channel'      => 'wechat',
            'subject'      => (string)($tx['subject'] ?? ''),
            'pay_url'      => (string)$json['code_url'], // 扫码支付：前端渲染二维码
            'code_url'     => (string)$json['code_url'],
        ];
    }

    public function verifyNotify(array $payload, array $headers, string $rawBody = ''): array
    {
        $base = [
            'ok' => false, 'code' => 400, 'reason' => '', 'out_trade_no' => '',
            'amount' => 0, 'paid_at' => '', 'trade_no' => '', 'raw' => $payload,
        ];
        $h = [];
        foreach ($headers as $k => $v) {
            $h[strtolower((string)$k)] = is_array($v) ? (string)reset($v) : (string)$v;
        }
        $timestamp = (string)($h['wechatpay-timestamp'] ?? '');
        $nonce = (string)($h['wechatpay-nonce'] ?? '');
        $signature = (string)($h['wechatpay-signature'] ?? '');
        if ($timestamp === '' || $nonce === '' || $signature === '') {
            $base['reason'] = '回调签名头缺失';
            $base['code'] = 401;
            return $base;
        }
        // 时效校验：5 分钟内（防重放）
        if (abs(time() - (int)$timestamp) > 300) {
            $base['reason'] = '回调时间戳超出有效期';
            $base['code'] = 401;
            return $base;
        }
        $platformCert = (string)($this->cfg['platform_cert'] ?? '');
        if ($platformCert === '') {
            $base['reason'] = '微信平台证书未配置';
            $base['code'] = 500;
            return $base;
        }
        $message = $timestamp . "\n" . $nonce . "\n" . $rawBody . "\n";
        if (!PayCrypto::rsaVerify($message, $signature, $platformCert)) {
            $base['reason'] = '验签失败';
            $base['code'] = 401;
            return $base;
        }

        // 解密 resource（AES-256-GCM）
        $resource = is_array($payload['resource'] ?? null) ? $payload['resource'] : [];
        $ciphertext = (string)($resource['ciphertext'] ?? '');
        if ($ciphertext === '') {
            $base['reason'] = '回调报文缺少密文';
            return $base;
        }
        try {
            $plain = PayCrypto::aesGcmDecrypt(
                $ciphertext,
                (string)$this->cfg['api_v3_key'],
                (string)($resource['nonce'] ?? ''),
                (string)($resource['associated_data'] ?? '')
            );
        } catch (\Throwable $e) {
            $base['reason'] = '回调解密失败：' . $e->getMessage();
            $base['code'] = 401;
            return $base;
        }
        $data = json_decode($plain, true);
        if (!is_array($data)) {
            $base['reason'] = '回调明文解析失败';
            return $base;
        }
        $outTradeNo = clean_input((string)($data['out_trade_no'] ?? ''), 64);
        if ($outTradeNo === '') {
            $base['reason'] = '回调缺少 out_trade_no';
            return $base;
        }
        if ((string)($data['trade_state'] ?? '') !== 'SUCCESS') {
            $base['reason'] = '交易未成功（' . (string)($data['trade_state'] ?? '') . '）';
            return $base;
        }
        return [
            'ok'           => true,
            'code'         => 200,
            'reason'       => '',
            'out_trade_no' => $outTradeNo,
            'amount'       => (int)($data['amount']['total'] ?? 0),
            'paid_at'      => (string)($data['success_time'] ?? date('Y-m-d H:i:s')),
            'trade_no'     => (string)($data['transaction_id'] ?? ''),
            'raw'          => $data,
        ];
    }

    public function refund(array $refund): array
    {
        return [
            'ok'                => false,
            'status'            => '待人工处理',
            'channel_refund_no' => '',
            'reason'            => '微信退款需后台人工发起（在线退款接口未启用）',
        ];
    }

    /**
     * 构造 APIv3 请求签名头
     * @param string $method POST/GET
     * @param string $path 含前导 / 的路径（不含域名与查询串）
     * @param string $body 原始请求体
     * @return string
     */
    private function buildAuthorization(string $method, string $path, string $body): string
    {
        $timestamp = time();
        $nonce = bin2hex(random_bytes(16));
        $message = $method . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . $body . "\n";
        $signature = PayCrypto::rsaSign($message, (string)$this->cfg['private_key']);
        return sprintf(
            'WECHATPAY2-SHA256-RSA2048 mchid="%s",nonce_str="%s",signature="%s",timestamp="%s",serial_no="%s"',
            (string)$this->cfg['mch_id'],
            $nonce,
            $signature,
            $timestamp,
            (string)$this->cfg['serial_no']
        );
    }

    /**
     * HTTP POST（curl）
     * @param string $url
     * @param string $body
     * @param array $headers
     * @return string 响应体
     * @throws \RuntimeException
     */
    private function httpPost(string $url, string $body, array $headers): string
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('服务器未安装 curl 扩展，无法发起微信下单');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $resp = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno !== 0) {
            throw new \RuntimeException('微信下单网络异常：' . $error);
        }
        if ($code >= 400) {
            throw new \RuntimeException('微信下单被拒（HTTP ' . $code . '）：' . $resp);
        }
        return (string)$resp;
    }
}
