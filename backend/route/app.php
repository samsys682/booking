<?php
// =====================================================================
// booking 后端 · 路由定义（/api/v1 前缀）
// 鉴权：全局 AuthMiddleware（白名单：login / install / pay-notify）
// 权限：PermissionMiddleware（静态码 或 auto:view|edit|delete 动态码）
// 说明：config/route.php 已开启 url_route_must，未定义路由一律 404
// =====================================================================

use app\controller\AuthController;
use app\controller\BackupController;
use app\controller\CommissionController;
use app\controller\DashboardController;
use app\controller\InstallController;
use app\controller\MarketingController;
use app\controller\MyController;
use app\controller\OrderController;
use app\controller\PayController;
use app\controller\ReportController;
use app\controller\ResourceController;
use app\controller\ReviewController;
use app\controller\StoreController;
use app\controller\TechController;
use app\controller\UpgradeController;
use app\controller\UploadController;
use app\controller\WorkflowController;
use app\middleware\PermissionMiddleware;
use think\facade\Route;

// ---------------------------------------------------------------------
// 认证（login 在 AuthMiddleware 白名单内）
// ---------------------------------------------------------------------
Route::group('api/v1/auth', function () {
    Route::post('login', 'AuthController/login');
    Route::post('member-send-code', 'AuthController/memberSendCode');
    Route::post('member-login', 'AuthController/memberLogin');
    // 微信一键授权登录（缺口补齐批次四；AuthMiddleware 白名单免 token）
    Route::post('member-wx-login', 'AuthController/memberWxLogin');
    Route::post('tech-login', 'AuthController/techLogin');
    Route::get('profile', 'AuthController/profile');
    Route::put('password', 'AuthController/changePassword');
    Route::post('sensitive-verify', 'AuthController/sensitiveVerify');
});

// ---------------------------------------------------------------------
// 授权激活（须注册在通用 {resource} CRUD 之前；写 license 表+覆盖 quota）
// v1.14：OA 真实验卡制——activate {card_no, card_key}；reverify 每日续验；smoke 发卡自检
// ---------------------------------------------------------------------
Route::group('api/v1', function () {
    Route::post('license/activate', 'LicenseController/activate');
    Route::put('license/reverify', 'LicenseController/reverify');
    Route::post('license/smoke', 'LicenseController/smoke');
});

// ---------------------------------------------------------------------
// 在线升级（检查更新 / 执行升级 / 回滚 / 状态）
// 备份 + 迁移 + 版本推进 + 留痕；仅管理端 + system 权限
// ⚠️ 须注册在通用 {resource} CRUD 之前（'upgrade/*' 会被前缀吞掉，坑 C）
// ---------------------------------------------------------------------
Route::group('api/v1', function () {
    Route::get('upgrade/check', 'UpgradeController/check')
        ->middleware(PermissionMiddleware::class, 'system.view');
    Route::get('upgrade/status', 'UpgradeController/status')
        ->middleware(PermissionMiddleware::class, 'system.view');
    Route::post('upgrade/run', 'UpgradeController/run')
        ->middleware(PermissionMiddleware::class, 'system.edit');
    Route::post('upgrade/rollback', 'UpgradeController/rollback')
        ->middleware(PermissionMiddleware::class, 'system.edit');
});

// ---------------------------------------------------------------------
// 门店作用域（多门店数据隔离：当前管理员可见门店范围概览）
// ⚠️ 须注册在通用 {resource} CRUD 之前（'stores/scope' 会被 GET {resource}/{id} 前缀吞掉，坑 C）
// ---------------------------------------------------------------------
Route::group('api/v1', function () {
    Route::get('stores/scope', 'StoreController/scope')
        ->middleware(PermissionMiddleware::class, 'system.view');
});

// ---------------------------------------------------------------------
// 仪表盘
// ---------------------------------------------------------------------
Route::group('api/v1', function () {
    Route::get('dashboard/stats', 'DashboardController/stats');
});

// ---------------------------------------------------------------------
// 经营报表导出（CSV；版本门禁 report.export=授权版+，权限 finance.export）
// 须注册在通用 {resource} 路由之前：'reports' 会匹配通用模式（坑 C）
// ---------------------------------------------------------------------
Route::group('api/v1', function () {
    Route::get('reports/export', 'ReportController/export')
        ->middleware(PermissionMiddleware::class, 'finance.export');
});

