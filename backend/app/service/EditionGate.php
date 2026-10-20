<?php
// =====================================================================
// booking 后端 · 版本功能门禁（四杠杆之一：功能开关）
// 版本：开源版(open) / 授权版(pro) / 源码版(src)，三档递增 open < pro < src
//
// 设计口径（对齐《功能矩阵》〇章 + 《开发计划》§2.6 付费转化设计）：
//   1. 读放行、写拦截：收费功能**入口可见**（客户看得到价值，才有升级动机），
//      但新增/修改/删除一律按版本拦截——禁止「前端藏了菜单、后端照样放行」的假门禁。
//   2. 未列出的资源 = 三版本皆可写（基础闭环，开源版必须能跑通业务）。
//   3. 拦截不硬断已有业务：只挡「新增配置」，已发券/已生成订单照常履约。
//   4. 加购项（分销）与版本无关，由 setting addon.* 开关独立解锁。
//
// 版本判定唯一事实来源 = LicenseService::effectiveEdition()
//   （到期/断网超宽限自动回落 open，激活即恢复；不读静态快照）
// =====================================================================

namespace app\service;

class EditionGate
{
    /** 版本档位序（比较大小用） */
    const RANK = ['open' => 1, 'pro' => 2, 'src' => 3];

    /**
     * 资源 → 最低可写版本（未列出 = open 可写）
     * 口径来源：《功能矩阵》对应章节，逐项对照，禁止拍脑袋加行
     */
    const RESOURCE_MIN_EDITION = [
        // —— 矩阵五章 会员与营销（增长工具 = 付费分界线）
        'member-cards'         => 'pro', // 会员卡（次卡/时长卡）
        'member-card-logs'     => 'pro',
        'stored-accounts'      => 'pro', // 储值卡与等级折扣
        'recharge-plans'       => 'pro',
        'stored-logs'          => 'pro',
        'coupons'              => 'pro', // 优惠券
        'coupon-instances'     => 'pro',
        'coupon-logs'          => 'pro',
        'marketing-activities' => 'pro', // 营销活动（拼团/秒杀/限时折扣）
        'referral-rules'       => 'pro', // 推荐有礼
        'referral-logs'        => 'pro',
        'point-rules'          => 'pro', // 积分体系
        'point-logs'           => 'pro',
        'gifts'                => 'pro', // 积分商城
        'gift-exchanges'       => 'pro', // 积分商城兑换单（履约/发货）
        'review-rewards'       => 'pro', // 评价有礼
        'timesheet-cards'      => 'pro', // 次卡与疗程卡
        // —— 矩阵一章 会员运营
        'member-tags'          => 'pro', // 会员标签与 RFM 分层
        'member-tag-rels'      => 'pro',
        // —— 矩阵六章 客户运营（客户运营闭环 = 付费分界）
        'follow-up-tasks'      => 'pro', // 回访任务（服务后回访/满意度收集）
        'complaints'           => 'pro', // 投诉工单（受理/处理/归档）
        'auto-marketing-rules' => 'pro', // 自动化营销（到期提醒/沉睡唤醒/生日营销）
        // —— 矩阵七章 经营与财务（商业件）
        'commission-rules'      => 'pro', // 员工提成与业绩结算
        'commission-settlements' => 'pro',
        'finance-reconciles'    => 'pro', // 财务对账
        'invoices'              => 'pro', // 发票
        'refunds'               => 'pro', // 退款单
        // —— 矩阵三章 技师（增值能力）
        'price-rules'          => 'pro', // 时段差价与节假日价目
        'dispatch-rules'       => 'pro', // 智能派单建议
        'attendances'          => 'pro', // 考勤打卡与工时统计
        // —— 矩阵四章 订单增值
        'booking-queues'       => 'pro', // 候补排队与放号
        'deposits'             => 'pro', // 定金尾款
        // —— 矩阵附四 补齐型缺口（2026-10-12 批准批次）
        'service-agreements'   => 'pro', // 电子服务协议（附四 #6：上门免责刚需）
        'agreement-signs'      => 'pro', // 协议签署流水（留痕查看）
    ];

    /** 加购项：资源 → setting 开关键（不看版本，看是否加购） */
    const RESOURCE_ADDON = [
        'distributors'           => 'addon.distribution',
        'distribution-orders'    => 'addon.distribution',
        'distribution-withdraws' => 'addon.distribution',
    ];

    /** 动作级功能 → 最低版本（非资源类的单点能力） */
    const FEATURE_MIN_EDITION = [
        'report.export'      => 'pro', // 经营报表导出
        'commission.settle'  => 'pro', // 提成结算生成工资单
        'dispatch.auto'      => 'pro', // 自动派单引擎
        'marketing.activity' => 'pro', // 营销活动参与下单计价
        'store.multi'        => 'pro', // 多门店（另受 stores_max 限额）
        'message.wechat'     => 'pro', // 微信消息推送（另受 wechat_messages_max 限额）
        'pay.online'         => 'pro', // 在线支付渠道配置（微信/支付宝商户参数，付费能力）
        'brand.custom'       => 'pro', // 自定义品牌（品牌名 / 去除 Powered by 版权标识）
        'import.export'      => 'pro', // 批量导入导出（会员/服务/技师，缺口补齐批次二）
        'emergency.alerts'   => 'pro', // 技师端一键报警（缺口补齐批次三）
        'travel.fee'         => 'pro', // 路途费/打车费（按距离分段加收，矩阵附五）
    ];

