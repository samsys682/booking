// =====================================================================
// 电子服务协议 API —— 顾客端小程序（批次一 C 联动）
// active：下单页预取当前服务适用的启用协议；sign：签署换一次性 token
// =====================================================================

import { request } from './http'

/** 当前服务适用的启用协议 → [{id, name, version, content}] */
export function fetchActiveAgreements(serviceId) {
  return request('/agreements/active', { query: { service_id: serviceId } })
}

/** 签署协议 → {sign_no, sign_token, agreement_name} */
export function signAgreement(id) {
  return request(`/agreements/${encodeURIComponent(id)}/sign`, { method: 'POST' })
}
