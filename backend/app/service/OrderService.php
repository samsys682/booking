<?php
// =====================================================================
// booking 后端 · 订单领域服务（状态机/计价/冲突校验/单号）
// 状态机（禁跳节点、禁终态回退；口径=业务错位整改方案 3.2，镜像前端 statusFlow.js）：
//   主流程：待支付 → 待派单 → 待接单 → 待服务 → 服务中 → 待确认 → 已完成 → 待评价 → 已评价
//   逆向：已取消（各阶段按预约规则）；售后中 → 已退款（审核驳回回原状态）
//   指定技师下单：待支付 → 待接单（跳过待派单）；拒单：待接单 → 回待派单
//   服务中不允许直接取消，纠纷转售后；已完成/已取消/已退款之外无终态回退
//   超期自动流转（待确认→已完成、已完成→待评价、待评价→已评价）由任务层执行
//   统计口径：「已完成订单」= 已完成 + 待评价 + 已评价 三态合计
// =====================================================================

namespace app\service;

use app\service\AuditService;
use think\facade\Db;

class OrderService
{
    /** 状态 → 可流转目标（白名单，其余一律拒绝；镜像前端 statusFlow.js::TRANSITIONS） */
    const TRANSITIONS = [
        '待支付' => ['待派单', '待接单', '已取消'],
        '待派单' => ['待接单', '已取消'],
        '待接单' => ['待服务', '待派单', '已取消'],
        '待服务' => ['服务中', '已取消'],
        '服务中' => ['待确认', '售后中'],
        '待确认' => ['已完成'],
        '已完成' => ['待评价', '售后中'],
        '待评价' => ['已评价', '售后中'],
        '已评价' => ['售后中'],
        '售后中' => ['已退款', '服务中', '待确认', '已完成', '待评价', '已评价'],
        '已取消' => [],
        '已退款' => [],
    ];

    /** 计费单位：分钟（服务价按时长比例折算） */
    const PRICE_UNIT_MINUTES = 60;

    /**
     * 生成订单号：BK + yyyyMMdd + 4 位序号（同日递增）
     * @return string
     */
    public function nextOrderNo(): string
    {
        $prefix = 'BK' . date('Ymd');
        // 取当日最大单号递增（count 会在软删后撞号，解析序号 + 唯一索引兜底）
        // ⚠️ 不能用 max('order_no')：think-orm 对 varchar 列聚合返回 float 0.0，
        //    导致每次生成同一单号撞 uk_order_no；改用 order+value 取字符串
        $lastNo = (string)Db::name('booking')->where('order_no', 'like', $prefix . '%')
            ->order('order_no', 'desc')->value('order_no');
        $seq = $lastNo !== '' ? ((int)substr($lastNo, -4)) + 1 : 1;
        return $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
    }

    /**
     * 状态流转校验（禁跳节点/禁终态回退）
     * @param string $from
     * @param string $to
     * @return bool
     */
    public function canTransition(string $from, string $to): bool
    {
        $targets = self::TRANSITIONS[$from] ?? [];
        return in_array($to, $targets, true);
    }

    /**
     * 状态流转断言
     * @param string $from
     * @param string $to
     * @throws \RuntimeException 非法流转抛出
     * @return void
     */
    public function assertTransition(string $from, string $to): void
    {
        if (!$this->canTransition($from, $to)) {
            throw new \RuntimeException('订单状态不允许从「' . $from . '」流转到「' . $to . '」');
        }
    }

    /**
     * 技师时段冲突校验（同一技师时间窗不允许重叠的待服务/服务中订单）
     * @param int    $techId 技师 ID
     * @param string $startAt 开始时间 Y-m-d H:i:s
     * @param string $endAt   结束时间
     * @param string $excludeOrderNo 排除订单（改约场景排除自身）
     * @return bool true=有冲突
     */
    public function hasConflict(int $techId, string $startAt, string $endAt, string $excludeOrderNo = ''): bool
    {
        if ($techId <= 0) {
            return false;
        }
        $query = Db::name('booking')
            ->where('deleted_at', 0)
            ->where('technician_id', $techId)
            // 下单即锁档期（行业惯例）：未支付/已支付/履约中的单都占用技师时段，
            // 已取消/已退款/待确认及之后的终态不占用
            // 待支付超时自动释放属任务层（遗留缺口，与超期自动流转一并实现）
            ->whereIn('status', ['待支付', '待派单', '待接单', '待服务', '服务中'])
            // 重叠条件：existing.start < new.end AND existing.end > new.start
            ->where('start_at', '<', $endAt)
            ->where('end_at', '>', $startAt);
        if ($excludeOrderNo !== '') {
            $query->where('order_no', '<>', $excludeOrderNo);
        }
        return $query->count() > 0;
    }

