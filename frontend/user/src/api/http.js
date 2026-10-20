// =====================================================================
// HTTP 请求封装（fetch，无第三方依赖）— 用户端 H5
// - 统一响应 {code,msg,data} 解包；code!==0 抛业务错误
// - 用户 token 注入 Authorization；401 自动清 token 跳登录
// - 后端不可达（网络错误/非 JSON/502）抛 HttpDownError，供 useCrud 兜底 mock
// =====================================================================

const BASE = import.meta.env.VITE_API_BASE || '/api/v1'

export class HttpDownError extends Error {
  constructor(message) {
    super(message)
    this.name = 'HttpDownError'
    this.isHttpDown = true
  }
}

export class BizError extends Error {
  constructor(code, msg) {
    super(msg)
    this.name = 'BizError'
    this.code = code
  }
}

export function getToken() {
  return localStorage.getItem('bk_user_token') || ''
}

export function setToken(token) {
  if (token) localStorage.setItem('bk_user_token', token)
  else localStorage.removeItem('bk_user_token')
}

/**
 * 请求后端
 * @param {string} path /members 形式（自动拼 /api/v1 前缀）
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

  let json
  try {
    json = await res.json()
  } catch (e) {
    if (res.status === 401) {
      setToken('')
      window.location.hash = '#/login'
    }
    throw new HttpDownError('后端响应异常')
  }

  if (json.code === 401) {
    setToken('')
    if (!window.location.hash.includes('/login')) window.location.hash = '#/login'
    throw new BizError(401, json.msg || '登录已过期')
  }
  if (json.code !== 0) {
    throw new BizError(json.code, json.msg || '请求失败')
  }
  return json.data
}
