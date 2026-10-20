<?php
// =====================================================================
// booking 后端 · 会员域动作控制器（开卡/核销/充值/发券/积分调整）
// 带业务语义的写入：主表 + 流水表同事务联动（member_card_log/stored_log/coupon_log/point_log）
// 金额/次数/积分一律服务端核算，流水 balance 记变动后值（余额守恒可对账）
// 多端守卫：admin 全放行；会员限本人（归属校验在控制器兜底）
// =====================================================================

namespace app\controller;

use app\service\AuditService;
use app\service\EditionGate;
use app\service\MarketingSchedulerService;
use app\service\MarketingService;
use app\service\StoreService;
use think\facade\Db;
use think\Request;
use think\Response;

class MemberActionController
{
    /** 动作 → 允许端（未列出的仅 admin）：核销/充值允许会员本人发起 */
    private const ACTION_ROLES = [
        'cardRedeem'       => ['admin', 'member'],
        'storedRecharge'   => ['admin', 'member'],
        'cardOpen'         => ['admin'],
        'couponGrant'      => ['admin'],
        'pointAdjust'      => ['admin'],
        'autoMarketingRun' => ['admin'],
        'withdrawApply'    => ['admin', 'member'],
        'withdrawAudit'    => ['admin'],
    ];

    // ==================================================================
    // 会员卡：开卡（member_card + member_card_log 流水）
    // POST /api/v1/member-cards/open  {member_id,name,type,cycle,total,amount,cross_store,expires}
    // ==================================================================
    public function cardOpen(Request $request): Response
    {
        if ($deny = $this->guardRole($request, 'cardOpen')) {
            return $deny;
        }
        if ($deny = $this->guardEdition('member-cards')) {
            return $deny;
        }
        $member = $this->mustMember((int)$request->post('member_id', 0));
        if (!$member) {
            return err(404, '会员不存在');
        }
        $name = clean_input($request->post('name', ''), 50);
        $type = (string)$request->post('type', '次卡');
        if (!in_array($type, ['次卡', '时长卡'], true)) {
            return err(400, '卡类型仅支持 次卡 / 时长卡');
        }
        $total = (int)$request->post('total', 0);
        if ($total <= 0) {
            return err(400, '总次数必须大于 0');
        }
        $amount = (int)$request->post('amount', 0);
        if ($amount < 0) {
            return err(400, '购卡金额不能为负');
        }
        $expires = clean_input($request->post('expires', ''), 10);
        if ($expires !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires)) {
            return err(400, '有效期格式应为 YYYY-MM-DD');
        }

        $cardNo = next_no('member_card', 'card_no', 'MC');
        $now = date('Y-m-d H:i:s');

