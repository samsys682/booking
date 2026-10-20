// =====================================================================
// 会员自助域（"我的"）API —— 顾客端小程序
// 与 user H5 api/memberSelf.js 同构；后端 MyController 直接输出视图
// 形状（camelCase + 金额元 + 展示串），本层零映射仅转发。
// =====================================================================

import { request } from './http'

// --- 会员卡 ---
export function fetchCards() {
  return request('/my/cards')
}

export function fetchCardOffers() {
  return request('/my/card-offers')
}

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

export function fetchGifts() {
  return request('/gifts', { query: { page: 1, per_page: 50, status: 1 } })
}

export function redeemGift(giftId, payload = {}) {
  return request(`/my/gifts/${giftId}/redeem`, { method: 'POST', body: payload })
}

export function fetchGiftExchanges() {
  return request('/my/gift-exchanges')
}

export function fetchInvite() {
  return request('/my/invite')
}

export function fetchReviewReward() {
  return request('/my/review-reward')
}

// --- 储值 ---
export function fetchStored() {
  return request('/my/stored')
}

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
