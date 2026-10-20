// =====================================================================
// 通用资源 API —— 顾客端小程序（对齐后端 /api/v1/{resource} 资源 CRUD）
// 与 user H5 api/resource.js 同构：列表/详情；会员端只使用只读部分
// =====================================================================

import { request } from './http'

/** 列表：GET /{resource}?page&per_page&keyword */
export function list(resource, params = {}) {
  return request(`/${resource}`, { query: params })
}

/** 详情：GET /{resource}/{id} */
export function read(resource, id) {
  return request(`/${resource}/${id}`)
}
