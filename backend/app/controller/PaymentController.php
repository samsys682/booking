<?php
// =====================================================================
// booking 后端 · 在线支付渠道配置控制器
//
// 背景：后台「在线支付」面板此前是纯前端空壳（paymentSettings.save() 只置 savedAt），
//       商户号/私钥/APIv3 密钥从未落到后端 —— 无法真正对接微信/支付宝。
//
// 本控制器提供配置读写：
//   GET  /api/v1/payment/channels  → 渠道清单（脱敏：敏感字段只回 has_ 布尔）
//   POST /api/v1/payment/channels  → 保存渠道配置（敏感字段服务端加密存储）
//
// 版本门禁：保存属付费能力（EditionGate 'pay.online' = pro），读取放行（入口可见）。
// 安全：敏感字段一律不回显明文；主密钥取自 .env（security.pay_config_key）。
// =====================================================================

namespace app\controller;

use app\service\AuditService;
use app\service\EditionGate;
use app\service\PaymentSettingsService;
use app\service\PayService;
use think\Request;
use think\Response;

class PaymentController
{
    /**
     * 渠道配置清单（脱敏）+ 生效支付方式
     * GET /api/v1/payment/channels
     */
    public function channels(Request $request): Response
    {
        $settings = new PaymentSettingsService();
        $data = $settings->publicView();
        $data['methods'] = (new PayService($settings))->methods();
        // 当前版本是否可写（前端据此展示「授权版功能」锁定态）
        $gate = new EditionGate();
        $data['can_edit'] = $gate->assertFeature('pay.online');
        return ok($data);
    }

    /**
     * 保存渠道配置（敏感字段加密落库）
     * POST /api/v1/payment/channels  {wechat:{...}, alipay:{...}, offline:{...}}
     */
    public function save(Request $request): Response
    {
        // 版本门禁：在线支付配置为付费能力（读放行、写拦截）
        $gate = new EditionGate();
        if (!$gate->assertFeature('pay.online')) {
            $deny = $gate->lastDeny();
            return err(403, $deny['tip'] ?? '在线支付为授权版功能', ['upgrade' => $deny], 403);
        }

        $payload = $request->post();
        // 仅接受已知渠道键，忽略其余（防批量赋值）
        $in = [];
        foreach (['wechat', 'alipay', 'offline'] as $channel) {
            if (isset($payload[$channel]) && is_array($payload[$channel])) {
                $in[$channel] = $payload[$channel];
            }
        }
        if (!$in) {
            return err(400, '未提交任何渠道配置');
        }

        $settings = new PaymentSettingsService();
        try {
            $view = $settings->save($in);
        } catch (\Throwable $e) {
            return err(500, '保存失败：' . $e->getMessage());
        }

        $summary = [];
        foreach ($view['channels'] as $c) {
            $summary[] = $c['key'] . ($c['enabled'] ? '=启用' : '=关闭') . ($c['configured'] ? '(已配置)' : '(未配置)');
        }
        (new AuditService())->log(
            'payment.save',
            'payment',
            '在线支付配置更新：' . implode('，', $summary),
            (int)($request->adminId ?? 0)
        );

        $view['methods'] = (new PayService($settings))->methods();
        $view['can_edit'] = true;
        return ok($view, '配置已保存');
    }
}
