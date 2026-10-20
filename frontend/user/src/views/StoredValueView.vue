<template>
  <div>
    <div class="navbar">
      <button class="navbar__back" @click="$router.back()">‹</button>
      <span class="navbar__title">储值余额</span>
    </div>

    <div class="page page--nobar">
      <!-- 账户 -->
      <div class="card balance-card">
        <div class="tiny" style="opacity: 0.85">当前余额（元）</div>
        <div class="balance-num">{{ account.balance }}</div>
        <div class="tiny" style="opacity: 0.85">
          {{ account.level }} · 累计充值 ¥{{ account.totalRecharge }} · 累计消费 ¥{{ account.totalConsume }}
        </div>
      </div>

      <!-- 充值套餐 -->
      <div class="sheet__title" style="margin: 4px 0 8px">充值套餐</div>
      <div class="plans" v-if="plans.length">
        <div v-for="p in plans" :key="p.money" class="card plan" @click="recharge(p)">
          <div class="plan__money">¥{{ p.money }}</div>
          <div v-if="p.gift" class="tag tag--orange">送 ¥{{ p.gift }}</div>
          <div class="tiny muted">到账 ¥{{ Number(p.money) + Number(p.gift) }}</div>
        </div>
      </div>
      <div v-else class="empty" style="margin-bottom: 12px">商家暂未配置充值套餐，请联系商家开通</div>
      <div class="tiny muted" style="margin-bottom: 12px">充值套餐由商家后台「套餐设置」维护；支付通道接入前，充值确认后即时到账。</div>

      <!-- 流水 -->
      <div class="sheet__title" style="margin: 4px 0 8px">余额流水</div>
      <div class="card">
        <div v-for="(l, i) in logs" :key="i" class="list-row">
          <div class="list-row__main">
            <div class="list-row__title">{{ l.action }} · {{ l.detail }}</div>
            <div class="list-row__sub">{{ l.at }}</div>
          </div>
          <div style="text-align: right">
            <div :class="Number(l.amount) >= 0 ? 'price' : 'muted'">{{ Number(l.amount) >= 0 ? '+' : '' }}{{ l.amount }}</div>
            <div class="tiny muted">余 ¥{{ l.balance }}</div>
          </div>
        </div>
        <div v-if="!logs.length" class="empty">暂无流水</div>
      </div>
    </div>

    <!-- 充值支付 -->
    <PaySheet :show="showPay" :amount="pending?.money ?? 0" @pay="pay" @cancel="showPay = false" />
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useCrud } from '../composables/useCrud'
import { useToast } from '../composables/useToast'
import PaySheet from '../components/PaySheet.vue'
import { fetchStored, rechargeStored } from '../api/memberSelf'
import { payConfirm } from '../api/orders'

const { toast } = useToast()

// 单一数据源：my/stored 聚合接口（账户/套餐/流水一次拉取）；失败报错不再落 mock
const ridOf = (row) => row._rid ?? row.id

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
    return
  } catch (e) {
    toast(e.isHttpDown ? '后端服务未连接，请检查后端 API 服务是否启动' : (e.message || '加载失败'))
  }
}

async function pay(channel) {
  const p = pending.value
  if (!p) return
  const add = Number(p.money) + Number(p.gift)
  try {
    // 真实链路：先建待支付充值单 → 确认支付（模拟渠道成功）→ 服务端统一入账
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
.plans { display: flex; gap: 10px; }
.plan { flex: 1; text-align: center; padding: 14px 6px; cursor: pointer; }
.plan__money { font-size: 18px; font-weight: 600; color: var(--primary); margin-bottom: 6px; }
</style>
