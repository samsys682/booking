// =====================================================================
// 技师认证 API —— 手机 + 密码登录（真实后端优先）
// 登录成功后 token 存 http.js 统一管理，技师资料缓存 localStorage 供各页恢复
// （technician 表无 level/store/area/rating 列，展示字段防御性兜底）
// =====================================================================

import { request, setToken } from './http'

const TECH_KEY = 'bk_tech_tech'

/** 手机号打码 139****2201（已打码的原样返回） */
function maskPhone(phone) {
  const p = String(phone || '')
  return /^(\d{3})\d{4}(\d{4})$/.test(p) ? p.replace(/^(\d{3})\d{4}(\d{4})$/, '$1****$2') : p
}

/** 后端 technician 行 → 视图技师形状（camel + 展示字段兜底） */
export function mapTechnician(row) {
  const r = row || {}
  return {
    id: r.id ?? '',
    name: r.name || '',
    phone: maskPhone(r.phone),
    level: r.level || '技师',
    skills: r.skills || r.skill || '',
    rating: Number(r.rating ?? 0),
    monthOrders: Number(r.month_orders ?? r.monthOrders ?? 0),
    store: r.store || '',
    area: r.area || '',
    status: r.status === '在职' ? '接单中' : r.status || '接单中'
  }
}

export function loadTechnician() {
  try {
    return JSON.parse(localStorage.getItem(TECH_KEY) || 'null')
  } catch (e) {
    return null
  }
}

export function saveTechnician(row) {
  const tech = mapTechnician(row)
  localStorage.setItem(TECH_KEY, JSON.stringify(tech))
  return tech
}

export function clearTechnician() {
  localStorage.removeItem(TECH_KEY)
}

/** 手机 + 密码登录 → 成功后落 token + 技师缓存 */
export async function techLogin(phone, password) {
  const data = await request('/auth/tech-login', { method: 'POST', body: { phone, password } })
  setToken(data.token)
  const technician = saveTechnician(data.technician)
  return { token: data.token, technician }
}
