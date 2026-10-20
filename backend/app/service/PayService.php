<?php
// =====================================================================
// booking 后端 · 支付渠道工厂
//
// 职责：按「渠道 + 凭证齐备性」选择驱动，让 PayController 不感知渠道差异。
//   · 微信/支付宝：凭证齐备 → 真实驱动；缺失 → 回落沙箱驱动（保证闭环可跑）
//   · 线下收款：OfflinePayDriver（无异步回调，后台人工确认）
//   · 未知渠道：沙箱驱动
// =====================================================================

namespace app\service;

use app\service\pay\AlipayDriver;
use app\service\pay\OfflinePayDriver;
use app\service\pay\PayDriver;
use app\service\pay\SandboxPayDriver;
use app\service\pay\WechatPayDriver;

class PayService
{
    /** 允许的支付渠道 */
    const CHANNELS = ['wechat', 'alipay', 'offline'];

    /** @var PaymentSettingsService */
    private $settings;

    public function __construct(?PaymentSettingsService $settings = null)
    {
        $this->settings = $settings ?: new PaymentSettingsService();
    }

    /**
     * 解析实际生效的驱动（真实渠道凭证缺失自动回落沙箱）
     * @param string $channel
     * @return PayDriver
     */
    public function resolve(string $channel): PayDriver
    {
        switch ($channel) {
            case 'wechat':
                $driver = new WechatPayDriver($this->settings->raw('wechat'));
                return $driver->configured() ? $driver : new SandboxPayDriver('wechat');
            case 'alipay':
                $driver = new AlipayDriver($this->settings->raw('alipay'));
                return $driver->configured() ? $driver : new SandboxPayDriver('alipay');
            case 'offline':
                return new OfflinePayDriver($this->settings->raw('offline'));
            default:
                return new SandboxPayDriver($channel);
        }
    }

    /**
     * 按渠道验签回调
     * @param string $channel
     * @param array $payload
     * @param array $headers
     * @param string $rawBody
     * @return array
     */
    public function verify(string $channel, array $payload, array $headers, string $rawBody = ''): array
    {
        return $this->resolve($channel)->verifyNotify($payload, $headers, $rawBody);
    }

    /**
     * 发起退款（未接入在线退款的渠道返回 pending）
     * @param string $channel
     * @param array $refund
     * @return array
     */
    public function refund(string $channel, array $refund): array
    {
        return $this->resolve($channel)->refund($refund);
    }

    /**
     * 渠道展示清单（用户端收银台选项来源：仅列出已启用渠道）
     * @return array [{key,label,configured,sandbox}]
     */
    public function methods(): array
    {
        $list = [];
        $view = $this->settings->publicView();
        foreach ($view['channels'] as $c) {
            if (!($c['enabled'] ?? false)) {
                continue;
            }
            $driver = $this->resolve($c['key']);
            $list[] = [
                'key'        => $c['key'],
                'label'      => $c['label'],
                'configured' => (bool)$c['configured'],
                'sandbox'    => $driver->channel() === 'sandbox',
            ];
        }
        return $list;
    }
}
