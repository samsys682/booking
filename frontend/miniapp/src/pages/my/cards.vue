<template>
  <view class="page">
    <view v-for="c in cards" :key="c.id" class="card">
      <view class="row row--between">
        <view>
          <view class="list-row__title">{{ c.card }}</view>
          <view class="tiny muted">{{ c.type }} · 有效期至 {{ c.expires }}</view>
        </view>
        <text class="tag" :class="c.status === '使用中' ? 'tag--green' : 'tag--gray'">{{ c.status }}</text>
      </view>
      <view class="progress">
        <view class="progress__bar" :style="{ width: pct(c) + '%' }"></view>
      </view>
      <view class="row row--between" style="margin-top: 6px">
        <text class="tiny muted">已用 {{ c.used }}/{{ c.total }} 次</text>
        <text class="tiny muted">购卡价 ¥{{ c.amount }}</text>
      </view>
    </view>
    <view v-if="!cards.length" class="empty">
      暂无会员卡
      <view><button class="btn btn--primary empty__btn" @tap="showBuy = true">去购卡</button></view>
    </view>

    <button class="btn btn--primary btn--block" style="margin-top: 4px" @tap="showBuy = true">购买新卡</button>

    <!-- 购卡：选择卡种 -->
    <view v-if="showBuy">
      <view class="mask" @tap="showBuy = false"></view>
      <view class="sheet">
        <view class="sheet__title">选择卡种</view>
        <view v-if="!cardOffers.length" class="tiny muted" style="padding: 16px 0; text-align: center">商家暂未配置可购卡种</view>
        <view v-for="o in cardOffers" :key="o.name" class="list-row" @tap="buy(o)">
          <view class="list-row__main">
            <view class="list-row__title">{{ o.name }}</view>
            <view class="list-row__sub">{{ o.type }} · 共 {{ o.total }} 次</view>
          </view>
          <view class="price"><text class="yen">¥</text>{{ o.amount }}</view>
        </view>
        <button class="btn btn--block" style="margin-top: 12px" @tap="showBuy = false">取消</button>
      </view>
    </view>

    <!-- 购卡支付：渠道选择（到店付/储值），确认后服务端统一入账开卡 -->
    <PaySheet :show="showCardPay" :amount="pendingCard?.amount ?? 0" @pay="payCard" @cancel="showCardPay = false" />
  </view>
</template>

<script setup>
// 我的会员卡 —— 顾客端小程序（对齐 user H5 MemberCardView：卡列表 + 购卡支付）
import { onMounted, ref } from 'vue'
import { toast } from '../../api/http'
import { fetchCards, fetchCardOffers, buyCard } from '../../api/memberSelf'
import { payConfirm } from '../../api/orders'
import PaySheet from '../../components/PaySheet.vue'

// total/used 为次数非金额；amount 为金额
// 单一数据源：my/cards 聚合接口；失败报错不再落 mock
const cards = ref([])
const showBuy = ref(false)
// 可购卡种（后端 card_type 表，取代历史前端 mock）
const cardOffers = ref([])
// 购卡支付单（建单成功后弹渠道选择）：{no: out_trade_no, amount: 购卡价元, name: 卡名}
const showCardPay = ref(false)
const pendingCard = ref(null)

function pct(c) {
  if (!c.total) return 0
  return Math.min(100, Math.round((Number(c.used) / Number(c.total)) * 100))
}

async function load() {
  try {
    cards.value = (await fetchCards()).list || []
  } catch (e) {
    toast(e.isHttpDown ? '后端服务未连接，请检查后端 API 服务是否启动' : (e.message || '加载失败'))
  }
}

async function loadOffers() {
  try {
    cardOffers.value = (await fetchCardOffers()).list || []
  } catch (e) {
    // 卡种加载失败不阻断页面：购卡面板显示「商家暂未配置可购卡种」
    cardOffers.value = []
  }
}

async function buy(o) {
  const expires = new Date()
  expires.setFullYear(expires.getFullYear() + 1)
  try {
    // 真实链路：先建待支付购卡单 → 弹渠道选择支付 → 服务端统一入账开卡
    const t = await buyCard({
      name: o.name,
      type: o.type,
      cycle: o.cycle,
      total: o.total,
      amount: o.amount,
      expires: expires.toISOString().slice(0, 10)
    })
    pendingCard.value = { no: t.out_trade_no, amount: o.amount, name: o.name }
    showCardPay.value = true
  } catch (e) {
    toast(e.isHttpDown ? '后端服务未连接，请检查后端 API 服务是否启动' : (e.message || '购卡失败'))
  }
}

async function payCard(channel) {
  const p = pendingCard.value
  if (!p) return
  try {
    await payConfirm(p.no, channel)
  } catch (e) {
    showCardPay.value = false
    return toast(e.message || '支付未完成，请稍后重试')
  }
  showCardPay.value = false
  showBuy.value = false
  await load()
  toast('购卡成功，已开通「' + p.name + '」')
}

onMounted(() => {
  load()
  loadOffers()
})
</script>

<style scoped>
.progress {
  height: 6px;
  border-radius: 999px;
  background: #e5e7e3;
  overflow: hidden;
  margin-top: 10px;
}
.progress__bar { height: 100%; background: #2f6b4f; border-radius: 999px; }
</style>
