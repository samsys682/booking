// =====================================================================
// 技师工作台 API —— 收入/排班/考勤/评价 四域（后端 TechController）
// 后端直出视图形状（camelCase + 金额「元」+ 二值化状态）→ 零映射直接喂视图；
// 请求失败由页面上抛报错提示。
// =====================================================================

import { request } from './http'

/** 收入结算列表（月度提成：period/orderCount/gross/commission/status/settledAt） */
export async function fetchIncome() {
  const data = await request('/tech/income')
  return data.list || []
}

/** 我的排班（date/timeRange/maxOrders/booked/status，含当日已约单量） */
export async function fetchSchedules() {
  const data = await request('/tech/schedules')
  return data.list || []
}

/** 排班休息开关（PUT 幂等翻转：可接单 ⇄ 休息；返回翻转后的排班行） */
export function toggleScheduleRest(id) {
  return request(`/tech/schedules/${id}/toggle`, { method: 'PUT' })
}

/** 出勤记录（date/checkIn/checkOut/hours/status） */
export async function fetchAttendance() {
  const data = await request('/tech/attendance')
  return data.list || []
}

/** 上班打卡（checkIn 'HH:MM' 可省=服务端当前时刻；重复打卡 400 上抛给页面） */
export function checkinAttendance(checkIn) {
  return request('/tech/attendance/checkin', { method: 'POST', body: { checkIn: checkIn || '' } })
}

/** 下班打卡（checkOut 'HH:MM' 可省；未上班/重复打卡 400 上抛给页面） */
export function checkoutAttendance(checkOut) {
  return request('/tech/attendance/checkout', { method: 'POST', body: { checkOut: checkOut || '' } })
}

/** 收到的评价（order/service/customer/rating/content/images/reply/createdAt） */
export async function fetchReviews() {
  const data = await request('/tech/reviews')
  return data.list || []
}

/** 我的自选服务（全量上架服务 + selected 标记：id/name/category/price/duration/selected） */
export async function fetchMyServices() {
  const data = await request('/tech/my-services')
  return data.list || []
}

/** 保存自选服务（service_ids 全量覆盖：新增勾选/取消软删） */
export function saveMyServices(serviceIds) {
  return request('/tech/my-services', { method: 'PUT', body: { service_ids: serviceIds } })
}