// ---------------------------------------------------------------------
// 订单（状态机动作路由，与通用资源 CRUD 分离）
// ---------------------------------------------------------------------
Route::group('api/v1/orders', function () {
    Route::post('/', 'OrderController/create');
    // ⚠️ my/pool 须注册在 {orderNo} 之前：TP8 路由匹配非锚定，GET {orderNo} 会吞掉 my/pool
    Route::get('my', 'OrderController/myList');
    Route::get('pool', 'OrderController/pool');
    Route::get('{orderNo}', 'OrderController/detail');
    Route::put('{orderNo}/cancel', 'OrderController/cancel');
    Route::put('{orderNo}/reschedule', 'OrderController/reschedule');
    // ⚠️ TP8 路由匹配非锚定：字面量段会前缀吞掉更长路径（extend 吞 extend-confirm），
    //    同前缀路由须长路径注册在前
    Route::post('{orderNo}/extend-confirm', 'OrderController/extendConfirm');
    Route::post('{orderNo}/extend', 'OrderController/extend');
    Route::post('{orderNo}/dispatch', 'OrderController/dispatch');
    Route::post('{orderNo}/grab', 'OrderController/grab');
    Route::post('{orderNo}/accept', 'OrderController/accept');
    Route::post('{orderNo}/reject', 'OrderController/reject');
    Route::post('{orderNo}/checkin', 'OrderController/checkin');
    Route::post('{orderNo}/finish', 'OrderController/finish');
    Route::post('{orderNo}/confirm', 'OrderController/confirm');
    Route::post('{orderNo}/noshow', 'OrderController/noshow');
    Route::post('{orderNo}/aftersale-reject', 'OrderController/aftersaleReject');
    Route::post('{orderNo}/aftersale', 'OrderController/aftersale');
    Route::post('{orderNo}/refund', 'OrderController/refund');
});

// ---------------------------------------------------------------------
// 支付（notify 在 AuthMiddleware 白名单内）
// ---------------------------------------------------------------------
Route::group('api/v1/pay', function () {
    Route::post('unified-order', 'PayController/unifiedOrder');
    Route::post('notify', 'PayController/notify');
    Route::post('confirm', 'PayController/confirm');
});

// ---------------------------------------------------------------------
// 在线支付渠道配置（后台「系统设置 → 在线支付」面板）
// 读放行（入口可见）；写走 system.edit + 控制器内 EditionGate('pay.online'=pro) 版本门禁
// ---------------------------------------------------------------------
Route::group('api/v1/payment', function () {
    Route::get('channels', 'PaymentController/channels')
        ->middleware(PermissionMiddleware::class, 'system.view');
    Route::post('channels', 'PaymentController/save')
        ->middleware(PermissionMiddleware::class, 'system.edit');
});

// ---------------------------------------------------------------------
// 站点与品牌（GET 免 token：登录页展示站点名/Logo；POST 走 system.edit + 版本门禁）
// ---------------------------------------------------------------------
Route::group('api/v1', function () {
    Route::get('site', 'BrandController/show');
    Route::post('site', 'BrandController/save')
        ->middleware(PermissionMiddleware::class, 'system.edit');
});

// ---------------------------------------------------------------------
// 文件上传（multipart；任意已登录端可用：服务图走管理员、评价晒图走会员 token）
// 不挂 RBAC —— 单一权限码无法同时覆盖 admin/member/tech 三类上传方
// ---------------------------------------------------------------------
Route::group('api/v1', function () {
    Route::post('upload', 'UploadController/upload');
});

// ---------------------------------------------------------------------
// 评价（带业务语义的写入：快照 + 订单状态流转；列表/详情/删除走通用 CRUD）
// 须注册在通用 {resource} 路由之前，特定路由优先命中
// ⚠️ TP8 新路由引擎下 {id:\d+} 内联正则整条路由失效（永不匹配），
//    必须写 {id} 并用 group pattern 约束；PermissionMiddleware 显式传资源名
// ---------------------------------------------------------------------
Route::group('api/v1', function () {
    // 评价创建放行会员 token（auto:member-edit），归属校验在 ReviewController
    Route::post('reviews', 'ReviewController/create')
        ->middleware(PermissionMiddleware::class, 'auto:member-edit', 'reviews');
    Route::put('reviews/{id}', 'ReviewController/update')
        ->middleware(PermissionMiddleware::class, 'auto:edit', 'reviews');
})->pattern(['id' => '\d+']);

