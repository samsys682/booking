<?php
// =====================================================================
// booking 后端 · OA 平台对接客户端（licenses 卡密验卡/发卡自检）
// 契约（feishu backend 实测源码为据，与 quote/backend OaClient 同源）：
//   鉴权   HMAC-SHA256，签名串 = appId|timestamp(秒)|nonce|JSON.stringify(body)
//          请求头 x-app-id / x-timestamp(±300s) / x-nonce(一次性,Redis 防重放) / x-signature
//   卡密   POST /api/v1/licenses/issue|verify|bind-device|refund   GET /stock
//          issue 幂等键=order_no；product_spec 来自卡密批次；OUT_OF_STOCK → HTTP 409
//   verify 首次 {card_no, card_key, device_id}；续验 {card_no, token, device_id}；
//          必须 code===0 且 data.ok===true 才算通过（业务拒绝可能仍是 HTTP 200）
//   错误码 EXPIRED / REVOKED / INVALID / DEVICE_MISMATCH（状态机映射见 LicenseService）
//
// ⚠ 关联坑（接支付网关前必读，quote 侧实测沉淀）：
//   1. OA 验签是对 Express 解析后重新 JSON.stringify 的 body 验签——PHP 侧必须用
//      JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES 编码且不含浮点数，否则验签必挂；
//   2. 金额一律传字符串（元，最多 2 位小数），禁止浮点；
//   3. err() 默认 HTTP 200，判断业务成败看 body.code 不看 HTTP 状态。
// =====================================================================

namespace app\service;

use think\facade\Log;

