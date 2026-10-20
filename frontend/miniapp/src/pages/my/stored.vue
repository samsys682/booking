<template>
  <view class="page">
    <!-- 账户 -->
    <view class="card balance-card">
      <view class="tiny" style="opacity: 0.85">当前余额（元）</view>
      <view class="balance-num">{{ account.balance }}</view>
      <view class="tiny" style="opacity: 0.85">
        {{ account.level }} · 累计充值 ¥{{ account.totalRecharge }} · 累计消费 ¥{{ account.totalConsume }}
      </view>
    </view>

    <!-- 充值套餐 -->
    <view class="sheet__title" style="margin: 4px 0 8px">充值套餐</view>
    <view v-if="plans.length" class="plans">
      <view v-for="p in plans" :key="p.id" class="card plan" @tap="recharge(p)">
        <view class="plan__money">¥{{ p.money }}</view>
        <view v-if="Number(p.gift) > 0" class="tag tag--orange">送 ¥{{ p.gift }}</view>
        <view class="tiny muted">到账 ¥{{ Number(p.money) + Number(p.gift) }}</view>
      </view>
    </view>
    <view v-else class="empty" style="margin-bottom: 12px">商家暂未配置充值套餐，请联系商家开通</view>
    <view class="tiny muted" style="margin-bottom: 12px">充值套餐由商家后台「套餐设置」维护；充值确认后即时到账。</view>

    <!-- 流水 -->
    <view class="sheet__title" style="margin: 4px 0 8px">余额流水</view>
    <view class="card">
      <view v-for="(l, i) in logs" :key="i" class="list-row">
        <view class="list-row__main">
          <view class="list-row__title">{{ l.action }} · {{ l.detail }}</view>
          <view class="list-row__sub">{{ l.at }}</view>
        </view>
        <view style="text-align: right">
          <view :class="Number(l.amount) >= 0 ? 'price' : 'muted'">{{ Number(l.amount) >= 0 ? '+' : '' }}{{ l.amount }}</view>
          <view class="tiny muted">余 ¥{{ l.balance }}</view>
        </view>
      </view>
      <view v-if="!logs.length" class="empty">暂无流水</view>
    </view>

    <!-- 充值支付：线下通道（到店付/储值）确认后服务端统一入账 -->
    <PaySheet :show="showPay" :amount="pending?.money ?? 0" @pay="pay" @cancel="showPay = false" />
  </view>
</template>

<script setup>
// 储值余额 —— 顾客端小程序（对齐 user H5 StoredValueView：账户/套餐/流水 + 充值支付）
import { onMounted, reactive, ref } from 'vue'
import { toast } from '../../api/http'
import PaySheet from '../../components/PaySheet.vue'
import { fetchStored, rechargeStored } from '../../api/memberSelf'
import { payConfirm } from '../../api/orders'

// 单一数据源：my/stored 聚合接口（账户/套餐/流水一次拉取）；失败报错不再落 mock
const account = reactive({ id: '', balance: 0, level: '', totalRecharge: 0, totalConsume: 0 })
const accountId = ref('')
const plans = ref([])
const logs = ref([])

const showPay = ref(false)
const pending = ref(null)

function recharge(p) {
  pending.value = p
  showPay.value = true
}

async function load() {
  try {
    const data = await fetchStored()
    if (data.account) {
      Object.assign(account, data.account)
      accountId.value = data.account.id
    }
    plans.value = data.plans || []
    logs.value = data.logs || []
  } catch (e) {
    toast(e.isHttpDown ? '后端服务未连接，请检查后端 API 服务是否启动' : (e.message || '加载失败'))
  }
}

async function pay(channel) {
  const p = pending.value
  if (!p) return
  const add = Number(p.money) + Number(p.gift)
  try {
    // 真实链路：先建待支付充值单 → 确认支付（线下通道）→ 服务端统一入账
    const t = await rechargeStored(accountId.value, p.id)
    await payConfirm(t.out_trade_no, channel)
  } catch (e) {
    showPay.value = false
    toast(e.isHttpDown ? '后端服务未连接，请检查后端 API 服务是否启动' : (e.message || '充值失败'))
    return
  }
  await load()
  showPay.value = false
  toast('充值成功，到账 ¥' + add)
}

onMounted(() => load())
</script>

<style scoped>
.balance-card {
  background: linear-gradient(135deg, #2f6b4f, #4a8f6c);
  color: #fff;
}
.balance-num { font-size: 30px; font-weight: 600; margin: 6px 0; }
.plans { display: flex; flex-wrap: wrap; gap: 10px; }
.plan { flex: 1; min-width: 44%; text-align: center; padding: 14px 6px; }
.plan__money { font-size: 18px; font-weight: 600; color: #2f6b4f; margin-bottom: 6px; }
</style>
