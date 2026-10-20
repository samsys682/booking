<?php
// =====================================================================
// booking 后端 · 仪表盘控制器（经营概览 + 限额黄条数据源）
// 金额一律返回「分」，前端 api 层统一分→元转换
// =====================================================================

namespace app\controller;

use app\service\LicenseService;
use app\service\QuotaService;
use think\facade\Db;
use think\Request;
use think\Response;

class DashboardController
{
    /**
     * 经营概览
     * GET /api/v1/dashboard/stats
     */
    public function stats(Request $request): Response
    {
        // 端别守卫：经营数据与客户 PII 仅管理端可见（防会员/技师 token 越权拉取）
        if ((string)($request->tokenType ?? '') !== 'admin') {
            return err(403, '仅管理端可访问', [], 403);
        }
        $today = date('Y-m-d 00:00:00');
        $monthStart = date('Y-m-01 00:00:00');

        $stats = [
            // 今日订单（含各状态拆分）
            'todayOrders'    => (int)Db::name('booking')->where('deleted_at', 0)->where('created_at', '>=', $today)->count(),
            // 今日待服务（含跨日单）
            'todayPending'   => (int)Db::name('booking')->where('deleted_at', 0)->where('status', '待服务')->where('start_at', '>=', $today)->where('start_at', '<', date('Y-m-d 23:59:59'))->count(),
            // 本月订单
            'monthOrders'    => (int)Db::name('booking')->where('deleted_at', 0)->where('created_at', '>=', $monthStart)->count(),
            // 本月实收（分）
            'monthRevenue'   => (int)Db::name('booking')->where('deleted_at', 0)->where('created_at', '>=', $monthStart)->whereIn('status', ['待服务', '服务中', '已完成'])->sum('actual'),
            // 待派单（无技师的待服务单）
            'pendingDispatch' => (int)Db::name('booking')->where('deleted_at', 0)->where('status', '待服务')->where('technician_id', 0)->count(),
            // 待回复评价
            'pendingReviews' => (int)Db::name('review')->where('deleted_at', 0)->where('status', '待回复')->count(),
            // 本月新会员
            'monthMembers'   => (int)Db::name('member')->where('deleted_at', 0)->where('created_at', '>=', $monthStart)->count(),
            // 退款处理中
            'pendingRefunds' => (int)Db::name('refund')->where('deleted_at', 0)->where('status', '待处理')->count(),
        ];

        // 最近订单（10 条，仪表盘动态）
        $recent = Db::name('booking')->where('deleted_at', 0)
            ->order('id desc')->limit(10)
            ->field('order_no,customer,phone,service,technician,start_at,address,actual,status')
            ->select()->toArray();

        return ok([
            'stats'   => $stats,
            'recent'  => $recent,
            'license' => (new LicenseService())->info(),
            // 限额用量（>=warn_rate 黄条提醒）
            'quota'   => (new QuotaService())->usage(),
        ]);
    }
}
