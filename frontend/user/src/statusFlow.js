// =====================================================
// booking 统一订单状态字典（业务错位整改 · 阶段 0 地基）
// 事实源：业务错位整改方案 3.2 统一订单状态机
// ⚠️ admin / user / mobile 三份副本必须逐字节一致（收尾 diff 断言）
// ⚠️ 后端镜像：backend/app/service/OrderService.php::TRANSITIONS（同步修改）
// 主流程：待支付 → 待派单 → 待接单 → 待服务 → 服务中 → 待确认 → 已完成 → 待评价 → 已评价
// 逆向：已取消（各阶段按预约规则）/ 售后中 → 已退款
// 指定技师下单：待支付 →（支付）→ 待接单（跳过待派单）
// 拒单：待接单 →（拒单）→ 回待派单（重新指派/改派）
// =====================================================

/** 主流程九态（顺序 = 流转方向） */
export const MAIN_FLOW = [
  '待支付', '待派单', '待接单', '待服务', '服务中', '待确认', '已完成', '待评价', '已评价',
]

/** 逆向态 */
export const REVERSE_FLOW = ['已取消', '售后中', '已退款']

/** 全部状态 */
export const ALL_STATUSES = [...MAIN_FLOW, ...REVERSE_FLOW]

/** 终态（不允许任何流转） */
export const TERMINAL_STATUSES = ['已取消', '已退款']

/** 统计口径：看板/报表的「已完成订单」= 三态合计（已完成不再是终态） */
export const FINISHED_SET = ['已完成', '待评价', '已评价']

/** 状态展示元数据（标签/主题色） */
export const STATUS_META = {
  '待支付': { label: '待支付', type: 'warning', desc: '订单已创建，等待支付' },
  '待派单': { label: '待派单', type: 'warning', desc: '已支付，等待平台指派技师' },
  '待接单': { label: '待接单', type: 'primary', desc: '已指派技师，等待接单' },
  '待服务': { label: '待服务', type: 'primary', desc: '技师已接单，等待上门服务' },
  '服务中': { label: '服务中', type: 'primary', desc: '服务进行中' },
  '待确认': { label: '待确认', type: 'info', desc: '服务完成，等待用户确认' },
  '已完成': { label: '已完成', type: 'success', desc: '订单已完成，可去评价' },
  '待评价': { label: '待评价', type: 'success', desc: '等待用户评价' },
  '已评价': { label: '已评价', type: 'success', desc: '订单闭环完成' },
  '已取消': { label: '已取消', type: 'info', desc: '订单已取消' },
  '售后中': { label: '售后中', type: 'danger', desc: '售后处理中' },
  '已退款': { label: '已退款', type: 'info', desc: '售后完成，已退款' },
}

/**
 * 状态流转白名单（与后端 TRANSITIONS 逐项一致，其余一律拒绝）
 * 「售后中 → 回原状态」= 售后审核驳回（原状态 ∈ 服务中/待确认/已完成/待评价/已评价）
 */
export const TRANSITIONS = {
  '待支付': ['待派单', '待接单', '已取消'],
  '待派单': ['待接单', '已取消'],
  '待接单': ['待服务', '待派单', '已取消'],
  '待服务': ['服务中', '已取消'],
  '服务中': ['待确认', '售后中'],
  '待确认': ['已完成'],
  '已完成': ['待评价', '售后中'],
  '待评价': ['已评价', '售后中'],
  '已评价': ['售后中'],
  '售后中': ['已退款', '服务中', '待确认', '已完成', '待评价', '已评价'],
  '已取消': [],
  '已退款': [],
}

/**
 * 各状态允许的动作（按钮口径，三端按 by 过滤）
 * to = 次态；null = 动态决定（如支付：未指定技师→待派单，已指定→待接单）
 */
export const STATUS_ACTIONS = {
  '待支付': [
    { key: 'pay', label: '支付/收款', to: null, by: ['user', 'admin'] },
    { key: 'cancel', label: '取消订单', to: '已取消', by: ['user', 'admin'] },
  ],
  '待派单': [
    { key: 'dispatch', label: '指派/改派', to: '待接单', by: ['admin'] },
    { key: 'grab', label: '抢单', to: '待接单', by: ['mobile'] },
    { key: 'cancel', label: '取消订单', to: '已取消', by: ['user', 'admin'] },
  ],
  '待接单': [
    { key: 'accept', label: '接单', to: '待服务', by: ['mobile'] },
    { key: 'reject', label: '拒单', to: '待派单', by: ['mobile'] },
    { key: 'dispatch', label: '改派', to: null, by: ['admin'] },
    { key: 'cancel', label: '取消订单', to: '已取消', by: ['user', 'admin'] },
  ],
  '待服务': [
    { key: 'reschedule', label: '改约', to: null, by: ['user', 'admin', 'mobile'] },
    { key: 'start', label: '到达打卡·开始服务', to: '服务中', by: ['mobile', 'admin'] },
    { key: 'noshow', label: '客户爽约', to: '已取消', by: ['admin', 'mobile'] },
    { key: 'cancel', label: '取消订单', to: '已取消', by: ['user', 'admin'] },
  ],
  '服务中': [
    { key: 'extend', label: '加钟', to: '服务中', by: ['mobile', 'admin'] },
    { key: 'finish', label: '完成服务', to: '待确认', by: ['mobile', 'admin'] },
    { key: 'aftersale', label: '纠纷转售后', to: '售后中', by: ['admin'] },
  ],
  '待确认': [
    { key: 'confirm', label: '确认完成', to: '已完成', by: ['user'] },
  ],
  '已完成': [
    { key: 'rate', label: '去评价', to: '待评价', by: ['user'] },
    { key: 'aftersale', label: '申请售后', to: '售后中', by: ['user', 'admin'] },
  ],
  '待评价': [
    { key: 'submitReview', label: '提交评价', to: '已评价', by: ['user'] },
    { key: 'aftersale', label: '申请售后', to: '售后中', by: ['user', 'admin'] },
  ],
  '已评价': [
    { key: 'aftersale', label: '申请售后', to: '售后中', by: ['user', 'admin'] },
  ],
  '售后中': [
    { key: 'refund', label: '退款', to: '已退款', by: ['admin'] },
    { key: 'rejectAftersale', label: '审核驳回', to: null, by: ['admin'] },
  ],
  '已取消': [],
  '已退款': [],
}

/** 判断流转是否合法（与后端 canTransition 同口径） */
export function canTransition(from, to) {
  return (TRANSITIONS[from] || []).includes(to)
}

/** 取某状态下某端允许的动作 */
export function allowedActions(status, role) {
  return (STATUS_ACTIONS[status] || []).filter((a) => a.by.includes(role))
}

/** 订单是否计入「已完成订单」统计口径 */
export function isFinishedSettled(status) {
  return FINISHED_SET.includes(status)
}
