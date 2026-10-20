// =====================================================================
// 订单动作 API（对接后端 OrderController / PayController）
// 与 useCrud 同口径：调用方捕获 HttpDownError 兜底内存 mock，
// BizError（code!==0）上抛提示，不吞业务错误
// 契约：金额只信服务端（extend/refund 不回传金额）；状态流转以服务端状态机为准
// =====================================================================

import { request } from './http'

const enc = encodeURIComponent

export const ordersApi = {
  /** 订单详情（含加钟/打卡记录） */
  detail: (orderNo) => request(`/orders/${enc(orderNo)}`),

  /** 下单（服务计价/档期校验在服务端）body: {service_id,start_at,customer,phone,address,technician_id?,duration?,remark?,source?} */
  create: (body) => request('/orders', { method: 'POST', body }),

  /** 取消（违约金服务端复算） */
  cancel: (orderNo, reason) => request(`/orders/${enc(orderNo)}/cancel`, { method: 'PUT', body: { reason } }),

  /** 标记客户爽约（仅待服务；累计月内爽约次数，超限按 noshow_freeze_days 冻结该会员下单） */
  noshow: (orderNo, reason) => request(`/orders/${enc(orderNo)}/noshow`, { method: 'POST', body: { reason } }),

  /** 改约（仅待接单/待服务，档期冲突服务端校验） */
  reschedule: (orderNo, startAt) => request(`/orders/${enc(orderNo)}/reschedule`, { method: 'PUT', body: { start_at: startAt } }),

  /** 加钟（仅服务中；差价服务端按比例复算，confirm=1 立即生效） */
  extend: (orderNo, body) => request(`/orders/${enc(orderNo)}/extend`, { method: 'POST', body }),

  /** 加钟审批（extend_id, approve） */
  extendConfirm: (orderNo, extendId, approve) =>
    request(`/orders/${enc(orderNo)}/extend-confirm`, { method: 'POST', body: { extend_id: extendId, approve: approve ? 1 : 0 } }),

  /** 派单/改派（待派单→待接单；待接单仅换技师） */
  dispatch: (orderNo, technicianId) => request(`/orders/${enc(orderNo)}/dispatch`, { method: 'POST', body: { technician_id: technicianId } }),

  /** 技师接单（待接单→待服务） */
  accept: (orderNo) => request(`/orders/${enc(orderNo)}/accept`, { method: 'POST' }),

  /** 技师拒单（待接单→回待派单） */
  reject: (orderNo, reason) => request(`/orders/${enc(orderNo)}/reject`, { method: 'POST', body: { reason } }),

  /** 服务打卡（kind: 上门/开始/结束；「开始」流转 待服务→服务中） */
  checkin: (orderNo, body) => request(`/orders/${enc(orderNo)}/checkin`, { method: 'POST', body }),

  /** 完单（服务中→待确认） */
  finish: (orderNo) => request(`/orders/${enc(orderNo)}/finish`, { method: 'POST' }),

  /** 用户确认完成（待确认→已完成） */
  confirm: (orderNo) => request(`/orders/${enc(orderNo)}/confirm`, { method: 'POST' }),

  /** 转售后（记录 pre_status 供驳回回退） */
  aftersale: (orderNo, reason) => request(`/orders/${enc(orderNo)}/aftersale`, { method: 'POST', body: { reason } }),

  /** 售后驳回（回 pre_status） */
  aftersaleReject: (orderNo, reason) => request(`/orders/${enc(orderNo)}/aftersale-reject`, { method: 'POST', body: { reason } }),

  /** 售后退款（售后中→已退款终态，落 refund 单；金额服务端按实付全额退） */
  refund: (orderNo, reason) => request(`/orders/${enc(orderNo)}/refund`, { method: 'POST', body: { reason } })
}

/**
 * 收款确认（PayController/confirm）
 * body: {order_no, channel: alipay|wechat|stored|offline}
 * 支付后分流服务端判定：指定技师→待接单；未指定→待派单
 */
export function payConfirm(body) {
  return request('/pay/confirm', { method: 'POST', body })
}
