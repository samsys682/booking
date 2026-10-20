<template>
  <view class="page">
    <!-- 顶部横幅 -->
    <view class="card hero">
      <view class="hero__title">上门服务预约</view>
      <view class="hero__sub">保洁 · 家电清洗 · 收纳整理，专业技师上门</view>
    </view>

    <!-- 搜索 -->
    <view class="card">
      <input v-model="keyword" class="field__input" placeholder="搜索服务，如：保洁" />
    </view>

    <!-- 分类筛选 -->
    <scroll-view scroll-x class="chips-scroll">
      <view class="chips">
        <text
          v-for="c in categories"
          :key="c"
          class="chip"
          :class="{ 'chip--active': category === c }"
          @tap="category = c"
        >{{ c }}</text>
      </view>
    </scroll-view>

    <!-- 进行中活动（管理端「营销活动」发布的限时拼团/秒杀/折扣；无活动时不占位） -->
    <view v-for="a in activities" :key="a.id" class="card activity" @tap="goActivity(a)">
      <view class="row row--between">
        <view class="list-row__title">{{ a.kind }} · {{ a.name }}</view>
        <text class="tag tag--red">进行中</text>
      </view>
      <view class="row row--between">
        <view class="price">
          <text class="yen">¥</text>{{ a.price }}
          <text v-if="a.originPrice > a.price" class="tiny muted" style="text-decoration: line-through; margin-left: 4px">¥{{ a.originPrice }}</text>
        </view>
        <text class="tiny muted" v-if="a.stock > 0">仅剩 {{ a.stock - a.sold }} 份</text>
      </view>
      <view class="tiny muted">{{ a.endAt }} 截止 · 点击查看可预约服务</view>
    </view>

    <!-- 服务列表 -->
    <view
      v-for="s in filtered"
      :key="s.id"
      class="card"
      @tap="goService(s)"
    >
      <view class="row row--between">
        <view class="list-row__title">{{ s.name }}</view>
        <text class="tag tag--green">{{ s.category }}</text>
      </view>
      <view class="muted tiny desc">{{ s.description }}</view>
      <view class="row row--between">
        <view class="price"><text class="yen">¥</text>{{ s.price }}<text class="tiny muted"> / {{ s.unit }}</text></view>
        <button class="btn btn--primary btn--mini" @tap.stop="goCreate(s.id)">立即预约</button>
      </view>
      <view class="tiny muted">已售 {{ s.sales }} · 约 {{ s.duration }} 分钟</view>
    </view>

    <view v-if="!filtered.length && !loading" class="empty">暂无相关服务，可联系商家或稍后再试</view>

    <!-- 未登录引导（services 公开可读，登录后才可下单/见活动） -->
    <view v-if="!isLogin" class="card" style="text-align: center">
      <view class="tiny muted" style="margin-bottom: 10px">登录后可预约下单、领券与积分兑换</view>
      <button class="btn btn--primary btn--block" @tap="goLogin">微信一键登录</button>
    </view>
  </view>
</template>

<script setup>
// 首页 —— 顾客端小程序（对齐 user H5 HomeView：搜索/分类/活动/服务列表）
import { computed, onMounted, ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { toast } from '../../api/http'
import { list } from '../../api/resource'
import { loadMember } from '../../api/auth'

const isLogin = ref(!!loadMember())
const loading = ref(true)
const rows = ref([])
const activities = ref([])
const keyword = ref('')
const category = ref('全部')
const categories = ['全部', '保洁', '家电清洗', '专项清洁', '收纳']

const filtered = computed(() =>
  rows.value.filter((s) => {
    if (!s.status) return false
    if (category.value !== '全部' && s.category !== category.value) return false
    if (keyword.value && !String(s.name + s.description).includes(keyword.value)) return false
    return true
  })
)

async function load() {
  loading.value = true
  try {
    const data = await list('services', { page: 1, per_page: 200 })
    rows.value = (data.list || []).map((r) => ({ ...r, price: Math.round(Number(r.price || 0)) / 100 }))
  } catch (e) {
    // 后端异常不阻断首页；模板已有空态提示
  } finally {
    loading.value = false
  }
}

// 进行中活动：marketing-activities 需登录态，未登录不拉取（避免 401 噪音）
async function loadActivities() {
  if (!loadMember()) return
  try {
    const data = await list('marketing-activities', { page: 1, per_page: 50, status: '进行中' })
    const now = Date.now()
    activities.value = (data.list || [])
      .map((r) => ({
        ...r,
        price: Math.round(Number(r.price || 0)) / 100,
        originPrice: Math.round(Number(r.origin_price || 0)) / 100
      }))
      .filter((a) => {
        const end = a.endAt || a.end_at
        const endTs = end ? new Date(String(end).replace(' ', 'T')).getTime() : 0
        return a.status === '进行中' && a.price > 0 && (!endTs || endTs > now)
      })
  } catch (e) {
    // 活动加载失败不阻断首页
  }
}

function goService(s) {
  uni.navigateTo({ url: '/pages/service/detail?id=' + s.id })
}

function goCreate(serviceId) {
  if (!loadMember()) {
    toast('请先登录')
    return goLogin()
  }
  uni.navigateTo({ url: '/pages/order/create?serviceId=' + serviceId })
}

function goActivity(a) {
  const sid = a.serviceId || a.service_id
  if (sid && sid > 0) return goCreate(sid)
  category.value = '全部'
  keyword.value = ''
}

function goLogin() {
  uni.navigateTo({ url: '/pages/login/login' })
}

onShow(() => {
  isLogin.value = !!loadMember()
})

onMounted(() => {
  load()
  loadActivities()
})
</script>

<style scoped>
.hero {
  background: linear-gradient(135deg, #2f6b4f, #4a8f6c);
  color: #fff;
}
.hero__title { font-size: 19px; font-weight: 600; }
.hero__sub { margin-top: 6px; font-size: 12px; opacity: 0.85; }
.activity {
  border: 1px solid #ffd6d6;
  background: #fffafa;
}
.tag--red { color: #d03050; }
.desc {
  margin: 6px 0 10px;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.chips-scroll { white-space: nowrap; }
</style>
