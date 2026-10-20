<?php
// =====================================================================
// booking 后端 · 支付控制器（统一下单 / 回调验签 / 线下确认）
// 安全要点：
//   1. 回调 HMAC-SHA256 验签（密钥仅存 .env，不入库不出参）
//   2. uk_out_trade_no 幂等 —— 重复回调直接返回成功，不重复入账
//   3. 金额服务端核对：回调金额 ≠ 订单应付金额则拒绝并留痕
//   4. 状态机流转统一走 OrderService::assertTransition（待支付→待派单/待接单，按是否指定技师分流）
// =====================================================================

namespace app\controller;

use app\service\AuditService;
use app\service\DepositService;
use app\service\MarketingService;
use app\service\OrderService;
use app\service\PayService;
use think\facade\Db;
use think\Request;
use think\Response;

class PayController
{
    /** @var OrderService */
    private $orders;

    public function __construct()
    {
        $this->orders = new OrderService();
    }

    /**
     * 统一下单（返回支付参数；金额以服务端订单为准，前端不可传金额）
     * POST /api/v1/pay/unified-order  {order_no, channel}
     * channel: wechat | alipay | offline
     */
    public function unifiedOrder(Request $request): Response
    {
        $orderNo = clean_input($request->post('order_no', ''), 32);
        $channel = clean_input($request->post('channel', 'wechat'), 20);
        if (!in_array($channel, ['wechat', 'alipay', 'offline'], true)) {
            return err(400, '支付渠道无效');
        }

        $booking = Db::name('booking')->where('order_no', $orderNo)->where('deleted_at', 0)->find();
        if (!$booking) {
            return err(404, '订单不存在');
        }

        // 归属校验：会员仅可支付本人订单（后台代客预约单 member_id=0，由管理员端收款）；技师端不参与支付
        $type = (string)($request->tokenType ?? 'admin');
        if ($type === 'member' && (int)$booking['member_id'] !== (int)($request->memberId ?? 0)) {
            return err(403, '无权支付他人订单', [], 403);
        }
        if ($type === 'tech') {
            return err(403, '该操作不允许当前端执行', [], 403);
        }

        // 定金尾款：启用时按「定金/尾款」分阶段收款（开关关闭时 payable=actual，全额口径不变）
        $pay = (new DepositService())->payableFor($orderNo, (int)$booking['actual']);
        $payKind = (string)$pay['kind'];
        $payAmount = (int)$pay['amount'];
        $isInstallment = in_array($payKind, [DepositService::KIND_DEPOSIT, DepositService::KIND_BALANCE], true);
        // 定金阶段允许状态已推进到待派单/待接单（尾款在服务前后均可收）；全额口径仍限「待支付」
        if ((string)$booking['status'] !== '待支付' && !$isInstallment) {
            return err(400, '当前订单状态不可支付');
        }
        if ($payAmount <= 0) {
            return err(400, '订单已结清，无需支付');
        }

        // 已有未支付交易则复用 out_trade_no（幂等下单；按 kind 区分定金/尾款）
        $outTradeNo = (string)Db::name('pay_transaction')
            ->where('order_no', $orderNo)->where('kind', $payKind)->where('status', '待支付')->value('out_trade_no');
        if ($outTradeNo === '') {
            $outTradeNo = trade_no();
            Db::name('pay_transaction')->insert([
                'order_no'     => $orderNo,
                'out_trade_no' => $outTradeNo,
                'channel'      => $channel,
                'kind'         => $payKind,
                'amount'       => $payAmount,
                'status'       => '待支付',
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
        }

        // 支付参数：真实渠道（已配置商户凭证）走驱动下单；未配置回落沙箱（模拟收银台）
        $driver = (new PayService())->resolve($channel);
        $txRow = [
            'out_trade_no' => $outTradeNo,
            'amount'       => $payAmount,
            'order_no'     => $orderNo,
            'kind'         => $payKind,
            'channel'      => $channel,
            'subject'      => ($isInstallment ? $payKind : '预约服务') . ' ' . (string)$booking['service'],
        ];
        try {
            $payParams = $driver->create($txRow, (array)$booking);
        } catch (\Throwable $e) {
            return err(500, '下单失败：' . $e->getMessage());
        }
        $payParams['kind'] = $payKind;
        return ok($payParams, '下单成功');
    }

    /**
     * 回调渠道识别：微信 V3（有 wechatpay-signature 头）/ 支付宝（sign_type=RSA2 或 trade_status）/
     * 其余一律按沙箱 HMAC 口径（保持历史行为）
     * @param Request $request
     * @return string wechat|alipay|sandbox
     */
    private function detectNotifyChannel(Request $request): string
    {
        $headers = array_change_key_case((array)$request->header(), CASE_LOWER);
        if (!empty($headers['wechatpay-signature'])) {
            return 'wechat';
        }
        $payload = (array)$request->post();
        if (isset($payload['sign_type']) && strtoupper((string)$payload['sign_type']) === 'RSA2') {
            return 'alipay';
        }
        if (isset($payload['trade_status']) && isset($payload['out_trade_no']) && isset($payload['sign'])) {
            return 'alipay';
        }
        return 'sandbox';
    }

    /**
     * 支付回调（第三方服务器 → 本接口）
     * POST /api/v1/pay/notify
     * body: out_trade_no, amount, paid_at, nonce, sign
     * sign = hmac_sha256(out_trade_no&amount&paid_at&nonce, PAY_NOTIFY_KEY)
     * 响应：字符串 "success" 表示处理成功（失败请返回非 success 供第三方重试）
     */
    public function notify(Request $request): Response
    {
        // ---- 0. 渠道识别 + 验签（微信 V3 平台证书 / 支付宝 RSA2 / 沙箱 HMAC）----
        // 沙箱口径与历史实现逐字节一致（既有 400+ 断言依赖）；真实渠道走各自驱动验签
        $channel = $this->detectNotifyChannel($request);
        $payload = (array)$request->post();
        $res = (new PayService())->verify($channel, $payload, (array)$request->header(), (string)$request->getContent());
        if (!$res['ok']) {
            $code = (int)($res['code'] ?: 400);
            (new AuditService())->log('pay.notify', (string)($payload['out_trade_no'] ?? ''), '回调被拒：' . $res['reason'], 0);
            return err($code, $res['reason'], null, $code);
        }
        $outTradeNo = (string)$res['out_trade_no'];
        $amount = (int)$res['amount'];
        $paidAt = (string)$res['paid_at'];

        // ---- 2. 幂等：已入账的交易重复回调直接返回成功 ----
        $tx = Db::name('pay_transaction')->where('out_trade_no', $outTradeNo)->find();
        if (!$tx) {
            return err(404, '交易不存在', null, 404);
        }
        if ((string)$tx['status'] === '已支付') {
            return response('success', 200);
        }

        $paidAt = $paidAt !== '' ? $paidAt : date('Y-m-d H:i:s');
        // ---- 3. 业务分流：定金/尾款（分期收款） / 服务费订单 / 储值充值·购卡等辅助交易 ----
        $kind = (string)$tx['kind'];

        // ---- 3.0 定金 / 尾款回调：金额核对（对交易单）→ 结算 → 状态推进 ----
        if ($kind === DepositService::KIND_DEPOSIT || $kind === DepositService::KIND_BALANCE) {
            if ($amount !== (int)$tx['amount']) {
                (new AuditService())->log('pay.notify', $outTradeNo, '回调被拒：金额不符（回调 ' . $amount . ' / 应付 ' . (int)$tx['amount'] . '）', 0);
                return err(400, '金额不符', null, 400);
            }
            $booking = Db::name('booking')->where('order_no', (string)$tx['order_no'])->where('deleted_at', 0)->find();
            if (!$booking) {
                return err(404, '订单不存在', null, 404);
            }
            try {
                $this->settleInstallment((string)$tx['order_no'], $kind, (int)$tx['amount'], (string)$tx['channel'], $paidAt);
            } catch (\Throwable $e) {
                (new AuditService())->log('pay.notify', $outTradeNo, $kind . '入账失败：' . $e->getMessage(), 0);
                return err(500, '入账失败，请重试', null, 500);
            }
            (new AuditService())->log('pay.notify', $outTradeNo, $kind . '入账 ' . $amount . ' 分', 0);
            return response('success', 200);
        }

        if ($kind !== '服务费') {
            // 金额与交易单核对（防篡改回调金额）
            if ($amount !== (int)$tx['amount']) {
                (new AuditService())->log('pay.notify', $outTradeNo, '回调被拒：金额不符（回调 ' . $amount . ' / 应付 ' . (int)$tx['amount'] . '）', 0);
                return err(400, '金额不符', null, 400);
            }
            try {
                $this->settleAuxTransaction($tx, (string)$tx['channel'], $paidAt);
            } catch (\Throwable $e) {
                return err(500, '入账失败，请重试', null, 500);
            }
            (new AuditService())->log('pay.notify', $outTradeNo, $kind . '入账 ' . $amount . ' 分', 0);
            return response('success', 200);
        }

        $booking = Db::name('booking')->where('order_no', (string)$tx['order_no'])->where('deleted_at', 0)->find();
        if (!$booking) {
            return err(404, '订单不存在', null, 404);
        }

        // ---- 3.1 金额服务端核对（防篡改回调金额）----
        if ($amount !== (int)$booking['actual']) {
            (new AuditService())->log('pay.notify', $outTradeNo, '回调被拒：金额不符（回调 ' . $amount . ' / 应付 ' . (int)$booking['actual'] . '）', 0);
            return err(400, '金额不符', null, 400);
        }

        // ---- 4. 状态机流转 + 记账（事务保证一致）----
        // 支付后次态：已指定技师 → 待接单（跳过待派单）；待分配 → 待派单
        $nextStatus = (int)$booking['technician_id'] > 0 ? '待接单' : '待派单';
        try {
            $this->orders->assertTransition((string)$booking['status'], $nextStatus);
        } catch (\RuntimeException $e) {
            return err(400, $e->getMessage(), null, 400);
        }

        Db::startTrans();
        try {
            Db::name('pay_transaction')->where('id', $tx['id'])->update([
                'status'    => '已支付',
                'paid_at'   => $paidAt !== '' ? $paidAt : date('Y-m-d H:i:s'),
                'raw'       => json_encode((array)$request->post(), JSON_UNESCAPED_UNICODE),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            Db::name('booking')->where('order_no', (string)$tx['order_no'])->update([
                'status'     => $nextStatus,
                'paid'       => (int)$booking['actual'],
                'pay_channel' => (string)$tx['channel'],
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return err(500, '入账失败，请重试', null, 500);
        }

        // 券核销 + 拼团开团/参团（支付成功才核销/成团）
        try {
            $booking = Db::name('booking')->where('order_no', (string)$tx['order_no'])->find();
            if ($booking) {
                $couponCode = (string)$booking['coupon_code'];
                if ($couponCode !== '') {
                    (new MarketingService())->consumeCoupon($couponCode, (string)$tx['order_no']);
                }
                $activityId = (int)$booking['activity_id'];
                if ($activityId > 0) {
                    $activity = Db::name('marketing_activity')->where('id', $activityId)->find();
                    if ($activity && (string)$activity['kind'] === '拼团') {
                        $memberId = (int)$booking['member_id'];
                        $memberName = (string)$booking['customer'];
                        if ($memberId > 0) {
                            (new MarketingService())->openGroup($activityId, $memberId, $memberName, (string)$tx['order_no']);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            (new AuditService())->log('pay.notify', (string)$tx['order_no'], '营销联动失败：' . $e->getMessage(), 0);
        }

        (new AuditService())->log('pay.notify', (string)$tx['order_no'], '支付成功 ' . $amount . ' 分', 0);
        return response('success', 200);
    }

    /**
     * 储值充值/购卡交易入账（notify 渠道回调与 confirm 线下/沙箱确认共用）
     * 入账口径：仅凭「已验签/已确认」的待支付交易入账，杜绝无支付凭据到账。
     * 幂等：原子占用（where status=待支付）保证并发/重放只入账一次。
     * raw 结构：储值充值 {type:stored, account_id, member_id, gift}；购卡 {type:card, member_id, card:{...}}
     */
    private function settleAuxTransaction(array $tx, string $channel, string $paidAt): void
    {
        $raw = json_decode((string)$tx['raw'], true) ?: [];
        $kind = (string)$tx['kind'];
        $now = date('Y-m-d H:i:s');
        Db::startTrans();
        try {
            $hit = Db::name('pay_transaction')
                ->where('id', (int)$tx['id'])->where('status', '待支付')
                ->update(['status' => '已支付', 'channel' => $channel, 'paid_at' => $paidAt, 'updated_at' => $now]);
            if ((int)$hit === 0) {
                throw new \RuntimeException('交易已被处理');
            }
            $money = (int)$tx['amount'];
            if ($kind === '储值充值') {
                $account = Db::name('stored_account')->where('id', (int)($raw['account_id'] ?? 0))->where('deleted_at', 0)->find();
                if (!$account) {
                    throw new \RuntimeException('储值账户不存在');
                }
                $gift = (int)($raw['gift'] ?? 0);
                $balance = (int)$account['balance'] + $money + $gift;
                Db::name('stored_account')->where('id', (int)$account['id'])->update([
                    'balance'        => $balance,
                    'total_recharge' => (int)$account['total_recharge'] + $money + $gift,
                    'updated_at'     => $now,
                ]);
                Db::name('stored_log')->insert([
                    'account_id' => (int)$account['id'],
                    'account_no' => (string)$account['account_no'],
                    'action'     => '充值',
                    'amount'     => $money,
                    'balance'    => (int)$account['balance'] + $money,
                    'order_no'   => (string)$tx['out_trade_no'],
                    'detail'     => '支付充值 ' . fen_to_yuan($money) . ' 元' . ($gift > 0 ? '（另赠 ' . fen_to_yuan($gift) . ' 元）' : ''),
                    'at'         => $now, 'created_at' => $now, 'updated_at' => $now,
                ]);
                if ($gift > 0) {
                    Db::name('stored_log')->insert([
                        'account_id' => (int)$account['id'],
                        'account_no' => (string)$account['account_no'],
                        'action'     => '赠送',
                        'amount'     => $gift,
                        'balance'    => $balance,
                        'order_no'   => (string)$tx['out_trade_no'],
                        'detail'     => '充值赠送 ' . fen_to_yuan($gift) . ' 元',
                        'at'         => $now, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            } elseif ($kind === '购卡') {
                $card = is_array($raw['card'] ?? null) ? $raw['card'] : [];
                if (!$card) {
                    throw new \RuntimeException('购卡参数缺失');
                }
                $member = Db::name('member')->where('id', (int)($raw['member_id'] ?? 0))->where('deleted_at', 0)->find();
                if (!$member) {
                    throw new \RuntimeException('会员不存在');
                }
                $cardNo = next_no('member_card', 'card_no', 'MC');
                Db::name('member_card')->insert([
                    'card_no'     => $cardNo,
                    'member_id'   => (int)$member['id'],
                    'member'      => (string)$member['name'],
                    'phone'       => (string)$member['phone'],
                    'name'        => (string)($card['name'] ?? ''),
                    'type'        => (string)($card['type'] ?? '次卡'),
                    'cycle'       => (string)($card['cycle'] ?? 'once'),
                    'total'       => (int)($card['total'] ?? 0),
                    'used'        => 0,
                    'amount'      => $money,
                    'cross_store' => (int)($card['cross_store'] ?? 0),
                    'expires'     => ($card['expires'] ?? '') !== '' ? (string)$card['expires'] : null,
                    'status'      => '使用中',
                    'created_at'  => $now, 'updated_at' => $now,
                ]);
                $cardId = (int)Db::name('member_card')->getLastInsID();
                Db::name('member_card_log')->insert([
                    'card_id'    => $cardId,
                    'card_no'    => $cardNo,
                    'action'     => '开卡',
                    'times'      => (int)($card['total'] ?? 0),
                    'order_no'   => (string)$tx['out_trade_no'],
                    'detail'     => '支付购卡「' . (string)($card['name'] ?? '') . '」共 ' . (int)($card['total'] ?? 0) . ((string)($card['type'] ?? '') === '次卡' ? ' 次' : ' 分钟'),
                    'at'         => $now, 'created_at' => $now, 'updated_at' => $now,
                ]);
            } else {
                throw new \RuntimeException('未知交易类型：' . $kind);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 定金 / 尾款入账（notify 回调与 confirm 线下确认共用）
     * 口径：settle 定金行/尾款行 → 定金阶段落尾款待支付行并推进订单状态机 → booking.paid 累加。
     * 幂等：settle 内部以「status=待支付」原子占用，重复入账返回 ok=false 即抛错回滚。
     * @param string $orderNo
     * @param string $kind 定金|尾款
     * @param int $amount 分
     * @param string $channel
     * @param string $paidAt
     * @return void
     * @throws \Throwable
     */
    private function settleInstallment(string $orderNo, string $kind, int $amount, string $channel, string $paidAt): void
    {
        $deposits = new DepositService();
        $now = date('Y-m-d H:i:s');
        Db::startTrans();
        try {
            $booking = Db::name('booking')->where('order_no', $orderNo)->where('deleted_at', 0)->find();
            if (!$booking) {
                throw new \RuntimeException('订单不存在');
            }
            $res = $deposits->settle($orderNo, $kind, $channel, $paidAt);
            if (!$res['ok']) {
                throw new \RuntimeException($kind . '收款状态异常（可能已入账）');
            }
            $nextStatus = (string)$booking['status'];
            if ($kind === DepositService::KIND_DEPOSIT) {
                // 定金入账 = 支付成功时刻：落尾款待支付行 + 订单进入派单链路
                $deposits->afterDepositPaid($booking);
                if ((string)$booking['status'] === '待支付') {
                    $nextStatus = (int)$booking['technician_id'] > 0 ? '待接单' : '待派单';
                    $this->orders->assertTransition('待支付', $nextStatus);
                }
            }
            Db::name('booking')->where('order_no', $orderNo)->update([
                'status'      => $nextStatus,
                'paid'        => (int)$booking['paid'] + (int)$res['amount'],
                'pay_channel' => $channel,
                'updated_at'  => $now,
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        // 定金入账视为支付成功：自动派单 + 券核销 + 拼团开团（失败不阻断主链路）
        if ($kind === DepositService::KIND_DEPOSIT) {
            try {
                $this->orders->autoDispatch($orderNo);
            } catch (\Throwable $e) {
                // 转人工派单
            }
            try {
                $booking = Db::name('booking')->where('order_no', $orderNo)->find();
                if ($booking) {
                    $couponCode = (string)$booking['coupon_code'];
                    if ($couponCode !== '') {
                        (new MarketingService())->consumeCoupon($couponCode, $orderNo);
                    }
                    $activityId = (int)$booking['activity_id'];
                    if ($activityId > 0) {
                        $activity = Db::name('marketing_activity')->where('id', $activityId)->find();
                        if ($activity && (string)$activity['kind'] === '拼团') {
                            $memberId = (int)$booking['member_id'];
                            if ($memberId > 0) {
                                (new MarketingService())->openGroup($activityId, $memberId, (string)$booking['customer'], $orderNo);
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                (new AuditService())->log('pay.link', $orderNo, '营销联动失败：' . $e->getMessage(), 0);
            }
        }
    }

    /**
     * 线下收款确认（后台代收现金/POS 刷卡后手动确认；沙箱环境亦以此模拟渠道支付成功）
     * POST /api/v1/pay/confirm  {order_no, channel, amount?, remark?}
     */
    public function confirm(Request $request): Response
    {
        $orderNo = clean_input($request->post('order_no', ''), 32);
        $channel = clean_input($request->post('channel', 'offline'), 20);

        // 充值/购卡等辅助业务单：凭待支付交易确认入账（归属校验后走统一入账）
        $aux = Db::name('pay_transaction')
            ->where('order_no', $orderNo)
            ->whereIn('kind', ['储值充值', '购卡'])
            ->where('status', '待支付')
            ->order('id desc')->find();
        if ($aux) {
            $type = (string)($request->tokenType ?? 'admin');
            if ($type === 'tech') {
                return err(403, '该操作不允许当前端执行', [], 403);
            }
            $raw = json_decode((string)$aux['raw'], true) ?: [];
            if ($type === 'member' && (int)($raw['member_id'] ?? 0) !== (int)($request->memberId ?? 0)) {
                return err(403, '无权操作他人交易', [], 403);
            }
            try {
                $this->settleAuxTransaction($aux, $channel, date('Y-m-d H:i:s'));
            } catch (\Throwable $e) {
                return err(500, '确认收款失败');
            }
            (new AuditService())->log('pay.confirm', $orderNo, (string)$aux['kind'] . '确认入账 ' . (int)$aux['amount'] . ' 分', (int)($request->adminId ?? 0));
            return ok(['out_trade_no' => (string)$aux['out_trade_no'], 'amount' => (int)$aux['amount']], '支付成功');
        }

        $booking = Db::name('booking')->where('order_no', $orderNo)->where('deleted_at', 0)->find();
        if (!$booking) {
            return err(404, '订单不存在');
        }
        // 归属校验：会员仅可确认本人订单收款；技师端不参与收款（admin 全放行）
        $type = (string)($request->tokenType ?? 'admin');
        if ($type === 'member' && (int)$booking['member_id'] !== (int)($request->memberId ?? 0)) {
            return err(403, '无权操作他人订单', [], 403);
        }
        if ($type === 'tech') {
            return err(403, '该操作不允许当前端执行', [], 403);
        }

        // ---- 定金尾款：分期收款分支（开关关闭时 payable=actual→kind=服务费，走下方全额路径）----
        $pay = (new DepositService())->payableFor($orderNo, (int)$booking['actual']);
        $payKind = (string)$pay['kind'];
        $payAmount = (int)$pay['amount'];
        if (in_array($payKind, [DepositService::KIND_DEPOSIT, DepositService::KIND_BALANCE], true)) {
            if ($payAmount <= 0) {
                return err(400, '订单已结清，无需收款');
            }
            $reqAmount = (int)$request->post('amount', 0);
            // 未传金额按当前应付入账；传了必须一致（防少收多收无痕）
            if ($reqAmount > 0 && $reqAmount !== $payAmount) {
                return err(400, '收款金额与应付金额不一致（应付 ' . $payAmount . ' 分）');
            }
            $outTradeNo = trade_no();
            $now = date('Y-m-d H:i:s');
            Db::startTrans();
            try {
                Db::name('pay_transaction')->insert([
                    'order_no'     => $orderNo,
                    'out_trade_no' => $outTradeNo,
                    'channel'      => $channel,
                    'kind'         => $payKind,
                    'amount'       => $payAmount,
                    'status'       => '已支付',
                    'paid_at'      => $now,
                    'raw'          => json_encode(['remark' => clean_input($request->post('remark', ''), 255)], JSON_UNESCAPED_UNICODE),
                    'created_at'   => $now,
                ]);
                Db::commit();
            } catch (\Throwable $e) {
                Db::rollback();
                return err(500, '确认收款失败');
            }
            try {
                $this->settleInstallment($orderNo, $payKind, $payAmount, $channel, $now);
            } catch (\Throwable $e) {
                return err(500, '确认收款失败');
            }
            (new AuditService())->log('pay.confirm', $orderNo, $payKind . '线下收款确认 ' . $payAmount . ' 分', (int)($request->adminId ?? 0));
            return ok(['order_no' => $orderNo, 'out_trade_no' => $outTradeNo, 'kind' => $payKind, 'amount' => $payAmount], $payKind . '收款确认成功');
        }

        if ((string)$booking['status'] !== '待支付') {
            return err(400, '当前订单状态不可确认收款');
        }

        $amount = (int)$request->post('amount', 0);
        // 未传金额按订单应付入账；传了必须一致（防少收多收无痕）
        if ($amount > 0 && $amount !== (int)$booking['actual']) {
            return err(400, '收款金额与订单应付金额不一致');
        }
        $amount = (int)$booking['actual'];

        $outTradeNo = trade_no();
        Db::startTrans();
        try {
            Db::name('pay_transaction')->insert([
                'order_no'     => $orderNo,
                'out_trade_no' => $outTradeNo,
                'channel'      => $channel,
                'kind'         => '服务费',
                'amount'       => $amount,
                'status'       => '已支付',
                'paid_at'      => date('Y-m-d H:i:s'),
                'raw'          => json_encode(['remark' => clean_input($request->post('remark', ''), 255)], JSON_UNESCAPED_UNICODE),
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
            Db::name('booking')->where('order_no', $orderNo)->update([
                'status'      => (int)$booking['technician_id'] > 0 ? '待接单' : '待派单',
                'paid'        => $amount,
                'pay_channel' => $channel,
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return err(500, '确认收款失败');
        }

        // 自动派单：setting dispatch_mode=自动派单 时，支付后的待派单订单按权重自动指派；失败转人工不影响收款
        try {
            $this->orders->autoDispatch($orderNo);
        } catch (\Throwable $e) {
            // 转人工派单
        }
        // 券核销 + 拼团开团/参团（支付成功才核销/成团，未支付不算）
        try {
            $booking = Db::name('booking')->where('order_no', $orderNo)->find();
            if ($booking) {
                $couponCode = (string)$booking['coupon_code'];
                if ($couponCode !== '') {
                    (new MarketingService())->consumeCoupon($couponCode, $orderNo);
                }
                $activityId = (int)$booking['activity_id'];
                if ($activityId > 0) {
                    $activity = Db::name('marketing_activity')->where('id', $activityId)->find();
                    if ($activity && (string)$activity['kind'] === '拼团') {
                        $memberId = (int)$booking['member_id'];
                        $memberName = (string)$booking['customer'];
                        $groupNo = clean_input($request->post('group_no', ''), 32);
                        if ($groupNo !== '') {
                            (new MarketingService())->joinGroup($groupNo, $memberId, $memberName, $orderNo);
                        } else {
                            (new MarketingService())->openGroup($activityId, $memberId, $memberName, $orderNo);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // 营销联动失败不阻断支付主链路；日志留痕
            (new AuditService())->log('pay.confirm', $orderNo, '营销联动失败：' . $e->getMessage(), 0);
        }

        (new AuditService())->log('pay.confirm', $orderNo, '线下收款确认 ' . $amount . ' 分', (int)($request->adminId ?? 0));
        return ok(['order_no' => $orderNo, 'out_trade_no' => $outTradeNo], '收款确认成功');
    }
}
