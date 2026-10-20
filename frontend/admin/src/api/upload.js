// =====================================================================
// 文件上传 API 客户端（admin）
// 后端 POST /api/v1/upload（multipart，字段名 file）→ {url,name,size,ext,mime,kind}
// 注意：multipart 不能走通用 request（它固定 Content-Type: application/json），
//      故此处单独用 fetch + FormData，错误处理与 http.js 保持一致。
// =====================================================================

import { getToken, setToken, BizError, HttpDownError } from './http'
import { promptUpgrade } from '../store/upgrade'

const BASE = import.meta.env.VITE_API_BASE || '/api/v1'

/**
 * 上传文件
 * @param {File} file 浏览器 File 对象
 * @param {(percent:number)=>void} [onProgress] 进度回调（0-100）
 * @returns {Promise<{url:string,name:string,size:number,ext:string,mime:string,kind:string}>}
 */
export function uploadFile(file, onProgress) {
  return new Promise((resolve, reject) => {
    if (!file) return reject(new BizError(400, '未选择文件'))

    const form = new FormData()
    form.append('file', file)

    // 优先 XHR（可拿到上传进度）；无 XHR 环境回落 fetch
    if (typeof XMLHttpRequest !== 'undefined') {
      const xhr = new XMLHttpRequest()
      xhr.open('POST', BASE + '/upload')
      const token = getToken()
      if (token) xhr.setRequestHeader('Authorization', `Bearer ${token}`)
      if (xhr.upload && onProgress) {
        xhr.upload.onprogress = (e) => {
          if (e.lengthComputable) onProgress(Math.round((e.loaded / e.total) * 100))
        }
      }
      xhr.onload = () => {
        let json
        try {
          json = JSON.parse(xhr.responseText)
        } catch (e) {
          return reject(new HttpDownError('后端响应异常'))
        }
        if (json.code === 401) {
          setToken('')
          if (!/\/login$/.test(window.location.pathname)) window.location.assign('/login')
          return reject(new BizError(401, json.msg || '登录已过期'))
        }
        if (json.code !== 0) {
          if (json.code === 403 && json.data && json.data.upgrade) promptUpgrade(json.data.upgrade)
          return reject(new BizError(json.code, json.msg || '上传失败', json.data))
        }
        resolve(json.data)
      }
      xhr.onerror = () => reject(new HttpDownError('后端服务不可达'))
      xhr.send(form)
      return
    }

    // fetch 回落（无进度）
    fetch(BASE + '/upload', {
      method: 'POST',
      headers: getToken() ? { Authorization: `Bearer ${getToken()}` } : {},
      body: form
    })
      .then((res) => res.json())
      .then((json) => {
        if (json.code !== 0) return reject(new BizError(json.code, json.msg || '上传失败', json.data))
        resolve(json.data)
      })
      .catch(() => reject(new HttpDownError('后端服务不可达')))
  })
}
