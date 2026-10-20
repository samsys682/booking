// =====================================================================
// 电子服务协议 API —— 用户端（批次一 C）
// - active：下单页预取当前服务适用的启用协议（无协议/开源版返回空数组）
// - sign：会员签署协议，换一次性 sign_token（下单时提交消费，用后作废）
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
