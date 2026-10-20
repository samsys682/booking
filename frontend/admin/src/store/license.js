// 授权状态 store（卡密激活后菜单/功能联动解锁）
// 授权以真实后端为准：登录/设置页从 /auth/profile、/license/activate（卡号+卡密）拉取；
// 不再做前端演示兜底，后端不可达时保持未激活态并提示错误
import { defineStore } from 'pinia'
import { request } from '../api/http'

const TAG_BY_EDITION = { open: 'warning', pro: 'success', src: 'danger' }
// 后端 limits 形状 {stores_max,...}（-1=不限）→ 视图形状 {stores:{used,max},...}（max:null=不限）
const LIMIT_KEY = {
  stores: 'stores_max',
  technicians: 'technicians_max',
  monthlyOrders: 'monthly_orders_max',
  wechatMessages: 'wechat_messages_max'
}

function mapLimits(apiLimits, prevLimits) {
  const out = {}
  for (const key of Object.keys(LIMIT_KEY)) {
    const raw = Number((apiLimits || {})[LIMIT_KEY[key]] ?? 0)
    out[key] = {
      used: (prevLimits && prevLimits[key] && Number(prevLimits[key].used)) || 0,
      max: raw < 0 ? null : raw
    }
  }
  return out
}

export const useLicenseStore = defineStore('license', {
  state: () => ({
    edition: '开源版',
    editionKey: 'open',
    status: '未激活',
    tagType: 'warning',
    lastVerifiedAt: '',
    limits: {
      stores: { used: 0, max: 1 },
      technicians: { used: 0, max: 5 },
      monthlyOrders: { used: 0, max: 200 },
      // 2026-10-10 产品决策：微信通知全版本不限条数（max:null=不限）
      wechatMessages: { used: 0, max: null }
    },
    // 版本能力清单（/auth/profile 的 gates）：{edition,resources,features,addons}
    // 供视图判断某资源/动作是否可写（未加载时按可写处理，真实拦截仍以后端 403 为准）
    gates: null,
    loaded: false
  }),
  getters: {
    // 开源版为受限版本（后端键 open / mock 键 opensource）；授权版 / 源码版均为已开放版本
    isPro: (state) => state.editionKey === 'pro' || state.editionKey === 'src',
    // 某资源当前版本是否可写（gates 未加载时不拦截，交给后端）
    canWrite: (state) => (resource) => {
      if (!state.gates || !state.gates.resources) return true
      return state.gates.resources[resource] !== false
    },
    orderLimitRatio: (state) =>
      state.limits.monthlyOrders.max === null
        ? 0
        : state.limits.monthlyOrders.used / state.limits.monthlyOrders.max
  },
  actions: {
    // 后端授权信息（LicenseService::info）→ store 视图形状
    applyApi(info) {
      if (!info || !info.edition) return
      this.editionKey = info.edition
      this.edition = info.editionName || info.edition
      this.status = info.status || '未激活'
      this.tagType = TAG_BY_EDITION[info.edition] || 'warning'
      this.lastVerifiedAt = info.lastVerifiedAt || ''
      this.limits = mapLimits(info.limits, this.limits)
      this.loaded = true
    },
    // 拉取当前授权（设置页/直接进入时刷新）；失败静默，保持现状（未激活/上次已知状态）
    async refresh() {
      try {
        const res = await request('/auth/profile')
        if (res && res.license) this.applyApi(res.license)
        // 同步版本能力清单（菜单标签/按钮可用性判断用）
        if (res && res.gates) this.gates = res.gates
      } catch (e) {
        /* 后端不可达时保留 mock/现状 */
      }
    },
    // 卡密激活（v1.14 OA 真实验卡制）：card_key 仅本次请求，不落任何存储；
    // 成功后后端写 license 表 + 覆盖配额，返回授权信息直接驱动视图
    async activateWithCard(cardNo, cardKey) {
      const info = await request('/license/activate', {
        method: 'POST',
        body: { card_no: cardNo, card_key: cardKey }
      })
      this.applyApi(info)
      return info
    },
    // 每日续验：成功刷新 last_verified_at；业务拒绝/网络不可达由后端按宽限规则降级
    async reverify() {
      const res = await request('/license/reverify', { method: 'PUT' })
      await this.refresh()
      return res // {verified, edition, message}
    }
  }
})