// ---------------------------------------------------------------------
// 会员域动作（开卡/核销/充值/发券/积分调整；主表+流水同事务）
// 须注册在通用 {resource} 路由之前：TP8 非锚定短模式会吞长尾巴（坑 C）
// {id} 不写内联正则（坑 B），靠 group pattern 约束
// 中间件显式传资源名（坑 D）；member-edit 放行会员 token，归属校验在控制器
// ---------------------------------------------------------------------
Route::group('api/v1', function () {
    Route::post('member-cards/open', 'MemberActionController/cardOpen')
        ->middleware(PermissionMiddleware::class, 'auto:edit', 'member-cards');
    Route::post('member-cards/{id}/redeem', 'MemberActionController/cardRedeem')
        ->middleware(PermissionMiddleware::class, 'auto:member-edit', 'member-cards');
    Route::post('stored-accounts/{id}/recharge', 'MemberActionController/storedRecharge')
        ->middleware(PermissionMiddleware::class, 'auto:member-edit', 'stored-accounts');
    Route::post('coupons/{id}/grant', 'MemberActionController/couponGrant')
        ->middleware(PermissionMiddleware::class, 'auto:edit', 'coupons');
    Route::post('points/adjust', 'MemberActionController/pointAdjust')
        ->middleware(PermissionMiddleware::class, 'auto:edit', 'point-logs');
    Route::post('auto-marketing-rules/{id}/run', 'MemberActionController/autoMarketingRun')
        ->middleware(PermissionMiddleware::class, 'auto:edit', 'auto-marketing-rules');
})->pattern(['id' => '\d+']);

// ---------------------------------------------------------------------
// 消息通知（后台手工补发 / 模板预览 / 触发点清单）
// ⚠️ 须注册在通用 {resource} 路由之前（'messages' 会匹配通用模式 [a-z][a-z0-9\-]*）
// 权限码借 system 域（消息模板/发送记录同属系统设置）
// ---------------------------------------------------------------------
Route::group('api/v1', function () {
    Route::post('messages/send', 'MessageController/send')
        ->middleware(PermissionMiddleware::class, 'auto:edit', 'message-send-logs');
    Route::get('messages/preview', 'MessageController/preview')
        ->middleware(PermissionMiddleware::class, 'auto:view', 'message-templates');
    Route::get('messages/triggers', 'MessageController/triggers')
        ->middleware(PermissionMiddleware::class, 'auto:view', 'message-templates');
    // 微信通知配置（读放行；写走控制器内 EditionGate message.wechat=授权版）
    Route::get('messages/notify-config', 'MessageController/notifyConfig')
        ->middleware(PermissionMiddleware::class, 'auto:view', 'message-templates');
    Route::post('messages/notify-config', 'MessageController/notifyConfigSave')
        ->middleware(PermissionMiddleware::class, 'auto:edit', 'message-templates');
    Route::post('messages/notify-test', 'MessageController/notifyTest')
        ->middleware(PermissionMiddleware::class, 'auto:view', 'message-templates');
});

