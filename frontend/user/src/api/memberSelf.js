// =====================================================================
// 会员自助域（"我的"）API 客户端 — 用户端 H5
// 后端 MyController 直接输出视图形状（camelCase + 金额元 + 展示串），
// 本层零映射，仅转发；请求失败由页面上抛报错提示。
// =====================================================================

import { request } from './http'

// --- 会员卡 ---
export function fetchCards() {
  return request('/my/cards')
}

/** 可购卡种（会员卡模板，后台「可购卡种」维护）→ {list:[{id,name,type,cycle,total,amount(元),days}]} */
export function fetchCardOffers() {
  return request('/my/card-offers')
}

/** 自助购卡 {name?,type,cycle,total,amount(元),expires?} → 后端 amount 分 */
export function buyCard({ name, type, cycle, total, amount, expires }) {
  return request('/my/cards/buy', {
    method: 'POST',
    body: {
      name: name || '',
      type,
      cycle: cycle || 'once',
      total: Number(total),
      amount: Math.round(Number(amount) * 100),
      expires: expires || ''
    }
  })
}

// --- 优惠券 ---
export function fetchAvailableCoupons() {
  return request('/my/coupons/available')
}

export function fetchMyCoupons() {
  return request('/my/coupons')
}

export function claimCoupon(couponId) {
  return request(`/my/coupons/${couponId}/claim`, { method: 'POST' })
}

// --- 积分 ---
export function fetchPoints() {
  return request('/my/points')
}

// --- 积分商城 ---
export function fetchGifts() {
  return request('/gifts', { query: { page: 1, per_page: 50, status: 1 } })
}

export function redeemGift(giftId, payload = {}) {
  return request(`/my/gifts/${giftId}/redeem`, { method: 'POST', body: payload })
}

// --- 我的兑换记录（积分商城兑换单） ---
export function fetchGiftExchanges() {
  return request('/my/gift-exchanges')
}

// --- 邀请有礼（邀请码/已邀请人数/双向奖励规则） ---
export function fetchInvite() {
  return request('/my/invite')
}

// --- 评价有礼（当前启用规则的展示文案；无规则返回默认文案） ---
export function fetchReviewReward() {
  return request('/my/review-reward')
}

// --- 储值 ---
export function fetchStored() {
  return request('/my/stored')
}

/** 套餐充值（服务端核算赠送）；accountId 为储值账户 id */
export function rechargeStored(accountId, planId) {
  return request(`/stored-accounts/${accountId}/recharge`, {
    method: 'POST',
    body: { plan_id: Number(planId) }
  })
}

// --- 地址 ---
export function fetchAddresses() {
  return request('/my/addresses')
}

export function createAddress(a) {
  return request('/my/addresses', {
    method: 'POST',
    body: { name: a.name, phone: a.phone, address: a.address, isDefault: a.isDefault ? 1 : 0 }
  })
}

export function updateAddress(id, a) {
  return request(`/my/addresses/${id}`, {
    method: 'PUT',
    body: { name: a.name, phone: a.phone, address: a.address, isDefault: a.isDefault ? 1 : 0 }
  })
}

export function deleteAddress(id) {
  return request(`/my/addresses/${id}`, { method: 'DELETE' })
}
