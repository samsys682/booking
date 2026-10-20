<template>
  <view class="page" style="padding-bottom: 80px">
    <view v-if="service" class="card">
      <view class="row row--between">
        <view class="list-row__title" style="font-size: 17px">{{ service.name }}</view>
        <text class="tag tag--green">{{ service.category }}</text>
      </view>
      <view class="price" style="font-size: 22px; margin: 10px 0">
        <text class="yen">¥</text>{{ service.price }}<text class="tiny muted"> / {{ service.unit }}</text>
      </view>
      <view class="muted tiny">服务时长约 {{ service.duration }} 分钟 · 已售 {{ service.sales }}</view>
    </view>

    <!-- 可服务技师（批次一 F 反向视图；后端详情 enrich 出参） -->
    <view v-if="service && techs.length" class="card">
      <view class="sheet__title">可服务技师</view>
      <view v-for="t in techs" :key="t.id" class="list-row">
        <view class="list-row__main">
          <view class="list-row__title">{{ t.name }}</view>
          <view class="list-row__sub">{{ t.skills }}</view>
        </view>
        <text class="tag tag--green">{{ t.status }}</text>
      </view>
    </view>

    <view v-if="service" class="card">
      <view class="sheet__title">服务介绍</view>
      <!-- 富文本为商家后台录入的自家内容；小程序用 rich-text 渲染 -->
      <rich-text :nodes="service.detail || '商家暂未填写介绍'"></rich-text>
    </view>

    <view v-if="!service && !loading" class="empty">未找到该服务</view>

    <view v-if="service" class="action-bar">
      <button class="btn" @tap="goHome">返回首页</button>
      <button class="btn btn--primary" :disabled="!service.status" @tap="goCreate">
        {{ service.status ? '立即预约' : '暂未上架' }}
      </button>
    </view>
  </view>
</template>

<script setup>
// 服务详情 —— 顾客端小程序（对齐 user H5 ServiceDetail + 批次一 F 可服务技师）
import { onMounted, ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { list, read } from '../../api/resource'
import { skillsText } from '../../utils'

const service = ref(null)
const techs = ref([])
const loading = ref(true)
let serviceId = ''

onLoad((q) => {
  serviceId = String(q.id || '')
})

onMounted(async () => {
  try {
    const data = await list('services', { page: 1, per_page: 200 })
    const row = (data.list || []).find((s) => String(s.id) === serviceId) || null
    if (row) {
      // 金额分→元；可服务技师（详情 enrich 字段，列表无此字段时回退详情接口）
      row.price = Math.round(Number(row.price || 0)) / 100
      service.value = row
      try {
        const detail = await read('services', row.id)
        const enrich = detail?.serviceable_technicians || []
        techs.value = enrich.map((t) => ({ ...t, skills: skillsText(t.skills) }))
      } catch (e) { /* 反向视图加载失败不阻断页面 */ }
    }
  } catch (e) {
    service.value = null
  } finally {
    loading.value = false
  }
})

function goHome() {
  uni.switchTab({ url: '/pages/index/index' })
}

function goCreate() {
  uni.navigateTo({ url: '/pages/order/create?serviceId=' + serviceId })
}
</script>