// ---------------------------------------------------------------------
// 会员自助域（"我的"：卡/券/积分/储值/地址，仅会员 token，归属校验在控制器）
// ⚠️ 须注册在通用 {resource} 路由之前：'my' 会匹配通用模式 [a-z][a-z0-9\-]*（坑 C）
// ⚠️ my/coupons/available 须注册在 my/coupons 之前（坑 C 吞尾）
// {id} 不写内联正则（坑 B），靠 group pattern 约束；中间件显式传资源名（坑 D）
// ---------------------------------------------------------------------
Route::group('api/v1', function () {
    Route::get('my/cards', 'MyController/cards')
        ->middleware(PermissionMiddleware::class, 'auto:member-edit', 'member-cards');
    Route::get('my/card-offers', 'MyController/cardOffers')
        ->middleware(PermissionMiddleware::class, 'auto:member-edit', 'member-cards');
    Route::post('my/cards/buy', 'MyController/cardBuy')
        ->middleware(PermissionMiddleware::class, 'auto:member-edit', 'member-cards');
    Route::get('my/coupons/available', 'MyController/couponAvailable')
        ->middleware(PermissionMiddleware::class, 'auto:member-edit', 'coupons');
    Route::get('my/coupons', 'MyController/coupons')
        ->middleware(PermissionMiddleware::class, 'auto:member-edit', 'coupons');
    Route::post('my/coupons/{id}/claim', 'MyController/couponClaim')
        ->middleware(PermissionMiddleware::class, 'auto:member-edit', 'coupons');
    Route::get('my/points', 'MyController/points')
        ->middleware(PermissionMiddleware::class, 'auto:member-edit', 'point-logs');
    Route::post('my/gifts/{id}/redeem', 'MyController/giftRedeem')
        ->middleware(PermissionMiddleware::class, 'auto:member-edit', 'gifts');
    Route::get('my/gift-exchanges', 'MyController/giftExchanges')
        ->middleware(PermissionMiddleware::class, 'auto:member-edit', 'gift-exchanges');
    Route::get('my/invoices', 'MyController/invoices')
        ->middleware(PermissionMiddleware::class, 'auto:member-edit', 'invoices');
    Route::post('my/invoices', 'MyController/invoiceApply')
        ->middleware(PermissionMiddleware::class, 'auto:member-edit', 'invoices');
    Route::get('my/invite', 'MyController/invite')
        ->middleware(PermissionMiddleware::class, 'auto:member-edit', 'members');
    Route::get('my/review-reward', 'MyController/reviewReward')
        ->middleware(PermissionMiddleware::class, 'auto:member-edit', 'reviews');
    Route::get('my/activities', 'MyController/activities')
        ->middleware(PermissionMiddleware::class, 'auto:member-edit', 'marketing-activities');
    Route::get('my/groups', 'MyController/groups')
        ->middleware(PermissionMiddleware::class, 'auto:member-edit', 'marketing-activities');
    Route::get('my/stored', 'MyController/stored')
        ->middleware(PermissionMiddleware::class, 'auto:member-edit', 'stored-accounts');
    Route::get('my/addresses', 'MyController/addresses')
        ->middleware(PermissionMiddleware::class, 'auto:member-edit', 'member-addresses');
    Route::post('my/addresses', 'MyController/addressCreate')
        ->middleware(PermissionMiddleware::class, 'auto:member-edit', 'member-addresses');
    Route::put('my/addresses/{id}', 'MyController/addressUpdate')
        ->middleware(PermissionMiddleware::class, 'auto:member-edit', 'member-addresses');
    Route::delete('my/addresses/{id}', 'MyController/addressDelete')
        ->middleware(PermissionMiddleware::class, 'auto:member-edit', 'member-addresses');
    Route::get('my/queue', 'MyController/queueList')
        ->middleware(PermissionMiddleware::class, 'auto:member-edit', 'booking-queues');
    Route::post('my/queue', 'MyController/queueJoin')
        ->middleware(PermissionMiddleware::class, 'auto:member-edit', 'booking-queues');
    Route::delete('my/queue/{id}', 'MyController/queueCancel')
        ->middleware(PermissionMiddleware::class, 'auto:member-edit', 'booking-queues');
})->pattern(['id' => '\d+']);

