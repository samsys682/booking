// =====================================================================
// HTTP 请求封装（uni.request）— 顾客端小程序（批次五）
// 对齐 user H5 api/http.js 的响应拦截约定：
// - 统一响应 {code,msg,data} 解包；code!==0 抛 BizError
// - 会员 token 注入 Authorization；401 清 token 跳登录页
// - 网络不可达/非 JSON 抛 HttpDownError，页面统一提示
// =====================================================================

// 小程序无 devServer 代理，需完整地址；真机调试时改为局域网 IP
// （VITE_API_BASE 经 .env 注入，缺省本地联调地址）
const BASE = import.meta.env.VITE_API_BASE || 'http://127.0.0.1:8180/api/v1'

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

const TOKEN_KEY = 'bk_user_token'

export function getToken() {
  try {
    return uni.getStorageSync(TOKEN_KEY) || ''
  } catch (e) {
    return ''
  }
}

export function setToken(token) {
  try {
    if (token) uni.setStorageSync(TOKEN_KEY, token)
    else uni.removeStorageSync(TOKEN_KEY)
  } catch (e) { /* 存储异常忽略 */ }
}

/** 跳登录页（清 token 后调用；tabBar 页用 reLaunch） */
function gotoLogin() {
  uni.reLaunch({ url: '/pages/login/login' })
}

/**
 * 请求后端
 * @param {string} path /members 形式（自动拼 API 前缀）
 * @param {object} opts { method, body, query }
 * @returns {Promise<any>} 解包后的 data
 */
export function request(path, opts = {}) {
  const { method = 'GET', body, query } = opts
  let url = BASE + path
  if (query) {
    const qs = Object.entries(query)
      .filter(([, v]) => v !== '' && v !== null && v !== undefined)
      .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`)
      .join('&')
    if (qs) url += `?${qs}`
  }

  return new Promise((resolve, reject) => {
    uni.request({
      url,
      method,
      data: body,
      header: {
        'Content-Type': 'application/json',
        ...(getToken() ? { Authorization: `Bearer ${getToken()}` } : {})
      },
      success: (res) => {
        const json = res.data
        if (!json || typeof json !== 'object') {
          if (res.statusCode === 401) {
            setToken('')
            gotoLogin()
          }
          return reject(new HttpDownError('后端响应异常'))
        }
        if (json.code === 401) {
          setToken('')
          gotoLogin()
          return reject(new BizError(401, json.msg || '登录已过期'))
        }
        if (json.code !== 0) {
          return reject(new BizError(json.code, json.msg || '请求失败'))
        }
        resolve(json.data)
      },
      fail: () => reject(new HttpDownError('后端服务不可达'))
    })
  })
}

/** 轻提示（对齐 useToast 语义，页面统一入口） */
export function toast(title) {
  uni.showToast({ title: String(title || ''), icon: 'none' })
}
