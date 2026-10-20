// 购买授权在线支付对接层（完整对接 OA 开放平台统一支付）
//
// 通道：Vite 服务端签名网关 /oa-pay/:action（vite.config.js oaPayGateway）
//   - HMAC-SHA256("app_id|timestamp|nonce|body", app_secret) 仅在服务端计算，密钥永不进浏览器 bundle
//   - .env 配置 OA_APP_ID / OA_APP_SECRET / OA_BASE_URL；VITE_OA_PAY=real 开启真实支付，否则走模拟支付
// OA 契约（feishu/backend/src/routes/paymentApi.js，签名同 utils/crypto.js verifySignatureV2）：
//   POST /api/v1/payment/create-order
//     body: { account_type: personal|enterprise, pay_method: face_to_face|wap_pay|page_pay,
//             subject, total_amount, out_trade_no?, notify_url? | no_notify: true, extra_data? }
//       personal 账户仅支持 face_to_face；enterprise 三种支付方式全支持
//     → { code: 0, data: { order_no, qr_code_url, qr_code_image, alipay_form_html, pay_url } }
//       face_to_face 用 qr_code_image（base64 二维码）；page_pay/wap_pay 用 pay_url 或 alipay_form_html 跳转
//   POST /api/v1/payment/query-order   body: { order_no | out_trade_no }
//     → { code: 0, data: { order_no, trade_status, total_amount, subject, pay_method, account_type,
//                          alipay_trade_no, buyer_id, paid_at, created_at } }
//       trade_status: WAIT_BUYER_PAY / TRADE_SUCCESS / TRADE_FINISHED / ALIPAY_ERROR
//   POST /api/v1/payment/refund        body: { order_no, refund_amount, refund_reason? }（仅 enterprise 账户）
//   GET  /api/v1/payment/subjects      → 收款科目列表
// 支付结果：本阶段 no_notify:true + 前端轮询 query-order 确认；
//   PHP 后端上线后改传 notify_url，OA 验签后 forwardToCaller 转发回调，按 out_trade_no 幂等开通授权（《开发计划》§1.7）

/** 是否真实支付通道（false = 模拟支付：8 秒后自动确认支付） */
export const isRealPay = import.meta.env.VITE_OA_PAY === 'real'

async function callOaPay(action, body, method = 'POST') {
  const res = await fetch(`/oa-pay/${action}`, {
    method,
    headers: method === 'GET' ? {} : { 'content-type': 'application/json' },
    body: method === 'GET' ? undefined : JSON.stringify(body || {})
  })
  return res.json()
}

// ---------------- mock 通道（模拟支付） ----------------
const orderCreatedAt = new Map()
const MOCK_PAID_AFTER_MS = 8000 // mock：下单 8 秒后视为支付成功

function mockDelay(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

/**
 * 创建支付订单
 * @param {{ subject: string, totalAmount: number, payMethod: 'face_to_face'|'page_pay'|'wap_pay', plan?: string }} payload
 * @returns {Promise<{ code: number, data: { order_no: string, qr_code_url: string, qr_code_image: string|null, pay_url: string, alipay_form_html: string|null }, mock?: boolean }>}
 */
export async function createPayOrder({ subject, totalAmount, payMethod, plan }) {
  if (isRealPay) {
    const res = await callOaPay('create-order', {
      account_type: 'enterprise', // 企业账户：三种支付方式全支持且可退款
      pay_method: payMethod,
      subject,
      total_amount: Number(totalAmount).toFixed(2),
      no_notify: true, // 本阶段前端轮询确认；后端上线后改传 notify_url
      extra_data: { plan: plan || '', channel: 'admin-purchase' }
    })
    if (res.code !== 0) throw new Error(res.message || '创建支付订单失败')
    return res
  }

  await mockDelay(600)
  const orderNo = 'PAY' + Date.now()
  orderCreatedAt.set(orderNo, Date.now())
  return {
    code: 0,
    mock: true,
    data: {
      order_no: orderNo,
      // face_to_face：返回二维码；page_pay/wap_pay：返回收银台跳转地址
      qr_code_url: payMethod === 'face_to_face' ? 'https://qr.alipay.com/mock-' + orderNo : '',
      qr_code_image: null, // 真实接入后为 base64 二维码图片（qr_code_image）
      pay_url: payMethod === 'face_to_face' ? '' : 'https://openapi.alipay.com/gateway.do?mock=' + orderNo,
      alipay_form_html: null
    }
  }
}

/**
 * 查询支付订单状态（轮询用）
 * @param {string} orderNo
 */
export async function queryPayOrder(orderNo) {
  if (isRealPay) {
    const res = await callOaPay('query-order', { order_no: orderNo })
    if (res.code !== 0) throw new Error(res.message || '查询支付订单失败')
    return res
  }

  await mockDelay(300)
  const createdAt = orderCreatedAt.get(orderNo) || 0
  const paid = Date.now() - createdAt > MOCK_PAID_AFTER_MS
  return {
    code: 0,
    mock: true,
    data: {
      order_no: orderNo,
      trade_status: paid ? 'TRADE_SUCCESS' : 'WAIT_BUYER_PAY'
    }
  }
}

/**
 * 退款（仅企业账户；授权购买退款/售后用）
 * @param {{ orderNo: string, refundAmount: number, refundReason?: string }} payload
 */
export async function refundPayOrder({ orderNo, refundAmount, refundReason }) {
  if (isRealPay) {
    const res = await callOaPay('refund', {
      order_no: orderNo,
      refund_amount: Number(refundAmount).toFixed(2),
      refund_reason: refundReason || ''
    })
    if (res.code !== 0) throw new Error(res.message || '退款失败')
    return res
  }

  await mockDelay(400)
  return { code: 0, mock: true, data: { order_no: orderNo, refund_status: 'REFUND_SUCCESS' } }
}

/**
 * 收款科目列表（GET，签名 body 为 '{}'）
 */
export async function getPaySubjects() {
  if (isRealPay) return callOaPay('subjects', null, 'GET')
  return { code: 0, mock: true, data: { subjects: [] } }
}