    /**
     * 计价（服务价按时长折算，金额单位分，只信服务端）
     * 技师级定价：指定技师且该技师配置了该项目定价时，用技师价替代服务标准价
     * @param int $serviceId 服务 ID
     * @param int $minutes   时长（分钟）
     * @param int $techId    指定技师 ID（0=未指定，用服务标准价）
     * @return int 应付金额（分）
     */
    public function calcAmount(int $serviceId, int $minutes, int $techId = 0): int
    {
        $service = Db::name('service')->where('id', $serviceId)->where('deleted_at', 0)->find();
        if (!$service) {
            throw new \RuntimeException('服务不存在或已下架');
        }
        if ($minutes <= 0) {
            $minutes = (int)$service['duration'];
        }
        $baseDuration = (int)$service['duration'] > 0 ? (int)$service['duration'] : self::PRICE_UNIT_MINUTES;
        $price = (int)$service['price'];
        // 技师级定价覆盖（technician_service.price，0=未设置则用标准价）
        if ($techId > 0) {
            $override = $this->technicianPrice($techId, $serviceId);
            if ($override > 0) {
                $price = $override;
            }
        }
        // 按时长比例折算，四舍五入到分（不产生浮点误差）
        return (int)round($price * $minutes / $baseDuration);
    }

    /**
     * 技师该项目定价（分，0=未设置或读取失败）
     * @param int $techId
     * @param int $serviceId
     * @return int
     */
    private function technicianPrice(int $techId, int $serviceId): int
    {
        try {
            $row = Db::name('technician_service')
                ->where('technician_id', $techId)
                ->where('service_id', $serviceId)
                ->where('deleted_at', 0)
                ->find();
            return $row ? (int)$row['price'] : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 预约时间窗校验（提前预约小时数/最远可约天数，读 setting 配置）
     * @param string $startAt
     * @throws \RuntimeException 不在可约时间窗抛出
     * @return void
     */
    public function assertBookingWindow(string $startAt): void
    {
        $ts = strtotime($startAt);
        if ($ts === false) {
            throw new \RuntimeException('预约时间格式无效');
        }
        $advanceHours = (int)$this->setting('advance_hours', '2');
        $maxDays = (int)$this->setting('max_advance_days', '30');
        $earliest = time() + $advanceHours * 3600;
        $latest = time() + $maxDays * 86400;
        if ($ts < $earliest) {
            throw new \RuntimeException('需提前 ' . $advanceHours . ' 小时预约');
        }
        if ($ts > $latest) {
            throw new \RuntimeException('最远可预约 ' . $maxDays . ' 天内的服务');
        }
    }

    /**
     * 取消违约金计算（服务开始前 N 小时内取消收违约金，读 setting 配置）
     * @param array $booking 订单行
     * @return int 违约金（分，0=免费取消）
     */
    public function calcCancelFee(array $booking): int
    {
        $limitHours = (int)$this->setting('cancel_limit_hours', '4');
        $feeRate = (int)$this->setting('cancel_fee_rate', '10');
        $startTs = strtotime((string)$booking['start_at']);
        // 距开始时间超过 limit 小时 → 免费；否则按比例收违约金
        if ($startTs - time() > $limitHours * 3600) {
            return 0;
        }
        return (int)round((int)$booking['actual'] * $feeRate / 100);
    }

    /**
     * 会员下单资格校验：爽约超限的冻结期内禁止下单（读 member.frozen_until，见 markNoshow）
     * @param int $memberId
     * @throws \RuntimeException 冻结期内抛出
     * @return void
     */
    public function assertMemberCanBook(int $memberId): void
    {
        if ($memberId <= 0) {
            return;
        }
        $row = Db::name('member')->where('id', $memberId)->where('deleted_at', 0)->field('frozen_until')->find();
        if (!$row || empty($row['frozen_until'])) {
            return;
        }
        $until = strtotime((string)$row['frozen_until']);
        if ($until === false || $until <= time()) {
            return;
        }
        $days = (int)ceil(($until - time()) / 86400);
        throw new \RuntimeException('因多次爽约，账号已被限制下单，约 ' . $days . ' 天后自动解除');
    }

    /**
     * 标记客户爽约（技师已到场而客户未到）：累计月内爽约次数，达 setting noshow_limit 后
     * 按 setting noshow_freeze_days 冻结下单（写 member.frozen_until，由 assertMemberCanBook 拦截）。
     * @param array $booking 订单行
     * @return array {count:int, limit:int, frozen_until:string}
     */
    public function markNoshow(array $booking): array
    {
        $limit = (int)$this->setting('noshow_limit', '3');
        $freezeDays = (int)$this->setting('noshow_freeze_days', '7');
        $memberId = (int)($booking['member_id'] ?? 0);
        if ($memberId <= 0) {
            return ['count' => 0, 'limit' => $limit, 'frozen_until' => ''];
        }
        $member = Db::name('member')->where('id', $memberId)->where('deleted_at', 0)->find();
        if (!$member) {
            return ['count' => 0, 'limit' => $limit, 'frozen_until' => ''];
        }
        // 「月内」口径：跨月首次爽约重置为 1（noshow_month 记 YYYY-MM）
        $month = date('Y-m');
        $count = ((string)($member['noshow_month'] ?? '') === $month) ? (int)$member['noshow_count'] + 1 : 1;
        $update = [
            'noshow_count' => $count,
            'noshow_month' => $month,
            'updated_at'   => date('Y-m-d H:i:s'),
        ];
        $frozenUntil = '';
        if ($limit > 0 && $count >= $limit && $freezeDays > 0) {
            $frozenUntil = date('Y-m-d H:i:s', time() + $freezeDays * 86400);
            $update['frozen_until'] = $frozenUntil;
        }
        Db::name('member')->where('id', $memberId)->update($update);
        return ['count' => $count, 'limit' => $limit, 'frozen_until' => $frozenUntil];
    }

    /**
     * 通用积分发放：balance 与流水守恒；返回发放后余额（0=未发）。
     * 供订单完成奖励 / 评价有礼 / 推荐有礼 / 自动化营销共用。
     */
    public function awardPoints(int $memberId, string $member, string $phone, int $points, string $orderNo, string $detail): int
    {
        if ($memberId <= 0 || $points <= 0) {
            return 0;
        }
        $last = (int)(Db::name('point_log')->where('member_id', $memberId)->order('id', 'desc')->value('balance') ?: 0);
        Db::name('point_log')->insert([
            'member_id'  => $memberId,
            'member'     => $member,
            'phone'      => $phone,
            'action'     => '获取',
            'points'     => $points,
            'balance'    => $last + $points,
            'order_no'   => $orderNo,
            'detail'     => $detail,
            'at'         => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'deleted_at' => 0,
        ]);
        return $last + $points;
    }

    /**
     * 订单确认完成后按积分比例发放积分（setting: point_rate，实付 1 元 = N 积分）
     */
    public function awardPointsOnConfirm(array $booking): void
    {
        $memberId = (int)($booking['member_id'] ?? 0);
        $actual = (int)($booking['actual'] ?? 0); // 单位分
        if ($memberId <= 0 || $actual <= 0) {
            return;
        }
        $rate = (float)$this->setting('point_rate', '1');
        if ($rate <= 0) {
            return;
        }
        $points = (int)floor($actual / 100 * $rate);
        if ($points <= 0) {
            return;
        }
        $this->awardPoints($memberId, (string)($booking['member'] ?? ''), (string)($booking['phone'] ?? ''), $points, (string)($booking['order_no'] ?? ''), '订单完成奖励（实付 ' . number_format($actual / 100, 2) . ' 元）');
    }

    /**
     * 推荐有礼 + 分销佣金引擎（订单确认完成时触发）：
     * 被邀请人首单完成 → 按启用 referral_rule 给邀请人/被邀请人发积分；
     * 邀请人为在职分销员 → 佣金按 setting distribution_rate（百分比）计入 pending。
     */
    public function awardReferralOnConfirm(array $booking): void
    {
        $memberId = (int)($booking['member_id'] ?? 0);
        if ($memberId <= 0) {
            return;
        }
        $member = Db::name('member')->where('id', $memberId)->where('deleted_at', 0)->find();
        $inviterId = (int)($member['inviter_id'] ?? 0);
        if (!$member || $inviterId <= 0) {
            return;
        }
        // 首单判定：含本单的完成口径订单数 > 1 则非首单（确认完成在本单状态更新后调用）
        $done = Db::name('booking')->where('member_id', $memberId)->where('deleted_at', 0)
            ->whereIn('status', ['已完成', '待评价', '已评价'])->count();
        if ($done > 1) {
            return;
        }
        $rule = Db::name('referral_rule')->where('deleted_at', 0)->where('status', 1)->order('id asc')->find();
        $inviter = Db::name('member')->where('id', $inviterId)->where('deleted_at', 0)->find();
        if ($rule && $inviter) {
            $orderNo = (string)($booking['order_no'] ?? '');
            if ((int)$rule['inviter_reward'] > 0) {
                $this->awardPoints($inviterId, (string)$inviter['name'], (string)$inviter['phone'], (int)$rule['inviter_reward'], $orderNo, '推荐有礼：被邀请人首单完成');
            }
            if ((int)$rule['invitee_reward'] > 0) {
                $this->awardPoints($memberId, (string)$member['name'], (string)$member['phone'], (int)$rule['invitee_reward'], $orderNo, '推荐有礼：首单奖励');
            }
            Db::name('referral_rule')->where('id', (int)$rule['id'])->inc('issued')->update([]);
        }
        // 分销佣金：邀请人是启用分销员 → 佣金计入待结算
        $dist = Db::name('distributor')->where('deleted_at', 0)->where('member_id', $inviterId)->where('status', 1)->find();
        if ($dist) {
            $rate = (float)$this->setting('distribution_rate', '5');
            $commission = (int)floor((int)($booking['actual'] ?? 0) * $rate / 100);
            if ($commission > 0) {
                Db::name('distributor')->where('id', (int)$dist['id'])
                    ->inc('pending', $commission)->inc('orders')->update([]);
                (new AuditService())->log('distribution.commission', (string)($booking['order_no'] ?? ''), '分销佣金 ' . $commission . ' 分计入待结算（' . $dist['name'] . '）', 0);
            }
        }
    }

    /**
     * 自动派单引擎：setting dispatch_mode=自动派单 时，支付成功后的待派单订单
     * 按权重评分自动指派 Top1 技师（技能匹配/档期空闲/负载/评分，权重可配）。
     * 返回 [是否派单, 说明]；失败不影响支付主流程（调用方捕获）。
     */
    public function autoDispatch(string $orderNo): array
    {
        $booking = Db::name('booking')->where('order_no', $orderNo)->where('deleted_at', 0)->find();
        if (!$booking || (string)$booking['status'] !== '待派单' || (int)$booking['technician_id'] > 0) {
            return [false, '订单不在待派单状态'];
        }
        if ((string)$this->setting('dispatch_mode', '手动指派') !== '自动派单') {
            return [false, '未开启自动派单'];
        }
        // 版本门禁：自动派单引擎为授权版功能（dispatch.auto）
        // 未升级时保持「待派单」转人工，不硬断订单主流程
        $gate = new EditionGate();
        if (!$gate->assertFeature('dispatch.auto')) {
            $deny = $gate->lastDeny();
            return [false, ($deny['tip'] ?? '') ?: '自动派单为授权版功能，请升级后使用（当前转人工派单）'];
        }
        $weights = json_decode((string)$this->setting('dispatch_weights', ''), true)
            ?: ['skill' => 40, 'schedule' => 25, 'load' => 20, 'rating' => 15];
        $techs = Db::name('technician')->where('deleted_at', 0)->where('status', '在岗')->select()->toArray();
        if (!$techs) {
            return [false, '暂无在岗技师，转人工派单'];
        }
        $serviceName = (string)(Db::name('service')->where('id', (int)$booking['service_id'])->value('name') ?: '');
        $startAt = (string)$booking['start_at'];
        $endAt = date('Y-m-d H:i:s', strtotime($startAt) + (int)$booking['duration'] * 60);

        $best = null;
        $bestScore = -1;
        foreach ($techs as $t) {
            // 技能匹配：服务名含技师技能词（或技能含服务名），无法识别给基准分 60
            $skills = json_decode((string)$t['skills'], true) ?: [];
            $skillScore = 60;
            foreach ($skills as $s) {
                if ($s !== '' && (mb_strpos($serviceName, $s) !== false || mb_strpos($s, $serviceName) !== false)) {
                    $skillScore = 100;
                    break;
                }
            }
            // 档期空闲：该时段无待服务/服务中冲突单
            $conflict = Db::name('booking')->where('technician_id', (int)$t['id'])->where('deleted_at', 0)
                ->whereIn('status', ['待接单', '待服务', '服务中'])
                ->where('start_at', '<', $endAt)
                ->where('end_at', '>', $startAt)->count();
            $scheduleScore = $conflict > 0 ? 0 : 100;
            // 负载：进行中订单越少分越高
            $busy = Db::name('booking')->where('technician_id', (int)$t['id'])->where('deleted_at', 0)
                ->whereIn('status', ['待接单', '待服务', '服务中'])->count();
            $loadScore = max(0, 100 - $busy * 20);
            $ratingScore = (float)$t['rating'] / 5 * 100;
            $score = $skillScore * ($weights['skill'] ?? 40) + $scheduleScore * ($weights['schedule'] ?? 25)
                + $loadScore * ($weights['load'] ?? 20) + $ratingScore * ($weights['rating'] ?? 15);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $t;
            }
        }
        if (!$best) {
            return [false, '无可用技师'];
        }
        Db::name('booking')->where('order_no', $orderNo)->update([
            'technician_id' => (int)$best['id'],
            'technician'    => (string)$best['name'],
            'status'        => '待接单',
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        (new AuditService())->log('order.autodispatch', $orderNo, '自动派单 → ' . $best['name'], 0);
        return [true, '已自动派单给 ' . $best['name']];
    }

    /**
     * 会员等级同步：累计消费达标自动升级（读 setting level_rules）
     * 规则形如 [{"name":"普通会员","threshold":0},{"name":"白银会员","threshold":500}]，
     * threshold 单位「元」；只升不降（累计消费单调递增，降级属运营决策，由后台手工调整）。
     * @param int $memberId
     * @return array {level:string, from:string, changed:bool}
     */
    public function syncMemberLevel(int $memberId): array
    {
        if ($memberId <= 0) {
            return ['level' => '', 'from' => '', 'changed' => false];
        }
        $member = Db::name('member')->where('id', $memberId)->where('deleted_at', 0)->find();
        if (!$member) {
            return ['level' => '', 'from' => '', 'changed' => false];
        }
        $rules = $this->levelRules();
        $current = (string)$member['level'];
        if (!$rules) {
            return ['level' => $current, 'from' => $current, 'changed' => false];
        }
        $yuan = (int)$member['total_amount'] / 100;
        // 命中最高门槛（规则已按门槛升序，位次即等级高低）
        $matchedIdx = 0;
        foreach ($rules as $i => $r) {
            if ($yuan >= $r['threshold']) {
                $matchedIdx = $i;
            }
        }
        $currentIdx = -1;
        foreach ($rules as $i => $r) {
            if ($r['name'] === $current) {
                $currentIdx = $i;
                break;
            }
        }
        if ($matchedIdx <= $currentIdx) {
            return ['level' => $current, 'from' => $current, 'changed' => false];
        }
        $newLevel = $rules[$matchedIdx]['name'];
        Db::name('member')->where('id', $memberId)->update([
            'level'      => $newLevel,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return ['level' => $newLevel, 'from' => $current, 'changed' => true];
    }

    /**
     * 会员等级规则（setting level_rules，JSON 数组，按门槛升序）
     * 注：setting 的「组」是 member，但「键」为 level_rules（与后台 MemberProfileList 同源）
     * @return array<int, array{name:string, threshold:float}>
     */
    private function levelRules(): array
    {
        $raw = $this->setting('level_rules', '');
        if ($raw === '') {
            return [];
        }
        $arr = json_decode($raw, true);
        if (!is_array($arr)) {
            return [];
        }
        $out = [];
        foreach ($arr as $r) {
            if (is_array($r) && isset($r['name']) && (string)$r['name'] !== '') {
                $out[] = ['name' => (string)$r['name'], 'threshold' => (float)($r['threshold'] ?? 0)];
            }
        }
        usort($out, fn ($a, $b) => $a['threshold'] <=> $b['threshold']);
        return $out;
    }

    /**
     * 读 setting 值
     * @param string $key
     * @param string $default
     * @return string
     */
    private function setting(string $key, string $default = ''): string
    {
        try {
            $value = Db::name('setting')->where('key', $key)->value('value');
            return $value === null ? $default : (string)$value;
        } catch (\Throwable $e) {
            return $default;
        }
    }
}