        Db::startTrans();
        try {
            Db::name('member_card')->insert([
                'card_no'     => $cardNo,
                'member_id'   => (int)$member['id'],
                'member'      => (string)$member['name'],
                'phone'       => (string)$member['phone'],
                'name'        => $name !== '' ? $name : ($type === '次卡' ? '次卡 ' . $total . ' 次' : '时长卡 ' . $total . ' 分钟'),
                'type'        => $type,
                'cycle'       => $this->cleanCycle((string)$request->post('cycle', 'once')),
                'total'       => $total,
                'used'        => 0,
                'amount'      => $amount,
                'cross_store' => (int)$request->post('cross_store', 0) ? 1 : 0,
                'expires'     => $expires !== '' ? $expires : null,
                'status'      => '使用中',
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
            $cardId = (int)Db::name('member_card')->getLastInsID();
            Db::name('member_card_log')->insert([
                'card_id'    => $cardId,
                'card_no'    => $cardNo,
                'action'     => '开卡',
                'times'      => $total,
                'order_no'   => '',
                'detail'     => '开卡「' . $name . '」共 ' . $total . ($type === '次卡' ? ' 次' : ' 分钟'),
                'at'         => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return err(500, '开卡失败，请重试');
        }
        (new AuditService())->log('member.card-open', $cardNo, '会员「' . $member['name'] . '」开卡 ' . $total . ' 次', (int)($request->adminId ?? 0));
        return ok(['card_no' => $cardNo, 'card_id' => $cardId, 'member_id' => (int)$member['id'], 'total' => $total, 'used' => 0, 'status' => '使用中'], '开卡成功');
    }

    // ==================================================================
    // 会员卡：核销（used+times，用完置已用完；流水记关联订单）
    // POST /api/v1/member-cards/{id}/redeem  {times?,order_no?,detail?}
    // ==================================================================
    public function cardRedeem(Request $request, int $id): Response
    {
        $card = $this->mustCard($id);
        if (!$card) {
            return err(404, '会员卡不存在');
        }
        if ($deny = $this->guardOwner($request, 'cardRedeem', (int)$card['member_id'])) {
            return $deny;
        }
        if ($deny = $this->guardEdition('member-cards')) {
            return $deny;
        }
        // 多门店跨店通兑校验：card.cross_store=0 且归属门店未开跨店 → 仅限归属门店核销
        $storeSvc = new StoreService();
        $opStore = $storeSvc->scopeId($request);
        if ($opStore > 0) {
            $ownerStore = (int)Db::name('member')->where('id', (int)$card['member_id'])->where('deleted_at', 0)->value('store_id');
            try {
                $storeSvc->assertCrossStore($ownerStore, $opStore, (int)$card['cross_store'], '会员卡「' . (string)$card['name'] . '」');
            } catch (\RuntimeException $e) {
                return err(403, $e->getMessage(), [], 403);
            }
        }
        if ((string)$card['status'] !== '使用中') {
            return err(400, '仅「使用中」的卡可核销（当前：' . $card['status'] . '）');
        }
        if ((string)$card['expires'] !== '' && (string)$card['expires'] < date('Y-m-d')) {
            return err(400, '该卡已过期（有效期至 ' . $card['expires'] . '）');
        }
        $left = (int)$card['total'] - (int)$card['used'];
        if ($left <= 0) {
            return err(400, '该卡次数已用完');
        }
        $times = (int)$request->post('times', 1);
        if ($times <= 0) {
            return err(400, '核销次数必须大于 0');
        }
        if ($times > $left) {
            return err(400, '核销次数超过剩余（剩余 ' . $left . '）');
        }
        $orderNo = clean_input($request->post('order_no', ''), 32);
        $now = date('Y-m-d H:i:s');
        $used = (int)$card['used'] + $times;
        $status = $used >= (int)$card['total'] ? '已用完' : '使用中';

        Db::startTrans();
        try {
            Db::name('member_card')->where('id', $id)->update([
                'used'       => $used,
                'status'     => $status,
                'updated_at' => $now,
            ]);
            Db::name('member_card_log')->insert([
                'card_id'    => $id,
                'card_no'    => (string)$card['card_no'],
                'action'     => '核销',
                'times'      => $times,
                'order_no'   => $orderNo,
                'detail'     => '核销 ' . $times . ((string)$card['type'] === '次卡' ? ' 次' : ' 分钟') . '，剩余 ' . ($left - $times),
                'at'         => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return err(500, '核销失败，请重试');
        }
        (new AuditService())->log('member.card-redeem', (string)$card['card_no'], '核销 ' . $times . ' 次' . ($orderNo !== '' ? '（' . $orderNo . '）' : ''), (int)($request->adminId ?? 0));
        return ok(['card_no' => (string)$card['card_no'], 'used' => $used, 'total' => (int)$card['total'], 'status' => $status], '核销成功');
    }

    // ==================================================================
    // 储值：充值（余额/累计充值联动，赠送走 recharge_plan 服务端核算）
    // POST /api/v1/stored-accounts/{id}/recharge  {plan_id?} 或 {money}
    // ==================================================================
    public function storedRecharge(Request $request, int $id): Response
    {
        $account = Db::name('stored_account')->where('id', $id)->where('deleted_at', 0)->find() ?: null;
        if (!$account) {
            return err(404, '储值账户不存在');
        }
        if ($deny = $this->guardOwner($request, 'storedRecharge', (int)$account['member_id'])) {
            return $deny;
        }
        if ($deny = $this->guardEdition('stored-accounts')) {
            return $deny;
        }
        // 金额只信服务端：按充值套餐核算（含赠送）；自定义金额仅后台代充可发起
        $money = 0;
        $gift = 0;
        $planId = (int)$request->post('plan_id', 0);
        if ($planId > 0) {
            $plan = Db::name('recharge_plan')->where('id', $planId)->where('status', 1)->where('deleted_at', 0)->find() ?: null;
            if (!$plan) {
                return err(400, '充值套餐不存在或已下架');
            }
            $money = (int)$plan['money'];
            $gift = (int)$plan['gift'];
        } else {
            if ((string)($request->tokenType ?? 'admin') !== 'admin') {
                return err(403, '会员端请选择充值套餐', [], 403);
            }
            $money = (int)$request->post('money', 0);
            if ($money <= 0) {
                return err(400, '充值金额必须大于 0');
            }
        }
        $now = date('Y-m-d H:i:s');
        // 安全红线：充值不再直接入账——先建「储值充值」待支付交易，
        // 入账仅发生在支付回调（验签+金额核对）或收款确认（PayController::settleAuxTransaction）
        $bizNo = trade_no();
        try {
            Db::name('pay_transaction')->insert([
                'order_no'     => $bizNo,
                'out_trade_no' => $bizNo,
                'channel'      => 'offline',
                'kind'         => '储值充值',
                'amount'       => $money,
                'status'       => '待支付',
                'raw'          => json_encode([
                    'type'       => 'stored',
                    'account_id' => $id,
                    'member_id'  => (int)$account['member_id'],
                    'gift'       => $gift,
                    'plan_id'    => $planId,
                ], JSON_UNESCAPED_UNICODE),
                'created_at'   => $now,
            ]);
        } catch (\Throwable $e) {
            return err(500, '充值单创建失败，请重试');
        }
        (new AuditService())->log('member.stored-recharge', (string)$account['account_no'], '创建充值单 ' . $bizNo . '（' . fen_to_yuan($money) . ' 元' . ($gift > 0 ? '，赠 ' . fen_to_yuan($gift) . ' 元' : '') . '）', (int)($request->adminId ?? 0));
        return ok([
            'out_trade_no' => $bizNo,
            'amount'       => $money,
            'gift'         => $gift,
            'account_no'   => (string)$account['account_no'],
            'status'       => '待支付',
        ], '充值单已创建，请完成支付');
    }

    // ==================================================================
    // 优惠券：发放（券码实例 + 模板 sent 统计 + 流水同事务）
    // POST /api/v1/coupons/{id}/grant  {member_id,source?,count?}
    // ==================================================================
    public function couponGrant(Request $request, int $id): Response
    {
        if ($deny = $this->guardRole($request, 'couponGrant')) {
            return $deny;
        }
        if ($deny = $this->guardEdition('coupons')) {
            return $deny;
        }
        $coupon = Db::name('coupon')->where('id', $id)->where('deleted_at', 0)->find() ?: null;
        if (!$coupon) {
            return err(404, '优惠券不存在');
        }
        if ((string)$coupon['status'] !== '发放中') {
            return err(400, '仅「发放中」的券可发放（当前：' . $coupon['status'] . '）');
        }
        if ((string)$coupon['expires'] !== '' && (string)$coupon['expires'] < date('Y-m-d')) {
            return err(400, '该券已过有效期（' . $coupon['expires'] . '）');
        }
        $left = (int)$coupon['total'] - (int)$coupon['sent'];
        if ($left <= 0) {
            return err(400, '该券发放量已达上限');
        }
        $count = (int)$request->post('count', 1);
        if ($count <= 0) {
            return err(400, '发放数量必须大于 0');
        }
        if ($count > $left) {
            return err(400, '发放数量超过剩余量（剩余 ' . $left . '）');
        }
        $member = $this->mustMember((int)$request->post('member_id', 0));
        if (!$member) {
            return err(404, '会员不存在');
        }
        $source = clean_input($request->post('source', '后台发放'), 20) ?: '后台发放';
        $now = date('Y-m-d H:i:s');
        $codes = [];

        Db::startTrans();
        try {
            for ($i = 0; $i < $count; $i++) {
                $code = next_coupon_code();
                $codes[] = $code;
                Db::name('coupon_instance')->insert([
                    'code'        => $code,
                    'coupon_id'   => (int)$coupon['id'],
                    'coupon_name' => (string)$coupon['name'],
                    'member_id'   => (int)$member['id'],
                    'member'      => (string)$member['name'],
                    'phone'       => (string)$member['phone'],
                    'source'      => $source,
                    'at'          => $now,
                    'status'      => '未使用',
                    'order_no'    => '',
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);
            }
            Db::name('coupon')->where('id', $id)->update([
                'sent'       => (int)$coupon['sent'] + $count,
                'updated_at' => $now,
            ]);
            Db::name('coupon_log')->insert([
                'coupon_id'  => (int)$coupon['id'],
                'coupon'     => (string)$coupon['name'],
                'action'     => '发放',
                'count'      => $count,
                'detail'     => '发放给「' . $member['name'] . '」' . $count . ' 张（' . $source . '）',
                'at'         => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return err(500, '发券失败，请重试');
        }
        (new AuditService())->log('member.coupon-grant', (string)$coupon['code'], '发放「' . $coupon['name'] . '」' . $count . ' 张给 ' . $member['name'], (int)($request->adminId ?? 0));
        return ok(['coupon_id' => (int)$coupon['id'], 'codes' => $codes, 'sent' => (int)$coupon['sent'] + $count, 'total' => (int)$coupon['total']], '发放成功');
    }

    // ==================================================================
    // 积分：调整（获取/抵扣/过期/兑换，余额由流水末条推算，扣减不为负）
    // POST /api/v1/points/adjust  {member_id,action,points,order_no?,detail?}
    // ==================================================================
    public function pointAdjust(Request $request): Response
    {
        if ($deny = $this->guardRole($request, 'pointAdjust')) {
            return $deny;
        }
        if ($deny = $this->guardEdition('point-logs')) {
            return $deny;
        }
        $member = $this->mustMember((int)$request->post('member_id', 0));
        if (!$member) {
            return err(404, '会员不存在');
        }
        $action = clean_input($request->post('action', ''), 20);
        if (!in_array($action, ['获取', '抵扣', '过期', '兑换'], true)) {
            return err(400, '积分动作仅支持 获取 / 抵扣 / 过期 / 兑换');
        }
        $points = (int)$request->post('points', 0);
        if ($points === 0) {
            return err(400, '积分变动不能为 0');
        }
        // 口径：正入负出。获取传正数；抵扣/过期/兑换按负数记（前端传正数时服务端取负）
        if ($action !== '获取' && $points > 0) {
            $points = -$points;
        }
        if ($action === '获取' && $points < 0) {
            $points = -$points;
        }
        // 余额 = 上一条流水 balance + 本次变动（不依赖 max() 聚合，坑 A）
        $lastBalance = (int)Db::name('point_log')->where('member_id', (int)$member['id'])
            ->order('id', 'desc')->value('balance');
        $balance = $lastBalance + $points;
        if ($balance < 0) {
            return err(400, '积分不足（当前 ' . $lastBalance . '，本次 ' . $points . '）');
        }
        $orderNo = clean_input($request->post('order_no', ''), 32);
        $detail = clean_input($request->post('detail', ''), 255);
        $now = date('Y-m-d H:i:s');

        Db::startTrans();
        try {
            Db::name('point_log')->insert([
                'member_id'   => (int)$member['id'],
                'member'      => (string)$member['name'],
                'phone'       => (string)$member['phone'],
                'action'      => $action,
                'points'      => $points,
                'balance'     => $balance,
                'order_no'    => $orderNo,
                'detail'      => $detail !== '' ? $detail : ($action . '积分 ' . $points),
                'at'          => $now,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return err(500, '积分调整失败，请重试');
        }
        (new AuditService())->log('member.point-adjust', (string)$member['phone'], $action . '积分 ' . $points . '，余 ' . $balance, (int)($request->adminId ?? 0));
        return ok(['member_id' => (int)$member['id'], 'action' => $action, 'points' => $points, 'balance' => $balance], '积分调整成功');
    }

    // ==================================================================
    // 自动化营销：立即执行规则（生日关怀 / 沉睡唤醒）
    // POST /api/v1/auto-marketing-rules/{id}/run
    // 说明：微信消息渠道未对接（无资质），当前落地方式为积分触达 +
    //       触达人数记录；action 含「N 积分」的规则对圈中会员真实发放积分。
    // ==================================================================
    public function autoMarketingRun(Request $request, int $id): Response
    {
        if ($deny = $this->guardRole($request, 'autoMarketingRun')) {
            return $deny;
        }
        if ($deny = $this->guardEdition('auto-marketing-rules')) {
            return $deny;
        }
        $rule = Db::name('auto_marketing_rule')->where('id', $id)->find();
        if (!$rule) {
            return err(404, '规则不存在');
        }
        if ((int)$rule['status'] !== 1) {
            return err(400, '规则未启用，无法执行');
        }

        // 圈人 + 动作执行统一走 MarketingSchedulerService（与定时调度共用，杜绝双份实现）
        try {
            $res = (new MarketingSchedulerService())->runRule($rule);
        } catch (\RuntimeException $e) {
            return err(400, $e->getMessage());
        }

        $scene = $res['scene'];
        $points = $res['points'];
        $hit = $res['hit'];
        $granted = $res['granted'];
        (new AuditService())->log('marketing.rule-run', (string)$rule['name'], $scene . '：触达 ' . $hit . ' 人' . ($points > 0 ? '，发积分 ' . $points . '×' . $granted . ' 人' : ''), (int)($request->adminId ?? 0));
        $msg = $points > 0
            ? '触达 ' . $hit . ' 人，发放积分 ' . $points . ' × ' . $granted . ' 人'
            : '触达 ' . $hit . ' 人（动作「' . (string)$rule['action'] . '」为券/消息类，微信渠道未对接，仅记录触达）';
        return ok(['rule_id' => (int)$id, 'hit' => $hit, 'points' => $points, 'granted' => $granted], $msg);
    }

    // ==================================================================
    // 分销提现
    // ==================================================================

    /**
     * 推广员申请提现
     * POST /api/v1/distributors/{id}/withdraw  {amount(分), channel}
     */
    public function withdrawApply(Request $request, int $id): Response
    {
        if ($deny = $this->guardRole($request, 'withdrawApply')) {
            return $deny;
        }
        if ($deny = $this->guardEdition('distributors')) {
            return $deny;
        }
        $dist = Db::name('distributor')->where('id', $id)->where('deleted_at', 0)->find();
        if (!$dist) {
            return err(404, '推广员不存在');
        }
        // 会员端只能为自己申请
        if ((string)($request->tokenType ?? 'admin') === 'member'
            && (int)$dist['member_id'] !== (int)($request->memberId ?? 0)) {
            return err(403, '无权为他人申请提现', [], 403);
        }
        try {
            $row = (new MarketingService())->applyWithdraw(
                $id,
                (int)$request->post('amount', 0),
                clean_input($request->post('channel', '微信'), 20)
            );
            return ok($row, '提现申请已提交，等待审核');
        } catch (\RuntimeException $e) {
            return err(400, $e->getMessage());
        }
    }

    /**
     * 提现审核（打款/驳回）
     * PUT /api/v1/distribution-withdraws/{id}/audit  {pass:1|0, remark}
     */
    public function withdrawAudit(Request $request, int $id): Response
    {
        if ($deny = $this->guardRole($request, 'withdrawAudit')) {
            return $deny;
        }
        if ($deny = $this->guardEdition('distribution-orders')) {
            return $deny;
        }
        try {
            $row = (new MarketingService())->auditWithdraw(
                $id,
                (bool)$request->put('pass', false),
                clean_input($request->put('remark', ''), 255)
            );
            return ok($row, '审核完成');
        } catch (\RuntimeException $e) {
            return err(400, $e->getMessage());
        }
    }

    // ==================================================================
    // 内部工具
    // ==================================================================

    /**
     * 版本功能门禁（动作路由同样受「读放行、写拦截」约束）
     * 背景：会员域动作（开卡/核销/充值/发券/积分/营销/提现）此前只走 RBAC，
     *       绕过了 ResourceController 的 EditionGate —— 开源版可经动作接口写入付费能力。
     *       本守卫补齐：动作路由按对应资源做版本门禁，与资源 CRUD 口径一致。
     * @param string $resource 资源键（resources.php / EditionGate 同名）
     * @return Response|null null=放行
     */
    private function guardEdition(string $resource): ?Response
    {
        $gate = new EditionGate();
        if ($gate->assertResource($resource, 'write')) {
            return null;
        }
        $deny = $gate->lastDeny();
        return err(403, $deny['tip'] ?: '当前版本不支持该功能', [
            'upgrade' => [
                'edition'  => $deny['edition'],
                'need'     => $deny['need'],
                'needName' => $deny['needName'],
                'subject'  => $resource,
                'tip'      => $deny['tip'],
            ],
        ]);
    }

    /**
     * 端角色守卫（仅 admin 的动作）
     * @return Response|null
     */
    private function guardRole(Request $request, string $action): ?Response
    {
        $type = (string)($request->tokenType ?? 'admin');
        $roles = self::ACTION_ROLES[$action] ?? ['admin'];
        if (!in_array($type, $roles, true)) {
            return err(403, '该操作不允许当前端执行', [], 403);
        }
        return null;
    }

    /**
     * 归属守卫（会员限本人资源）
     * @return Response|null
     */
    private function guardOwner(Request $request, string $action, int $ownerMemberId): ?Response
    {
        if ($deny = $this->guardRole($request, $action)) {
            return $deny;
        }
        if ((string)($request->tokenType ?? 'admin') === 'member'
            && $ownerMemberId !== (int)($request->memberId ?? 0)) {
            return err(403, '无权操作他人账户', [], 403);
        }
        return null;
    }

    /**
     * 按 ID 取会员
     */
    private function mustMember(int $memberId): ?array
    {
        if ($memberId <= 0) {
            return null;
        }
        return Db::name('member')->where('id', $memberId)->where('deleted_at', 0)->find() ?: null;
    }

    /**
     * 按 ID 取会员卡
     */
    private function mustCard(int $id): ?array
    {
        return Db::name('member_card')->where('id', $id)->where('deleted_at', 0)->find() ?: null;
    }

    /**
     * 卡周期白名单
     */
    private function cleanCycle(string $cycle): string
    {
        return in_array($cycle, ['once', 'monthly', 'quarterly', 'yearly'], true) ? $cycle : 'once';
    }
}
