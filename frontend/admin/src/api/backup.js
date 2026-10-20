// =====================================================================
// 数据备份 API 客户端（admin）
// 后端：POST /backups/run、GET /backups/{id}/download、POST /backups/{id}/restore
// 删除走通用资源 DELETE /backups/{id}（后端已改为含物理文件清理）
// =====================================================================

import { request, getToken, setToken } from './http'

const BASE = import.meta.env.VITE_API_BASE || '/api/v1'

/** 执行一次备份 {kind:'manual'|'auto'} */
export function runBackup(kind = 'manual') {
  return request('/backups/run', { method: 'POST', body: { kind } })
}

/** 从指定备份恢复（confirm=1 防误触） */
export function restoreBackup(id) {
  return request(`/backups/${id}/restore`, { method: 'POST', body: { confirm: '1' } })
}

/** 下载备份文件（带 token，故用 fetch + blob 触发浏览器下载） */
export async function downloadBackup(id, name) {
  const res = await fetch(`${BASE}/backups/${id}/download`, {
    headers: getToken() ? { Authorization: 'Bearer ' + getToken() } : {}
  })
  if (res.status === 401) {
    setToken('')
    if (!/\/login$/.test(window.location.pathname)) window.location.assign('/login')
    throw new Error('登录已过期')
  }
  if (!res.ok) throw new Error('下载失败（备份文件可能已被清理）')
  const blob = await res.blob()
  const a = document.createElement('a')
  a.href = URL.createObjectURL(blob)
  a.download = name || `backup_${id}.sql.gz`
  a.click()
  URL.revokeObjectURL(a.href)
}
