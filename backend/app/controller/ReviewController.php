<?php
// =====================================================================
// booking 后端 · 评价控制器（评价创建 + 商家回复）
// 业务要点：
//   1. 仅 已完成/待评价 状态的订单可评价，一单一评（软删后可补评）
//   2. 评价落快照（customer/service/technician 等取下单时值），订单后续改派不影响评价
//   3. 创建评价即推进订单状态：已完成 → 待评价 → 已评价（状态机禁跳，走两步 assertTransition）
//   4. 去技师化口径：评价前台不展示技师，快照字段仅作服务留痕/统计
//   5. 商家回复后状态 待回复 → 已回复（清空回复回 待回复）
// 评价的列表/详情/删除走通用 CRUD（resources.php 'reviews'），此处只做带业务语义的写入
// =====================================================================

namespace app\controller;

use app\service\AuditService;
use app\service\OrderService;
use think\facade\Db;
use think\Request;
use think\Response;

class ReviewController
{
    /** @var OrderService */
    private $orders;

    public function __construct()
    {
        $this->orders = new OrderService();
    }

    /**
     * 创建评价（用户端/后台补录共用）
     * POST /api/v1/reviews
     * body: order_no, rating(1-5), content, images?（数组）
     */
    public function create(Request $request): Response
    {
        $orderNo = clean_input($request->post('order_no', ''), 32);
        $rating = (int)$request->post('rating', 0);
        $content = clean_input($request->post('content', ''), 1000);
        $images = $request->post('images', []);

        if ($orderNo === '') {
            return err(400, '缺少订单号');
        }
        if ($rating < 1 || $rating > 5) {
            return err(400, '评分需在 1-5 分之间');
        }
        if ($content === '') {
            return err(400, '评价内容不能为空');
        }

        $booking = Db::name('booking')->where('order_no', $orderNo)->where('deleted_at', 0)->find();
        if (!$booking) {
            return err(404, '订单不存在');
        }
        // 会员 token 仅可评价本人订单（admin 补录不受限）
        if ((string)($request->tokenType ?? 'admin') === 'member'
            && (int)$booking['member_id'] !== (int)($request->memberId ?? 0)) {
            return err(403, '无权评价他人订单', [], 403);
        }
        $status = (string)$booking['status'];
        if (!in_array($status, ['已完成', '待评价'], true)) {
            return err(400, '当前订单状态不可评价（需已完成/待评价，当前「' . $status . '」）');
        }
        $exists = Db::name('review')->where('order_no', $orderNo)->where('deleted_at', 0)->count();
        if ($exists > 0) {
            return err(400, '该订单已评价');
        }

        // 快照取下单时值（订单改派/改约不回写评价）
        $data = [
            'order_no'      => $orderNo,
            'member_id'     => (int)$booking['member_id'],
            'customer'      => (string)$booking['customer'],
            'service'       => (string)$booking['service'],
            'technician_id' => (int)$booking['technician_id'],
            'technician'    => (string)$booking['technician'],
            'rating'        => $rating,
            'content'       => $content,
            'images'        => json_encode(array_values((array)$images), JSON_UNESCAPED_UNICODE),
            'reply'         => '',
            'status'        => '待回复',
            'created_at'    => date('Y-m-d H:i:s'),
        ];

        // 状态机禁跳：已完成 → 待评价 → 已评价（已在待评价则一步到位）
        try {
            $steps = $status === '已完成' ? ['待评价', '已评价'] : ['已评价'];
            $from = $status;
            foreach ($steps as $to) {
                $this->orders->assertTransition($from, $to);
                $from = $to;
            }
        } catch (\RuntimeException $e) {
            return err(400, $e->getMessage());
        }

        Db::startTrans();
        try {
            $id = Db::name('review')->insertGetId($data);
            Db::name('booking')->where('order_no', $orderNo)->update([
                'status'     => end($steps),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return err(500, '评价失败，请重试');
        }
        (new AuditService())->log('review.create', $orderNo, '评价 ' . $rating . ' 分', (int)($request->adminId ?? 0));

        // 评价有礼引擎（A1 实测修复）：遍历全部启用规则，condition 匹配后才发奖。
        // 旧实现只取 id 最小的一条规则且不校验 condition——被「五星返券」挡住时
        // 后续积分规则永不可达；若是积分类规则则任何评价都误发分。
        // 奖励发放：reward 文本中「N 积分」→ awardPoints；券类（¥/元/券）待优惠券
        // 核销链路开放后接入，命中但跳过时不计 issued（未真实发放不虚报）
        $memberId = (int)$booking['member_id'];
        if ($memberId > 0) {
            try {
                $hasImages = count(array_filter((array)$images)) > 0;
                $isFirstReview = (int)Db::name('review')->where('member_id', $memberId)
                    ->where('order_no', '<>', $orderNo)->where('deleted_at', 0)->count() === 0;
                $rules = Db::name('review_reward')->where('deleted_at', 0)->where('status', 1)->order('id asc')->select();
                foreach ($rules as $rule) {
                    if (!$this->matchCondition((string)$rule['condition'], $rating, $hasImages, $isFirstReview)) {
                        continue;
                    }
                    if (preg_match('/(\d+)\s*积分/u', (string)$rule['reward'], $m)) {
                        $pts = (int)$m[1];
                        if ($pts > 0) {
                            (new OrderService())->awardPoints($memberId, (string)$booking['customer'], (string)$booking['phone'], $pts, $orderNo, '评价有礼：' . $rule['name']);
                            Db::name('review_reward')->where('id', (int)$rule['id'])->inc('issued')->update([]);
                        }
                    }
                }
            } catch (\Throwable $e) {
                // 奖励发放失败不影响评价成功
            }
        }

        $row = Db::name('review')->where('id', $id)->find();
        return ok($row, '评价成功');
    }

    /**
     * 评价有礼条件匹配：condition 是运营自由文本（如「评分 = 5 星」「评分≥4 且带图」），
     * 按常见写法做模式识别，支持「且/并且/，/、」组合（AND 语义）。
     * 识别不了的写法保守视为不匹配（宁可少发不可误发），上线新写法前先用一条真实评价验证。
     */
    private function matchCondition(string $cond, int $rating, bool $hasImages, bool $isFirstReview): bool
    {
        $cond = trim($cond);
        if ($cond === '' || preg_match('/^(无条件|不限|全部|所有)/u', $cond)) {
            return true;
        }
        // 「或」语义组合暂不支持，保守拒绝
        if (preg_match('/或者|或/u', $cond)) {
            return false;
        }
        $parts = preg_split('/且|并且|同时|，|,|、/u', $cond);
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '' && !$this->matchSingleCondition($p, $rating, $hasImages, $isFirstReview)) {
                return false;
            }
        }
        return true;
    }

