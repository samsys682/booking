<?php
// =====================================================================
// booking 后端 · 通用资源元数据（ResourceController 驱动 40+ 资源 CRUD）
// 字段白名单与 data/sql/*.schema 一一对应（安全：防批量赋值越权写字段）
// perm：权限组（booking/tech/member/finance/system）→ 角色权限码 {perm}.view/edit/delete
// hidden：查询返回时脱敏字段；fields：允许写入的字段（不含 id/created_at/updated_at/deleted_at）
// parent：派生关系声明 ['table'=>父表,'fk'=>外键列] → 删父记录时由 ResourceController 递归级联软删子记录
// require_setting：功能开关声明 ['key'=>setting键,'msg'=>拒绝文案] → 开关关闭时拒绝该资源写操作
// =====================================================================

return [

    // ---------------- 系统域（01_system） ----------------
    'admins' => [
        'table' => 'admin_account', 'label' => '管理员', 'perm' => 'system',
        'fields' => ['name', 'account', 'password', 'role_id', 'phone', 'store_id', 'status'],
        'search' => ['name', 'account', 'phone'], 'order' => 'id desc',
        'hidden' => ['password'],
    ],
    'roles' => [
        'table' => 'role', 'label' => '角色', 'perm' => 'system',
        'fields' => ['name', 'code', 'desc', 'is_system', 'status'],
        'search' => ['name', 'code'], 'order' => 'id asc',
    ],
    'role-permissions' => [
        'table' => 'role_permission', 'label' => '角色权限', 'perm' => 'system',
        'fields' => ['role_id', 'perm'], 'search' => ['perm'], 'order' => 'id asc',
    ],
    'settings' => [
        'table' => 'setting', 'label' => '系统设置', 'perm' => 'system',
        'fields' => ['group', 'key', 'value', 'type', 'remark'],
        'search' => ['key', 'remark'], 'order' => 'group asc, id asc',
    ],
    'audit-logs' => [
        'table' => 'audit_log', 'label' => '操作日志', 'perm' => 'system',
        'fields' => [], 'search' => ['admin_name', 'module', 'action', 'target'],
        'order' => 'id desc', 'readonly' => true,
    ],
    'backups' => [
        'table' => 'backup', 'label' => '数据备份', 'perm' => 'system',
        'fields' => ['name', 'kind', 'size', 'tables', 'at', 'operator', 'status', 'keep', 'note'],
        'search' => ['name', 'operator'], 'order' => 'id desc',
    ],
    'upgrade-records' => [
        'table' => 'upgrade_record', 'label' => '升级记录', 'perm' => 'system',
        'fields' => ['from_version', 'to_version', 'at', 'operator', 'duration', 'status', 'note'],
        'search' => ['operator', 'note'], 'order' => 'id desc',
    ],
    'security-events' => [
        'table' => 'security_event', 'label' => '安全事件', 'perm' => 'system',
        'fields' => ['kind', 'order_no', 'technician', 'customer', 'at', 'detail', 'status'],
        'search' => ['kind', 'order_no', 'customer'], 'order' => 'id desc',
    ],
    'stores' => [
        'table' => 'store', 'label' => '门店', 'perm' => 'system',
        'fields' => ['name', 'code', 'manager', 'phone', 'address', 'hours', 'cross_store', 'status'],
        'search' => ['name', 'code', 'manager', 'phone'], 'order' => 'id desc',
    ],
    'message-templates' => [
        'table' => 'message_template', 'label' => '消息模板', 'perm' => 'system',
        'fields' => ['name', 'channel', 'trigger', 'scene', 'content', 'wx_template_id', 'wx_data_map', 'sent', 'status'],
        'search' => ['name', 'scene', 'trigger'], 'order' => 'id desc',
    ],
    'message-send-logs' => [
        'table' => 'message_send_log', 'label' => '消息发送记录', 'perm' => 'system',
        'fields' => ['template_id', 'template', 'channel', 'target', 'order_no', 'content', 'status', 'reason', 'ext', 'at'],
        'search' => ['template', 'target', 'order_no'], 'order' => 'id desc',
    ],
    'license' => [
        'table' => 'license', 'label' => '授权', 'perm' => 'system',
        'fields' => ['card_no', 'edition', 'buyer', 'activated_at', 'expires_at', 'status', 'remark'],
        'search' => ['card_no', 'buyer'], 'order' => 'id desc',
    ],

    // ---------------- 服务与技师域（02_service） ----------------
    'service-categories' => [
        'table' => 'service_category', 'label' => '服务分类', 'perm' => 'booking',
        'fields' => ['name', 'sort', 'status'], 'search' => ['name'], 'order' => 'sort desc',
    ],
    'services' => [
        'table' => 'service', 'label' => '服务项目', 'perm' => 'booking',
        'fields' => ['name', 'category_id', 'category', 'price', 'duration', 'unit', 'covers', 'detail', 'description', 'sales', 'status'],
        'search' => ['name', 'category'], 'order' => 'id desc',
    ],
    'service-specs' => [
        'table' => 'service_spec', 'label' => '服务规格', 'perm' => 'booking',
        'fields' => ['service_id', 'service', 'name', 'type', 'price', 'duration', 'sort', 'status'],
        'search' => ['name', 'service'], 'order' => 'sort desc',
    ],
    // 电子服务协议（附四 #6；EditionGate=pro；会员签署/下单校验见 AgreementController/OrderController）
    'service-agreements' => [
        'table' => 'service_agreement', 'label' => '服务协议', 'perm' => 'booking',
        'fields' => ['name', 'content', 'scope', 'service_ids', 'version', 'status'],
        'search' => ['name'], 'order' => 'id desc',
    ],
    // 协议签署流水（只增不改留痕表；used/order_no 由下单链路回写，后台仅查看）
    'agreement-signs' => [
        'table' => 'agreement_sign', 'label' => '协议签署流水', 'perm' => 'booking', 'readonly' => true,
        'fields' => [], 'search' => ['sign_no', 'agreement_name', 'member', 'phone', 'order_no'], 'order' => 'id desc',
    ],
    'service-areas' => [
        'table' => 'service_area', 'label' => '服务区域', 'perm' => 'booking',
        'fields' => ['name', 'cities', 'districts', 'start_price', 'remote_fee', 'radius', 'center_lng', 'center_lat', 'free_km', 'per_km_fen', 'travel_cap_fen', 'sort', 'status'],
        'search' => ['name', 'cities'], 'order' => 'sort desc',
    ],
    'price-rules' => [
        'table' => 'price_rule', 'label' => '价格规则', 'perm' => 'booking',
        'fields' => ['name', 'scope', 'service_ids', 'date_type', 'date_range', 'time_range', 'mode', 'value', 'priority', 'status'],
        'search' => ['name', 'scope'], 'order' => 'priority desc',
    ],
    'technicians' => [
        'table' => 'technician', 'label' => '技师', 'perm' => 'tech',
        'fields' => ['name', 'phone', 'password', 'avatar', 'gallery', 'skills', 'certs', 'store_id', 'area_ids', 'rating', 'month_orders', 'status', 'intro'],
        'search' => ['name', 'phone', 'skills'], 'order' => 'id desc',
        'hidden' => ['password'],
    ],
    'technician-services' => [
        'table' => 'technician_service', 'label' => '技师可服务项', 'perm' => 'tech',
        'fields' => ['technician_id', 'service_id', 'price'], 'search' => [], 'order' => 'id asc',
    ],
    'schedules' => [
        'table' => 'schedule', 'label' => '排班', 'perm' => 'tech',
        'fields' => ['technician_id', 'technician', 'date', 'shift', 'start_at', 'end_at', 'is_rest', 'status', 'note'],
        'search' => ['technician', 'date', 'shift'], 'order' => 'date desc',
    ],
    'attendances' => [
        'table' => 'attendance', 'label' => '考勤', 'perm' => 'tech',
        'fields' => ['technician_id', 'technician', 'date', 'clock_in', 'clock_out', 'hours', 'orders', 'status', 'note'],
        'search' => ['technician', 'date'], 'order' => 'date desc',
    ],
    'dispatch-rules' => [
        'table' => 'dispatch_rule', 'label' => '派单规则', 'perm' => 'tech',
        'fields' => ['key', 'label', 'weight', 'desc', 'status'], 'search' => ['label', 'key'],
        'order' => 'weight desc',
    ],
    'order-checkins' => [
        'table' => 'order_checkin', 'label' => '服务打卡', 'perm' => 'tech',
        'fields' => ['order_no', 'technician_id', 'kind', 'lng', 'lat', 'address', 'images', 'note', 'at'],
        'search' => ['order_no', 'address'], 'order' => 'id desc',
    ],

    // ---------------- 交易域（03_trade） ----------------
    'bookings' => [
        'table' => 'booking', 'label' => '预约订单', 'perm' => 'booking',
        'fields' => ['order_no', 'member_id', 'customer', 'phone', 'service_id', 'service', 'technician_id', 'technician', 'store_id', 'start_at', 'end_at', 'duration', 'address', 'lng', 'lat', 'remark', 'total', 'discount', 'actual', 'paid', 'pay_channel', 'coupon_code', 'status', 'cancel_reason', 'pre_status', 'aftersale_reason', 'aftersale_at', 'aftersale_result', 'source'],
        'search' => ['order_no', 'customer', 'phone', 'service', 'technician'], 'order' => 'id desc',
    ],
    'order-extends' => [
        'table' => 'order_extend', 'label' => '加钟', 'perm' => 'booking',
        'fields' => ['order_no', 'minutes', 'price', 'reason', 'operator', 'status', 'at'],
        'search' => ['order_no', 'operator'], 'order' => 'id desc',
    ],
    'booking-queues' => [
        'table' => 'booking_queue', 'label' => '候补排队', 'perm' => 'booking',
        // 功能开关：setting queue_enabled=0 时拒绝新增（此前开关无任何消费点）
        'require_setting' => ['key' => 'queue_enabled', 'msg' => '候补排队已在「系统设置 → 预约规则」中关闭，如需启用请先打开开关'],
        'fields' => ['member_id', 'customer', 'phone', 'service_id', 'service', 'date', 'time_slot', 'seq', 'status', 'note'],
        'search' => ['customer', 'phone', 'service'], 'order' => 'date asc, seq asc',
    ],
    'deposits' => [
        'table' => 'deposit', 'label' => '定金', 'perm' => 'finance',
        // 功能开关：setting deposit_enabled=0 时拒绝新增（此前开关无任何消费点）
        'require_setting' => ['key' => 'deposit_enabled', 'msg' => '定金尾款已在「系统设置 → 预约规则」中关闭，如需启用请先打开开关'],
        'fields' => ['order_no', 'kind', 'amount', 'pay_channel', 'status', 'paid_at'],
        'search' => ['order_no'], 'order' => 'id desc',
    ],
    'pay-transactions' => [
        'table' => 'pay_transaction', 'label' => '支付流水', 'perm' => 'finance',
        'fields' => ['order_no', 'out_trade_no', 'channel', 'kind', 'amount', 'status', 'paid_at', 'raw'],
        'search' => ['order_no', 'out_trade_no'], 'order' => 'id desc',
    ],
    'refunds' => [
        'table' => 'refund', 'label' => '退款', 'perm' => 'finance',
        'fields' => ['refund_no', 'order_no', 'member_id', 'member', 'amount', 'channel', 'reason', 'reject_reason', 'operator', 'status', 'refunded_at'],
        'search' => ['refund_no', 'order_no', 'member'], 'order' => 'id desc',
    ],
    'invoices' => [
        'table' => 'invoice', 'label' => '发票', 'perm' => 'finance',
        'fields' => ['order_no', 'member_id', 'member', 'type', 'title_type', 'title', 'tax_no', 'amount', 'status', 'invoice_no', 'reject_reason'],
        'search' => ['order_no', 'member', 'title'], 'order' => 'id desc',
    ],
    'finance-reconciles' => [
        'table' => 'finance_reconcile', 'label' => '财务对账', 'perm' => 'finance',
        'fields' => ['order_no', 'channel', 'gross', 'discount', 'actual', 'fee', 'net', 'at', 'status', 'diff'],
        'search' => ['order_no', 'channel'], 'order' => 'id desc',
    ],
    'commission-rules' => [
        'table' => 'commission_rule', 'label' => '提成规则', 'perm' => 'finance',
        'fields' => ['name', 'scope', 'target_ids', 'mode', 'value', 'ladder', 'status'],
        'search' => ['name', 'scope'], 'order' => 'id desc',
    ],
    'commission-settlements' => [
        'table' => 'commission_settlement', 'label' => '提成结算', 'perm' => 'finance',
        'fields' => ['technician_id', 'technician', 'month', 'orders', 'gross', 'commission', 'bonus', 'penalty', 'actual', 'status', 'note'],
        'search' => ['technician', 'month'], 'order' => 'month desc',
    ],

    // ---------------- 会员域（04_member） ----------------
    'members' => [
        'table' => 'member', 'label' => '会员', 'perm' => 'member',
        'fields' => ['name', 'phone', 'avatar', 'wechat_openid', 'level', 'gender', 'birthday', 'store_id', 'remark', 'r_score', 'f_score', 'm_score', 'rfm_tag', 'total_amount', 'total_orders', 'last_order_at', 'status'],
        'search' => ['name', 'phone', 'rfm_tag'], 'order' => 'id desc',
    ],
    'member-addresses' => [
        'table' => 'member_address', 'label' => '会员地址', 'perm' => 'member',
        'fields' => ['member_id', 'contact', 'phone', 'address', 'lng', 'lat', 'is_default'],
        'search' => ['contact', 'phone', 'address'], 'order' => 'id desc',
    ],
    'member-tags' => [
        'table' => 'member_tag', 'label' => '会员标签', 'perm' => 'member',
        'fields' => ['name', 'kind', 'rule', 'color', 'members', 'status'],
        'search' => ['name', 'kind'], 'order' => 'id desc',
    ],
    'member-tag-rels' => [
        'table' => 'member_tag_rel', 'label' => '会员标签关联', 'perm' => 'member',
        'fields' => ['member_id', 'tag_id'], 'search' => [], 'order' => 'id asc',
    ],
    'member-cards' => [
        'table' => 'member_card', 'label' => '会员卡', 'perm' => 'member',
        'fields' => ['card_no', 'member_id', 'member', 'phone', 'name', 'type', 'cycle', 'total', 'used', 'amount', 'cross_store', 'expires', 'status'],
        'search' => ['card_no', 'member', 'phone', 'name'], 'order' => 'id desc',
    ],
    'card-types' => [
        'table' => 'card_type', 'label' => '可购卡种', 'perm' => 'member',
        'fields' => ['name', 'type', 'cycle', 'total', 'amount', 'days', 'sort', 'status'],
        'search' => ['name'], 'order' => 'sort asc, id asc',
    ],
    'member-card-logs' => [
        'table' => 'member_card_log', 'label' => '会员卡流水', 'perm' => 'member',
        'fields' => ['card_id', 'card_no', 'action', 'times', 'order_no', 'detail', 'at'],
        'search' => ['card_no', 'order_no'], 'order' => 'id desc',
    ],
    'stored-accounts' => [
        'table' => 'stored_account', 'label' => '储值账户', 'perm' => 'member',
        'fields' => ['account_no', 'member_id', 'member', 'phone', 'level', 'balance', 'total_recharge', 'total_consume'],
        'search' => ['account_no', 'member', 'phone'], 'order' => 'id desc',
    ],
    'recharge-plans' => [
        'table' => 'recharge_plan', 'label' => '充值套餐', 'perm' => 'member',
        'fields' => ['money', 'gift', 'sort', 'status'], 'search' => [], 'order' => 'sort desc',
    ],
    'stored-logs' => [
        'table' => 'stored_log', 'label' => '储值流水', 'perm' => 'member',
        'fields' => ['account_id', 'account_no', 'action', 'amount', 'balance', 'order_no', 'detail', 'at'],
        'search' => ['account_no', 'order_no'], 'order' => 'id desc',
    ],
    'coupons' => [
        'table' => 'coupon', 'label' => '优惠券', 'perm' => 'member',
        'fields' => ['code', 'name', 'type', 'value', 'threshold', 'total', 'sent', 'used', 'expires', 'status'],
        'search' => ['name', 'code', 'type'], 'order' => 'id desc',
    ],
    'coupon-instances' => [
        'table' => 'coupon_instance', 'label' => '券码', 'perm' => 'member',
        'fields' => ['code', 'coupon_id', 'coupon_name', 'member_id', 'member', 'phone', 'source', 'at', 'status', 'used_at', 'order_no'],
        'search' => ['code', 'member', 'phone', 'order_no'], 'order' => 'id desc',
    ],
    'coupon-logs' => [
        'table' => 'coupon_log', 'label' => '券流水', 'perm' => 'member',
        'fields' => ['coupon_id', 'coupon', 'action', 'count', 'detail', 'at'],
        'search' => ['coupon', 'action'], 'order' => 'id desc',
    ],
    'timesheet-cards' => [
        'table' => 'timesheet_card', 'label' => '次卡商品', 'perm' => 'member',
        'fields' => ['name', 'service_id', 'service', 'times', 'price', 'origin_price', 'valid', 'cross_store', 'sold', 'status'],
        'search' => ['name', 'service'], 'order' => 'id desc',
    ],

    // ---------------- 营销与客户运营域（05_marketing） ----------------
    'marketing-activities' => [
        'table' => 'marketing_activity', 'label' => '营销活动', 'perm' => 'member',
        // per_limit=每人限购（0=不限，参与下单限购校验）；sold/joined 为系统计数不开放写
        'fields' => ['name', 'kind', 'target', 'service_id', 'price', 'origin_price', 'group_size', 'stock', 'per_limit', 'start_at', 'end_at', 'status'],
        'search' => ['name', 'kind', 'target'], 'order' => 'id desc',
    ],
    // 拼团团单：活动派生数据（parent 声明 → 删活动时级联软删，防孤儿团单）
    'marketing-groups' => [
        'table' => 'marketing_group', 'label' => '拼团团单', 'perm' => 'member',
        'parent' => ['table' => 'marketing_activity', 'fk' => 'activity_id'],
        'fields' => ['group_no', 'activity_id', 'activity_name', 'leader_member_id', 'leader_name', 'size', 'joined', 'status', 'order_no', 'expire_at'],
        'search' => ['group_no', 'activity_name', 'leader_name'], 'order' => 'id desc',
    ],
    // 拼团参团记录：团单派生数据（parent 声明 → 删团单时级联软删）
    'marketing-group-members' => [
        'table' => 'marketing_group_member', 'label' => '拼团成员', 'perm' => 'member',
        'parent' => ['table' => 'marketing_group', 'fk' => 'group_id'],
        'fields' => ['group_id', 'group_no', 'member_id', 'member', 'order_no', 'is_leader', 'status', 'joined_at'],
        'search' => ['group_no', 'member', 'order_no'], 'order' => 'id asc',
    ],
    'referral-rules' => [
        'table' => 'referral_rule', 'label' => '推荐有礼规则', 'perm' => 'member',
        'fields' => ['name', 'inviter_reward', 'invitee_reward', 'condition', 'issued', 'status'],
        'search' => ['name'], 'order' => 'id desc',
    ],
    'referral-logs' => [
        'table' => 'referral_log', 'label' => '推荐记录', 'perm' => 'member',
        'fields' => ['inviter_id', 'inviter', 'invitee_id', 'invitee', 'reward', 'status', 'detail', 'at'],
        'search' => ['inviter', 'invitee'], 'order' => 'id desc',
    ],
    'point-rules' => [
        'table' => 'point_rule', 'label' => '积分规则', 'perm' => 'member',
        'fields' => ['name', 'kind', 'rule', 'limit', 'status'], 'search' => ['name', 'kind'], 'order' => 'id desc',
    ],
    'point-logs' => [
        'table' => 'point_log', 'label' => '积分流水', 'perm' => 'member',
        'fields' => ['member_id', 'member', 'phone', 'action', 'points', 'balance', 'order_no', 'detail', 'at'],
        'search' => ['member', 'phone', 'order_no'], 'order' => 'id desc',
    ],
    'gifts' => [
        'table' => 'gift', 'label' => '积分商城礼品', 'perm' => 'member',
        'fields' => ['name', 'points', 'stock', 'image', 'detail', 'exchanged', 'limit_per_user', 'end_at', 'status'],
        'search' => ['name'], 'order' => 'id desc',
    ],
    'gift-exchanges' => [
        'table' => 'gift_exchange', 'label' => '积分兑换单', 'perm' => 'member',
        'fields' => ['exchange_no', 'member_id', 'member', 'phone', 'gift_id', 'gift_name', 'points', 'qty', 'contact', 'contact_phone', 'address', 'status', 'express_no', 'remark', 'at'],
        'search' => ['exchange_no', 'member', 'gift_name', 'contact'], 'order' => 'id desc',
    ],
    'reviews' => [
        'table' => 'review', 'label' => '评价', 'perm' => 'member',
        'fields' => ['order_no', 'member_id', 'customer', 'service', 'technician_id', 'technician', 'rating', 'content', 'images', 'reply', 'status'],
        'search' => ['order_no', 'customer', 'technician'], 'order' => 'id desc',
    ],
    'review-rewards' => [
        'table' => 'review_reward', 'label' => '评价有礼', 'perm' => 'member',
        'fields' => ['name', 'condition', 'reward', 'issued', 'cost', 'status'],
        'search' => ['name'], 'order' => 'id desc',
    ],
    'distributors' => [
        'table' => 'distributor', 'label' => '推广员', 'perm' => 'member',
        'fields' => ['name', 'phone', 'member_id', 'level', 'team', 'orders', 'commission', 'pending', 'withdrawn', 'rule', 'status'],
        'search' => ['name', 'phone'], 'order' => 'id desc',
    ],
    'distribution-orders' => [
        'table' => 'distribution_order', 'label' => '分销订单', 'perm' => 'member',
        'fields' => ['order_no', 'distributor_id', 'distributor', 'level', 'order_amount', 'commission', 'status', 'at'],
        'search' => ['order_no', 'distributor'], 'order' => 'id desc',
    ],
    'distribution-withdraws' => [
        // 提现单只读：审核走 PUT /api/v1/distribution-withdraws/{id}/audit（MemberActionController）
        'table' => 'distribution_withdraw', 'label' => '提现单', 'perm' => 'member', 'readonly' => true,
        'fields' => ['distributor_id', 'distributor', 'amount', 'status', 'channel', 'apply_at', 'audit_at', 'remark'],
        'search' => ['distributor'], 'order' => 'id desc',
    ],
    'follow-up-tasks' => [
        'table' => 'follow_up_task', 'label' => '回访任务', 'perm' => 'member',
        'fields' => ['order_no', 'customer', 'technician', 'kind', 'due_at', 'owner', 'status', 'result'],
        'search' => ['order_no', 'customer', 'owner'], 'order' => 'id desc',
    ],
    'complaints' => [
        'table' => 'complaint', 'label' => '投诉工单', 'perm' => 'member',
        'fields' => ['order_no', 'customer', 'target', 'kind', 'level', 'content', 'owner', 'resolution', 'status'],
        'search' => ['order_no', 'customer', 'owner'], 'order' => 'id desc',
    ],
    'auto-marketing-rules' => [
        'table' => 'auto_marketing_rule', 'label' => '自动化营销', 'perm' => 'member',
        'fields' => ['name', 'trigger', 'condition', 'action', 'sent', 'last_run_date', 'status'],
        'search' => ['name', 'trigger'], 'order' => 'id desc',
    ],
    // 工单流转日志（回访/投诉时间线；服务端写入，后台可按对象检索与归档）
    'workflow-logs' => [
        'table' => 'workflow_log', 'label' => '工单流转日志', 'perm' => 'member',
        'fields' => ['resource', 'biz_id', 'action', 'from_status', 'to_status', 'owner', 'remark', 'operator'],
        'search' => ['resource', 'operator', 'remark'], 'order' => 'id desc',
    ],
];
