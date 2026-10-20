<?php
// =====================================================================
// booking 后端 · 订单控制器（下单/取消/改约/加钟/派单/打卡/完单）
// 状态机（OrderService，镜像前端 statusFlow.js）：主流程 待支付→待派单→待接单→待服务→服务中→待确认→已完成→待评价→已评价；逆向 已取消/售后中→已退款；禁跳节点、禁终态回退
// 金额一律服务端计算（单位分），时间窗/冲突/限额全部服务端校验
// =====================================================================

namespace app\controller;

use app\service\AuditService;
use app\service\DepositService;
use app\service\EditionGate;
use app\service\MarketingService;
use app\service\MessageService;
use app\service\OrderService;
use app\service\Module;
use app\service\QueueService;
use app\service\QuotaService;
use app\service\StockException;
use think\facade\Db;
use think\Request;
use think\Response;

class OrderController
{
    /** @var OrderService */
    private $orders;

    public function __construct()
    {
        $this->orders = new OrderService();
    }

    // ==================================================================
    // 多端归属校验（admin 全放行；会员限本人单；技师限派给自己的单）
    // 动作 → 允许端映射（未列出的动作仅 admin）
    // ==================================================================
    private const ACTION_ROLES = [
        'detail'        => ['admin', 'member', 'tech'],
        'cancel'        => ['admin', 'member'],
        'reschedule'    => ['admin', 'member'],
        'extend'        => ['admin', 'member', 'tech'],
        'extendConfirm' => ['admin'],
        'dispatch'      => ['admin'],
        'grab'          => ['tech'],
        'accept'        => ['admin', 'tech'],
        'reject'        => ['admin', 'tech'],
        'checkin'       => ['admin', 'tech'],
        'finish'        => ['admin', 'tech'],
        'confirm'       => ['admin', 'member'],
        'aftersale'     => ['admin', 'member'],
        'aftersaleReject' => ['admin'],
        'refund'        => ['admin'],
    ];

    /**
     * 查单 + 归属/角色守卫（替代裸 mustFind 调用）
     * @return array [booking|null, denyResponse|null]
     */
    private function guardFind(Request $request, string $orderNo): array
    {
        $booking = $this->mustFind($orderNo);
        if (!$booking) {
            return [null, err(404, '订单不存在')];
        }
        $deny = $this->guard($request, $booking, self::ACTION_ROLES[(string)$request->action()] ?? ['admin']);
        return $deny ? [null, $deny] : [$booking, null];
    }

    /**
     * 角色守卫（不含查单；admin 全放行，member/tech 追加归属校验）
     * @return Response|null 拒绝响应，null=放行
     */
    private function guard(Request $request, array $booking, array $roles): ?Response
    {
        $type = (string)($request->tokenType ?? 'admin');
        if ($type === 'admin') {
            return null;
        }
        if (!in_array($type, $roles, true)) {
            return err(403, '该操作不允许当前端执行', [], 403);
        }
        if ($type === 'member' && (int)$booking['member_id'] !== (int)($request->memberId ?? 0)) {
            return err(403, '无权操作他人订单', [], 403);
        }
        if ($type === 'tech' && (int)$booking['technician_id'] !== (int)($request->techId ?? 0)) {
            return err(403, '无权操作非本人服务订单', [], 403);
        }
        return null;
    }

