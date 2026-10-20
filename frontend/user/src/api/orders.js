// =====================================================================
// 订单 API —— 用户端核心交易链路（下单/支付/流转/评价）
// 后端 booking 行为 snake_case + 金额「分」；视图层统一 camelCase + 「元」，
// 由 mapBooking 双向换算。请求失败由页面报错提示。
// 路由动词事实源 route/app.php：cancel/reschedule=PUT，confirm/aftersale=POST
// =====================================================================

import { request } from './http'

const MONEY_FIELDS = ['total', 'discount', 'actual', 'paid']
// 定金尾款收款摘要内的金额字段（分→元）
const PAYMENT_MONEY_FIELDS = ['deposit', 'balance', 'payable', 'paidSum']

/** snake_case → camelCase */
function camelKey(k) {
  return String(k).replace(/_([a-z])/g, (_, c) => c.toUpperCase())
}

/** 嵌套对象键 camelCase（用于后端附加的 payment 摘要） */
function camelObj(o) {
  const out = {}
  for (const [k, v] of Object.entries(o || {})) out[camelKey(k)] = v
  return out
}

/** 后端 booking 行 → 视图订单形状（camel + 金额分→元） */
export function mapBooking(row) {
  const out = {}
  for (const [k, v] of Object.entries(row || {})) out[camelKey(k)] = v
  for (const f of MONEY_FIELDS) {
    if (out[f] !== undefined && out[f] !== null && out[f] !== '') out[f] = Math.round(Number(out[f])) / 100
  }
  // 定金尾款收款摘要（后端附加字段；depositEnabled=false 时 payable=actual，可忽略）
  if (out.payment && typeof out.payment === 'object') {
    const p = camelObj(out.payment)
    for (const f of PAYMENT_MONEY_FIELDS) {
      if (p[f] !== undefined && p[f] !== null && p[f] !== '') p[f] = Math.round(Number(p[f])) / 100
    }
    out.payment = p
  }
  return out
}

/** 我的订单（member 按本人、tech 按派单过滤）→ { list:[视图行], total, page, per_page } */
export async function myOrders(params = {}) {
  const data = await request('/orders/my', { query: params })
  return {
    list: (data.list || []).map(mapBooking),
    total: Number(data.total || 0),
    page: Number(data.page || 1),
    perPage: Number(data.per_page || 20)
  }
}

/** 订单详情 */
export async function orderDetail(orderNo) {
  return mapBooking(await request(`/orders/${encodeURIComponent(orderNo)}`))
}

/**
 * 下单（金额服务端按服务价目计算，不接受前端金额）
 * body: service_id, start_at, duration, customer, phone, address, lng, lat, remark, technician_id?, coupon_code?
 */
export async function createOrder(body) {
  return mapBooking(await request('/orders', { method: 'POST', body }))
}

/** 取消（违约金由服务端按取消时限计入，返回 {order_no, cancel_fee}，cancel_fee 单位分） */
export function cancelOrder(orderNo, reason) {
  return request(`/orders/${encodeURIComponent(orderNo)}/cancel`, { method: 'PUT', body: { reason: reason || '' } })
}

/** 改期（仅待接单/待服务，返回冲突 409 / 时间窗 400 由页面提示） */
export function rescheduleOrder(orderNo, startAt) {
  return request(`/orders/${encodeURIComponent(orderNo)}/reschedule`, { method: 'PUT', body: { start_at: startAt } })
}

/** 确认完成（待确认→已完成，会员消费统计服务端落账） */
export function confirmOrder(orderNo) {
  return request(`/orders/${encodeURIComponent(orderNo)}/confirm`, { method: 'POST' })
}

/** 转售后（服务中/已完成/待评价/已评价→售后中，服务端记 pre_status） */
export function aftersaleOrder(orderNo, reason) {
  return request(`/orders/${encodeURIComponent(orderNo)}/aftersale`, { method: 'POST', body: { reason: reason || '' } })
}

/**
 * 确认收款（支付成功落账 + 状态分流：有技师→待接单，无→待派单）
 * amount 为「元」，传入须与订单应付一致（不传由服务端按应付入账）
 */
export function payConfirm(orderNo, channel, amount) {
  const body = { order_no: orderNo, channel: channel || 'offline' }
  if (amount !== undefined && amount !== null && amount !== '') body.amount = Math.round(Number(amount) * 100)
  return request('/pay/confirm', { method: 'POST', body })
}

/**
 * 发表评价（服务端自动流转 待评价→已评价 并防重复评价）
 * body: { orderNo, rating 1-5, content 必填, images 数组 }
 */
export function createReview({ orderNo, rating, content, images }) {
  return request('/reviews', {
    method: 'POST',
    body: {
      order_no: orderNo,
      rating: rating,
      content: content,
      images: Array.isArray(images) ? images : []
    }
  })
}
