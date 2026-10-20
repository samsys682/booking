<?php
// =====================================================================
// booking 后端 · 会员自助域（"我的"聚合 + 动作）
// 面向 user 端会员 token：所有数据只回本人（归属校验在控制器兜底），
// 通用资源接口（ResourceController）无归属过滤不能对会员放行，故独立成域。
// 响应直接输出用户端视图形状（camelCase + 金额「元」+ 展示串），
// 与 user 端 mock 兜底形状一致，前端双轨切换零映射。
// 写入动作带业务语义：主表 + 流水表同事务（coupon sent 统计 / coupon_log / member_card_log）。
// =====================================================================

namespace app\controller;

use app\service\AuditService;
use app\service\EditionGate;
use app\service\MarketingService;
use app\service\QueueService;
use think\facade\Db;
use think\Request;
use think\Response;

class MyController
{
    // ==================================================================
    // 会员卡：我的卡列表
    // GET /api/v1/my/cards
    // ==================================================================
    public function cards(Request $request): Response
    {
        if ($deny = $this->guardMember($request)) {
            return $deny;
        }
        $rows = Db::name('member_card')->where('member_id', $this->memberId($request))
            ->where('deleted_at', 0)->order('id', 'desc')->select()->toArray();
        return ok(['list' => array_map([$this, 'mapCard'], $rows)]);
    }

    // ==================================================================
    // 可购卡种（会员卡模板）：用户端「购买新卡」面板据此列出
    // GET /api/v1/my/card-offers  → {list:[{id,name,type,cycle,total,amount(元),days}]}
    // 事实源为后端 card_type 表（后台「可购卡种」维护），取代历史前端 mock
    // ==================================================================
    public function cardOffers(Request $request): Response
    {
        if ($deny = $this->guardMember($request)) {
            return $deny;
        }
        $rows = Db::name('card_type')->where('deleted_at', 0)->where('status', 1)
            ->order('sort asc, id asc')->select()->toArray();
        $list = array_map(static function ($r) {
            return [
                'id'     => (int)$r['id'],
                'name'   => (string)$r['name'],
                'type'   => (string)$r['type'],
                'cycle'  => (string)$r['cycle'],
                'total'  => (int)$r['total'],
                'amount' => fen_to_yuan((int)$r['amount']),
                'days'   => (int)$r['days'],
            ];
        }, $rows);
        return ok(['list' => $list]);
    }

    // ==================================================================
    // 会员卡：自助购卡（member_card + member_card_log「开卡」同事务）
    // POST /api/v1/my/cards/buy  {name?,type,cycle,total,amount(分),expires?,cross_store?}
    // ==================================================================
    public function cardBuy(Request $request): Response
    {
        if ($deny = $this->guardMember($request)) {
            return $deny;
        }
        if ($deny = $this->guardEdition('member-cards')) {
            return $deny;
        }
        $member = Db::name('member')->where('id', $this->memberId($request))->where('deleted_at', 0)->find() ?: null;
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
        // 安全红线：金额为 0 的卡可直接白得，自助购卡必须产生真实支付单
        if ($amount <= 0) {
            return err(400, '购卡金额必须大于 0');
        }
        $expires = clean_input($request->post('expires', ''), 10);
        if ($expires !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires)) {
            return err(400, '有效期格式应为 YYYY-MM-DD');
        }

