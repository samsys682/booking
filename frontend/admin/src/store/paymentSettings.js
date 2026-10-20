// 在线支付设置 store（系统设置里开通支付功能，付费版可配置）
// 支付方式：支付宝 + 微信支付；开源版不提供该功能（Settings 中以锁定卡展示）
// 对接后端：GET/POST /api/v1/payment/channels
//   - 敏感字段（私钥 / APIv3 密钥 / 公钥）服务端 AES-256-GCM 加密存储，前端永不回显明文（只回 has_xxx）
//   - 保存属付费能力（后端 EditionGate 'pay.online' = pro），开源版保存会被 403 拦截并弹升级
import { defineStore } from 'pinia'
import { request } from '../api/http'

export const usePaymentSettingsStore = defineStore('paymentSettings', {
  state: () => ({
    alipay: {
      enabled: false,
      pid: '',
      appId: '',
      privateKey: '', // 仅用于本次提交；回显为空，用 hasPrivateKey 判断是否已配置
      publicKey: '',
      notifyUrl: '',
      returnUrl: '',
      gateway: ''
    },
    wechat: {
      enabled: false,
      mchId: '',
      appId: '',
      serialNo: '',
      apiKey: '', // 仅用于本次提交；回显为空，用 hasApiKey 判断
      privateKey: '',
      platformCert: '',
      notifyUrl: '',
      gateway: ''
    },
    offline: { enabled: true },
    // 服务端回显的「已配置」标记（敏感字段）
    has: {
      alipay: { privateKey: false, publicKey: false },
      wechat: { apiKey: false, privateKey: false, platformCert: false }
    },
    configured: { wechat: false, alipay: false, offline: true },
    canEdit: false,
    serverMethods: [],
    savedAt: null
  }),
  getters: {
    // 当前可用的在线支付方式（收款弹窗选项来源）
    // 优先用服务端返回的生效方式；未加载时按启用开关本地推导
    methods: (state) => {
      if (Array.isArray(state.serverMethods) && state.serverMethods.length) {
        return state.serverMethods.map((m) => ({ key: m.key, label: m.label }))
      }
      const list = []
      if (state.alipay.enabled) list.push({ key: 'alipay', label: '支付宝' })
      if (state.wechat.enabled) list.push({ key: 'wechat', label: '微信支付' })
      if (state.offline.enabled) list.push({ key: 'offline', label: '线下收款' })
      return list
    }
  },
  actions: {
    // 从后端加载（脱敏）；后端不可达时保持默认值
    async load() {
      const data = await request('/payment/channels')
      this.applyServer(data)
      return data
    },
    applyServer(data) {
      if (!data || !Array.isArray(data.channels)) return
      for (const c of data.channels) {
        const f = c.fields || {}
        if (c.key === 'alipay') {
          this.alipay.enabled = !!f.enabled
          this.alipay.pid = f.pid || ''
          this.alipay.appId = f.app_id || ''
          this.alipay.notifyUrl = f.notify_url || ''
          this.alipay.returnUrl = f.return_url || ''
          this.alipay.gateway = f.gateway || ''
          this.has.alipay.privateKey = !!f.has_private_key
          this.has.alipay.publicKey = !!f.has_public_key
        } else if (c.key === 'wechat') {
          this.wechat.enabled = !!f.enabled
          this.wechat.mchId = f.mch_id || ''
          this.wechat.appId = f.app_id || ''
          this.wechat.serialNo = f.serial_no || ''
          this.wechat.notifyUrl = f.notify_url || ''
          this.wechat.gateway = f.gateway || ''
          this.has.wechat.apiKey = !!f.has_api_v3_key
          this.has.wechat.privateKey = !!f.has_private_key
          this.has.wechat.platformCert = !!f.has_platform_cert
        } else if (c.key === 'offline') {
          this.offline.enabled = !!f.enabled
        }
        this.configured[c.key] = !!c.configured
      }
      this.canEdit = !!data.can_edit
      this.serverMethods = data.methods || []
    },
    // 保存到后端（敏感字段留空=保持原值）
    async save(payload) {
      const data = await request('/payment/channels', { method: 'POST', body: payload })
      this.applyServer(data)
      this.savedAt = new Date().toISOString()
      return data
    }
  }
})
