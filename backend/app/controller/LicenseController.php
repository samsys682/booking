<?php
// =====================================================================
// booking 后端 · 授权激活控制器（v1.14：OA 真实验卡制）
// POST /api/v1/license/activate  {card_no, card_key}   首次激活（OA verify 绑定设备）
// PUT  /api/v1/license/reverify                       每日续验（card_no+token+device_id）
// POST /api/v1/license/smoke     {product_spec?}      发卡链路冒烟自检（§4.1a 熔断依据）
// 激活写 license 表 + 覆盖 setting.quota 组限额；card_key 绝不落库/落日志
// 仅通用 CRUD 写 license 表不会触发配额覆盖，必须走本接口完成激活闭环
// =====================================================================

namespace app\controller;

use app\service\AuditService;
use app\service\LicenseService;
use app\service\OaApiException;
use think\Request;
use think\Response;

class LicenseController
{
    /** 授权动作仅限管理端（member/tech token 一律拒绝） */
    private function guardAdmin(Request $request): ?Response
    {
        if ((string)($request->tokenType ?? '') !== 'admin') {
            return err(403, '仅管理端可操作授权', [], 403);
        }
        return null;
    }

    /**
     * 首次激活：OA verify 绑定设备 → 写 license 表 → 覆盖配额
     */
    public function activate(Request $request): Response
    {
        if ($resp = $this->guardAdmin($request)) return $resp;
        $cardNo = clean_input($request->post('card_no', ''), 64);
        $cardKey = (string)$request->post('card_key', '');
        try {
            $info = (new LicenseService())->activate($cardNo, $cardKey);
        } catch (OaApiException $e) {
            $oaCode = (string)($e->oaResponse['data']['code'] ?? '');
            $msg = LicenseService::OA_ERROR_MSG[$oaCode] ?? $e->getMessage();
            return err(400, $msg);
        } catch (\InvalidArgumentException $e) {
            return err(400, $e->getMessage());
        } catch (\RuntimeException $e) {
            // OA 网络不可达：激活时无宽限可言，引导稍后重试
            return err(503, '授权服务暂不可达，请稍后重试');
        }
        (new AuditService())->log('license.activate', $info['cardNo'], '激活' . $info['editionName'], (int)($request->adminId ?? 0));
        return ok($info, '激活成功');
    }

    /**
     * 每日续验：成功刷新 last_verified_at；业务拒绝降级留痕；网络不可达宽限保持
     */
    public function reverify(Request $request): Response
    {
        if ($resp = $this->guardAdmin($request)) return $resp;
        $result = (new LicenseService())->dailyReverify();
        if ($result['verified']) {
            (new AuditService())->log('license.reverify', '', '续验通过（当前 ' . $result['edition'] . '）', (int)($request->adminId ?? 0));
        }
        return ok($result, $result['message']);
    }

    /**
     * 发卡链路冒烟自检：issue → verify → refund 全链路对测试卡核验
     * 自检不过 → 发卡熔断转人工（§4.1a）
     */
    public function smoke(Request $request): Response
    {
        if ($resp = $this->guardAdmin($request)) return $resp;
        $spec = clean_input($request->post('product_spec', ''), 30);
        if ($spec === '') {
            $spec = (string)env('oa.smoke_spec', 'year');
        }
        $result = (new \app\service\OaClient())->smokeTest($spec);
        (new AuditService())->log('license.smoke', $result['card_no'], $result['ok'] ? '发卡自检通过' : ('发卡自检失败：' . $result['detail']), (int)($request->adminId ?? 0));
        return ok($result, $result['ok'] ? '自检通过' : '自检失败');
    }
}