class OaClient
{
    /** 签名用 JSON 编码位（与 JS JSON.stringify 对齐：原生 UTF-8、不转义斜杠） */
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * 通用请求（POST）
     * @param string $path 以 / 开头，如 /api/v1/licenses/verify
     * @param array  $body 业务参数（仅标量/数组，禁浮点）
     * @param int    $timeout
     * @return array OA 响应（已 json_decode，取 data 部分）
     * @throws \RuntimeException 网络失败/HTTP 非 2xx（调用方按「网络不可达」宽限，不误杀）
     * @throws OaApiException OA 业务失败 code!=0（调用方按错误码映射降级）
     */
    public function post(string $path, array $body = [], int $timeout = 15): array
    {
        $appId = (string)env('oa.app_id', '');
        $secret = (string)env('oa.app_secret', '');
        $base = rtrim((string)env('oa.base_url', ''), '/');
        if ($appId === '' || $secret === '' || $base === '') {
            // 配置缺失按网络不可达处理（未配置 OA 时保持可宽限运行，不炸站）
            throw new \RuntimeException('OA 对接未配置（oa.base_url / oa.app_id / oa.app_secret）');
        }

        $bodyStr = json_encode($body ?: new \stdClass(), self::JSON_FLAGS);
        if ($bodyStr === false) {
            throw new \RuntimeException('请求体编码失败（含非法 UTF-8？）');
        }
        $timestamp = (string)time();
        $nonce = bin2hex(random_bytes(8));
        $signature = hash_hmac('sha256', $appId . '|' . $timestamp . '|' . $nonce . '|' . $bodyStr, $secret);

        $ch = curl_init($base . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $bodyStr,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-app-id: ' . $appId,
                'x-timestamp: ' . $timestamp,
                'x-nonce: ' . $nonce,
                'x-signature: ' . $signature,
            ],
        ]);
        $resp = curl_exec($ch);
        $errno = curl_errno($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new \RuntimeException('OA 连接失败（curl#' . $errno . '）');
        }
        $json = json_decode((string)$resp, true);
        if (!is_array($json)) {
            throw new \RuntimeException('OA 响应非 JSON（HTTP ' . $http . '）');
        }
        // OA 应答风格统一 {code, message, data}：code!=0 视为业务失败（409 缺货等由调用方识别）
        if ((int)($json['code'] ?? -1) !== 0) {
            throw new OaApiException((string)($json['message'] ?? 'OA 业务失败'), (int)($json['code'] ?? 500), $json);
        }
        return (array)($json['data'] ?? []);
    }

    // ==================================================================
    // 卡密（验卡 / 发卡自检 / 回收）
    // ==================================================================

    /**
     * 首次验卡（激活）：{card_no, card_key, device_id} → 绑定设备
     * @return array {ok, code?, msg?, license_id, product_spec, expire_at, features, token}
     * @throws \RuntimeException 网络不可达
     * @throws OaApiException 业务拒绝
     */
    public function verifyCard(string $cardNo, string $cardKey, string $deviceId = ''): array
    {
        return $this->post('/api/v1/licenses/verify', array_filter([
            'card_no'   => $cardNo,
            'card_key'  => $cardKey,
            'device_id' => $deviceId,
        ]));
    }

    /**
     * 续验卡（已激活实例每日续验）：{card_no, token, device_id}，token 不透明不解码
     * @return array 同 verifyCard
     * @throws \RuntimeException 网络不可达（调用方保持现状不降级）
     * @throws OaApiException 业务拒绝（EXPIRED/REVOKED/INVALID/DEVICE_MISMATCH）
     */
    public function reverifyCard(string $cardNo, string $token, string $deviceId): array
    {
        return $this->post('/api/v1/licenses/verify', array_filter([
            'card_no'   => $cardNo,
            'token'     => $token,
            'device_id' => $deviceId,
        ]));
    }

    /**
     * 发卡（发卡自检用；正式发卡唯一入口在 admin/ 平台，booking 侧仅 smoke 链路使用）
     * @return array {license_id, card_no, card_key, product_name, product_spec, duration_days, features}
     * @throws OaApiException code=409 缺货
     */
    public function issueLicense(string $productSpec, string $orderNo, string $buyerContact = ''): array
    {
        return $this->post('/api/v1/licenses/issue', array_filter([
            'product_spec'  => $productSpec,
            'channel'       => 'portal',
            'order_no'      => $orderNo,
            'buyer_contact' => $buyerContact,
        ]));
    }

    /**
     * 回收卡密（issued/used → unissued 可重卖；自检链路回收用）
     */
    public function refundLicense(string $cardNo, string $reason = ''): array
    {
        return $this->post('/api/v1/licenses/refund', array_filter([
            'card_no' => $cardNo,
            'reason'  => $reason,
        ]));
    }

    /**
     * 库存查询（GET；GET 时 Express req.body={} → 签名串 body 固定 "{}"）
     * @return array 各 product_spec 库存汇总
     */
    public function stock(string $productSpec = ''): array
    {
        $appId = (string)env('oa.app_id', '');
        $secret = (string)env('oa.app_secret', '');
        $base = rtrim((string)env('oa.base_url', ''), '/');
        if ($appId === '' || $secret === '' || $base === '') {
            throw new \RuntimeException('OA 对接未配置');
        }
        $qs = $productSpec !== '' ? '?product_spec=' . urlencode($productSpec) : '';
        $timestamp = (string)time();
        $nonce = bin2hex(random_bytes(8));
        $signature = hash_hmac('sha256', $appId . '|' . $timestamp . '|' . $nonce . '|{}', $secret);
        $ch = curl_init($base . '/api/v1/licenses/stock' . $qs);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'x-app-id: ' . $appId,
                'x-timestamp: ' . $timestamp,
                'x-nonce: ' . $nonce,
                'x-signature: ' . $signature,
            ],
        ]);
        $resp = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($errno !== 0) {
            throw new \RuntimeException('OA 连接失败');
        }
        $json = json_decode((string)$resp, true);
        if (!is_array($json) || (int)($json['code'] ?? -1) !== 0) {
            throw new OaApiException((string)($json['message'] ?? '库存查询失败'), (int)($json['code'] ?? 500), is_array($json) ? $json : []);
        }
        return (array)($json['data'] ?? []);
    }

    // ==================================================================
    // 发卡自检（§4.1a 熔断依据：自检不过 → 停发卡转人工）
    // 流程：issue 一张指定规格卡 → verify 验卡必须 ok → refund 回收。
    // 回收后卡回 unissued 可重卖，不损耗库存（卡号落 audit 留痕备查）。
    // ==================================================================

    /**
     * @param string $productSpec 自检用的产品规格
     * @return array {ok: bool, detail: string, card_no: string}
     */
    public function smokeTest(string $productSpec): array
    {
        try {
            $card = $this->issueLicense($productSpec, 'SMOKE-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)), 'self-check');
            $cardNo = (string)($card['card_no'] ?? '');
            $cardKey = (string)($card['card_key'] ?? '');
            if ($cardNo === '' || $cardKey === '') {
                return ['ok' => false, 'detail' => '发卡成功但响应缺 card_no/card_key', 'card_no' => ''];
            }
            $verify = $this->verifyCard($cardNo, $cardKey, 'smoke-check-device');
            if ((bool)($verify['ok'] ?? false) !== true) {
                $this->refundLicense($cardNo, '自检验卡失败回收');
                return ['ok' => false, 'detail' => '验卡未通过：' . (string)($verify['msg'] ?? ($verify['code'] ?? 'unknown')), 'card_no' => $cardNo];
            }
            $this->refundLicense($cardNo, '发卡自检完成回收');
            return ['ok' => true, 'detail' => '发卡→验卡→回收链路通畅', 'card_no' => $cardNo];
        } catch (\Throwable $e) {
            Log::error('[OaClient] 发卡自检失败: ' . $e->getMessage());
            return ['ok' => false, 'detail' => '自检异常：' . $e->getMessage(), 'card_no' => ''];
        }
    }
}

/**
 * OA 业务失败（code!=0），携带 OA 原响应供调用方识别错误码（EXPIRED/REVOKED/INVALID/DEVICE_MISMATCH）
 */
class OaApiException extends \RuntimeException
{
    public int $oaCode;
    public array $oaResponse;

    public function __construct(string $message, int $code, array $response = [])
    {
        parent::__construct($message);
        $this->oaCode = $code;
        $this->oaResponse = $response;
    }
}