// ---------------------------------------------------------------------
// 技师自助域（收入/排班/考勤/评价，仅技师 token，归属校验在控制器）
// ⚠️ 须注册在通用 {resource} 路由之前：'tech' 会匹配通用模式 [a-z][a-z0-9\-]*（坑 C）
// ⚠️ 同前缀长路径注册在前：attendance/checkin 吞 attendance（坑 C）
// {id} 不写内联正则（坑 B），靠 group pattern 约束；中间件显式传资源名（坑 D）
// ---------------------------------------------------------------------
Route::group('api/v1', function () {
    Route::get('tech/income', 'TechController/income')
        ->middleware(PermissionMiddleware::class, 'auto:tech-edit', 'commission-settlements');
    Route::put('tech/schedules/{id}/toggle', 'TechController/scheduleToggle')
        ->middleware(PermissionMiddleware::class, 'auto:tech-edit', 'schedules');
    Route::get('tech/schedules', 'TechController/schedules')
        ->middleware(PermissionMiddleware::class, 'auto:tech-edit', 'schedules');
    Route::post('tech/attendance/checkin', 'TechController/attendanceCheckin')
        ->middleware(PermissionMiddleware::class, 'auto:tech-edit', 'attendances');
    Route::post('tech/attendance/checkout', 'TechController/attendanceCheckout')
        ->middleware(PermissionMiddleware::class, 'auto:tech-edit', 'attendances');
    Route::get('tech/attendance', 'TechController/attendance')
        ->middleware(PermissionMiddleware::class, 'auto:tech-edit', 'attendances');
    Route::get('tech/reviews', 'TechController/reviews')
        ->middleware(PermissionMiddleware::class, 'auto:tech-edit', 'reviews');
    Route::get('tech/my-services', 'TechController/myServices')
        ->middleware(PermissionMiddleware::class, 'auto:tech-edit', 'technician-services');
    Route::put('tech/my-services', 'TechController/myServicesSave')
        ->middleware(PermissionMiddleware::class, 'auto:tech-edit', 'technician-services');
})->pattern(['id' => '\d+']);

// ---------------------------------------------------------------------
// 安装向导（AuthMiddleware 白名单；装后 install.lock 生效 404）
// ---------------------------------------------------------------------
Route::group('api/v1/install', function () {
    Route::get('check', 'InstallController/check');
    Route::post('test-db', 'InstallController/testDb');
    Route::post('run', 'InstallController/run');
});

// ---------------------------------------------------------------------
// 分销提现（独立动作，不走通用 CRUD）
// ⚠️ 必须注册在通用 {resource} CRUD 之前：TP8 非锚定短模式 POST {resource}
//    会前缀吞掉 distributors/{id}/withdraw（坑 C，同 my/ 与 tech/ 域）
// ---------------------------------------------------------------------
Route::group('api/v1', function () {
    Route::post('distributors/{id}/withdraw', 'MemberActionController/withdrawApply')
        ->middleware(PermissionMiddleware::class, 'auto:edit', 'distributors');
    Route::put('distribution-withdraws/{id}/audit', 'MemberActionController/withdrawAudit')
        ->middleware(PermissionMiddleware::class, 'auto:edit', 'distribution-orders');
})->pattern(['id' => '\d+']);

// ---------------------------------------------------------------------
// 提成结算（生成工资条 / 发放；独立动作，版本门禁 commission.settle=授权版+）
// ⚠️ 须注册在通用 {resource} 之前（坑 C）
// ---------------------------------------------------------------------
Route::group('api/v1', function () {
    Route::get('commissions/summary', 'CommissionController/summary')
        ->middleware(PermissionMiddleware::class, 'finance.view');
    Route::post('commissions/generate', 'CommissionController/generate')
        ->middleware(PermissionMiddleware::class, 'finance.edit');
    Route::post('commissions/settle', 'CommissionController/settle')
        ->middleware(PermissionMiddleware::class, 'finance.edit');
});

// ---------------------------------------------------------------------
// 数据备份（真实 mysqldump / 恢复；独立动作，不走通用 CRUD）
// ⚠️ 须注册在通用 {resource} CRUD 之前：'backups/run' 与 DELETE backups/{id}
//    否则被通用 {resource} 前缀吞掉（坑 C）
// ---------------------------------------------------------------------
Route::group('api/v1', function () {
    Route::post('backups/run', 'BackupController/run')
        ->middleware(PermissionMiddleware::class, 'system.edit');
    Route::get('backups/{id}/download', 'BackupController/download')
        ->middleware(PermissionMiddleware::class, 'system.view');
    Route::post('backups/{id}/restore', 'BackupController/restore')
        ->middleware(PermissionMiddleware::class, 'system.edit');
    Route::delete('backups/{id}', 'BackupController/remove')
        ->middleware(PermissionMiddleware::class, 'system.edit');
})->pattern(['id' => '\d+']);

