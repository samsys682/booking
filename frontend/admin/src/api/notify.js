// =====================================================================
// 微信消息通知 API 客户端（admin）
// 后端：
//   GET  /messages/notify-config          读取微信通知配置（脱敏）
//   POST /messages/notify-config          保存（授权版功能，message.wechat）
//   POST /messages/notify-test            链路自检（拉取 access_token）
// =====================================================================

import { request } from './http'

/** 读取微信通知配置（脱敏：secret 只回 has_secret） */
export function fetchNotifyConfig() {
  return request('/messages/notify-config')
}

/** 保存微信通知配置 */
export function saveNotifyConfig(payload) {
  return request('/messages/notify-config', { method: 'POST', body: payload })
}

/** 链路自检（不发送真实消息，仅验证 access_token 可获取） */
export function testNotify() {
  return request('/messages/notify-test', { method: 'POST', body: {} })
}