    /** 单个子条件匹配：带图/首评/评分类（= ≥ > ≤ <） */
    private function matchSingleCondition(string $p, int $rating, bool $hasImages, bool $isFirstReview): bool
    {
        if (preg_match('/带图|含图|图片|晒图/u', $p)) {
            return $hasImages;
        }
        if (preg_match('/首次评价|首评/u', $p)) {
            return $isFirstReview;
        }
        // 评分类：「评分 = 5 星」「评分≥4」「5 星以上」
        $n = 0;
        if (preg_match('/(\d+)\s*星/u', $p, $m) || preg_match('/评分\s*[^0-9]*?(\d+)/u', $p, $m)) {
            $n = (int)$m[1];
        }
        if ($n <= 0) {
            return false; // 未识别写法
        }
        if (preg_match('/≥|>=|大于等于|以上|及以上|不低于/u', $p)) {
            return $rating >= $n;
        }
        if (preg_match('/>|超过|高于|多于/u', $p)) {
            return $rating > $n;
        }
        if (preg_match('/≤|<=|小于等于|以下|不超过|以内/u', $p)) {
            return $rating <= $n;
        }
        if (preg_match('/</u', $p)) {
            return $rating < $n;
        }
        return $rating === $n; // 「评分 = 5 星」「5 星」
    }

    /**
     * 修改（商家回复 / 评价内容维护；快照字段不可改）
     * PUT /api/v1/reviews/{id}
     * body: rating?, content?, images?, reply?, status?
     */
    public function update(Request $request, int $id): Response
    {
        $row = Db::name('review')->where('id', $id)->where('deleted_at', 0)->find();
        if (!$row) {
            return err(404, '评价不存在');
        }

        $input = $request->put();
        $data = [];
        if (array_key_exists('rating', $input)) {
            $rating = (int)$input['rating'];
            if ($rating < 1 || $rating > 5) {
                return err(400, '评分需在 1-5 分之间');
            }
            $data['rating'] = $rating;
        }
        if (array_key_exists('content', $input)) {
            $data['content'] = clean_input($input['content'], 1000);
        }
        if (array_key_exists('images', $input)) {
            $data['images'] = json_encode(array_values((array)$input['images']), JSON_UNESCAPED_UNICODE);
        }
        if (array_key_exists('reply', $input)) {
            $data['reply'] = clean_input($input['reply'], 500);
            // 回复状态联动：有回复 → 已回复；清空回复 → 待回复（显式传 status 时以显式值为准）
            $data['status'] = $data['reply'] !== '' ? '已回复' : '待回复';
        }
        if (array_key_exists('status', $input)) {
            $data['status'] = clean_input($input['status'], 20);
        }
        if (empty($data)) {
            return err(400, '没有可写入的字段');
        }
        $data['updated_at'] = date('Y-m-d H:i:s');

        try {
            Db::name('review')->where('id', $id)->update($data);
        } catch (\Throwable $e) {
            return err(500, '修改失败，请重试');
        }
        (new AuditService())->log('review.update', (string)$id, '评价修改', (int)($request->adminId ?? 0));

        $row = Db::name('review')->where('id', $id)->find();
        return ok($row, '修改成功');
    }
}
