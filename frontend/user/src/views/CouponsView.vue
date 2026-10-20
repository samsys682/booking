<template>
  <div>
    <div class="navbar">
      <button class="navbar__back" @click="$router.back()">‹</button>
      <span class="navbar__title">我的优惠券</span>
    </div>

    <div class="page page--nobar">
      <!-- 可领取 -->
      <div class="sheet__title" style="margin: 4px 0 8px">可领取</div>
      <div v-for="c in available" :key="c.id" class="card coupon">
        <div class="coupon__value">
          <div class="coupon__num">{{ c.value }}</div>
          <div class="tiny">{{ c.threshold }}</div>
        </div>
        <div class="coupon__main">
          <div class="list-row__title">{{ c.name }}</div>
          <div class="tiny muted">有效期至 {{ c.expires }}</div>
          <div class="tiny muted">已领 {{ c.sent }}/{{ c.total }}</div>
        </div>
        <button class="btn btn--primary btn--mini" style="flex: none" @click="claim(c)">领取</button>
      </div>
      <div v-if="!available.length" class="empty">暂无可领取的券</div>

      <!-- 我的券 -->
      <div class="sheet__title" style="margin: 16px 0 8px">我的券（{{ myCoupons.length }}）</div>
      <div v-for="c in myCoupons" :key="c.code" class="card coupon">
        <div class="coupon__value">
          <div class="coupon__num tiny-code">{{ c.code }}</div>
          <div class="tiny">{{ c.couponName }}</div>
        </div>
        <div class="coupon__main">
          <div class="list-row__title">{{ c.couponName }}</div>
          <div class="tiny muted">领取于 {{ c.at }} · 来源 {{ c.source }}</div>
          <div v-if="c.orderNo" class="tiny muted">已用于订单 {{ c.orderNo }}</div>
        </div>
        <span class="tag" :class="c.status === '未使用' ? 'tag--green' : 'tag--gray'" style="flex: none">{{ c.status }}</span>
      </div>
      <div v-if="!myCoupons.length" class="empty">还没有券，去上面领一张吧</div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { useToast } from '../composables/useToast'
import { fetchAvailableCoupons, fetchMyCoupons, claimCoupon } from '../api/memberSelf'

const { toast } = useToast()

// 单一数据轨道：my/coupons*（后端输出视图形状 + 限领/库存服务端校验）；失败报错不再降级演示数据
const available = ref([])
const myCoupons = ref([])

// 错误统一提示：后端不可达报连接问题，其余报业务错误
function toastErr(e, fallback) {
  toast(e && e.isHttpDown ? '后端服务未连接，请检查后端 API 服务是否启动' : (e && e.message ? e.message : fallback))
}

async function load() {
  try {
    available.value = (await fetchAvailableCoupons()).list || []
    myCoupons.value = (await fetchMyCoupons()).list || []
  } catch (e) {
    toastErr(e, '加载失败')
  }
}

async function claim(c) {
  try {
    await claimCoupon(c.id)
    toast('领取成功')
  } catch (e) {
    toastErr(e, '领取失败')
  }
  await load()
}

onMounted(() => load())
</script>

<style scoped>
.coupon { display: flex; align-items: center; gap: 12px; }
.coupon__value {
  width: 88px;
  flex: none;
  text-align: center;
  padding: 10px 4px;
  background: var(--primary-light);
  border-radius: var(--radius-sm);
  color: var(--primary);
}
.coupon__num { font-size: 16px; font-weight: 600; }
.tiny-code { font-size: 11px; word-break: break-all; }
.coupon__main { flex: 1; min-width: 0; }
</style>
