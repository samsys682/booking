// =====================================================================
// 订单 API —— 顾客端小程序核心交易链路（下单/支付/流转/评价）
// 与 user H5 api/orders.js 同构（camelCase + 金额分→元）。
// 路由动词事实源 route/app.php：cancel/reschedule=PUT，confirm/aftersale=POST
// =====================================================================

import { request } from './http'

const MONEY_FIELDS = ['total', 'discount', 'actual', 'paid']
const PAYMENT_MONEY_FIELDS = ['deposit', 'balance', 'payable', 'paidSum']

function camelKey(k) {
  return String(k).replace(/_([a-z])/g, (_, c) => c.toUpperCase())
}

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
  if (out.payment && typeof out.payment === 'object') {
    const p = camelObj(out.payment)
    for (const f of PAYMENT_MONEY_FIELDS) {
      if (p[f] !== undefined && p[f] !== null && p[f] !== '') p[f] = Math.round(Number(p[f])) / 100
    }
    out.payment = p
  }
  return out
}

/** 我的订单 → { list:[视图行], total, page, perPage } */
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
 * 下单（金额服务端按服务价目计算）
 * body: service_id, start_at, duration, customer, phone, address, remark, technician_id?, coupon_code?, agreement_token?
 */
export async function createOrder(body) {
  return mapBooking(await request('/orders', { method: 'POST', body }))
}

/** 取消（违约金由服务端按取消时限计入，返回 {order_no, cancel_fee}，cancel_fee 单位分） */
export function cancelOrder(orderNo, reason) {
  return request(`/orders/${encodeURIComponent(orderNo)}/cancel`, { method: 'PUT', body: { reason: reason || '' } })
}

/** 改期（仅待接单/待服务） */
export function rescheduleOrder(orderNo, startAt) {
  return request(`/orders/${encodeURIComponent(orderNo)}/reschedule`, { method: 'PUT', body: { start_at: startAt } })
}

/** 确认完成 */
export function confirmOrder(orderNo) {
  return request(`/orders/${encodeURIComponent(orderNo)}/confirm`, { method: 'POST' })
}

/** 转售后 */
export function aftersaleOrder(orderNo, reason) {
  return request(`/orders/${encodeURIComponent(orderNo)}/aftersale`, { method: 'POST', body: { reason: reason || '' } })
}

/**
 * 确认收款（到店付/储值等线下通道，真实扣款/状态分流由服务端完成；
 * 微信支付 wx.requestPayment 不做——无商户权限，收款默认到店付/储值）
 */
export function payConfirm(orderNo, channel, amount) {
  const body = { order_no: orderNo, channel: channel || '到店付' }
  if (amount !== undefined && amount !== null && amount !== '') body.amount = Math.round(Number(amount) * 100)
  return request('/pay/confirm', { method: 'POST', body })
}

/** 发表评价（服务端自动流转 待评价→已评价 并防重复评价） */
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