// ---------------------------------------------------------------------
// 客户运营工作流（回访任务 / 投诉工单：状态流转 / 指派 / 时间线 / 到期扫描）
// 版本门禁在控制器内（EditionGate，矩阵 §六 = 授权版+）
// ⚠️ 须注册在通用 {resource} CRUD 之前（坑 C）
// ---------------------------------------------------------------------
Route::group('api/v1', function () {
    Route::get('workflow/meta', 'WorkflowController/meta');
    Route::get('workflow/overdue', 'WorkflowController/overdue')
        ->middleware(PermissionMiddleware::class, 'member.view');
    Route::get('workflow/{resource}/{id}/logs', 'WorkflowController/logs')
        ->middleware(PermissionMiddleware::class, 'member.view');
    Route::post('workflow/{resource}/{id}/transition', 'WorkflowController/transition')
        ->middleware(PermissionMiddleware::class, 'member.edit');
    Route::post('workflow/{resource}/{id}/assign', 'WorkflowController/assign')
        ->middleware(PermissionMiddleware::class, 'member.edit');
})->pattern(['resource' => 'follow-up-tasks|complaints', 'id' => '\d+']);

// ---------------------------------------------------------------------
// 营销定时调度（自动化营销：生日关怀/沉睡唤醒/到期提醒；同日幂等）
// 版本门禁在控制器内（EditionGate，矩阵 §六 = 授权版+）
// ⚠️ 须注册在通用 {resource} CRUD 之前（坑 C）
// ---------------------------------------------------------------------
Route::group('api/v1', function () {
    Route::get('marketing/scheduler/status', 'MarketingController/schedulerStatus')
        ->middleware(PermissionMiddleware::class, 'member.view');
    Route::post('marketing/scheduler/run', 'MarketingController/schedulerRun')
        ->middleware(PermissionMiddleware::class, 'member.edit');
});

// ---------------------------------------------------------------------
// 发票开具/驳回（独立动作；版本门禁 invoices=授权版+，在控制器内）
// ⚠️ 须注册在通用 {resource} CRUD 之前：否则被 POST {resource} 前缀吞掉（坑 C）
// ---------------------------------------------------------------------
Route::group('api/v1', function () {
    Route::post('invoices/{id}/issue', 'InvoiceController/issue')
        ->middleware(PermissionMiddleware::class, 'finance.edit');
    Route::post('invoices/{id}/reject', 'InvoiceController/reject')
        ->middleware(PermissionMiddleware::class, 'finance.edit');
})->pattern(['id' => '\d+']);

// ---------------------------------------------------------------------
// 财务对账（一键对账 / 确认 / 标记差异；独立动作，版本门禁 finance-reconciles=授权版+）
// ⚠️ 须注册在通用 {resource} CRUD 之前（坑 C）
// ---------------------------------------------------------------------
Route::group('api/v1', function () {
    Route::post('finance-reconciles/reconcile', 'FinanceController/reconcile')
        ->middleware(PermissionMiddleware::class, 'finance.edit');
    Route::post('finance-reconciles/{id}/confirm', 'FinanceController/confirm')
        ->middleware(PermissionMiddleware::class, 'finance.edit');
    Route::post('finance-reconciles/{id}/diff', 'FinanceController/markDiff')
        ->middleware(PermissionMiddleware::class, 'finance.edit');
})->pattern(['id' => '\d+']);

// ---------------------------------------------------------------------
// 积分商城兑换单（发货 / 取消 / 完成；独立动作，版本门禁 gift-exchanges=授权版+）
// ⚠️ 须注册在通用 {resource} CRUD 之前（坑 C）
// ---------------------------------------------------------------------
Route::group('api/v1', function () {
    Route::post('gift-exchanges/{id}/ship', 'GiftExchangeController/ship')
        ->middleware(PermissionMiddleware::class, 'member.edit');
    Route::post('gift-exchanges/{id}/complete', 'GiftExchangeController/complete')
        ->middleware(PermissionMiddleware::class, 'member.edit');
    Route::post('gift-exchanges/{id}/cancel', 'GiftExchangeController/cancel')
        ->middleware(PermissionMiddleware::class, 'member.edit');
})->pattern(['id' => '\d+']);

