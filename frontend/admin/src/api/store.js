// =====================================================================
// 门店作用域 API 客户端（admin）
//   GET /stores/scope  当前管理员的门店可见范围（多门店数据隔离）
// =====================================================================

import { request } from './http'

/** 当前门店作用域概览 */
export function fetchStoreScope() {
  return request('/stores/scope')
}
