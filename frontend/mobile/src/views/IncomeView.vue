<template>
  <div class="page">
    <div class="card" style="background: linear-gradient(135deg, #2f6b4f, #3f8a66); color: #fff">
      <div class="tiny" style="opacity: 0.85">本月预估收入</div>
      <div style="font-size: 30px; font-weight: 600; margin: 6px 0">
        <span style="font-size: 16px">¥</span>{{ monthIncome }}
      </div>
      <div class="tiny" style="opacity: 0.85">本月完成 {{ monthOrders }} 单 · 待结算 ¥{{ pendingAmount }}</div>
    </div>

    <div class="sheet__title" style="margin: 4px 0 8px">结算记录</div>
    <div class="card">
      <div v-for="s in rows" :key="s.id" class="list-row">
        <div class="list-row__main">
          <div class="list-row__title">{{ s.period }} 结算</div>
          <div class="list-row__sub">{{ s.orderCount }} 单 · 营收 ¥{{ s.gross }} · {{ s.settledAt ? s.settledAt + ' 到账' : '待到账' }}</div>
        </div>
        <div style="text-align: right">
          <div class="price"><span class="yen">¥</span>{{ s.commission }}</div>
          <span class="tag" :class="s.status === '已结算' ? 'tag--green' : 'tag--orange'">{{ s.status }}</span>
        </div>
      </div>
      <div v-if="!rows.length" class="empty">暂无结算记录</div>
    </div>

    <div class="sheet__title" style="margin: 4px 0 8px">计佣规则</div>
    <div class="card">
      <div v-for="r in commissionRules" :key="r.name" class="list-row">
        <div class="list-row__main">
          <div class="list-row__title">{{ r.name }}</div>
          <div class="list-row__sub">{{ r.detail }}</div>
        </div>
        <div class="price">{{ r.rate }}</div>
      </div>
      <div class="tiny muted" style="margin-top: 8px">规则由商家后台维护，最终以结算单为准</div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useToast } from '../composables/useToast'
import { fetchIncome } from '../api/techSelf'

const { toast } = useToast()

// gross/commission 为金额；orderCount 为次数
// 单一数据轨道：tech/income（后端直出视图形状）；失败报错不再降级演示数据

const rows = ref([])

// 本地当月（toISOString 为 UTC，须本地拼）
const nowD = new Date()
const periodNow = nowD.getFullYear() + '-' + String(nowD.getMonth() + 1).padStart(2, '0')

const monthIncome = computed(() => {
  const cur = rows.value.find((s) => s.period === periodNow)
  return cur ? cur.commission : 0
})
const monthOrders = computed(() => {
  const cur = rows.value.find((s) => s.period === periodNow)
  return cur ? cur.orderCount : 0
})
const pendingAmount = computed(() =>
  rows.value
    .filter((s) => s.status !== '已结算')
    .reduce((sum, s) => sum + Number(s.commission || 0), 0)
)

async function load() {
  try {
    rows.value = await fetchIncome()
  } catch (e) {
    toast(e && e.isHttpDown ? '后端服务未连接，请检查后端 API 服务是否启动' : (e && e.message ? e.message : '加载失败'))
  }
}

onMounted(load)
</script>
