// =====================================================================
// 在线升级 API 客户端（admin）
// 后端：
//   GET  /upgrade/check                 检查更新（已安装 vs 清单最新）
//   GET  /upgrade/status                当前版本 + 最近升级记录
//   POST /upgrade/run                   执行升级 {to_version?}
//   POST /upgrade/rollback              回滚 {record_id}
// =====================================================================

import { request } from './http'

/** 检查更新 */
export function checkUpgrade() {
  return request('/upgrade/check')
}

/** 版本状态（当前版本 + 待升级 + 最近记录） */
export function upgradeStatus() {
  return request('/upgrade/status')
}

/** 执行升级 */
export function runUpgrade(toVersion = '') {
  return request('/upgrade/run', { method: 'POST', body: { to_version: toVersion } })
}

/** 回滚到某次升级前的备份 */
export function rollbackUpgrade(recordId) {
  return request('/upgrade/rollback', { method: 'POST', body: { record_id: recordId } })
}
