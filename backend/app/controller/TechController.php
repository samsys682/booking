<?php
// =====================================================================
// booking 后端 · 技师自助域（收入/排班/考勤/收到的评价）
// 面向 mobile 端技师 token：所有数据只回本人（归属校验在控制器兜底），
// 通用资源接口（ResourceController）无归属过滤不能对技师放行，故独立成域。
// 响应直接输出技师端视图形状（camelCase + 金额「元」+ 展示串），
// 与 mobile 端 mock 兜底形状一致，前端双轨切换零映射。
// 写入动作带业务语义：排班休息开关 / 上下班打卡（考勤行 upsert + 工时计算）。
// =====================================================================

namespace app\controller;

use app\service\AuditService;
use think\facade\Db;
use think\Request;
use think\Response;

class TechController
{
    /** 默认班容量（schedule 表无 max_orders 列，休息日归零，工作日取此值） */
    private const MAX_ORDERS = 8;

    /** 订单终态（booked 聚合排除；口径同 mobile statusFlow.js::TERMINAL_STATUSES） */
    private const TERMINAL_STATUSES = ['已取消', '已退款', '已关闭', '已完成', '已评价', '已售后'];

    // ==================================================================
    // 收入：提成结算列表（本月/待结算聚合由前端按 period 计算）
    // GET /api/v1/tech/income
    // ==================================================================
    public function income(Request $request): Response
    {
        if ($deny = $this->guardTech($request)) {
            return $deny;
        }
        $rows = Db::name('commission_settlement')
            ->where('technician_id', $this->techId($request))
            ->where('deleted_at', 0)->order('month', 'desc')->select()->toArray();
        return ok(['list' => array_map([$this, 'mapSettlement'], $rows)]);
    }

    // ==================================================================
    // 排班：我的排班列表（含当日已约单量聚合）
    // GET /api/v1/tech/schedules
    // ==================================================================
    public function schedules(Request $request): Response
    {
        if ($deny = $this->guardTech($request)) {
            return $deny;
        }
        $techId = $this->techId($request);
        $rows = Db::name('schedule')
            ->where('technician_id', $techId)
            ->where('deleted_at', 0)->order('date', 'asc')->select()->toArray();
        return ok(['list' => array_map(function ($r) use ($techId) {
            return $this->mapSchedule($r, $this->bookedCount($techId, (string)$r['date']));
        }, $rows)]);
    }

