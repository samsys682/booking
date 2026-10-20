<template>
  <div class="page">
    <div class="chips">
      <div
        v-for="s in rows"
        :key="s.id"
        class="chip"
        :class="{ active: s.date === activeDate }"
        @click="activeDate = s.date"
      >
        {{ shortDate(s.date) }}
      </div>
    </div>

    <div v-if="daySchedule" class="card">
      <div class="row row--between">
        <div>
          <div class="list-row__title">{{ daySchedule.date }} 排班</div>
          <div class="list-row__sub">
            {{ daySchedule.status === '休息' ? '今日休息' : daySchedule.timeRange + ' · 已约 ' + daySchedule.booked + '/' + daySchedule.maxOrders + ' 单' }}
          </div>
        </div>
        <button class="btn btn--mini" @click="toggleSchedule(daySchedule)">
          {{ daySchedule.status === '休息' ? '恢复接单' : '设为休息' }}
        </button>
      </div>
      <div v-if="daySchedule.status !== '休息'" class="progress">
        <div class="progress__bar" :style="{ width: pct(daySchedule) + '%' }"></div>
      </div>
    </div>
    <div v-else class="empty">该日无排班</div>

    <div class="sheet__title" style="margin: 4px 0 8px">当日订单（{{ dayOrders.length }}）</div>
    <div v-for="b in dayOrders" :key="b.id" class="card" style="cursor: pointer" @click="$router.push('/order/' + b.orderNo)">
      <div class="row row--between">
        <div class="list-row__title">{{ b.time }} · {{ b.service }}</div>
        <span class="tag" :class="statusClass(b.status)">{{ b.status }}</span>
      </div>
      <div class="tiny muted" style="margin-top: 4px">{{ b.customer }} {{ b.phone }}</div>
      <div class="tiny muted" style="margin-top: 2px">{{ b.address }}</div>
    </div>
    <div v-if="!dayOrders.length" class="empty">当日暂无订单</div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useToast } from '../composables/useToast'
import { fetchSchedules, toggleScheduleRest } from '../api/techSelf'
import { myOrders } from '../api/orders'
import { STATUS_META, TERMINAL_STATUSES } from '../statusFlow'

const { toast } = useToast()

// 排班：maxOrders/booked 为次数非金额
// 单一数据轨道：tech/schedules + orders/my；失败报错不再降级演示数据

const rows = ref([])
const rowsB = ref([])

// 本地今天（toISOString 为 UTC，须本地拼）；无该日排班时 load 后回落首个排班日
const dNow = new Date()
const activeDate = ref(dNow.getFullYear() + '-' + String(dNow.getMonth() + 1).padStart(2, '0') + '-' + String(dNow.getDate()).padStart(2, '0'))

const daySchedule = computed(() => rows.value.find((s) => s.date === activeDate.value))
const dayOrders = computed(() =>
  rowsB.value.filter((b) => b.date === activeDate.value && !TERMINAL_STATUSES.includes(b.status))
    .slice()
    .sort((a, b) => String(a.time).localeCompare(String(b.time)))
)

function shortDate(d) {
  const p = String(d).split('-')
  return p[1] + '/' + p[2]
}

function pct(s) {
  if (!s.maxOrders) return 0
  return Math.min(100, Math.round((Number(s.booked) / Number(s.maxOrders)) * 100))
}

// 状态主题色 → 端内 tag 样式（语义口径来自 statusFlow.js::STATUS_META）
const TYPE_TAG = { warning: 'tag--orange', primary: 'tag--green', info: 'tag--gray', success: 'tag--green', danger: 'tag--red' }
function statusClass(s) {
  return TYPE_TAG[STATUS_META[s]?.type] || 'tag--gray'
}

// 错误统一提示：后端不可达报连接问题，其余报业务错误
function toastErr(e, fallback) {
  toast(e && e.isHttpDown ? '后端服务未连接，请检查后端 API 服务是否启动' : (e && e.message ? e.message : fallback))
}

async function load() {
  try {
    const [sch, orders] = await Promise.all([fetchSchedules(), myOrders({ page: 1, per_page: 50 })])
    rows.value = sch
    rowsB.value = orders.list
  } catch (e) {
    toastErr(e, '加载失败')
  }
  // activeDate 容错：当日无排班时切到首个排班日
  if (!rows.value.some((s) => s.date === activeDate.value)) {
    activeDate.value = rows.value[0]?.date || activeDate.value
  }
}

async function toggleSchedule(s) {
  const toRest = s.status !== '休息'
  try {
    await toggleScheduleRest(s.id)
  } catch (e) {
    return toastErr(e, '操作失败')
  }
  await load()
  toast(toRest ? '已设为休息' : '已恢复接单')
}

onMounted(load)
</script>
