// =====================================================================
// 订单 API —— 技师端核心链路（我的单/派单池/抢单/接单/打卡/流转）
// 后端 booking 行为 snake_case + 金额「分」；视图层统一 camelCase + 「元」
// 佣金口径：commission = amount × 55%（前端展示口径，后端 booking 无 commission 列）
// =====================================================================

import { request } from './http'

export const COMMISSION_RATE = 0.55

/** 后端 booking 行 → 视图订单形状（camel + 金额分→元 + date/time 拆分） */
export function mapBooking(row) {
  const r = row || {}
  const amount = Math.round(Number(r.actual ?? r.total ?? 0)) / 100
  const startAt = String(r.start_at || '')
  return {
    id: r.id ?? r.order_no,
    orderNo: r.order_no || '',
    customer: r.customer || '',
    phone: r.phone || '',
    serviceId: r.service_id ?? '',
    service: r.service || r.service_name || '',
    startAt,
    date: startAt.slice(0, 10),
    time: startAt.slice(11, 16),
    amount,
    commission: Math.round(amount * COMMISSION_RATE * 10) / 10,
    status: r.status || '',
    note: r.remark || '',
    cancelReason: r.cancel_reason || '',
    technician: r.technician || '',
    technicianId: Number(r.technician_id ?? 0),
    createdAt: r.created_at || ''
  }
}

// 后端打卡 kind → 视图动作名（与 mock orderCheckins.action 口径一致）
const KIND_ACTION = { 上门: '到达打卡', 开始: '开始服务', 结束: '完成服务' }

/** 详情里的 checkins 行 → 视图打卡行 */
export function mapCheckins(raw) {
  return (raw.checkins || []).map((c, i) => ({
    id: c.id ?? 'CK' + i,
    orderNo: c.order_no || raw.order_no || '',
    at: c.at || c.created_at || '',
    action: KIND_ACTION[c.kind] || c.kind || '',
    photo: (Array.isArray(c.images) && c.images[0]) || '',
    note: c.note || ''
  }))
}

/** 我的订单（tech 按派单过滤）→ { list:[视图行], total, page, perPage } */
export async function myOrders(params = {}) {
  const data = await request('/orders/my', { query: params })
  return {
    list: (data.list || []).map(mapBooking),
    total: Number(data.total || 0),
    page: Number(data.page || 1),
    perPage: Number(data.per_page || 20)
  }
}

/** 派单池（待派单 + 未指派，抢单候选） */
export async function poolOrders(params = {}) {
  const data = await request('/orders/pool', { query: params })
  return {
    list: (data.list || []).map(mapBooking),
    total: Number(data.total || 0)
  }
}

/** 订单详情（含 extends + checkins） */
export async function orderDetail(orderNo) {
  const raw = await request(`/orders/${encodeURIComponent(orderNo)}`)
  return { ...mapBooking(raw), checkins: mapCheckins(raw) }
}

/** 抢单（待派单+未指派 → 待接单 自指派；并发 409 由页面提示） */
export function grabOrder(orderNo) {
  return request(`/orders/${encodeURIComponent(orderNo)}/grab`, { method: 'POST' })
}

/** 接单（待接单 → 待服务） */
export function acceptOrder(orderNo) {
  return request(`/orders/${encodeURIComponent(orderNo)}/accept`, { method: 'POST' })
}

/** 拒单（待接单 → 回待派单并释放技师回派单池） */
export function rejectOrder(orderNo, reason) {
  return request(`/orders/${encodeURIComponent(orderNo)}/reject`, { method: 'POST', body: { reason: reason || '' } })
}

/** 服务打卡（kind: 上门|开始|结束；「开始」流转 待服务→服务中；返回不含 status，需重读详情） */
export function checkinOrder(orderNo, kind, extra = {}) {
  return request(`/orders/${encodeURIComponent(orderNo)}/checkin`, { method: 'POST', body: { kind, ...extra } })
}

/** 完单（服务中 → 待确认） */
export function finishOrder(orderNo) {
  return request(`/orders/${encodeURIComponent(orderNo)}/finish`, { method: 'POST' })
}
