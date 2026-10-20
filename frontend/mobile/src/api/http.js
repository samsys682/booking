// HTTP 封装：统一错误分类与 token 注入（技师端）
// 网络不可达/非 JSON 响应 → 抛 HttpDownError（useCrud 自动落 mock 兜底）
// 业务错误 code!==0 → 抛 BizError(msg, code)（上抛给页面提示）
const BASE = import.meta.env.VITE_API_BASE || '/api/v1'
const TOKEN_KEY = 'bk_tech_token'

export class HttpDownError extends Error {
  constructor(msg) {
    super(msg)
    this.isHttpDown = true
  }
}

export class BizError extends Error {
  constructor(msg, code) {
    super(msg)
    this.code = code
  }
}

export function getToken() {
  return localStorage.getItem(TOKEN_KEY) || ''
}

export function setToken(token) {
  if (token) localStorage.setItem(TOKEN_KEY, token)
  else localStorage.removeItem(TOKEN_KEY)
}

/**
 * 请求后端
 * @param {string} path /orders 形式（自动拼 /api/v1 前缀）
 * @param {object} opts { method, body, query }（body 传对象自动 JSON 序列化，query 自动拼 URL）
 * @returns {Promise<any>} 解包后的 data
 */
export async function request(path, opts = {}) {
  const { method = 'GET', body, query, headers: extraHeaders } = opts
  let url = BASE + path
  if (query) {
    const qs = Object.entries(query)
      .filter(([, v]) => v !== '' && v !== null && v !== undefined)
      .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`)
      .join('&')
    if (qs) url += '?' + qs
  }

  const headers = { 'Content-Type': 'application/json', ...(extraHeaders || {}) }
  const token = getToken()
  if (token) headers.Authorization = 'Bearer ' + token

  let res
  try {
    res = await fetch(url, {
      method,
      headers,
      body: body !== undefined ? JSON.stringify(body) : undefined
    })
  } catch (e) {
    throw new HttpDownError('网络异常，请检查网络连接')
  }

  let json
  try {
    json = await res.json()
  } catch (e) {
    if (res.status === 401) {
      setToken('')
      location.hash = '#/login'
    }
    throw new HttpDownError('服务不可用，请稍后重试')
  }

  if (json.code === 401) {
    setToken('')
    if (!location.hash.includes('/login')) location.hash = '#/login'
    throw new BizError(json.msg || '登录已过期', 401)
  }
  if (json.code !== 0) throw new BizError(json.msg || '请求失败', json.code)
  return json.data
}
