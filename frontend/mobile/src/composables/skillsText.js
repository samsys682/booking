// 技师技能展示：后端存 JSON 数组串（如 ["空调清洗","油烟机清洗"]），解析为「、」分隔；
// 兼容逗号/顿号分隔的存量数据，解析失败原样返回（user 端 OrderCreate 同款逻辑）
export function skillsText(raw) {
  const s = String(raw || '').trim()
  if (!s) return '综合服务'
  try {
    const arr = JSON.parse(s)
    if (Array.isArray(arr)) return arr.filter(Boolean).join('、') || '综合服务'
  } catch (e) { /* 非 JSON 走分隔符兼容 */ }
  return s.split(/[,，、]/).filter(Boolean).join('、') || '综合服务'
}