// ---------------------------------------------------------------------
// 电子服务协议（会员端：适用协议读取 / 签署；管理端配置走通用资源 CRUD）
// 版本门禁 service-agreements=授权版+（EditionGate），由资源元数据驱动；
// ⚠️ 须注册在通用 {resource} CRUD 之前（坑 C）
// ---------------------------------------------------------------------
Route::group('api/v1', function () {
    Route::get('agreements/active', 'AgreementController/active')
        ->middleware(PermissionMiddleware::class, 'auto:member-edit', 'service-agreements');
    Route::post('agreements/{id}/sign', 'AgreementController/sign')
        ->middleware(PermissionMiddleware::class, 'auto:member-edit', 'service-agreements');
})->pattern(['id' => '\d+']);

// ---------------------------------------------------------------------
// 批量导入导出（缺口补齐批次二；模板/导出/导入，资源白名单 members/services/technicians）
// 版本门禁 import.export=授权版+（控制器内 EditionGate）；权限 auto 按 {resource} 动态解析
// ⚠️ 须注册在通用 {resource} CRUD 之前：'import-template'/'export' 会被
//    通用 GET {resource} 前缀吞掉、'import' 会被 POST {resource} 吞掉（坑 C）
// ---------------------------------------------------------------------
Route::group('api/v1', function () {
    Route::get('import-template/{resource}', 'ImportExportController/template')
        ->middleware(PermissionMiddleware::class, 'auto:view');
    Route::get('export/{resource}', 'ImportExportController/export')
        ->middleware(PermissionMiddleware::class, 'auto:view');
    Route::post('import/{resource}', 'ImportExportController/import')
        ->middleware(PermissionMiddleware::class, 'auto:edit');
})->pattern(['resource' => '[a-z][a-z0-9\-]*']);

// ---------------------------------------------------------------------
// 技师端一键报警（缺口补齐批次三；复用 security_event 表，kind=一键报警）
// 端别守卫 auto:tech-edit（技师 token 放行）+ 控制器内 EditionGate（emergency.alerts=授权版+）
// ⚠️ 须注册在通用 {resource} CRUD 之前：POST /emergency-alerts 会被 POST {resource} 吞掉（坑 C）
// ---------------------------------------------------------------------
Route::group('api/v1', function () {
    Route::post('emergency-alerts', 'EmergencyAlertController/report')
        ->middleware(PermissionMiddleware::class, 'auto:tech-edit', 'security-events');
    // ⚠️ my 须注册在通用 GET {resource}/{id} 之前，且 id 内联正则不可用（坑 B）——
    //    通用 {id} 约束 \d+ 不吞 my，但字面量前缀习惯上仍前置更稳
    Route::get('emergency-alerts/my', 'EmergencyAlertController/my')
        ->middleware(PermissionMiddleware::class, 'auto:tech-edit', 'security-events');
});

// ---------------------------------------------------------------------
// 通用资源 CRUD（55 资源，元数据见 app/config/resources.php）
// 权限码按资源动态解析：auto:view → {perm}.view / auto:edit → {perm}.edit
//   GET    /api/v1/{resource}          列表（分页/搜索/过滤/排序）
//   GET    /api/v1/{resource}/{id}     详情
//   POST   /api/v1/{resource}          新增
//   PUT    /api/v1/{resource}/{id}     修改
//   DELETE /api/v1/{resource}/{id}     删除（软删）
// ---------------------------------------------------------------------
Route::group('api/v1', function () {
    // ⚠️ 详情/修改/删除须注册在列表之前：TP8 路由匹配非锚定，
    //    GET {resource} 会吞掉 /{id} 尾巴误入 index
    Route::get('{resource}/{id}', 'ResourceController/read')
        ->middleware(PermissionMiddleware::class, 'auto:view');
    Route::get('{resource}', 'ResourceController/index')
        ->middleware(PermissionMiddleware::class, 'auto:view');
    Route::post('{resource}', 'ResourceController/create')
        ->middleware(PermissionMiddleware::class, 'auto:edit');
    Route::put('{resource}/{id}', 'ResourceController/update')
        ->middleware(PermissionMiddleware::class, 'auto:edit');
    Route::delete('{resource}/{id}', 'ResourceController/delete')
        ->middleware(PermissionMiddleware::class, 'auto:delete');
})->pattern(['resource' => '[a-z][a-z0-9\-]*', 'id' => '\d+']);
