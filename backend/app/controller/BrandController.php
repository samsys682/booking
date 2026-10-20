<?php
// =====================================================================
// booking 后端 · 站点与品牌控制器
//
//   GET  /api/v1/site  → 站点/品牌信息（登录页/后台/用户端展示；白名单免 token）
//   POST /api/v1/site  → 保存站点/品牌设置（system.edit；品牌权益按版本门禁）
//
// 免 token 仅限 GET（AuthMiddleware 方法级白名单），POST 仍需登录 + 权限。
// =====================================================================

namespace app\controller;

use app\service\AuditService;
use app\service\BrandService;
use think\Request;
use think\Response;

class BrandController
{
    /**
     * 站点/品牌信息
     * GET /api/v1/site
     */
    public function show(Request $request): Response
    {
        return ok((new BrandService())->info());
    }

    /**
     * 保存站点/品牌设置
     * POST /api/v1/site  {site_name?, company_name?, service_phone?, logo?, brand_name?, powered_by?}
     */
    public function save(Request $request): Response
    {
        $payload = (array)$request->post();
        // 仅接受已知键，忽略其余（防批量赋值）
        $in = [];
        foreach (array_merge(BrandService::SITE_KEYS, ['brand_name', 'powered_by']) as $k) {
            if (array_key_exists($k, $payload)) {
                $in[$k] = $payload[$k];
            }
        }
        if (!$in) {
            return err(400, '未提交任何站点设置');
        }

        try {
            $info = (new BrandService())->save($in);
        } catch (\RuntimeException $e) {
            // 版本门禁：转 403 + 升级引导
            return err(403, $e->getMessage(), [
                'upgrade' => ['feature' => 'brand.custom', 'tip' => $e->getMessage()],
            ], 403);
        } catch (\Throwable $e) {
            return err(500, '保存失败：' . $e->getMessage());
        }

        (new AuditService())->log(
            'site.save',
            'site',
            '站点/品牌设置更新：' . implode('，', array_keys($in)),
            (int)($request->adminId ?? 0)
        );
        return ok($info, '设置已保存');
    }
}
