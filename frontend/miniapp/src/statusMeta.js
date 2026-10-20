// =====================================================================
// 订单状态展示字典 —— 顾客端小程序精简版
// 口径源自三端同构 statusFlow.js（admin/user/mobile 逐字节副本）：
// 小程序端为避免出现第四份同构副本，仅保留顾客域需要的 META 与
// user 动作子集（对齐 STATUS_ACTIONS 中 by 含 'user' 的项）。
// 修改状态机时须同步 statusFlow.js 与后端 OrderService::TRANSITIONS。
// =====================================================================

/** 状态展示元数据（标签主题色 → tag 类） */
export const STATUS_META = {
  '待支付': { type: 'warning', desc: '订单已创建，等待支付' },
  '待派单': { type: 'warning', desc: '已支付，等待平台指派技师' },
  '待接单': { type: 'primary', desc: '已指派技师，等待接单' },
  '待服务': { type: 'primary', desc: '技师已接单，等待上门服务' },
  '服务中': { type: 'primary', desc: '服务进行中' },
  '待确认': { type: 'info', desc: '服务完成，等待用户确认' },
  '已完成': { type: 'success', desc: '订单已完成，可去评价' },
  '待评价': { type: 'success', desc: '等待用户评价' },
  '已评价': { type: 'success', desc: '订单闭环完成' },
  '已取消': { type: 'info', desc: '订单已取消' },
  '售后中': { type: 'danger', desc: '售后处理中' },
  '已退款': { type: 'info', desc: '售后完成，已退款' }
}

/** 主题色 → tag 类（小程序端样式映射） */
export const TYPE_TAG = {
  warning: 'tag--orange',
  primary: 'tag--green',
  info: 'tag--gray',
  success: 'tag--green',
  danger: 'tag--red'
}

export function statusTag(s) {
  return TYPE_TAG[STATUS_META[s]?.type] || 'tag--gray'
}

/**
 * 顾客端各状态可用动作（口径 = statusFlow.js::STATUS_ACTIONS 的 user 子集）
 * detail: 是否在详情页操作（改约/评价/售后等复杂动作）
 */
export const USER_ACTIONS = {
  '待支付': [
    { key: 'pay', label: '去支付' },
    { key: 'cancel', label: '取消订单' }
  ],
  '待派单': [{ key: 'cancel', label: '取消订单' }],
  '待接单': [
    { key: 'reschedule', label: '改约', detail: true },
    { key: 'cancel', label: '取消订单' }
  ],
  '待服务': [
    { key: 'reschedule', label: '改约', detail: true },
    { key: 'cancel', label: '取消订单' }
  ],
  '待确认': [{ key: 'confirm', label: '确认完成' }],
  '已完成': [
    { key: 'rate', label: '去评价', detail: true },
    { key: 'aftersale', label: '申请售后', detail: true }
  ],
  '待评价': [
    { key: 'submitReview', label: '填写评价', detail: true },
    { key: 'aftersale', label: '申请售后', detail: true }
  ],
  '已评价': [{ key: 'aftersale', label: '申请售后', detail: true }]
}

/** 订单是否已完成闭环集合（可「再来一单」） */
export const FINISHED_SET = ['已完成', '待评价', '已评价']
