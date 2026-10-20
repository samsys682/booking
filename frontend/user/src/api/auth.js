// =====================================================================
// 会员认证 API —— 手机验证码登录（真实后端优先）
// 登录成功后 token 存 http.js 统一管理，会员资料缓存 localStorage 供各页恢复
// （member 表无 points/balance 列，资产由储值/积分域单独提供，这里保底 0）
// =====================================================================

import { request, setToken } from './http'

const MEMBER_KEY = 'bk_user_member'

/** 后端 member 行 → 视图会员形状（camel + 展示字段兜底） */
export function mapMember(row) {
  const r = row || {}
  return {
    id: r.id ?? '',
    name: r.name || '',
    phone: r.phone || '',
    level: r.level || '普通会员',
    avatar: r.avatar || '',
    points: Number(r.points ?? 0),
    balance: Number(r.balance ?? 0),
    totalAmount: Number(r.total_amount ?? 0),
    totalOrders: Number(r.total_orders ?? 0)
  }
}

export function loadMember() {
  try {
    return JSON.parse(localStorage.getItem(MEMBER_KEY) || 'null')
  } catch (e) {
    return null
  }
}

export function saveMember(row) {
  const member = mapMember(row)
  localStorage.setItem(MEMBER_KEY, JSON.stringify(member))
  return member
}

export function clearMember() {
  localStorage.removeItem(MEMBER_KEY)
}

/** 发送验证码 → { demo_code }（env security.sms_demo_mode=1 时回显验证码） */
export function memberSendCode(phone) {
  return request('/auth/member-send-code', { method: 'POST', body: { phone } })
}

/** 验证码登录（未注册自动建档）→ 成功后落 token + 会员缓存 */
export async function memberLogin(phone, code, inviteCode) {
  // inviteCode：推荐有礼邀请码，仅首次注册建档时生效（老会员登录后端不更改归属）
  const body = { phone, code }
  if (inviteCode) body.invite_code = String(inviteCode).trim().toUpperCase()
  const data = await request('/auth/member-login', { method: 'POST', body })
  setToken(data.token)
  const member = saveMember(data.member)
  return { token: data.token, member }
}
