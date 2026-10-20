<?php
// =====================================================================
// booking 后端 · 支付渠道驱动接口（矩阵七章 · 在线支付，付费版功能）
//
// 背景：PayController 此前只有「模拟支付参数 + HMAC 沙箱回调」一条路径，
//       后台「在线支付」面板（支付宝 PID/AppID/私钥、微信商户号/AppID/APIv3 密钥）
//       只写在前端 pinia store（paymentSettings.save() 仅置 savedAt），后端完全不知道。
//
// 本接口把「下单 / 回调验签 / 退款」抽象为渠道驱动，三档实现：
//   · WechatPayDriver  —— 微信支付 APIv3：RSA-SHA256 请求签名 + 平台证书验签 + AES-256-GCM 解密
//   · AlipayDriver     —— 支付宝：RSA2(SHA256withRSA) 请求签名 + 异步通知验签
//   · SandboxPayDriver —— 沙箱/模拟：HMAC-SHA256（密钥仅存 .env），本地开发与 E2E 用
//   · OfflinePayDriver —— 线下收款：无异步回调（后台人工确认）
//
// 契约统一：verifyNotify 一律返回同一结构，PayController 不感知渠道差异。
// =====================================================================

namespace app\service\pay;

interface PayDriver
{
    /** 渠道键：wechat | alipay | offline | sandbox */
    public function channel(): string;

    /** 渠道中文名（后台/用户端展示） */
    public function label(): string;

    /**
     * 是否已完成商户参数配置（凭证齐备才走真实渠道；否则回落沙箱）
     * @return bool
     */
    public function configured(): bool;

    /**
     * 统一下单 → 前端所需支付参数
     * @param array $tx 支付流水行（out_trade_no / amount / order_no / kind / channel）
     * @param array $booking 订单行
     * @return array{out_trade_no:string,amount:int,channel:string,subject:string,pay_url:string,code_url?:string,prepay_id?:string,pay_params?:array,sandbox?:bool}
     * @throws \RuntimeException 渠道下单失败
     */
    public function create(array $tx, array $booking): array;

    /**
     * 回调验签 + 报文解析 → 统一结果
     * @param array $payload 请求体（JSON 已解码 或 表单数组）
     * @param array $headers 请求头（键小写，如 wechatpay-signature）
     * @param string $rawBody 原始请求体（微信 V3 验签须用原文）
     * @return array{ok:bool,code:int,reason:string,out_trade_no:string,amount:int,paid_at:string,trade_no:string,raw:array}
     *         code：验签失败时建议的 HTTP 状态码（400 参数错 / 401 验签失败 / 500 配置缺失）
     */
    public function verifyNotify(array $payload, array $headers, string $rawBody = ''): array;

    /**
     * 发起退款（未接入/未配置返回 pending，由后台人工处理）
     * @param array $refund 退款单行（refund_no / out_trade_no / amount / reason）
     * @return array{ok:bool,status:string,channel_refund_no:string,reason:string}
     */
    public function refund(array $refund): array;
}
