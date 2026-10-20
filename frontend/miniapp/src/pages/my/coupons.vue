<template>
  <view class="page">
    <!-- 可领取 -->
    <view class="sheet__title" style="margin: 4px 0 8px">可领取</view>
    <view v-for="c in available" :key="c.id" class="card coupon">
      <view class="coupon__value">
        <view class="coupon__num">{{ c.value }}</view>
        <view class="tiny">{{ c.threshold }}</view>
      </view>
      <view class="coupon__main">
        <view class="list-row__title">{{ c.name }}</view>
        <view class="tiny muted">有效期至 {{ c.expires }}</view>
        <view class="tiny muted">已领 {{ c.sent }}/{{ c.total }}</view>
      </view>
      <button class="btn btn--primary btn--mini" style="flex: none" @tap="claim(c)">领取</button>
    </view>
    <view v-if="!available.length" class="empty">暂无可领取的券</view>

    <!-- 我的券 -->
    <view class="sheet__title" style="margin: 16px 0 8px">我的券（{{ myCoupons.length }}）</view>
    <view v-for="c in myCoupons" :key="c.code" class="card coupon">
      <view class="coupon__value">
        <view class="coupon__num tiny-code">{{ c.code }}</view>
        <view class="tiny">{{ c.couponName }}</view>
      </view>
      <view class="coupon__main">
        <view class="list-row__title">{{ c.couponName }}</view>
        <view class="tiny muted">领取于 {{ c.at }} · 来源 {{ c.source }}</view>
        <view v-if="c.orderNo" class="tiny muted">已用于订单 {{ c.orderNo }}</view>
      </view>
      <text class="tag" :class="c.status === '未使用' ? 'tag--green' : 'tag--gray'" style="flex: none">{{ c.status }}</text>
    </view>
    <view v-if="!myCoupons.length" class="empty">还没有券，去上面领一张吧</view>
  </view>
</template>

<script setup>
// 我的优惠券 —— 顾客端小程序（对齐 user H5 CouponsView：可领取 + 我的券）
import { onMounted, ref } from 'vue'
import { toast } from '../../api/http'
import { fetchAvailableCoupons, fetchMyCoupons, claimCoupon } from '../../api/memberSelf'

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
  background: #e4efe8;
  border-radius: 8px;
  color: #2f6b4f;
}
.coupon__num { font-size: 16px; font-weight: 600; }
.tiny-code { font-size: 11px; word-break: break-all; }
.coupon__main { flex: 1; min-width: 0; }
</style>
