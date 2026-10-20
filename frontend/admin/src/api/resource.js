// =====================================================================
// 通用资源 API（对齐后端 /api/v1/{resource} 55 资源 CRUD）
// 列表/详情/新增/修改/删除；分页出参 {list,total,page,per_page,pages}
// =====================================================================

import { request } from './http'

/** 列表：GET /{resource}?page&per_page&keyword&排序&精确过滤 */
export function list(resource, params = {}) {
  return request(`/${resource}`, { query: params })
}

/** 详情：GET /{resource}/{id} */
export function read(resource, id) {
  return request(`/${resource}/${id}`)
}

/** 新增：POST /{resource} */
export function create(resource, data) {
  return request(`/${resource}`, { method: 'POST', body: data })
}

/** 修改：PUT /{resource}/{id} */
export function update(resource, id, data) {
  return request(`/${resource}/${id}`, { method: 'PUT', body: data })
}

/** 删除（软删）：DELETE /{resource}/{id} */
export function remove(resource, id) {
  return request(`/${resource}/${id}`, { method: 'DELETE' })
}
