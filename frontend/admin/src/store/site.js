// 站点与品牌 store（四杠杆 · 品牌权益）
// GET /site 免 token（登录页即可读取站点名/Logo）；POST /site 需 system.edit + 版本门禁
// 开源版：品牌名不生效、Powered by 强制保留（后端已兜底，前端据 canCustomBrand 展示锁定态）
import { defineStore } from 'pinia'
import { request } from '../api/http'

export const useSiteStore = defineStore('site', {
  state: () => ({
    siteName: '预约上门服务系统',
    brandName: '',
    displayName: '预约上门服务系统',
    companyName: '',
    servicePhone: '',
    logo: '',
    poweredBy: true,
    poweredText: 'Powered by 预约上门服务系统',
    edition: 'open',
    editionName: '开源版',
    canCustomBrand: false,
    loaded: false
  }),
  actions: {
    // 读取站点信息（后端不可达时保持默认，不阻断登录页渲染）
    async load() {
      try {
        this.apply(await request('/site'))
      } catch (e) {
        // 静默：登录页/后台展示用，失败不弹错
      }
      this.loaded = true
      return this.$state
    },
    apply(d) {
      if (!d) return
      this.siteName = d.site_name || this.siteName
      this.brandName = d.brand_name || ''
      this.displayName = d.display_name || this.siteName
      this.companyName = d.company_name || ''
      this.servicePhone = d.service_phone || ''
      this.logo = d.logo || ''
      this.poweredBy = d.powered_by !== false
      this.poweredText = d.powered_text || this.poweredText
      this.edition = d.edition || this.edition
      this.editionName = d.edition_name || this.editionName
      this.canCustomBrand = !!d.can_custom_brand
    },
    // 保存（品牌权益按版本门禁，开源版会被 403 拦截并弹升级引导）
    async save(payload) {
      const d = await request('/site', { method: 'POST', body: payload })
      this.apply(d)
      return d
    }
  }
})