    // ==================================================================
    // 排班：休息开关（可接单 ↔ 休息）
    // PUT /api/v1/tech/schedules/{id}/toggle
    // ==================================================================
    public function scheduleToggle(Request $request, int $id): Response
    {
        if ($deny = $this->guardTech($request)) {
            return $deny;
        }
        $techId = $this->techId($request);
        $row = Db::name('schedule')->where('id', $id)->where('deleted_at', 0)->find() ?: null;
        if (!$row) {
            return err(404, '排班不存在');
        }
        if ($deny = $this->guardOwnerTech($request, (int)$row['technician_id'])) {
            return $deny;
        }
        $toRest = (int)$row['is_rest'] !== 1;
        $now = date('Y-m-d H:i:s');
        $date = (string)$row['date'];

        Db::startTrans();
        try {
            if ($toRest) {
                Db::name('schedule')->where('id', $id)->update([
                    'is_rest'    => 1,
                    'status'     => '休息',
                    'note'       => '技师自助设为休息',
                    'updated_at' => $now,
                ]);
            } else {
                // 恢复接单：无起止时间则回落默认班次 09:00-18:00
                $startAt = (string)($row['start_at'] ?? '');
                $endAt = (string)($row['end_at'] ?? '');
                Db::name('schedule')->where('id', $id)->update([
                    'is_rest'    => 0,
                    'status'     => '已排班',
                    'start_at'   => $startAt !== '' ? $startAt : $date . ' 09:00:00',
                    'end_at'     => $endAt !== '' ? $endAt : $date . ' 18:00:00',
                    'note'       => '技师自助恢复接单',
                    'updated_at' => $now,
                ]);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return err(500, '操作失败，请重试');
        }
        (new AuditService())->log('tech.schedule-toggle', (string)$id, '技师自助' . ($toRest ? '设为休息' : '恢复接单') . '（' . $date . '）', 0);
        $fresh = Db::name('schedule')->where('id', $id)->find() ?: [];
        return ok($this->mapSchedule($fresh, $this->bookedCount($techId, $date)), $toRest ? '已设为休息' : '已恢复接单');
    }

    // ==================================================================
    // 考勤：我的出勤记录
    // GET /api/v1/tech/attendance
    // ==================================================================
    public function attendance(Request $request): Response
    {
        if ($deny = $this->guardTech($request)) {
            return $deny;
        }
        $rows = Db::name('attendance')
            ->where('technician_id', $this->techId($request))
            ->where('deleted_at', 0)->order('date', 'desc')->limit(60)->select()->toArray();
        return ok(['list' => array_map([$this, 'mapAttendance'], $rows)]);
    }

    // ==================================================================
    // 考勤：上班打卡（当日行 upsert；已打卡不允许重复上班）
    // POST /api/v1/tech/attendance/checkin  {checkIn?: 'HH:MM'}
    // ==================================================================
    public function attendanceCheckin(Request $request): Response
    {
        if ($deny = $this->guardTech($request)) {
            return $deny;
        }
        $techId = $this->techId($request);
        $checkIn = $this->cleanClock($request->post('checkIn', '')) ?: date('H:i');
        $today = date('Y-m-d');
        $now = date('Y-m-d H:i:s');

        $row = Db::name('attendance')->where('technician_id', $techId)
            ->where('date', $today)->where('deleted_at', 0)->find() ?: null;
        if ($row && (string)$row['clock_in'] !== '') {
            return err(400, '今日已打上班卡（' . $row['clock_in'] . '）');
        }

        Db::startTrans();
        try {
            if ($row) {
                Db::name('attendance')->where('id', (int)$row['id'])->update([
                    'clock_in'   => $checkIn,
                    'status'     => '打卡中',
                    'updated_at' => $now,
                ]);
                $attId = (int)$row['id'];
            } else {
                $tech = Db::name('technician')->where('id', $techId)->where('deleted_at', 0)->find() ?: null;
                Db::name('attendance')->insert([
                    'technician_id' => $techId,
                    'technician'    => (string)($tech['name'] ?? ''),
                    'date'          => $today,
                    'clock_in'      => $checkIn,
                    'clock_out'     => '',
                    'hours'         => 0,
                    'orders'        => 0,
                    'status'        => '打卡中',
                    'note'          => '',
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]);
                $attId = (int)Db::name('attendance')->getLastInsID();
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return err(500, '打卡失败，请重试');
        }
        (new AuditService())->log('tech.attendance-checkin', (string)$attId, '技师上班打卡 ' . $today . ' ' . $checkIn, 0);
        $fresh = Db::name('attendance')->where('id', $attId)->find() ?: [];
        return ok($this->mapAttendance($fresh), '上班打卡成功');
    }

    // ==================================================================
    // 考勤：下班打卡（须已上班；工时 = 下班 - 上班，保留 1 位小数）
    // POST /api/v1/tech/attendance/checkout  {checkOut?: 'HH:MM'}
    // ==================================================================
    public function attendanceCheckout(Request $request): Response
    {
        if ($deny = $this->guardTech($request)) {
            return $deny;
        }
        $techId = $this->techId($request);
        $checkOut = $this->cleanClock($request->post('checkOut', '')) ?: date('H:i');
        $today = date('Y-m-d');
        $now = date('Y-m-d H:i:s');

        $row = Db::name('attendance')->where('technician_id', $techId)
            ->where('date', $today)->where('deleted_at', 0)->find() ?: null;
        if (!$row || (string)$row['clock_in'] === '') {
            return err(400, '请先打上班卡');
        }
        if ((string)$row['clock_out'] !== '') {
            return err(400, '今日已打下班卡（' . $row['clock_out'] . '）');
        }
        $hours = max(0, round(($this->toMin($checkOut) - $this->toMin((string)$row['clock_in'])) / 60, 1));

        Db::startTrans();
        try {
            Db::name('attendance')->where('id', (int)$row['id'])->update([
                'clock_out'  => $checkOut,
                'hours'      => $hours,
                'status'     => '正常',
                'updated_at' => $now,
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return err(500, '打卡失败，请重试');
        }
        (new AuditService())->log('tech.attendance-checkout', (string)$row['id'], '技师下班打卡 ' . $today . ' ' . $checkOut . '（' . $hours . ' 小时）', 0);
        $fresh = Db::name('attendance')->where('id', (int)$row['id'])->find() ?: [];
        return ok($this->mapAttendance($fresh), '下班打卡成功');
    }

    // ==================================================================
    // 评价：我收到的评价（去技师化口径：评价对象=订单/服务，技师侧只读聚合）
    // GET /api/v1/tech/reviews
    // ==================================================================
    public function reviews(Request $request): Response
    {
        if ($deny = $this->guardTech($request)) {
            return $deny;
        }
        $rows = Db::name('review')
            ->where('technician_id', $this->techId($request))
            ->where('deleted_at', 0)->order('id', 'desc')->limit(100)->select()->toArray();
        return ok(['list' => array_map([$this, 'mapReview'], $rows)]);
    }

    // ==================================================================
    // 自选项目：技师可接服务全量清单（标注已选；全版本、无门禁）
    // GET /api/v1/tech/my-services
    // ==================================================================
    public function myServices(Request $request): Response
    {
        if ($deny = $this->guardTech($request)) {
            return $deny;
        }
        $techId = $this->techId($request);
        $services = Db::name('service')
            ->where('status', 1)->where('deleted_at', 0)
            ->field('id,name,category,price,duration')
            ->order('id', 'desc')->select()->toArray();
        $mine = array_map('intval', Db::name('technician_service')
            ->where('technician_id', $techId)->where('deleted_at', 0)->column('service_id'));
        return ok(['list' => array_map(function ($s) use ($mine) {
            return [
                'id'       => (int)$s['id'],
                'name'     => (string)$s['name'],
                'category' => (string)$s['category'],
                'price'    => (float)fen_to_yuan((int)$s['price']),
                'duration' => (int)$s['duration'],
                'selected' => in_array((int)$s['id'], $mine, true),
            ];
        }, $services)]);
    }

    // ==================================================================
    // 自选项目：保存技师可接服务（全量覆盖：新增勾选 / 软删取消 / 复活历史行，规避 uk_pair 冲突）
    // PUT /api/v1/tech/my-services  body: { service_ids: [1,2,...] }
    // ==================================================================
    public function myServicesSave(Request $request): Response
    {
        if ($deny = $this->guardTech($request)) {
            return $deny;
        }
        $techId = $this->techId($request);
        $ids = $request->post('service_ids', []);
        if (!is_array($ids)) {
            return err(400, 'service_ids 必须为数组');
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($v) => $v > 0)));
        $valid = [];
        if ($ids) {
            $valid = array_map('intval', Db::name('service')
                ->whereIn('id', $ids)->where('status', 1)->where('deleted_at', 0)->column('id'));
        }
        $now = date('Y-m-d H:i:s');
        Db::startTrans();
        try {
            $cur = array_map('intval', Db::name('technician_service')
                ->where('technician_id', $techId)->where('deleted_at', 0)->column('service_id'));
            foreach (array_diff($cur, $valid) as $sid) {
                Db::name('technician_service')
                    ->where('technician_id', $techId)->where('service_id', $sid)->where('deleted_at', 0)
                    ->update(['deleted_at' => time(), 'updated_at' => $now]);
            }
            foreach (array_diff($valid, $cur) as $sid) {
                $revive = Db::name('technician_service')
                    ->where('technician_id', $techId)->where('service_id', $sid)->where('deleted_at', '>', 0)->find();
                if ($revive) {
                    Db::name('technician_service')->where('id', $revive['id'])->update(['deleted_at' => 0, 'updated_at' => $now]);
                } else {
                    Db::name('technician_service')->insert([
                        'technician_id' => $techId, 'service_id' => $sid,
                        'created_at' => $now, 'updated_at' => $now, 'deleted_at' => 0,
                    ]);
                }
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return err(500, '保存失败');
        }
        (new AuditService())->log('tech.my_services', 'tech:' . $techId, '更新自选项目 ' . count($valid) . ' 项', 0);
        return ok(['service_ids' => array_values($valid)], '自选项目已保存');
    }

    // ==================================================================
    // 内部工具
    // ==================================================================

    /**
     * 技师端守卫：tech 域仅技师本人可用（admin/member 一律拒绝）
     * @return Response|null
     */
    private function guardTech(Request $request): ?Response
    {
        if ((string)($request->tokenType ?? '') !== 'tech') {
            return err(403, '请先登录技师账号', [], 403);
        }
        if ($this->techId($request) <= 0) {
            return err(403, '技师身份无效', [], 403);
        }
        return null;
    }

    /**
     * 归属守卫（行数据必须属于当前技师，坑 S）
     * @return Response|null
     */
    private function guardOwnerTech(Request $request, int $ownerTechId): ?Response
    {
        if ($ownerTechId !== $this->techId($request)) {
            return err(403, '无权操作他人数据', [], 403);
        }
        return null;
    }

    /**
     * 当前登录技师 ID
     */
    private function techId(Request $request): int
    {
        return (int)($request->techId ?? 0);
    }

    /**
     * 打卡时刻校验（HH:MM）
     */
    private function cleanClock(string $t): string
    {
        $t = clean_input($t, 5);
        return preg_match('/^\d{2}:\d{2}$/', $t) ? $t : '';
    }

    /**
     * HH:MM → 当日分钟数
     */
    private function toMin(string $t): int
    {
        $p = explode(':', $t);
        return (int)($p[0] ?? 0) * 60 + (int)($p[1] ?? 0);
    }

    /**
     * 当日已约单量（非终态订单；口径同 mobile ScheduleView.booked；
     * booking 无 date 列，日期在 start_at datetime，按日区间聚合）
     */
    private function bookedCount(int $techId, string $date): int
    {
        return (int)Db::name('booking')
            ->where('technician_id', $techId)
            ->where('start_at', '>=', $date . ' 00:00:00')
            ->where('start_at', '<=', $date . ' 23:59:59')
            ->where('deleted_at', 0)
            ->whereNotIn('status', self::TERMINAL_STATUSES)
            ->count();
    }

    /**
     * 结算行 → 视图形状（month→period；orders→orderCount；金额分→元；
     * 状态二值化「已结算/待结算」与 mock 口径一致，settledAt 取结算更新日）
     */
    private function mapSettlement(array $r): array
    {
        $status = (string)$r['status'];
        $settled = in_array($status, ['已结算', '已发放'], true);
        return [
            'id'         => (int)$r['id'],
            'period'     => (string)$r['month'],
            'orderCount' => (int)$r['orders'],
            'gross'      => (float)fen_to_yuan((int)$r['gross']),
            'commission' => (float)fen_to_yuan((int)$r['commission']),
            'status'     => $settled ? '已结算' : '待结算',
            'settledAt'  => $settled ? substr((string)($r['updated_at'] ?? ''), 0, 10) : '',
        ];
    }

    /**
     * 排班行 → 视图形状（start_at/end_at→timeRange；is_rest→status；
     * maxOrders 无库列取默认班容量；booked 由 booking 聚合传入）
     */
    private function mapSchedule(array $r, int $booked = 0): array
    {
        $rest = (int)$r['is_rest'] === 1;
        $startAt = (string)($r['start_at'] ?? '');
        $endAt = (string)($r['end_at'] ?? '');
        return [
            'id'         => (int)$r['id'],
            'date'       => substr((string)$r['date'], 0, 10),
            'timeRange'  => $rest ? '' : substr($startAt, 11, 5) . '-' . substr($endAt, 11, 5),
            'maxOrders'  => $rest ? 0 : self::MAX_ORDERS,
            'booked'     => $booked,
            'status'     => $rest ? '休息' : '可接单',
        ];
    }

    /**
     * 考勤行 → 视图形状（clock_in/clock_out→checkIn/checkOut）
     */
    private function mapAttendance(array $r): array
    {
        return [
            'id'       => (int)$r['id'],
            'date'     => substr((string)$r['date'], 0, 10),
            'checkIn'  => (string)$r['clock_in'],
            'checkOut' => (string)$r['clock_out'],
            'status'   => (string)$r['status'],
            'hours'    => (float)$r['hours'],
        ];
    }

    /**
     * 评价行 → 视图形状（order_no→order；images JSON 解码；created_at→createdAt）
     */
    private function mapReview(array $r): array
    {
        $images = json_decode((string)($r['images'] ?? ''), true);
        return [
            'id'        => (int)$r['id'],
            'order'     => (string)$r['order_no'],
            'service'   => (string)$r['service'],
            'customer'  => (string)$r['customer'],
            'rating'    => (int)$r['rating'],
            'content'   => (string)$r['content'],
            'images'    => is_array($images) ? array_values($images) : [],
            'reply'     => (string)$r['reply'],
            'createdAt' => (string)($r['created_at'] ?? ''),
        ];
    }
}