        // 不再直接开卡：先建「购卡」待支付交易，开卡由支付回调/收款确认完成
        // （PayController::settleAuxTransaction，凭已验签/已确认交易入账）
        $now = date('Y-m-d H:i:s');
        $title = $name !== '' ? $name : ($type === '次卡' ? '次卡 ' . $total . ' 次' : '时长卡 ' . $total . ' 分钟');
        $bizNo = trade_no();
        try {
            Db::name('pay_transaction')->insert([
                'order_no'     => $bizNo,
                'out_trade_no' => $bizNo,
                'channel'      => 'offline',
                'kind'         => '购卡',
                'amount'       => $amount,
                'status'       => '待支付',
                'raw'          => json_encode([
                    'type'      => 'card',
                    'member_id' => (int)$member['id'],
                    'card'      => [
                        'name'        => $title,
                        'type'        => $type,
                        'cycle'       => $this->cleanCycle((string)$request->post('cycle', 'once')),
                        'total'       => $total,
                        'cross_store' => (int)$request->post('cross_store', 0) ? 1 : 0,
                        'expires'     => $expires,
                    ],
                ], JSON_UNESCAPED_UNICODE),
                'created_at'   => $now,
            ]);
        } catch (\Throwable $e) {
            return err(500, '购卡单创建失败，请重试');
        }
        (new AuditService())->log('member.card-buy', $bizNo, '会员「' . $member['name'] . '」创建购卡单 ' . $title . '（' . fen_to_yuan($amount) . ' 元）', 0);
        return ok([
            'out_trade_no' => $bizNo,
            'amount'       => $amount,
            'card_name'    => $title,
            'status'       => '待支付',
        ], '购卡单已创建，请完成支付');
    }

    // ==================================================================
    // 优惠券：我的券码实例（一券一码）
    // GET /api/v1/my/coupons
    // ==================================================================
    public function coupons(Request $request): Response
    {
        if ($deny = $this->guardMember($request)) {
            return $deny;
        }
        $rows = Db::name('coupon_instance')->where('member_id', $this->memberId($request))
            ->where('deleted_at', 0)->order('id', 'desc')->select()->toArray();
        // 拼券模板文案（value/threshold 在模板表；端上算抵扣与展示需要）
        $tplIds = array_values(array_unique(array_column($rows, 'coupon_id')));
        $tplMap = [];
        if ($tplIds) {
            foreach (Db::name('coupon')->where('deleted_at', 0)->whereIn('id', $tplIds)->select()->toArray() as $t) {
                $tplMap[(int)$t['id']] = $this->mapCouponTpl($t);
            }
        }
        $list = array_map(function ($r) use ($tplMap) {
            $m = $this->mapInstance($r);
            $t = $tplMap[(int)$r['coupon_id']] ?? null;
            if ($t) {
                $m['value'] = $t['value'];
                $m['threshold'] = $t['threshold'];
            }
            return $m;
        }, $rows);
        return ok(['list' => $list]);
    }

    // ==================================================================
    // 优惠券：可领取的券模板（发放中 + 未过期 + 有余量）
    // GET /api/v1/my/coupons/available
    // ==================================================================
    public function couponAvailable(Request $request): Response
    {
        if ($deny = $this->guardMember($request)) {
            return $deny;
        }
        $rows = Db::name('coupon')->where('status', '发放中')->where('deleted_at', 0)
            ->where(function ($q) {
                $q->where('expires', '>=', date('Y-m-d'))->whereOr('expires', '');
            })
            ->where('sent', '<', Db::raw('`total`'))
            ->order('id', 'desc')->select()->toArray();
        return ok(['list' => array_map([$this, 'mapCouponTpl'], $rows)]);
    }

    // ==================================================================
    // 优惠券：领取（coupon_instance + 模板 sent 统计 + coupon_log「领取」同事务）
    // 限领规则：同一券模板每人限领 1 张
    // POST /api/v1/my/coupons/{id}/claim
    // ==================================================================
    public function couponClaim(Request $request, int $id): Response
    {
        if ($deny = $this->guardMember($request)) {
            return $deny;
        }
        if ($deny = $this->guardEdition('coupons')) {
            return $deny;
        }
        $memberId = $this->memberId($request);
        $member = Db::name('member')->where('id', $memberId)->where('deleted_at', 0)->find() ?: null;
        if (!$member) {
            return err(404, '会员不存在');
        }
        $coupon = Db::name('coupon')->where('id', $id)->where('deleted_at', 0)->find() ?: null;
        if (!$coupon) {
            return err(404, '优惠券不存在');
        }
        if ((string)$coupon['status'] !== '发放中') {
            return err(400, '该券已停发（当前：' . $coupon['status'] . '）');
        }
        if ((string)$coupon['expires'] !== '' && (string)$coupon['expires'] < date('Y-m-d')) {
            return err(400, '该券已过有效期（' . $coupon['expires'] . '）');
        }
        $left = (int)$coupon['total'] - (int)$coupon['sent'];
        if ($left <= 0) {
            return err(400, '该券已被领完');
        }
        $claimed = Db::name('coupon_instance')->where('coupon_id', $id)->where('member_id', $memberId)
            ->where('deleted_at', 0)->count();
        if ($claimed > 0) {
            return err(400, '该券每人限领 1 张');
        }
        $now = date('Y-m-d H:i:s');
        $code = next_coupon_code();

        Db::startTrans();
        try {
            Db::name('coupon_instance')->insert([
                'code'        => $code,
                'coupon_id'   => (int)$coupon['id'],
                'coupon_name' => (string)$coupon['name'],
                'member_id'   => (int)$member['id'],
                'member'      => (string)$member['name'],
                'phone'       => (string)$member['phone'],
                'source'      => '用户领取',
                'at'          => $now,
                'status'      => '未使用',
                'order_no'    => '',
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
            Db::name('coupon')->where('id', $id)->update([
                'sent'       => (int)$coupon['sent'] + 1,
                'updated_at' => $now,
            ]);
            Db::name('coupon_log')->insert([
                'coupon_id'  => (int)$coupon['id'],
                'coupon'     => (string)$coupon['name'],
                'action'     => '领取',
                'count'      => 1,
                'detail'     => '「' . $member['name'] . '」用户端自助领取 1 张',
                'at'         => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return err(500, '领取失败，请重试');
        }
        (new AuditService())->log('member.coupon-claim', $code, '会员「' . $member['name'] . '」领取「' . $coupon['name'] . '」', 0);
        $row = Db::name('coupon_instance')->where('code', $code)->find() ?: [];
        return ok($this->mapInstance($row), '领取成功');
    }

    // ==================================================================
    // 积分：我的积分（余额 = 流水末条 balance）+ 流水
    // GET /api/v1/my/points
    // ==================================================================
    public function points(Request $request): Response
    {
        if ($deny = $this->guardMember($request)) {
            return $deny;
        }
        $memberId = $this->memberId($request);
        $rows = Db::name('point_log')->where('member_id', $memberId)
            ->order('id', 'desc')->limit(100)->select()->toArray();
        $balance = count($rows) > 0 ? (int)$rows[0]['balance'] : 0;
        return ok([
            'balance' => $balance,
            'logs'    => array_map([$this, 'mapPointLog'], $rows),
        ]);
    }

    // ==================================================================
    // 储值：我的储值聚合（账户 + 充值套餐 + 流水，一次请求整页数据）
    // GET /api/v1/my/stored
    // ==================================================================
    public function stored(Request $request): Response
    {
        if ($deny = $this->guardMember($request)) {
            return $deny;
        }
        $account = Db::name('stored_account')->where('member_id', $this->memberId($request))
            ->where('deleted_at', 0)->find() ?: null;
        if (!$account) {
            // 首次访问懒建档（uk_member 唯一键兜底并发，充值/购卡链路依赖账户存在）
            $mid = $this->memberId($request);
            $member = Db::name('member')->where('id', $mid)->find() ?: [];
            $now = date('Y-m-d H:i:s');
            Db::name('stored_account')->insert([
                'account_no' => 'SV' . str_pad((string)$mid, 6, '0', STR_PAD_LEFT),
                'member_id'  => $mid,
                'member'     => (string)($member['name'] ?? '') !== '' ? (string)$member['name'] : (string)($member['phone'] ?? ''),
                'phone'      => (string)($member['phone'] ?? ''),
                'level'      => '普通会员',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $account = Db::name('stored_account')->where('member_id', $mid)
                ->where('deleted_at', 0)->find() ?: null;
        }
        $plans = Db::name('recharge_plan')->where('status', 1)->where('deleted_at', 0)
            ->order('sort', 'asc')->select()->toArray();
        $logs = [];
        if ($account) {
            $logs = Db::name('stored_log')->where('account_id', (int)$account['id'])
                ->order('id', 'desc')->limit(100)->select()->toArray();
        }
        return ok([
            'account' => $account ? $this->mapStoredAccount($account) : null,
            'plans'   => array_map([$this, 'mapPlan'], $plans),
            'logs'    => array_map([$this, 'mapStoredLog'], $logs),
        ]);
    }

    // ==================================================================
    // 地址：我的地址列表
    // GET /api/v1/my/addresses
    // ==================================================================
    public function addresses(Request $request): Response
    {
        if ($deny = $this->guardMember($request)) {
            return $deny;
        }
        $rows = Db::name('member_address')->where('member_id', $this->memberId($request))
            ->where('deleted_at', 0)->order('is_default', 'desc')->order('id', 'desc')
            ->select()->toArray();
        return ok(['list' => array_map([$this, 'mapAddress'], $rows)]);
    }

    // ==================================================================
    // 地址：新增（member_id 强制本人；默认地址唯一化）
    // POST /api/v1/my/addresses  {name,phone,address,isDefault?}
    // ==================================================================
    public function addressCreate(Request $request): Response
    {
        if ($deny = $this->guardMember($request)) {
            return $deny;
        }
        $data = $this->addressData($request);
        if (isset($data['err'])) {
            return $data['err'];
        }
        $memberId = $this->memberId($request);
        $now = date('Y-m-d H:i:s');

        Db::startTrans();
        try {
            if ($data['is_default']) {
                Db::name('member_address')->where('member_id', $memberId)->where('deleted_at', 0)
                    ->update(['is_default' => 0, 'updated_at' => $now]);
            }
            Db::name('member_address')->insert([
                'member_id'   => $memberId,
                'contact'     => $data['contact'],
                'phone'       => $data['phone'],
                'address'     => $data['address'],
                'lng'         => 0,
                'lat'         => 0,
                'is_default'  => $data['is_default'],
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
            $addrId = (int)Db::name('member_address')->getLastInsID();
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return err(500, '保存失败，请重试');
        }
        (new AuditService())->log('member.address-create', (string)$addrId, '会员新增地址「' . $data['address'] . '」', 0);
        $row = Db::name('member_address')->where('id', $addrId)->find() ?: [];
        return ok($this->mapAddress($row), '地址已保存');
    }

    // ==================================================================
    // 地址：修改（归属校验 + 默认地址唯一化）
    // PUT /api/v1/my/addresses/{id}  {name,phone,address,isDefault?}
    // ==================================================================
    public function addressUpdate(Request $request, int $id): Response
    {
        if ($deny = $this->guardMember($request)) {
            return $deny;
        }
        $row = Db::name('member_address')->where('id', $id)->where('deleted_at', 0)->find() ?: null;
        if (!$row) {
            return err(404, '地址不存在');
        }
        if ($deny = $this->guardOwner($request, (int)$row['member_id'])) {
            return $deny;
        }
        $data = $this->addressData($request);
        if (isset($data['err'])) {
            return $data['err'];
        }
        $memberId = $this->memberId($request);
        $now = date('Y-m-d H:i:s');

        Db::startTrans();
        try {
            if ($data['is_default']) {
                Db::name('member_address')->where('member_id', $memberId)->where('id', '<>', $id)
                    ->where('deleted_at', 0)->update(['is_default' => 0, 'updated_at' => $now]);
            }
            Db::name('member_address')->where('id', $id)->update([
                'contact'    => $data['contact'],
                'phone'      => $data['phone'],
                'address'    => $data['address'],
                'is_default' => $data['is_default'],
                'updated_at' => $now,
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return err(500, '保存失败，请重试');
        }
        (new AuditService())->log('member.address-update', (string)$id, '会员修改地址「' . $data['address'] . '」', 0);
        $fresh = Db::name('member_address')->where('id', $id)->find() ?: [];
        return ok($this->mapAddress($fresh), '地址已保存');
    }

    // ==================================================================
    // 地址：删除（软删，归属校验）
    // DELETE /api/v1/my/addresses/{id}
    // ==================================================================
    public function addressDelete(Request $request, int $id): Response
    {
        if ($deny = $this->guardMember($request)) {
            return $deny;
        }
        $row = Db::name('member_address')->where('id', $id)->where('deleted_at', 0)->find() ?: null;
        if (!$row) {
            return err(404, '地址不存在');
        }
        if ($deny = $this->guardOwner($request, (int)$row['member_id'])) {
            return $deny;
        }
        Db::name('member_address')->where('id', $id)->update([
            'deleted_at' => time(),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        (new AuditService())->log('member.address-delete', (string)$id, '会员删除地址「' . (string)$row['address'] . '」', 0);
        return ok([], '地址已删除');
    }

    // ==================================================================
    // 候补排队：我的候补记录
    // GET /api/v1/my/queue
    // ==================================================================
    public function queueList(Request $request): Response
    {
        if ($deny = $this->guardMember($request)) {
            return $deny;
        }
        $rows = Db::name('booking_queue')->where('member_id', $this->memberId($request))
            ->where('deleted_at', 0)->order('date desc, seq asc')->limit(100)
            ->select()->toArray();
        return ok([
            'enabled' => (new QueueService())->enabled(),
            'list'    => array_map([$this, 'mapQueue'], $rows),
        ]);
    }

    // ==================================================================
    // 候补排队：加入候补（会员身份从 token 取，姓名/电话回填会员资料）
    // POST /api/v1/my/queue  {date, timeSlot?, serviceId?, service?, note?}
    // ==================================================================
    public function queueJoin(Request $request): Response
    {
        if ($deny = $this->guardMember($request)) {
            return $deny;
        }
        if ($deny = $this->guardEdition('booking-queues')) {
            return $deny;
        }
        $memberId = $this->memberId($request);
        $member = Db::name('member')->where('id', $memberId)->where('deleted_at', 0)->find() ?: [];
        try {
            $row = (new QueueService())->join([
                'member_id'  => $memberId,
                'customer'   => (string)($member['name'] ?? ''),
                'phone'      => (string)($member['phone'] ?? ''),
                'service_id' => (int)$request->post('serviceId', 0),
                'service'    => (string)$request->post('service', ''),
                'date'       => (string)$request->post('date', ''),
                'time_slot'  => (string)$request->post('timeSlot', ''),
                'note'       => (string)$request->post('note', ''),
            ]);
        } catch (\RuntimeException $e) {
            return err(400, $e->getMessage());
        }
        (new AuditService())->log('member.queue-join', (string)($row['id'] ?? ''), '会员加入候补（' . ($row['date'] ?? '') . ' ' . ($row['time_slot'] ?? '') . ' 第 ' . ($row['seq'] ?? '') . ' 位）', 0);
        return ok($this->mapQueue($row), '已加入候补队列，第 ' . (int)($row['seq'] ?? 0) . ' 位');
    }

    // ==================================================================
    // 候补排队：取消我的候补（软删，归属校验）
    // DELETE /api/v1/my/queue/{id}
    // ==================================================================
    public function queueCancel(Request $request, int $id): Response
    {
        if ($deny = $this->guardMember($request)) {
            return $deny;
        }
        if ($deny = $this->guardEdition('booking-queues')) {
            return $deny;
        }
        $row = Db::name('booking_queue')->where('id', $id)->where('deleted_at', 0)->find() ?: null;
        if (!$row) {
            return err(404, '候补记录不存在');
        }
        if ($deny = $this->guardOwner($request, (int)$row['member_id'])) {
            return $deny;
        }
        if ((string)$row['status'] !== QueueService::STATUS_WAITING) {
            return err(400, '仅「排队中」的候补可以取消');
        }
        Db::name('booking_queue')->where('id', $id)->update([
            'status'     => QueueService::STATUS_CANCELLED,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        (new AuditService())->log('member.queue-cancel', (string)$id, '会员取消候补', 0);
        return ok([], '已取消候补');
    }

    /**
     * 候补行 → 用户端视图（camelCase）
     */
    private function mapQueue(array $r): array
    {
        return [
            'id'        => (int)($r['id'] ?? 0),
            'customer'  => (string)($r['customer'] ?? ''),
            'phone'     => (string)($r['phone'] ?? ''),
            'serviceId' => (int)($r['service_id'] ?? 0),
            'service'   => (string)($r['service'] ?? ''),
            'date'      => (string)($r['date'] ?? ''),
            'timeSlot'  => (string)($r['time_slot'] ?? ''),
            'seq'       => (int)($r['seq'] ?? 0),
            'status'    => (string)($r['status'] ?? ''),
            'note'      => (string)($r['note'] ?? ''),
            'createdAt' => (string)($r['created_at'] ?? ''),
        ];
    }

    // ==================================================================
    // 内部工具
    // ==================================================================

    /**
     * 会员端守卫：my 域仅会员本人可用（admin/tech 一律拒绝，各自走管理端接口）
     * @return Response|null
     */
    private function guardMember(Request $request): ?Response
    {
        if ((string)($request->tokenType ?? '') !== 'member') {
            return err(403, '请先登录会员账号', [], 403);
        }
        if ($this->memberId($request) <= 0) {
            return err(403, '会员身份无效', [], 403);
        }
        return null;
    }

    /**
     * 归属守卫（路由 {id} 资源必须属于当前会员）
     * @return Response|null
     */
    private function guardOwner(Request $request, int $ownerMemberId): ?Response
    {
        if ($ownerMemberId !== $this->memberId($request)) {
            return err(403, '无权操作他人账户', [], 403);
        }
        return null;
    }

    /**
     * 版本门禁（会员自助写入动作与资源 CRUD 同口径：读放行、写拦截）
     * 会员卡/储值/优惠券/积分/发票/候补/兑换等均为付费能力，
     * 开源版可浏览入口（GET 放行），但写入动作一律 403 + upgrade 载荷。
     * @param string $resource resources.php 资源键（对应 EditionGate::RESOURCE_MIN_EDITION）
     * @return Response|null
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
     * 当前登录会员 ID
     */
    private function memberId(Request $request): int
    {
        return (int)($request->memberId ?? 0);
    }

    /**
     * 地址表单校验与列名映射（视图 name/phone/address → 列 contact/phone/address）
     * @return array 正常：['contact','phone','address','is_default']；异常：['err'=>Response]
     */
    private function addressData(Request $request): array
    {
        $contact = clean_input($request->post('name', ''), 30);
        if ($contact === '') {
            return ['err' => err(400, '联系人不能为空')];
        }
        $phone = clean_input($request->post('phone', ''), 20);
        if ($phone === '') {
            return ['err' => err(400, '手机号不能为空')];
        }
        $address = clean_input($request->post('address', ''), 255);
        if ($address === '') {
            return ['err' => err(400, '详细地址不能为空')];
        }
        return [
            'contact'    => $contact,
            'phone'      => $phone,
            'address'    => $address,
            'is_default' => (int)$request->post('isDefault', 0) ? 1 : 0,
        ];
    }

    /**
     * 会员卡行 → 视图形状（amount 分→元；name→card）
     */
    private function mapCard(array $r): array
    {
        return [
            'id'          => (int)$r['id'],
            'cardNo'      => (string)$r['card_no'],
            'member'      => (string)$r['member'],
            'phone'       => (string)$r['phone'],
            'card'        => (string)$r['name'],
            'type'        => (string)$r['type'],
            'cycle'       => (string)$r['cycle'],
            'total'       => (int)$r['total'],
            'used'        => (int)$r['used'],
            'amount'      => (float)fen_to_yuan((int)$r['amount']),
            'crossStore'  => (int)$r['cross_store'],
            'expires'     => (string)($r['expires'] ?? ''),
            'status'      => (string)$r['status'],
        ];
    }

    /**
     * 券码实例行 → 视图形状
     */
    private function mapInstance(array $r): array
    {
        return [
            'code'       => (string)$r['code'],
            'couponId'   => (int)$r['coupon_id'],
            'couponName' => (string)$r['coupon_name'],
            'member'     => (string)$r['member'],
            'phone'      => (string)$r['phone'],
            'source'     => (string)$r['source'],
            'at'         => (string)$r['at'],
            'status'     => (string)$r['status'],
            'usedAt'     => (string)($r['used_at'] ?? ''),
            'orderNo'    => (string)($r['order_no'] ?? ''),
        ];
    }

    /**
     * 券模板行 → 视图形状（value/threshold 数值分 → 展示串）
     */
    private function mapCouponTpl(array $r): array
    {
        $type = (string)$r['type'];
        $value = (int)$r['value'];
        $threshold = (int)$r['threshold'];
        if ($type === '折扣') {
            $valueText = rtrim(rtrim(number_format($value / 10, 1, '.', ''), '0'), '.') . ' 折';
        } else {
            $valueText = '减 ¥' . fen_to_yuan($value);
        }
        return [
            'id'        => (int)$r['id'],
            'code'      => (string)$r['code'],
            'name'      => (string)$r['name'],
            'type'      => $type,
            'value'     => $valueText,
            'threshold' => $threshold > 0 ? '满 ' . fen_to_yuan($threshold) . ' 可用' : '不限',
            'total'     => (int)$r['total'],
            'sent'      => (int)$r['sent'],
            'used'      => (int)$r['used'],
            'expires'   => (string)($r['expires'] ?? ''),
            'status'    => (string)$r['status'],
        ];
    }

    /**
     * 积分流水行 → 视图形状
     */
    private function mapPointLog(array $r): array
    {
        return [
            'at'      => (string)$r['at'],
            'member'  => (string)$r['member'],
            'phone'   => (string)$r['phone'],
            'action'  => (string)$r['action'],
            'points'  => (int)$r['points'],
            'balance' => (int)$r['balance'],
            'detail'  => (string)$r['detail'],
        ];
    }

    // ==================================================================
    // 积分商城：礼品兑换（库存/积分双重校验，写兑换单，流水守恒，同事务）
    // POST /api/v1/my/gifts/{id}/redeem
    // ==================================================================
    public function giftRedeem(Request $request, int $id): Response
    {
        if ($deny = $this->guardMember($request)) {
            return $deny;
        }
        if ($deny = $this->guardEdition('gift-exchanges')) {
            return $deny;
        }
        $memberId = $this->memberId($request);
        $gift = Db::name('gift')->where('id', $id)->where('deleted_at', 0)->find();
        if (!$gift || (int)$gift['status'] !== 1) {
            return err(404, '礼品不存在或已下架');
        }
        if ((int)$gift['stock'] <= 0) {
            return err(400, '礼品库存不足');
        }
        // 兑换截止（end_at NULL=长期）
        if (!empty($gift['end_at']) && $gift['end_at'] < date('Y-m-d H:i:s')) {
            return err(400, '该礼品兑换已截止');
        }
        // 每人限兑（0=不限）：以兑换单计数（已取消不计入），口径优于流水行数
        $limit = (int)($gift['limit_per_user'] ?? 0);
        if ($limit > 0) {
            $mine = Db::name('gift_exchange')->where('member_id', $memberId)->where('gift_id', $id)
                ->where('deleted_at', 0)->where('status', '<>', '已取消')->count();
            if ($mine >= $limit) {
                return err(400, '该礼品每人限兑 ' . $limit . ' 件，您已兑换');
            }
        }
        $last = Db::name('point_log')->where('member_id', $memberId)->order('id', 'desc')->value('balance');
        $balance = (int)($last ?: 0);
        $cost = (int)$gift['points'];
        if ($balance < $cost) {
            return err(400, '积分不足（需 ' . $cost . '，当前 ' . $balance . '）');
        }
        $member = Db::name('member')->where('id', $memberId)->find();
        // 收货信息：请求优先，缺省取会员默认地址兜底（到店自提可留空地址）
        $contact = trim((string)$request->post('contact', ''));
        $contactPhone = trim((string)$request->post('contact_phone', ''));
        $address = trim((string)$request->post('address', ''));
        if ($contact === '' || $contactPhone === '') {
            $addr = Db::name('member_address')->where('member_id', $memberId)->where('deleted_at', 0)
                ->order('is_default desc, id desc')->find();
            if ($addr) {
                $contact = $contact !== '' ? $contact : (string)$addr['contact'];
                $contactPhone = $contactPhone !== '' ? $contactPhone : (string)$addr['phone'];
                $address = $address !== '' ? $address : (string)$addr['address'];
            }
        }
        if ($contact === '' || $contactPhone === '') {
            return err(400, '请填写收货人姓名与联系电话');
        }
        $qty = max(1, (int)$request->post('qty', 1));
        $exchangeNo = $this->genExchangeNo();
        $now = date('Y-m-d H:i:s');
        Db::startTrans();
        try {
            Db::name('gift')->where('id', $id)->where('stock', '>', 0)->dec('stock', $qty)->inc('exchanged', $qty)->update([]);
            Db::name('point_log')->insert([
                'member_id'  => $memberId,
                'member'     => (string)$member['name'],
                'phone'      => (string)$member['phone'],
                'action'     => '兑换',
                'points'     => -$cost,
                'balance'    => $balance - $cost,
                'order_no'   => $exchangeNo,
                'detail'     => '积分商城兑换：' . $gift['name'],
                'at'         => $now,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => 0,
            ]);
            $exchangeId = Db::name('gift_exchange')->insertGetId([
                'exchange_no'   => $exchangeNo,
                'member_id'     => $memberId,
                'member'        => (string)$member['name'],
                'phone'         => (string)$member['phone'],
                'gift_id'       => $id,
                'gift_name'     => (string)$gift['name'],
                'points'        => $cost,
                'qty'           => $qty,
                'contact'       => mb_substr($contact, 0, 50),
                'contact_phone' => mb_substr($contactPhone, 0, 20),
                'address'       => mb_substr($address, 0, 255),
                'status'        => '待发货',
                'express_no'    => '',
                'remark'        => '',
                'at'            => $now,
                'created_at'    => $now,
                'updated_at'    => $now,
                'deleted_at'    => 0,
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return err(500, '兑换失败，请重试');
        }
        (new AuditService())->log('gift.redeem', 'gift#' . $id, $member['name'] . ' 兑换 ' . $gift['name'] . '（-' . $cost . ' 积分，单号 ' . $exchangeNo . '）', 0);
        return ok([
            'exchangeId' => (int)$exchangeId,
            'exchangeNo' => $exchangeNo,
            'gift'       => (string)$gift['name'],
            'cost'       => $cost,
            'balance'    => $balance - $cost,
            'status'     => '待发货',
        ], '兑换成功，商家发货后可查看物流');
    }

    /** 兑换单号：GE + yyyyMMdd + 4 位序号（同前缀自增，遇占用顺延） */
    private function genExchangeNo(): string
    {
        $prefix = 'GE' . date('Ymd');
        $last = Db::name('gift_exchange')->whereLike('exchange_no', $prefix . '%')->order('exchange_no', 'desc')->value('exchange_no');
        $seq = $last ? ((int)substr((string)$last, strlen($prefix)) + 1) : 1;
        for ($i = 0; $i < 100; $i++) {
            $no = $prefix . str_pad((string)($seq + $i), 4, '0', STR_PAD_LEFT);
            if (Db::name('gift_exchange')->where('exchange_no', $no)->where('deleted_at', 0)->count() === 0) {
                return $no;
            }
        }
        return $prefix . str_pad((string)random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
    }

    // ==================================================================
    // 积分商城：我的兑换记录
    // GET /api/v1/my/gift-exchanges
    // ==================================================================
    public function giftExchanges(Request $request): Response
    {
        if ($deny = $this->guardMember($request)) {
            return $deny;
        }
        $memberId = $this->memberId($request);
        $rows = Db::name('gift_exchange')->where('member_id', $memberId)->where('deleted_at', 0)
            ->order('id', 'desc')->limit(100)->select()->toArray();
        return ok(['list' => array_map(static function ($r) {
            return [
                'id'           => (int)$r['id'],
                'exchangeNo'   => (string)$r['exchange_no'],
                'giftName'     => (string)$r['gift_name'],
                'points'       => (int)$r['points'],
                'qty'          => (int)$r['qty'],
                'contact'      => (string)$r['contact'],
                'contactPhone' => (string)$r['contact_phone'],
                'address'      => (string)$r['address'],
                'status'       => (string)$r['status'],
                'expressNo'    => (string)$r['express_no'],
                'remark'       => (string)$r['remark'],
                'at'           => (string)$r['at'],
            ];
        }, $rows)]);
    }

    // ==================================================================
    // 发票：我的开票记录 + 申请开票（金额取订单实付，服务端核算防篡改）
    // GET  /api/v1/my/invoices
    // POST /api/v1/my/invoices
    // ==================================================================
    public function invoices(Request $request): Response
    {
        if ($deny = $this->guardMember($request)) {
            return $deny;
        }
        $memberId = $this->memberId($request);
        $rows = Db::name('invoice')->where('member_id', $memberId)->where('deleted_at', 0)
            ->order('id', 'desc')->limit(100)->select()->toArray();
        return ok(['list' => array_map([$this, 'mapInvoice'], $rows)]);
    }

    public function invoiceApply(Request $request): Response
    {
        if ($deny = $this->guardMember($request)) {
            return $deny;
        }
        if ($deny = $this->guardEdition('invoices')) {
            return $deny;
        }
        $memberId  = $this->memberId($request);
        $orderNo   = trim((string)$request->post('order_no', ''));
        $type      = (string)$request->post('type', '电子普票');
        $titleType = (string)$request->post('title_type', '个人');
        $title     = trim((string)$request->post('title', ''));
        $taxNo     = trim((string)$request->post('tax_no', ''));

        if ($orderNo === '') {
            return err(400, '请选择要开票的订单');
        }
        if (!in_array($type, ['电子普票', '电子专票'], true)) {
            return err(400, '不支持的发票类型');
        }
        if (!in_array($titleType, ['个人', '企业'], true)) {
            return err(400, '抬头类型仅支持个人 / 企业');
        }
        if ($title === '') {
            return err(400, '请填写发票抬头');
        }
        if ($titleType === '企业' && $taxNo === '') {
            return err(400, '企业抬头必须填写税号');
        }

        $order = Db::name('booking')->where('order_no', $orderNo)->where('deleted_at', 0)->find();
        if (!$order) {
            return err(404, '订单不存在');
        }
        if ((int)$order['member_id'] !== $memberId) {
            return err(403, '无权为该订单开票', [], 403);
        }
        // 仅已完成订单可开票（待评价/已评价均属已完成后续态）
        if (!in_array((string)$order['status'], ['已完成', '待评价', '已评价'], true)) {
            return err(400, '订单完成后才能申请开票，当前状态：' . $order['status']);
        }
        // 防重复开票：同一订单已存在「开具中 / 已开具」记录
        $dup = Db::name('invoice')->where('order_no', $orderNo)->where('deleted_at', 0)
            ->whereIn('status', ['开具中', '已开具'])->count();
        if ($dup > 0) {
            return err(400, '该订单已提交开票申请，请勿重复提交');
        }

        $member = Db::name('member')->where('id', $memberId)->find();
        $now = date('Y-m-d H:i:s');
        $id = Db::name('invoice')->insertGetId([
            'order_no'      => $orderNo,
            'member_id'     => $memberId,
            'member'        => (string)($order['customer'] ?: ($member['name'] ?? '')),
            'type'          => $type,
            'title_type'    => $titleType,
            'title'         => mb_substr($title, 0, 100),
            'tax_no'        => mb_substr($taxNo, 0, 50),
            'amount'        => (int)$order['actual'], // 金额服务端取订单实付，忽略前端传值
            'status'        => '开具中',
            'invoice_no'    => '',
            'reject_reason' => '',
            'created_at'    => $now,
            'updated_at'    => $now,
            'deleted_at'    => 0,
        ]);
        (new AuditService())->log('invoice.apply', 'invoice#' . $id, $member['name'] . ' 申请开票（订单 ' . $orderNo . '，' . $type . '，' . fen_to_yuan((int)$order['actual']) . ' 元）', 0);
        return ok(['id' => $id, 'amount' => fen_to_yuan((int)$order['actual'])], '开票申请已提交，商家开具后可查看发票号');
    }

    /** 发票行 → 会员视图（金额分→元） */
    private function mapInvoice(array $r): array
    {
        return [
            'id'         => (int)$r['id'],
            'order'      => (string)$r['order_no'],
            'type'       => (string)$r['type'],
            'titleType'  => (string)$r['title_type'],
            'title'      => (string)$r['title'],
            'taxNo'      => (string)$r['tax_no'],
            'amount'     => fen_to_yuan((int)$r['amount']),
            'status'     => (string)$r['status'],
            'invoiceNo'  => (string)$r['invoice_no'],
            'reason'     => (string)$r['reject_reason'],
            'createdAt'  => (string)$r['created_at'],
        ];
    }

    // ==================================================================
    // 推荐有礼：我的邀请码（会员 ID 即邀请码，绑定关系在登录/注册时写入）
    // GET /api/v1/my/invite
    // ==================================================================
    public function invite(Request $request): Response
    {
        if ($deny = $this->guardMember($request)) {
            return $deny;
        }
        $memberId = $this->memberId($request);
        $member = Db::name('member')->where('id', $memberId)->find();
        $invited = Db::name('member')->where('inviter_id', $memberId)->where('deleted_at', 0)->count();
        $rule = Db::name('referral_rule')->where('deleted_at', 0)->where('status', 1)->order('id asc')->find();
        return ok([
            'inviteCode' => 'M' . str_pad((string)$memberId, 5, '0', STR_PAD_LEFT),
            'invited'    => $invited,
            'inviterReward' => $rule ? (int)$rule['inviter_reward'] : 0,
            'inviteeReward' => $rule ? (int)$rule['invitee_reward'] : 0,
            'condition'  => $rule ? (string)$rule['condition'] : '',
        ]);
    }

    // ==================================================================
    // 评价有礼：当前启用规则的展示文案（user 端评价弹层提示用）
    // GET /api/v1/my/review-reward
    // ==================================================================
    public function reviewReward(Request $request): Response
    {
        if ($deny = $this->guardMember($request)) {
            return $deny;
        }
        // 启用规则中取第一条含积分奖励的（返券类不向会员承诺积分）；无则默认文案
        $rules = Db::name('review_reward')->where('deleted_at', 0)->where('status', 1)->order('id asc')->select()->toArray();
        $rule = null;
        $points = 0;
        foreach ($rules as $r) {
            if (preg_match('/(\d+)\s*积分/', (string)$r['reward'], $m)) {
                $rule = $r;
                $points = (int)$m[1];
                break;
            }
        }
        if (!$rule) {
            return ok(['rewardText' => '评价将随订单展示，感谢您的反馈', 'points' => 0]);
        }
        $text = '评价本单可得 ' . $points . ' 积分（' . $rule['name'] . '），积分可到「我的积分」兑换礼品';
        return ok(['rewardText' => $text, 'points' => $points]);
    }

    // ==================================================================
    // 营销活动：进行中活动列表（user 端首页展示 + 下单可选）
    // GET /api/v1/my/activities?service_id=0
    // ==================================================================
    public function activities(Request $request): Response
    {
        if ($deny = $this->guardMember($request)) {
            return $deny;
        }
        $serviceId = (int)$request->get('service_id', 0);
        $list = (new MarketingService())->activities($serviceId);
        return ok([
            'list' => array_map(function ($r) {
                return [
                    'id'          => (int)$r['id'],
                    'name'        => (string)$r['name'],
                    'kind'        => (string)$r['kind'],
                    'target'      => (string)$r['target'],
                    'serviceId'   => (int)$r['service_id'],
                    'price'       => number_format((int)$r['price'] / 100, 2),
                    'originPrice' => number_format((int)$r['origin_price'] / 100, 2),
                    'stockLeft'   => (int)$r['stock_left'],
                    'perLimit'    => (int)($r['per_limit'] ?? 0),
                    'groupSize'   => (int)$r['group_size'],
                    'startAt'     => (string)$r['start_at'],
                    'endAt'       => (string)$r['end_at'],
                ];
            }, $list),
        ]);
    }

    // ==================================================================
    // 拼团：进行中团单列表（user 端参团入口）
    // GET /api/v1/my/groups?activity_id=0
    // ==================================================================
    public function groups(Request $request): Response
    {
        if ($deny = $this->guardMember($request)) {
            return $deny;
        }
        $activityId = (int)$request->get('activity_id', 0);
        // status：拼团中（默认，参团入口用）/ 已成团 / 已失败 / all（全部）
        $status = clean_input($request->get('status', '拼团中'), 20);
        $query = Db::name('marketing_group')->where('deleted_at', 0);
        if ($status !== 'all') {
            $query->where('status', $status !== '' ? $status : '拼团中');
        }
        if ($activityId > 0) {
            $query->where('activity_id', $activityId);
        }
        $rows = $query->order('id', 'desc')->select()->toArray();
        return ok([
            'list' => array_map(function ($r) {
                return [
                    'id'         => (int)$r['id'],
                    'groupNo'    => (string)$r['group_no'],
                    'activityId' => (int)$r['activity_id'],
                    'activityName' => (string)$r['activity_name'],
                    'leaderName' => (string)$r['leader_name'],
                    'size'       => (int)$r['size'],
                    'joined'     => (int)$r['joined'],
                    'status'     => (string)$r['status'],
                    'expireAt'   => (string)$r['expire_at'],
                ];
            }, $rows),
        ]);
    }

    /**
     * 储值账户行 → 视图形状（金额分→元）
     */
    private function mapStoredAccount(array $r): array
    {
        return [
            'id'             => (int)$r['id'],
            'accountNo'      => (string)$r['account_no'],
            'member'         => (string)$r['member'],
            'phone'          => (string)$r['phone'],
            'level'          => (string)$r['level'],
            'balance'        => (float)fen_to_yuan((int)$r['balance']),
            'totalRecharge'  => (float)fen_to_yuan((int)$r['total_recharge']),
            'totalConsume'   => (float)fen_to_yuan((int)$r['total_consume']),
        ];
    }

    /**
     * 充值套餐行 → 视图形状（金额分→元）
     */
    private function mapPlan(array $r): array
    {
        return [
            'id'    => (int)$r['id'],
            'money' => (float)fen_to_yuan((int)$r['money']),
            'gift'  => (float)fen_to_yuan((int)$r['gift']),
        ];
    }

    /**
     * 储值流水行 → 视图形状（金额分→元；account_no→account）
     */
    private function mapStoredLog(array $r): array
    {
        return [
            'at'      => (string)$r['at'],
            'account' => (string)$r['account_no'],
            'action'  => (string)$r['action'],
            'amount'  => (float)fen_to_yuan((int)$r['amount']),
            'balance' => (float)fen_to_yuan((int)$r['balance']),
            'detail'  => (string)$r['detail'],
        ];
    }

    /**
     * 地址行 → 视图形状（contact→name；is_default→isDefault）
     */
    private function mapAddress(array $r): array
    {
        return [
            'id'        => (int)$r['id'],
            'name'      => (string)$r['contact'],
            'phone'     => (string)$r['phone'],
            'address'   => (string)$r['address'],
            'isDefault' => (int)$r['is_default'] === 1,
        ];
    }

    /**
     * 卡周期白名单
     */
    private function cleanCycle(string $cycle): string
    {
        return in_array($cycle, ['once', 'monthly', 'quarterly', 'yearly'], true) ? $cycle : 'once';
    }
}
