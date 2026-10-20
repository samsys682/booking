import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import { createHmac, randomBytes } from 'node:crypto'

// booking 管理后台：页面先行阶段默认 mock 数据，不连接后端
// 例外：购买授权在线支付已完整对接 OA 开放平台统一支付（），经下面的签名网关转发

// OA 统一支付签名网关（完整对接 OA 支付）
// 路由：/oa-pay/:action（action 白名单 create-order / query-order / refund / subjects）
// 安全红线：app_secret 仅在本插件（Node 服务端进程）内参与 HMAC-SHA256 签名，
//           永不进入浏览器 bundle；前端只拿签名结果对应的业务返回。
// 签名与 OA 侧 verifySignatureV2 对齐（feishu/backend/src/utils/crypto.js）：
//   message = `${appId}|${timestamp}|${nonce}|${bodyStr}`，GET 的 bodyStr 为 '{}'。
// 生产部署（PHP 后端上线）后由后端按同一契约代理并提供 notify_url 回调，本网关仅供 dev/preview。
const OA_PAY_ACTIONS = new Set(['create-order', 'query-order', 'refund', 'subjects'])

function oaPayGateway() {
  let env = {}

  const handler = async (req, res) => {
    const action = (req.url || '').split('?')[0].replace(/^\/+|\/+$/g, '')
    const isGet = req.method === 'GET'
    if (!OA_PAY_ACTIONS.has(action)) return sendJson(res, 404, { code: 404, message: '未知支付接口' })
    if (!isGet && req.method !== 'POST') return sendJson(res, 405, { code: 405, message: '请求方法不允许' })

    const appId = env.OA_APP_ID
    const appSecret = env.OA_APP_SECRET
    const baseUrl = (env.OA_BASE_URL || 'https://oa.example.com').replace(/\/+$/, '')
    if (!appId || !appSecret) {
      return sendJson(res, 501, {
        code: 501,
        message: '未配置 OA_APP_ID / OA_APP_SECRET：请在 .env 中配置后重启（参考 .env.example）'
      })
    }

    const raw = isGet ? '' : await readBody(req)
    const bodyStr = isGet ? '{}' : raw || '{}'
    const timestamp = String(Math.floor(Date.now() / 1000))
    const nonce = randomBytes(8).toString('hex')
    const signature = createHmac('sha256', appSecret)
      .update(`${appId}|${timestamp}|${nonce}|${bodyStr}`)
      .digest('hex')

    try {
      const upstream = await fetch(`${baseUrl}/api/v1/payment/${action}`, {
        method: isGet ? 'GET' : 'POST',
        headers: {
          'content-type': 'application/json',
          'x-app-id': appId,
          'x-timestamp': timestamp,
          'x-nonce': nonce,
          'x-signature': signature
        },
        body: isGet ? undefined : bodyStr
      })
      const text = await upstream.text()
      res.statusCode = upstream.status
      res.setHeader('content-type', 'application/json; charset=utf-8')
      res.end(text)
    } catch (e) {
      sendJson(res, 502, { code: 502, message: `OA 支付网关请求失败：${e.message}` })
    }
  }

  return {
    name: 'oa-pay-gateway',
    configResolved(config) {
      env = loadEnv(config.mode, config.root, '')
    },
    configureServer(server) {
      server.middlewares.use('/oa-pay', handler)
    },
    configurePreviewServer(server) {
      server.middlewares.use('/oa-pay', handler)
    }
  }
}

function readBody(req) {
  return new Promise((resolve) => {
    let buf = ''
    req.on('data', (chunk) => (buf += chunk))
    req.on('end', () => resolve(buf))
  })
}

function sendJson(res, status, obj) {
  res.statusCode = status
  res.setHeader('content-type', 'application/json; charset=utf-8')
  res.end(JSON.stringify(obj))
}

export default defineConfig({
  plugins: [vue(), oaPayGateway()],
  server: {
    host: true,
    port: 5174,
    // 后端联调：/api 同源转发 ThinkPHP8（127.0.0.1:8180），前端无跨域；
    // 后端不可达时 useCrud 统一报错提示
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8180',
        changeOrigin: true
      }
    }
  },
  build: {
    rollupOptions: {
      output: {
        manualChunks: {
          'element-plus': ['element-plus', '@element-plus/icons-vue']
        }
      }
    }
  }
})
