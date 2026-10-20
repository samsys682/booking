// =====================================================================
// 财务 / 积分履约 API 客户端（admin）
// 后端：
//   POST /invoices/{id}/issue              开具发票 {invoice_no?}
//   POST /invoices/{id}/reject             驳回开票 {reason}
//   POST /finance-reconciles/reconcile     一键对账 {from, to, channel?}
//   POST /finance-reconciles/{id}/confirm  人工确认对账
//   POST /finance-reconciles/{id}/diff     标记差异 {diff}
//   POST /gift-exchanges/{id}/ship         兑换单发货 {express_no, remark}
//   POST /gift-exchanges/{id}/complete     兑换单完成
//   POST /gift-exchanges/{id}/cancel       兑换单取消 {reason}（退积分 + 退库存）
// =====================================================================

import { request } from './http'

// ---------------- 发票 ----------------
export function issueInvoice(id, invoiceNo = '') {
  return request(`/invoices/${id}/issue`, { method: 'POST', body: { invoice_no: invoiceNo } })
}

export function rejectInvoice(id, reason) {
  return request(`/invoices/${id}/reject`, { method: 'POST', body: { reason } })
}

// ---------------- 财务对账 ----------------
export function runReconcile({ from = '', to = '', channel = '' } = {}) {
  return request('/finance-reconciles/reconcile', { method: 'POST', body: { from, to, channel } })
}

export function confirmReconcile(id) {
  return request(`/finance-reconciles/${id}/confirm`, { method: 'POST' })
}

export function markReconcileDiff(id, diff) {
  return request(`/finance-reconciles/${id}/diff`, { method: 'POST', body: { diff } })
}

// ---------------- 积分兑换单 ----------------
export function shipGiftExchange(id, { express_no = '', remark = '' } = {}) {
  return request(`/gift-exchanges/${id}/ship`, { method: 'POST', body: { express_no, remark } })
}

export function completeGiftExchange(id) {
  return request(`/gift-exchanges/${id}/complete`, { method: 'POST' })
}

export function cancelGiftExchange(id, reason) {
  return request(`/gift-exchanges/${id}/cancel`, { method: 'POST', body: { reason } })
}
