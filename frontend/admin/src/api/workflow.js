// =====================================================================
// 客户运营工作流 API 客户端（admin）
// 后端：
//   GET  /workflow/meta                       状态机定义（渲染流转按钮）
//   GET  /workflow/overdue?resource=...       到期/超时扫描
//   GET  /workflow/{resource}/{id}/logs       流转时间线
//   POST /workflow/{resource}/{id}/transition 状态流转 {to, result|resolution, owner}
//   POST /workflow/{resource}/{id}/assign     指派负责人 {owner}
// =====================================================================

import { request } from './http'

/** 状态机定义（resource → flow/terminal/resultLabel） */
export function fetchWorkflowMeta() {
  return request('/workflow/meta')
}

/** 到期/超时扫描；resource 为空 = 全部 */
export function fetchOverdue(resource = '') {
  const qs = resource ? `?resource=${encodeURIComponent(resource)}` : ''
  return request(`/workflow/overdue${qs}`)
}

/** 流转时间线 */
export function fetchWorkflowLogs(resource, id) {
  return request(`/workflow/${resource}/${id}/logs`)
}

/** 状态流转（to=目标状态；result=回访处理结果；resolution=投诉处理结果） */
export function transitionWorkflow(resource, id, payload) {
  return request(`/workflow/${resource}/${id}/transition`, { method: 'POST', body: payload })
}

/** 指派负责人 */
export function assignWorkflow(resource, id, owner) {
  return request(`/workflow/${resource}/${id}/assign`, { method: 'POST', body: { owner } })
}
