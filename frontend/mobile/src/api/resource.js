import { request } from './http'

// 通用资源 REST 封装（与后端 ResourceController 对齐）
// request 已升级为 {method, body, query} 形态：body 传对象自动 JSON 序列化
export function list(resource, params = {}) {
  return request('/' + resource, { query: params })
}

export function read(resource, id) {
  return request('/' + resource + '/' + id)
}

export function create(resource, data) {
  return request('/' + resource, { method: 'POST', body: data })
}

export function update(resource, id, data) {
  return request('/' + resource + '/' + id, { method: 'PUT', body: data })
}

export function remove(resource, id) {
  return request('/' + resource + '/' + id, { method: 'DELETE' })
}