    /** 版本中文名（升级引导文案用） */
    const EDITION_NAME = ['open' => '开源版', 'pro' => '授权版', 'src' => '源码版'];

    /**
     * 拦截结果（供控制器直接转响应）
     * @var array{edition:string, need:string, needName:string, feature:string, tip:string}
     */
    private array $lastDeny = [];

    /**
     * 资源写操作门禁
     * @param string $resource 资源键（resources.php）
     * @param string $action   read|write（read 一律放行，保证入口可见）
     * @return bool true=放行
     */
    public function assertResource(string $resource, string $action = 'write'): bool
    {
        if ($action !== 'write') {
            return true; // 读放行：收费功能入口可见，前端带「授权版功能」标签与升级入口
        }
        // 加购项优先判定（分销：三版本默认不含，加购解锁）
        if (isset(self::RESOURCE_ADDON[$resource])) {
            $key = self::RESOURCE_ADDON[$resource];
            if (!$this->addonEnabled($key)) {
                $this->deny($resource, 'addon', $key);
                return false;
            }
            return true;
        }
        $need = self::RESOURCE_MIN_EDITION[$resource] ?? 'open';
        return $this->pass($need, $resource);
    }

    /**
     * 动作级功能门禁
     * @param string $feature FEATURE_MIN_EDITION 键
     * @return bool true=放行
     */
    public function assertFeature(string $feature): bool
    {
        $need = self::FEATURE_MIN_EDITION[$feature] ?? 'open';
        return $this->pass($need, $feature);
    }

    /**
     * 当前版本能力清单（前端升级引导/菜单标签用）
     * @return array {edition, editionName, resources:{资源:bool}, features:{功能:bool}, addons:{开关:bool}}
     */
    public function capabilities(): array
    {
        $edition = (new LicenseService())->effectiveEdition();
        $rank = self::RANK[$edition] ?? 1;
        $resources = [];
        foreach (self::RESOURCE_MIN_EDITION as $res => $need) {
            $resources[$res] = $rank >= (self::RANK[$need] ?? 1);
        }
        foreach (self::RESOURCE_ADDON as $res => $key) {
            $resources[$res] = $this->addonEnabled($key);
        }
        $features = [];
        foreach (self::FEATURE_MIN_EDITION as $feat => $need) {
            $features[$feat] = $rank >= (self::RANK[$need] ?? 1);
        }
        return [
            'edition'     => $edition,
            'editionName' => self::EDITION_NAME[$edition] ?? '开源版',
            'resources'   => $resources,
            'features'    => $features,
            'addons'      => [
                'distribution' => $this->addonEnabled('addon.distribution'),
            ],
        ];
    }

    /**
     * 取最近一次拦截详情（控制器转响应文案用）
     * @return array {edition, need, needName, feature, tip}
     */
    public function lastDeny(): array
    {
        return $this->lastDeny;
    }

    /**
     * 版本比较放行
     * @param string $need    最低版本
     * @param string $subject 被校验对象（资源/功能键，进文案）
     * @return bool
     */
    private function pass(string $need, string $subject): bool
    {
        $edition = (new LicenseService())->effectiveEdition();
        $cur = self::RANK[$edition] ?? 1;
        $min = self::RANK[$need] ?? 1;
        if ($cur >= $min) {
            return true;
        }
        $this->deny($subject, $need, '');
        return false;
    }

    /**
     * 记录拦截详情
     * @param string $subject
     * @param string $need
     * @param string $addonKey
     * @return void
     */
    private function deny(string $subject, string $need, string $addonKey = ''): void
    {
        $edition = (new LicenseService())->effectiveEdition();
        $needName = $addonKey !== '' ? '加购模块' : (self::EDITION_NAME[$need] ?? $need);
        $this->lastDeny = [
            'edition'  => $edition,
            'need'     => $need,
            'needName' => $needName,
            'feature'  => $subject,
            'subject'  => $subject, // 与 feature 同值：资源类门禁统一以 subject 命名（前端/测试消费）
            'tip'      => $addonKey !== ''
                ? '「' . $subject . '」为加购模块，需单独购买后开启'
                : '「' . $subject . '」是' . $needName . '功能，升级后即可使用',
        ];
    }

    /**
     * 加购开关
     * @param string $key setting 键
     * @return bool
     */
    private function addonEnabled(string $key): bool
    {
        try {
            $value = \think\facade\Db::name('setting')->where('key', $key)->value('value');
            return $value !== null && (string)$value !== '0' && (string)$value !== '';
        } catch (\Throwable $e) {
            return false;
        }
    }
}