    /**
     * 我的订单（多端）：会员按 member_id、技师按 technician_id 过滤
     * GET /api/v1/orders/my?status=&page=1&per_page=20
     */
    public function myList(Request $request): Response
    {
        $type = (string)($request->tokenType ?? 'admin');
        $query = Db::name('booking')->where('deleted_at', 0);
        if ($type === 'member') {
            $query->where('member_id', (int)($request->memberId ?? 0));
        } elseif ($type === 'tech') {
            $query->where('technician_id', (int)($request->techId ?? 0));
        } else {
            return err(403, '请用会员/技师端 token 访问', [], 403);
        }
        $status = clean_input($request->get('status', ''), 20);
        if ($status !== '') {
            $query->where('status', $status);
        }
        $page = max(1, (int)$request->get('page', 1));
        $perPage = min(100, max(1, (int)$request->get('per_page', 20)));
        $total = (clone $query)->count();
        $list = $query->order('id', 'desc')->page($page, $perPage)->select()->toArray();
        return ok(['list' => $list, 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
    }

    /**
     * 派单池（未指派的待派单订单，技师抢单候选）
     * GET /api/v1/orders/pool?status=&page=1&per_page=20
     * 仅会员不可见；技师/后台可见（列表不含敏感越权数据）
     */
    public function pool(Request $request): Response
    {
        $type = (string)($request->tokenType ?? 'admin');
        if ($type === 'member') {
            return err(403, '派单池仅技师/后台可见', [], 403);
        }
        $query = Db::name('booking')
            ->where('deleted_at', 0)
            ->where('status', '待派单')
            ->where('technician_id', 0);
        $page = max(1, (int)$request->get('page', 1));
        $perPage = min(100, max(1, (int)$request->get('per_page', 20)));
        $total = (clone $query)->count();
        $list = $query->order('id', 'desc')->page($page, $perPage)->select()->toArray();
        return ok(['list' => $list, 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
    }

    /**
     * 下单（用户端/后台代客预约共用）
     * POST /api/v1/orders
     * body: service_id, start_at, duration, customer, phone, address, lng, lat, remark, technician_id?, coupon_code?
     */
    public function create(Request $request): Response
    {
        $serviceId = (int)$request->post('service_id', 0);
        $startAt = clean_input($request->post('start_at', ''), 20);
        $customer = clean_input($request->post('customer', ''), 50);
        $phone = clean_input($request->post('phone', ''), 20);
        $address = clean_input($request->post('address', ''), 255);
        $techId = (int)$request->post('technician_id', 0);

        if ($serviceId <= 0 || $startAt === '' || $customer === '' || $phone === '' || $address === '') {
            return err(400, '服务/时间/客户/电话/地址为必填');
        }

        // 会员端下单归属强制取自 token（防越权挂他人名下）；后台代客预约可传 member_id
        $memberId = (string)($request->tokenType ?? 'admin') === 'member'
            ? (int)($request->memberId ?? 0)
            : (int)$request->post('member_id', 0);
        $activityId = (int)$request->post('activity_id', 0);
        $couponCode = clean_input($request->post('coupon_code', ''), 32);

        try {
            // 月订单量限额（超限引导升级，见 QuotaService）
            (new QuotaService())->assert('monthly_orders_max');
            // 预约时间窗（提前 N 小时 / 最远 N 天，setting 配置）
            $this->orders->assertBookingWindow($startAt);
            // 爽约超限冻结校验（member.frozen_until，见 OrderService::markNoshow）
            $this->orders->assertMemberCanBook($memberId);
            $minutes = (int)$request->post('duration', 0);
            $amount = $this->orders->calcAmount($serviceId, $minutes, $techId);
            // 时段差价 / 节假日价目（price_rule）：加价先于营销优惠，计入订单原价
            // 时段差价引擎为商业模块（app\plus\service\PriceRuleService）；开源版经 Module 解析为 null → 不加价
            $prSvc = Module::service('PriceRuleService');
            $priceRule = $prSvc ? $prSvc->quote($serviceId, $startAt, $amount) : ['surcharge' => 0, 'amount' => $amount, 'rules' => []];
            $surcharge = (int)$priceRule['surcharge'];
            $amount = (int)$priceRule['amount'];
            // 路途费/打车费（按客户坐标到服务区域中心的实际距离分段加收，pro+；开源版=0）
            $userLng = (float)clean_input($request->post('lng', '0'), 20);
            $userLat = (float)clean_input($request->post('lat', '0'), 20);
            // 路费引擎为商业模块（app\plus\service\TravelFeeService）；开源版经 Module 解析为 null → 路费 0
            $travelSvc = Module::service('TravelFeeService');
            $travelFee = $travelSvc ? (int)$travelSvc->quote($userLng, $userLat)['travel_fee'] : 0;
            $amount += $travelFee; // 路费计入原价 total（营销优惠在其后叠加）
            // 营销活动属授权版功能（《功能矩阵》五章），开源版下单不得享活动价
            if ($activityId > 0 && !(new EditionGate())->assertFeature('marketing.activity')) {
                $gate = new EditionGate();
                return err(403, $gate->lastDeny()['tip'] ?? '营销活动为授权版功能', ['upgrade' => $gate->lastDeny()]);
            }
            // 统一计价：活动价 + 券叠加，金额守恒（服务端重算，不信前端金额）
            $quote = (new MarketingService())->priceQuote($serviceId, $minutes, $amount, $activityId, $couponCode, $memberId);
            $amount = (int)$quote['total'];
            // 电子服务协议（附四 #6）：存在适用协议时，会员端下单必须持有效签署 token（一次性）；
            // 后台代客预约（admin token）不做线上强校验（顾客线下签，口径见 AgreementService）
            $agreementSignId = null;
            // 电子服务协议为商业模块（app\plus）；开源版经 Module 解析为 null → 无协议、跳过校验
            $agrSvc = Module::service('AgreementService');
            $agreements = $agrSvc ? $agrSvc->applicableForService($serviceId) : [];
            if ($agreements !== [] && (string)($request->tokenType ?? '') === 'member') {
                $agreementSignId = $agrSvc->assertTokenUsable(
                    clean_input($request->post('agreement_token', ''), 64),
                    $memberId,
                    $agreements
                );
            }
        } catch (StockException $e) {
            // 库存售罄 = 资源冲突，与业务规则拒绝（400）区分，前端可提示刷新重试
            return err(409, $e->getMessage());
        } catch (\RuntimeException $e) {
            return err(400, $e->getMessage());
        }

        $service = Db::name('service')->where('id', $serviceId)->where('deleted_at', 0)->find();
        if ($minutes <= 0) {
            $minutes = (int)$service['duration'];
        }
        $endAt = date('Y-m-d H:i:s', strtotime($startAt) + $minutes * 60);

        // 指定技师时校验时段冲突
        if ($techId > 0 && $this->orders->hasConflict($techId, $startAt, $endAt)) {
            return err(409, '该技师在所选时段已有订单，请换时段或技师');
        }
        $tech = $techId > 0 ? Db::name('technician')->where('id', $techId)->where('deleted_at', 0)->find() : null;

        $data = [
            'order_no'      => $this->orders->nextOrderNo(),
            // 会员端下单归属强制取自 token（防越权挂他人名下）；后台代客预约可传 member_id
            'member_id'     => $memberId,
            'customer'      => $customer,
            'phone'         => $phone,
            'service_id'    => $serviceId,
            'service'       => (string)$service['name'],
            'technician_id' => $techId,
            'technician'    => $tech ? (string)$tech['name'] : '',
            'store_id'      => (int)$request->post('store_id', 0),
            'start_at'      => $startAt,
            'end_at'        => $endAt,
            'duration'      => $minutes,
            'address'       => $address,
            'lng'           => $userLng,
            'lat'           => $userLat,
            'remark'        => clean_input($request->post('remark', ''), 500),
            'total'         => $amount,
            'surcharge'     => $surcharge,
            'travel_fee'    => $travelFee,
            'price_rule'    => implode(',', $priceRule['rules']),
            'discount'      => (int)$quote['discount'],
            'actual'        => (int)$quote['actual'],
            'paid'          => 0,
            'coupon_code'   => $couponCode,
            'activity_id'   => $activityId,
            'activity_name' => $activityId > 0 ? (string)($quote['activity']['name'] ?? '') : '',
            'status'        => '待支付',
            'source'        => clean_input($request->post('source', 'admin'), 20),
            'created_at'    => date('Y-m-d H:i:s'),
        ];
        // 活动库存占位 + 订单入库：套事务，超卖时回滚
        if ($activityId > 0) {
            $held = (new MarketingService())->holdStock($activityId);
            if (!$held) {
                return err(409, '活动库存不足，请刷新后重试');
            }
        }
        $id = Db::name('booking')->insertGetId($data);
        (new AuditService())->log('order.create', $data['order_no'], '下单 ' . $data['service'], (int)($request->adminId ?? 0));
        // 协议签署凭证消费（一次性：回写订单号后作废，防转发复用）
        if ($agreementSignId !== null) {
            $agrSvc2 = Module::service('AgreementService');
            if ($agrSvc2) {
                $agrSvc2->markConsumed($agreementSignId, (string)$data['order_no']);
            }
        }

        $row = Db::name('booking')->where('id', $id)->find();
        // 定金尾款：启用时落「定金」待支付行（开关关闭时零动作，全额口径不变）
        $deposit = new DepositService();
        try {
            $deposit->createForOrder((array)$row);
        } catch (\Throwable $e) {
            // 定金行落库失败不影响下单（支付时 payableFor 会回落到全额口径）
        }
        // 消息通知：下单成功（模板「订单创建成功」；通道关闭/未配模板时落「跳过」日志，不阻断下单）
        try {
            (new MessageService())->send('订单创建成功', $row);
        } catch (\Throwable $e) {
            // 通知失败不影响下单
        }
        $row['payment'] = $deposit->summary((array)$row);
        return ok($row, '下单成功，请支付');
    }

    /**
     * 取消（待支付/待派单/待接单/待服务可取消；服务中不允许直接取消，纠纷转售后）
     * PUT /api/v1/orders/{orderNo}/cancel  {reason}
     */
    public function cancel(Request $request, string $orderNo): Response
    {
        [$booking, $deny] = $this->guardFind($request, $orderNo);
        if ($deny) {
            return $deny;
        }
        try {
            $this->orders->assertTransition((string)$booking['status'], '已取消');
        } catch (\RuntimeException $e) {
            return err(400, $e->getMessage());
        }

        // 违约金（服务开始前 N 小时内取消按比例收，读 setting）
        $cancelFee = $this->orders->calcCancelFee($booking);
        Db::name('booking')->where('order_no', $orderNo)->update([
            'status'        => '已取消',
            'cancel_reason' => clean_input($request->put('reason', ''), 255),
            'discount'      => (int)$booking['discount'] + $cancelFee, // 违约金计入优惠冲减口径，结算按 actual
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        // 活动库存回补（取消释放占位）
        if ((int)$booking['activity_id'] > 0) {
            (new MarketingService())->releaseStock((int)$booking['activity_id']);
        }
        // 定金尾款：已支付的定金/尾款一并置「已退款」
        try {
            (new DepositService())->refundAll($orderNo);
        } catch (\Throwable $e) {
            // 定金退款失败不影响取消
        }
        // 候补递补：档期释放后自动补位该时段最早排队者（queue_enabled 关闭时无排队数据，天然空转）
        $this->promoteQueue((array)$booking);
        (new AuditService())->log('order.cancel', $orderNo, '取消订单，违约金 ' . $cancelFee . ' 分', (int)($request->adminId ?? 0));

        return ok(['order_no' => $orderNo, 'cancel_fee' => $cancelFee], $cancelFee > 0 ? '已取消（含违约金 ' . fen_to_yuan($cancelFee) . ' 元）' : '已取消');
    }

    /**
     * 标记客户爽约（技师已到场而客户未到）：待服务 → 已取消，累计月内爽约次数，
     * 达 setting noshow_limit 后按 noshow_freeze_days 冻结该会员下单（assertMemberCanBook 拦截）
     * POST /api/v1/orders/{orderNo}/noshow  {reason?}
     */
    public function noshow(Request $request, string $orderNo): Response
    {
        [$booking, $deny] = $this->guardFind($request, $orderNo);
        if ($deny) {
            return $deny;
        }
        if ((string)$booking['status'] !== '待服务') {
            return err(400, '仅「待服务」订单可标记客户爽约');
        }
        try {
            $this->orders->assertTransition('待服务', '已取消');
        } catch (\RuntimeException $e) {
            return err(400, $e->getMessage());
        }
        $reason = clean_input($request->post('reason', ''), 255) ?: '客户爽约（技师到场未见到客户）';
        Db::name('booking')->where('order_no', $orderNo)->update([
            'status'        => '已取消',
            'cancel_reason' => $reason,
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        // 活动库存回补（爽约同样释放占位）
        if ((int)$booking['activity_id'] > 0) {
            (new MarketingService())->releaseStock((int)$booking['activity_id']);
        }
        // 定金尾款：已支付的定金/尾款一并置「已退款」
        try {
            (new DepositService())->refundAll($orderNo);
        } catch (\Throwable $e) {
            // 定金退款失败不影响爽约处理
        }
        // 候补递补：爽约同样释放档期
        $this->promoteQueue((array)$booking);
        $res = $this->orders->markNoshow($booking);
        (new AuditService())->log(
            'order.noshow',
            $orderNo,
            '标记客户爽约：本月第 ' . $res['count'] . ' 次（上限 ' . $res['limit'] . '）'
                . ($res['frozen_until'] !== '' ? '，已冻结下单至 ' . $res['frozen_until'] : ''),
            (int)($request->adminId ?? 0)
        );
        return ok([
            'order_no'     => $orderNo,
            'status'       => '已取消',
            'noshow_count' => $res['count'],
            'noshow_limit' => $res['limit'],
            'frozen_until' => $res['frozen_until'],
        ], $res['frozen_until'] !== ''
            ? '已记录爽约，该客户已达上限，限制下单至 ' . $res['frozen_until']
            : '已记录爽约');
    }

    /**
     * 改约（待接单/待服务可改约，即服务开始前；新时段冲突校验排除自身）
     * PUT /api/v1/orders/{orderNo}/reschedule  {start_at}
     */
    public function reschedule(Request $request, string $orderNo): Response
    {
        [$booking, $deny] = $this->guardFind($request, $orderNo);
        if ($deny) {
            return $deny;
        }
        if (!in_array((string)$booking['status'], ['待接单', '待服务'], true)) {
            return err(400, '仅待接单/待服务订单可改约');
        }
        $startAt = clean_input($request->put('start_at', ''), 20);
        if ($startAt === '') {
            return err(400, '新预约时间不能为空');
        }
        $endAt = date('Y-m-d H:i:s', strtotime($startAt) + (int)$booking['duration'] * 60);

        try {
            $this->orders->assertBookingWindow($startAt);
        } catch (\RuntimeException $e) {
            return err(400, $e->getMessage());
        }
        if ((int)$booking['technician_id'] > 0
            && $this->orders->hasConflict((int)$booking['technician_id'], $startAt, $endAt, $orderNo)) {
            return err(409, '该技师在新时段已有订单');
        }

        Db::name('booking')->where('order_no', $orderNo)->update([
            'start_at'   => $startAt,
            'end_at'     => $endAt,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        (new AuditService())->log('order.reschedule', $orderNo, '改约至 ' . $startAt, (int)($request->adminId ?? 0));
        return ok(['order_no' => $orderNo, 'start_at' => $startAt, 'end_at' => $endAt], '改约成功');
    }

    /**
     * 加钟（仅服务中；追加时长差价重新计价，写 order_extend）
     * POST /api/v1/orders/{orderNo}/extend  {minutes, reason, confirm=1}
     * confirm=1 立即生效并改订单；confirm=0 记待确认，走 /extend-confirm 审批
     */
    public function extend(Request $request, string $orderNo): Response
    {
        [$booking, $deny] = $this->guardFind($request, $orderNo);
        if ($deny) {
            return $deny;
        }
        if ((string)$booking['status'] !== '服务中') {
            return err(400, '仅服务中订单可加钟');
        }
        $minutes = (int)$request->post('minutes', 0);
        if ($minutes <= 0) {
            return err(400, '加钟时长必须大于 0');
        }

        try {
            // 差价按服务时长比例重算（金额只信服务端）；指定技师时用其项目定价
            $price = $this->orders->calcAmount((int)$booking['service_id'], $minutes, (int)$booking['technician_id']);
            // 加钟同样适用时段/节假日加价（商业模块；开源版不加价）
            $prSvc2 = Module::service('PriceRuleService');
            if ($prSvc2) {
                $price = (int)$prSvc2->quote(
                    (int)$booking['service_id'],
                    (string)($booking['start_at'] ?? date('Y-m-d H:i:s')),
                    $price
                )['amount'];
            }
        } catch (\RuntimeException $e) {
            return err(400, $e->getMessage());
        }

        $confirm = (int)$request->post('confirm', 1) === 1;
        $extendId = Db::name('order_extend')->insertGetId([
            'order_no'    => $orderNo,
            'minutes'     => $minutes,
            'price'       => $price,
            'reason'      => clean_input($request->post('reason', ''), 255),
            'operator'    => clean_input($request->post('operator', ''), 50),
            'status'      => $confirm ? '已生效' : '待确认',
            'at'          => date('Y-m-d H:i:s'),
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        if ($confirm) {
            Db::name('booking')->where('order_no', $orderNo)->update([
                'duration'   => (int)$booking['duration'] + $minutes,
                'end_at'     => date('Y-m-d H:i:s', strtotime((string)$booking['end_at']) + $minutes * 60),
                'actual'     => (int)$booking['actual'] + $price,
                'total'      => (int)$booking['total'] + $price,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
        (new AuditService())->log('order.extend', $orderNo, '加钟 ' . $minutes . ' 分钟 +' . $price . ' 分', (int)($request->adminId ?? 0));

        return ok(['extend_id' => $extendId, 'minutes' => $minutes, 'price' => $price, 'status' => $confirm ? '已生效' : '待确认'], $confirm ? '加钟已生效' : '加钟待确认');
    }

    /**
     * 加钟审批（待确认 → 已生效/已驳回）
     * POST /api/v1/orders/{orderNo}/extend-confirm  {extend_id, approve}
     */
    public function extendConfirm(Request $request, string $orderNo): Response
    {
        // 加钟审批为后台动作（会员/技师不可自批）
        $deny = $this->guard($request, [], ['admin']);
        if ($deny) {
            return $deny;
        }
        $extend = Db::name('order_extend')->where('id', (int)$request->post('extend_id', 0))->where('order_no', $orderNo)->find();
        if (!$extend) {
            return err(404, '加钟记录不存在');
        }
        if ((string)$extend['status'] !== '待确认') {
            return err(400, '加钟记录已处理');
        }
        $approve = (int)$request->post('approve', 1) === 1;

        Db::name('order_extend')->where('id', $extend['id'])->update([
            'status'     => $approve ? '已生效' : '已驳回',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        if ($approve) {
            $booking = $this->mustFind($orderNo);
            Db::name('booking')->where('order_no', $orderNo)->update([
                'duration'   => (int)$booking['duration'] + (int)$extend['minutes'],
                'end_at'     => date('Y-m-d H:i:s', strtotime((string)$booking['end_at']) + (int)$extend['minutes'] * 60),
                'actual'     => (int)$booking['actual'] + (int)$extend['price'],
                'total'      => (int)$booking['total'] + (int)$extend['price'],
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
        (new AuditService())->log('order.extend-confirm', $orderNo, $approve ? '加钟生效' : '加钟驳回', (int)($request->adminId ?? 0));
        return ok([], $approve ? '加钟已生效' : '加钟已驳回');
    }

    /**
     * 派单/改派（待派单指派 → 待接单；待接单改派仅换技师；技师时段冲突校验）
     * POST /api/v1/orders/{orderNo}/dispatch  {technician_id}
     */
    public function dispatch(Request $request, string $orderNo): Response
    {
        [$booking, $deny] = $this->guardFind($request, $orderNo);
        if ($deny) {
            return $deny;
        }
        $from = (string)$booking['status'];
        if (!in_array($from, ['待派单', '待接单'], true)) {
            return err(400, '仅待派单/待接单订单可派单');
        }
        $techId = (int)$request->post('technician_id', 0);
        $tech = Db::name('technician')->where('id', $techId)->where('deleted_at', 0)->find();
        if (!$tech) {
            return err(404, '技师不存在');
        }
        if ($this->orders->hasConflict($techId, (string)$booking['start_at'], (string)$booking['end_at'], $orderNo)) {
            return err(409, '该技师在订单时段已有其他订单');
        }

        // 首次指派流转 待派单→待接单；待接单改派状态不变
        $updates = [
            'technician_id' => $techId,
            'technician'    => (string)$tech['name'],
            'updated_at'    => date('Y-m-d H:i:s'),
        ];
        if ($from === '待派单') {
            try {
                $this->orders->assertTransition($from, '待接单');
            } catch (\RuntimeException $e) {
                return err(400, $e->getMessage());
            }
            $updates['status'] = '待接单';
        }
        Db::name('booking')->where('order_no', $orderNo)->update($updates);
        (new AuditService())->log('order.dispatch', $orderNo, ($from === '待派单' ? '派单' : '改派') . '给技师 ' . $tech['name'], (int)($request->adminId ?? 0));
        return ok(['order_no' => $orderNo, 'technician_id' => $techId, 'status' => $updates['status'] ?? $from], $from === '待派单' ? '派单成功' : '改派成功');
    }

    /**
     * 抢单（派单池「待派单 + 未指派」→ 技师自指派 → 待接单）
     * POST /api/v1/orders/{orderNo}/grab
     * ⚠️ 不走 guardFind：未指派单 technician_id=0，tech 归属校验必拒；
     *    此处自校验「待派单 + 未指派」，并发抢单用条件更新原子占坑（affected=0 即已被抢走）
     */
    public function grab(Request $request, string $orderNo): Response
    {
        if ((string)($request->tokenType ?? '') !== 'tech') {
            return err(403, '请用技师端 token 抢单', [], 403);
        }
        $techId = (int)($request->techId ?? 0);
        $tech = Db::name('technician')->where('id', $techId)->where('deleted_at', 0)->find();
        if (!$tech) {
            return err(404, '技师不存在');
        }
        $booking = $this->mustFind($orderNo);
        if (!$booking) {
            return err(404, '订单不存在');
        }
        if ((string)$booking['status'] !== '待派单' || (int)$booking['technician_id'] !== 0) {
            return err(409, '订单已被抢走或不在派单池');
        }
        if ($this->orders->hasConflict($techId, (string)$booking['start_at'], (string)$booking['end_at'], $orderNo)) {
            return err(409, '您在该时段已有其他订单');
        }
        try {
            $this->orders->assertTransition((string)$booking['status'], '待接单');
        } catch (\RuntimeException $e) {
            return err(400, $e->getMessage());
        }
        // 条件更新原子占坑：并发抢单只有一个 affected=1
        $affected = Db::name('booking')
            ->where('order_no', $orderNo)
            ->where('status', '待派单')
            ->where('technician_id', 0)
            ->update([
                'technician_id' => $techId,
                'technician'    => (string)$tech['name'],
                'status'        => '待接单',
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);
        if ($affected === 0) {
            return err(409, '手慢了，订单已被抢走', [], 409);
        }
        (new AuditService())->log('order.grab', $orderNo, '技师 ' . (string)$tech['name'] . ' 抢单成功', (int)($request->adminId ?? 0));
        return ok(['order_no' => $orderNo, 'technician_id' => $techId, 'status' => '待接单'], '抢单成功');
    }

    /**
     * 接单（待接单 → 待服务）
     * POST /api/v1/orders/{orderNo}/accept
     */
    public function accept(Request $request, string $orderNo): Response
    {
        [$booking, $deny] = $this->guardFind($request, $orderNo);
        if ($deny) {
            return $deny;
        }
        try {
            $this->orders->assertTransition((string)$booking['status'], '待服务');
        } catch (\RuntimeException $e) {
            return err(400, $e->getMessage());
        }
        Db::name('booking')->where('order_no', $orderNo)->update([
            'status'     => '待服务',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        (new AuditService())->log('order.accept', $orderNo, '技师接单', (int)($request->adminId ?? 0));
        // 消息通知：技师出发/接单（模板「技师出发」）
        try {
            $row = Db::name('booking')->where('order_no', $orderNo)->find();
            (new MessageService())->send('技师出发', $row ?: []);
        } catch (\Throwable $e) {
            // 通知失败不影响接单
        }
        return ok(['order_no' => $orderNo, 'status' => '待服务'], '接单成功');
    }

    /**
     * 拒单（待接单 → 回待派单，重新指派/改派）
     * POST /api/v1/orders/{orderNo}/reject  {reason}
     */
    public function reject(Request $request, string $orderNo): Response
    {
        [$booking, $deny] = $this->guardFind($request, $orderNo);
        if ($deny) {
            return $deny;
        }
        try {
            $this->orders->assertTransition((string)$booking['status'], '待派单');
        } catch (\RuntimeException $e) {
            return err(400, $e->getMessage());
        }
        // 拒单释放技师回派单池（technician_id=0），原技师留痕在 cancel_reason
        Db::name('booking')->where('order_no', $orderNo)->update([
            'status'        => '待派单',
            'technician_id' => 0,
            'technician'    => '',
            'cancel_reason' => '拒单：' . clean_input($request->post('reason', ''), 200)
                . '（原技师：' . (string)$booking['technician'] . '）',
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        (new AuditService())->log('order.reject', $orderNo, '技师 ' . (string)$booking['technician'] . ' 拒单，释放回派单池', (int)($request->adminId ?? 0));
        return ok(['order_no' => $orderNo, 'status' => '待派单'], '已拒单，订单回到派单池');
    }

    /**
     * 服务打卡（上门/开始/结束留痕；kind=start 时流转 待服务→服务中）
     * POST /api/v1/orders/{orderNo}/checkin  {kind, lng, lat, address, images, note}
     */
    public function checkin(Request $request, string $orderNo): Response
    {
        [$booking, $deny] = $this->guardFind($request, $orderNo);
        if ($deny) {
            return $deny;
        }
        $kind = clean_input($request->post('kind', '上门'), 20);
        if (!in_array($kind, ['上门', '开始', '结束'], true)) {
            return err(400, '打卡类型无效（上门/开始/结束）');
        }

        Db::name('order_checkin')->insert([
            'order_no'      => $orderNo,
            'technician_id' => (int)$booking['technician_id'],
            'kind'          => $kind,
            // 坐标 decimal(10,6) NOT NULL，空串/非法值归一为 0
            'lng'           => (float)clean_input($request->post('lng', '0'), 20),
            'lat'           => (float)clean_input($request->post('lat', '0'), 20),
            'address'       => clean_input($request->post('address', ''), 255),
            'images'        => json_encode((array)$request->post('images', []), JSON_UNESCAPED_UNICODE),
            'note'          => clean_input($request->post('note', ''), 255),
            'at'            => date('Y-m-d H:i:s'),
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        // 「开始」打卡 → 待服务流转服务中（状态机校验）
        if ($kind === '开始') {
            try {
                $this->orders->assertTransition((string)$booking['status'], '服务中');
            } catch (\RuntimeException $e) {
                return err(400, $e->getMessage());
            }
            Db::name('booking')->where('order_no', $orderNo)->update([
                'status'     => '服务中',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
        (new AuditService())->log('order.checkin', $orderNo, $kind . '打卡', (int)($request->adminId ?? 0));
        return ok(['order_no' => $orderNo, 'kind' => $kind], '打卡成功');
    }

    /**
     * 完单（服务中 → 待确认，等用户确认或超期自动确认）
     * POST /api/v1/orders/{orderNo}/finish
     */
    public function finish(Request $request, string $orderNo): Response
    {
        [$booking, $deny] = $this->guardFind($request, $orderNo);
        if ($deny) {
            return $deny;
        }
        try {
            $this->orders->assertTransition((string)$booking['status'], '待确认');
        } catch (\RuntimeException $e) {
            return err(400, $e->getMessage());
        }

        Db::name('booking')->where('order_no', $orderNo)->update([
            'status'     => '待确认',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        (new AuditService())->log('order.finish', $orderNo, '完单，待用户确认', (int)($request->adminId ?? 0));
        return ok(['order_no' => $orderNo, 'status' => '待确认'], '服务完成，待用户确认');
    }

    /**
     * 确认完成（待确认 → 已完成；用户确认，会员消费统计在此落账）
     * POST /api/v1/orders/{orderNo}/confirm
     */
    public function confirm(Request $request, string $orderNo): Response
    {
        [$booking, $deny] = $this->guardFind($request, $orderNo);
        if ($deny) {
            return $deny;
        }
        try {
            $this->orders->assertTransition((string)$booking['status'], '已完成');
        } catch (\RuntimeException $e) {
            return err(400, $e->getMessage());
        }

        // 定金尾款：未结清尾款不得确认完成（开关关闭时 settled 恒 true，零影响）
        try {
            $dep = new DepositService();
            if (!$dep->settled($orderNo)) {
                $sum = $dep->summary((array)$booking);
                return err(400, '请先收取尾款 ' . fen_to_yuan((int)$sum['payable']) . ' 元后再确认完成', ['payment' => $sum]);
            }
        } catch (\Throwable $e) {
            // 定金校验异常不阻断（保守放行）
        }

        Db::name('booking')->where('order_no', $orderNo)->update([
            'status'     => '已完成',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        // 会员消费统计（服务历史统计聚合字段；确认完成口径=FINISHED_SET 起点）
        if ((int)$booking['member_id'] > 0) {
            Db::name('member')->where('id', (int)$booking['member_id'])->inc('total_orders')->inc('total_amount', (int)$booking['actual'])->update([
                'last_order_at' => date('Y-m-d H:i:s'),
            ]);
            // 积分发放：按 setting point_rate（实付 1 元 = N 积分）写积分流水，余额守恒
            try {
                $this->orders->awardPointsOnConfirm($booking);
            } catch (\Throwable $e) {
                // 积分发放失败不阻断订单确认；流水缺失可人工在积分流水页补录
            }
            // 推荐有礼 + 分销佣金：被邀请人首单完成 → 邀请人/被邀请人积分奖励；邀请人是分销员则计入待结算佣金
            try {
                $this->orders->awardReferralOnConfirm($booking);
            } catch (\Throwable $e) {
                // 奖励失败不阻断订单确认
            }
            // 会员等级：累计消费达标自动升级（读 setting 键 level_rules，只升不降）
            try {
                $lv = $this->orders->syncMemberLevel((int)$booking['member_id']);
                if (!empty($lv['changed'])) {
                    (new AuditService())->log('member.level', (string)$booking['member_id'], '会员等级自动升级：' . $lv['from'] . ' → ' . $lv['level'], (int)($request->adminId ?? 0));
                }
            } catch (\Throwable $e) {
                // 等级同步失败不阻断订单确认
            }
        }
        (new AuditService())->log('order.confirm', $orderNo, '用户确认完成', (int)($request->adminId ?? 0));
        // 消息通知：订单完成与评价邀请（模板「订单完成」）
        try {
            $row = Db::name('booking')->where('order_no', $orderNo)->find();
            (new MessageService())->send('订单完成', $row ?: []);
        } catch (\Throwable $e) {
            // 通知失败不影响确认
        }
        return ok(['order_no' => $orderNo, 'status' => '已完成'], '已确认完成，可去评价');
    }

    /**
     * 转售后（服务中/已完成/待评价/已评价 → 售后中；记录 pre_status 供驳回回退）
     * POST /api/v1/orders/{orderNo}/aftersale  {reason}
     */
    public function aftersale(Request $request, string $orderNo): Response
    {
        [$booking, $deny] = $this->guardFind($request, $orderNo);
        if ($deny) {
            return $deny;
        }
        try {
            $this->orders->assertTransition((string)$booking['status'], '售后中');
        } catch (\RuntimeException $e) {
            return err(400, $e->getMessage());
        }
        $reason = clean_input($request->post('reason', ''), 255);
        if ($reason === '') {
            return err(400, '请填写转售后原因');
        }

        Db::name('booking')->where('order_no', $orderNo)->update([
            'status'           => '售后中',
            'pre_status'       => (string)$booking['status'],
            'aftersale_reason' => $reason,
            'aftersale_at'     => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);
        (new AuditService())->log('order.aftersale', $orderNo, '转售后：' . $reason, (int)($request->adminId ?? 0));
        return ok(['order_no' => $orderNo, 'status' => '售后中', 'pre_status' => (string)$booking['status']], '已转入售后处理');
    }

    /**
     * 售后驳回（售后中 → 回退 pre_status；仅可回状态机允许的节点）
     * POST /api/v1/orders/{orderNo}/aftersale-reject  {reason}
     */
    public function aftersaleReject(Request $request, string $orderNo): Response
    {
        [$booking, $deny] = $this->guardFind($request, $orderNo);
        if ($deny) {
            return $deny;
        }
        if ((string)$booking['status'] !== '售后中') {
            return err(400, '仅售后中订单可驳回');
        }
        $back = (string)$booking['pre_status'];
        if ($back === '') {
            return err(400, '原状态缺失，无法驳回');
        }
        try {
            $this->orders->assertTransition('售后中', $back);
        } catch (\RuntimeException $e) {
            return err(400, $e->getMessage());
        }

        $note = '驳回（' . date('Y-m-d H:i:s') . '）';
        $reason = clean_input($request->post('reason', ''), 200);
        if ($reason !== '') {
            $note .= '：' . $reason;
        }
        Db::name('booking')->where('order_no', $orderNo)->update([
            'status'           => $back,
            'aftersale_result' => $note,
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);
        (new AuditService())->log('order.aftersale-reject', $orderNo, '售后驳回，回「' . $back . '」', (int)($request->adminId ?? 0));
        return ok(['order_no' => $orderNo, 'status' => $back], '售后已驳回，订单回到「' . $back . '」');
    }

    /**
     * 售后退款（售后中 → 已退款终态；生成退款单，金额只信服务端按实付全额退）
     * POST /api/v1/orders/{orderNo}/refund  {reason}
     */
    public function refund(Request $request, string $orderNo): Response
    {
        [$booking, $deny] = $this->guardFind($request, $orderNo);
        if ($deny) {
            return $deny;
        }
        try {
            $this->orders->assertTransition((string)$booking['status'], '已退款');
        } catch (\RuntimeException $e) {
            return err(400, $e->getMessage());
        }

        // 金额只信服务端：按订单实付全额退，不接受前端回传金额（防篡改）
        $amount = (int)$booking['actual'];
        $refundNo = $this->nextRefundNo();

        Db::startTrans();
        try {
            Db::name('refund')->insert([
                'refund_no'   => $refundNo,
                'order_no'    => $orderNo,
                'member_id'   => (int)$booking['member_id'],
                'member'      => (string)$booking['customer'],
                'amount'      => $amount,
                'channel'     => (string)$booking['pay_channel'] !== '' ? (string)$booking['pay_channel'] : 'offline',
                'reason'      => clean_input($request->post('reason', ''), 255) !== '' ? clean_input($request->post('reason', ''), 255) : '售后退款',
                'operator'    => clean_input($request->post('operator', ''), 50),
                'status'      => '已退款',
                'refunded_at' => date('Y-m-d H:i:s'),
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
            Db::name('booking')->where('order_no', $orderNo)->update([
                'status'     => '已退款',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            // 活动库存回补（退款释放占位）
            if ((int)$booking['activity_id'] > 0) {
                (new MarketingService())->releaseStock((int)$booking['activity_id']);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return err(500, '退款失败，请重试');
        }
        (new AuditService())->log('order.refund', $orderNo, '售后退款 ' . $amount . ' 分（' . $refundNo . '）', (int)($request->adminId ?? 0));
        return ok(['order_no' => $orderNo, 'refund_no' => $refundNo, 'amount' => $amount, 'status' => '已退款'], '已退款，订单关闭');
    }

    /**
     * 订单详情（含加钟与打卡记录）
     * GET /api/v1/orders/{orderNo}
     */
    public function detail(Request $request, string $orderNo): Response
    {
        [$booking, $deny] = $this->guardFind($request, $orderNo);
        if ($deny) {
            return $deny;
        }
        $booking['extends'] = Db::name('order_extend')->where('order_no', $orderNo)->where('deleted_at', 0)->order('id asc')->select()->toArray();
        $booking['checkins'] = Db::name('order_checkin')->where('order_no', $orderNo)->where('deleted_at', 0)->order('id asc')->select()->toArray();
        // 定金尾款收款摘要（deposit_enabled=0 时 payable=actual、settled=true，前端可忽略）
        try {
            $booking['payment'] = (new DepositService())->summary((array)$booking);
        } catch (\Throwable $e) {
            $booking['payment'] = null;
        }
        return ok($booking);
    }

    // ==================================================================
    // 内部工具
    // ==================================================================

    /**
     * 退款单号（RD+yyyyMMdd+序号，schema 注释口径）
     * ⚠️ 不能用 max()：think-orm 对 varchar 列聚合返回 float 0.0（同 OrderService::nextOrderNo 坑），
     *    改用 order+value 取字符串
     * @return string
     */
    private function nextRefundNo(): string
    {
        $prefix = 'RD' . date('Ymd');
        $lastNo = (string)Db::name('refund')->where('refund_no', 'like', $prefix . '%')
            ->order('refund_no', 'desc')->value('refund_no');
        $seq = $lastNo !== '' ? ((int)substr($lastNo, -4)) + 1 : 1;
        return $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
    }

    /**
     * 按订单号取单
     * @param string $orderNo
     * @return array|null
     */
    private function mustFind(string $orderNo): ?array
    {
        return Db::name('booking')->where('order_no', clean_input($orderNo, 32))->where('deleted_at', 0)->find() ?: null;
    }

    /**
     * 候补递补（内部）：订单取消/爽约释放档期后，把该时段最早排队者置为「已补位」并推送通知。
     * 设计原则——候补是"增值动作"，任何异常（表缺失 / 通道关闭 / 通知失败）都不得影响取消主流程，
     * 因此整体 try/catch 吞异常；queue_enabled 关闭或无排队数据时 QueueService 内部天然空转。
     * @param array $booking 释放档期的订单快照（取 start_at / end_at / duration 推时段）
     * @return void
     */
    private function promoteQueue(array $booking): void
    {
        try {
            $queue = new QueueService();
            if (!$queue->enabled()) {
                return;
            }
            [$date, $slot] = $queue->slotOf($booking);
            if ($date === '' || $slot === '') {
                return;
            }
            $queue->promote($date, $slot);
        } catch (\Throwable $e) {
            // 静默：候补递补失败不影响订单取消/爽约
        }
    }
}
