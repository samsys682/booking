// =====================================================================
// 会员认证 API —— 顾客端小程序（批次五）
// 主通道：微信一键授权登录（wx.login → POST /auth/member-wx-login，
// 可选 phone_code 手机号快捷授权合并老会员）；短信验证码登录作为
// 回退通道（微信凭证未配置 / 真机环境受限时使用）。
// token 存 http.js 统一管理，会员资料缓存 storage 供各页恢复。
// =====================================================================

import { request, setToken } from './http'

const MEMBER_KEY = 'bk_user_member'

/** 后端 member 行 → 视图会员形状（对齐 user H5 api/auth.js::mapMember） */
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
    return uni.getStorageSync(MEMBER_KEY) || null
  } catch (e) {
    return null
  }
}

export function saveMember(row) {
  const member = mapMember(row)
  try {
    uni.setStorageSync(MEMBER_KEY, member)
  } catch (e) { /* ignore */ }
  return member
}

export function clearMember() {
  try {
    uni.removeStorageSync(MEMBER_KEY)
  } catch (e) { /* ignore */ }
}

/** 小程序静默登录凭证（wx.login 的 code，即后端 js_code） */
export function wxCode() {
  return new Promise((resolve, reject) => {
    uni.login({
      provider: 'weixin',
      success: (res) => resolve(res.code || ''),
      fail: (err) => reject(new Error((err && err.errMsg) || 'wx.login 调起失败'))
    })
  })
}

/**
 * 微信一键授权登录
 * @param {object} opts { phoneCode?: 手机号快捷授权 code（getPhoneNumber 事件）, inviteCode? }
 * 成功后落 token + 会员缓存；未配置/无效 code 等业务错误原样上抛（由页面分流提示）
 */
export async function memberWxLogin({ phoneCode = '', inviteCode = '' } = {}) {
  const jsCode = await wxCode()
  if (!jsCode) throw new Error('wx.login 未返回凭证，请重试')
  const body = { js_code: jsCode }
  if (phoneCode) body.phone_code = phoneCode
  if (inviteCode) body.invite_code = String(inviteCode).trim().toUpperCase()
  const data = await request('/auth/member-wx-login', { method: 'POST', body })
  setToken(data.token)
  const member = saveMember(data.member)
  return { token: data.token, member }
}

/** 发送验证码 → { demo_code }（回退通道） */
export function memberSendCode(phone) {
  return request('/auth/member-send-code', { method: 'POST', body: { phone } })
}

/** 验证码登录（未注册自动建档；回退通道，对齐 user H5 memberLogin） */
export async function memberLogin(phone, code, inviteCode) {
  const body = { phone, code }
  if (inviteCode) body.invite_code = String(inviteCode).trim().toUpperCase()
  const data = await request('/auth/member-login', { method: 'POST', body })
  setToken(data.token)
  const member = saveMember(data.member)
  return { token: data.token, member }
}
