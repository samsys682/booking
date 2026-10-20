// =====================================================================
// HTTP 请求封装（fetch，无第三方依赖）
// - 统一响应 {code,msg,data} 解包；code!==0 抛业务错误
// - token 注入 Authorization；401 自动清 token 跳登录
// - 后端不可达（网络错误/非 JSON/502）抛 HttpDownError，供 useCrud 兜底 mock
// =====================================================================

import { promptUpgrade } from '../store/upgrade'

const BASE = import.meta.env.VITE_API_BASE || '/api/v1'

export class HttpDownError extends Error {
  constructor(message) {
    super(message)
    this.name = 'HttpDownError'
    this.isHttpDown = true
  }
}

export class BizError extends Error {
  constructor(code, msg, data) {
    super(msg)
    this.name = 'BizError'
    this.code = code
    // 后端业务响应体 data（如版本门禁的 upgrade 升级引导信息），供调用方/全局处理读取
    this.data = data
  }
}

export function getToken() {
  return localStorage.getItem('bk_token') || ''
}

export function setToken(token) {
  if (token) localStorage.setItem('bk_token', token)
  else localStorage.removeItem('bk_token')
}

/**
 * 请求后端
 * @param {string} path /auth/login 形式（自动拼 /api/v1 前缀）
 * @param {object} opts { method, body, query }
 * @returns {Promise<any>} 解包后的 data
 */
export async function request(path, opts = {}) {
  const { method = 'GET', body, query } = opts
  let url = BASE + path
  if (query) {
    const qs = Object.entries(query)
      .filter(([, v]) => v !== '' && v !== null && v !== undefined)
      .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`)
      .join('&')
    if (qs) url += `?${qs}`
  }

  let res
  try {
    res = await fetch(url, {
      method,
      headers: {
        'Content-Type': 'application/json',
        ...(getToken() ? { Authorization: `Bearer ${getToken()}` } : {})
      },
      body: body !== undefined ? JSON.stringify(body) : undefined
    })
  } catch (e) {
    // 网络不可达 → 由调用方兜底 mock
    throw new HttpDownError('后端服务不可达')
  }

  // 支付回调等非 JSON 响应不走这里（由支付网关直连后端）
  let json
  try {
    json = await res.json()
  } catch (e) {
    if (res.status === 401) {
      setToken('')
      // 同上：history 路由用整页跳转，hash 赋值无效
      if (!/\/login$/.test(window.location.pathname)) window.location.assign('/login')
    }
    throw new HttpDownError('后端响应异常')
  }

  if (json.code === 401) {
    setToken('')
    // 路由是 createWebHistory（history 模式），hash 赋值对它无效——
    // 旧实现 window.location.hash='#/login' 导致 token 过期后页面「假登录 + 全站空数据」
    // （A1 走查实测：后端返回 20 行、页面显示 0 条且无任何提示）。整页跳转登录页。
    if (!/\/login$/.test(window.location.pathname)) window.location.assign('/login')
    throw new BizError(401, json.msg || '登录已过期')
  }
  if (json.code !== 0) {
    // 版本门禁拦截（EditionGate）：统一弹升级引导，让「入口可见、点击才提示升级」
    // 的付费转化路径闭环（见《开发计划》§2.6）
    if (json.code === 403 && json.data && json.data.upgrade) {
      promptUpgrade(json.data.upgrade)
    }
    throw new BizError(json.code, json.msg || '请求失败', json.data)
  }
  return json.data
}
