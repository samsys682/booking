// =====================================================================
// 通用工具 —— 顾客端小程序（对齐 user H5 各页内的同名实现）
// =====================================================================

/** 技师技能展示：JSON 数组串/逗号顿号分隔 → 「、」连接 */
export function skillsText(raw) {
  const s = String(raw || '').trim()
  if (!s) return '综合服务'
  try {
    const arr = JSON.parse(s)
    if (Array.isArray(arr)) return arr.filter(Boolean).join('、') || '综合服务'
  } catch (e) { /* 非 JSON 走分隔符兼容 */ }
  return s.split(/[,，、]/).filter(Boolean).join('、') || '综合服务'
}

/** 预约时间窗校验（事实源 OrderService::assertBookingWindow：提前 2 小时 / 最远 30 天） */
export function bookingWindowError(startAt) {
  const ts = new Date(String(startAt).replace(/-/g, '/')).getTime()
  if (Number.isNaN(ts)) return '时间格式不正确'
  if (ts < Date.now() + 2 * 3600000) return '需提前 2 小时预约'
  if (ts > Date.now() + 30 * 86400000) return '最远可预约 30 天内的服务'
  return ''
}

/** startAt + minutes → 'YYYY-MM-DD HH:mm' */
export function fmtPlus(startAt, minutes) {
  const d = new Date(String(startAt).replace(/-/g, '/'))
  d.setMinutes(d.getMinutes() + minutes)
  const p = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}

/** 优惠券抵扣（满减/折扣，对齐 user H5 OrderCreate::discountOf） */
export function discountOf(coupon, t) {
  if (!coupon) return 0
  const threshold = String(coupon.threshold || '').match(/(\d+(?:\.\d+)?)/)
  const off = String(coupon.value || '').match(/减\s*¥?\s*(\d+(?:\.\d+)?)/)
  if (off) {
    if (threshold && t < Number(threshold[1])) return 0
    return Math.min(Number(off[1]), t)
  }
  const zhe = String(coupon.value || '').match(/(\d+(?:\.\d+)?)\s*折/)
  if (zhe) return Math.round(t * (1 - Number(zhe[1]) / 10) * 100) / 100
  return 0
}

/** 统一错误提示文案（HttpDown → 连接提示；其余业务错误原样） */
export function errText(e, fallback) {
  return e && e.isHttpDown ? '后端服务未连接，请检查后端 API 服务是否启动' : (e && e.message ? e.message : fallback)
}
