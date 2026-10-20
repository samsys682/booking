// =====================================================================
// 紧急报警 API —— 技师端一键上报（缺口补齐批次三）
// 复用 security_event 留痕模型（kind=一键报警）；版本门禁 emergency.alerts=授权版+
// =====================================================================

import { request } from './http'

/** 报警类型枚举（与后端 EmergencyAlertController::TYPES 同源） */
export const ALERT_TYPES = ['紧急联络', '纠纷', '事故']

/**
 * 一键报警上报
 * @param {{type:string, orderNo?:string, location?:string}} payload
 * @returns {Promise<{id:number, kind:string, status:string, notify:{sent:boolean, reason:string, mode:string}}>}
 */
export function reportAlert(payload) {
  return request('/emergency-alerts', {
    method: 'POST',
    body: {
      type: payload.type,
      order_no: payload.orderNo || '',
      location: payload.location || ''
    }
  })
}

/** 技师本人最近上报记录（后端按技师姓名 + 一键报警追溯，最近 50 条） */
export function myAlerts() {
  return request('/emergency-alerts/my').then((data) => ({
    list: (data.list || []).map((r) => ({
      id: r.id,
      orderNo: r.order_no || '',
      at: r.at || '',
      detail: r.detail || '',
      status: r.status || ''
    }))
  }))
}
