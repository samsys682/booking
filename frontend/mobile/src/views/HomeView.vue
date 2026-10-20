<template>
  <div class="page">
    <div class="card">
      <div class="row row--between">
        <div>
          <div style="font-size: 17px; font-weight: 600">{{ greeting }}，{{ tech.name }}</div>
          <div class="tiny muted" style="margin-top: 4px">{{ techLine }}</div>
        </div>
        <span class="tag tag--green">{{ tech.status }}</span>
      </div>
    </div>

    <div class="stats">
      <div class="stat">
        <div class="stat__num">{{ todayOrders.length }}</div>
        <div class="stat__label">今日订单</div>
      </div>
      <div class="stat">
        <div class="stat__num">{{ pending.length }}</div>
        <div class="stat__label">待接单</div>
      </div>
      <div class="stat">
        <div class="stat__num">{{ monthCommission }}</div>
        <div class="stat__label">本月收入(元)</div>
      </div>
    </div>

    <!-- 紧急上报：长按 1.5s 防误触（缺口补齐批次三；开源版后端拦截并提示升级） -->
    <div class="card sos-card">
      <button
        class="sos-btn"
        :class="{ 'sos-btn--pressing': sosPressing }"
        @touchstart.prevent="startHold"
        @touchend="cancelHold"
        @touchmove="cancelHold"
        @mousedown="startHold"
        @mouseup="cancelHold"
        @mouseleave="cancelHold"
      >紧急上报 · 长按 1.5 秒</button>
      <div class="tiny muted" style="margin-top: 6px; text-align: center">遇紧急情况长按上报，门店管理员将立即收到通知</div>
    </div>

    <div class="sheet__title" style="margin: 4px 0 8px">待接单（{{ pending.length }}）</div>
    <div v-for="b in pending" :key="b.id" class="card">
      <div class="row row--between">
        <div class="list-row__title">{{ b.service }}</div>
        <span class="tag" :class="statusClass(b.status)">{{ b.status }}</span>
      </div>
      <div class="tiny muted" style="margin-top: 6px">{{ b.startAt }} · {{ b.customer }} {{ b.phone }}</div>
      <div class="tiny muted" style="margin-top: 2px">{{ b.address }}</div>
      <div v-if="b.note" class="tiny muted" style="margin-top: 2px">备注：{{ b.note }}</div>
      <div class="row row--between" style="margin-top: 10px">
        <div class="price"><span class="yen">¥</span>{{ b.amount }} <span class="tiny muted">佣金 ¥{{ b.commission }}</span></div>
        <div class="row" style="gap: 8px">
          <button class="btn btn--mini" @click="decline(b)">拒绝</button>
          <button class="btn btn--primary btn--mini" @click="accept(b)">接单</button>
        </div>
      </div>
    </div>
    <div v-if="!pending.length" class="empty">暂无待接单</div>

    <div class="sheet__title" style="margin: 4px 0 8px">抢单池（{{ pool.length }}）</div>
    <div v-for="b in pool" :key="b.id" class="card">
      <div class="row row--between">
        <div class="list-row__title">{{ b.service }}</div>
        <span class="tag tag--orange">待派单</span>
      </div>
      <div class="tiny muted" style="margin-top: 6px">{{ b.startAt }} · {{ b.customer }} {{ b.phone }}</div>
      <div class="tiny muted" style="margin-top: 2px">{{ b.address }}</div>
      <div v-if="b.note" class="tiny muted" style="margin-top: 2px">备注：{{ b.note }}</div>
      <div class="row row--between" style="margin-top: 10px">
        <div class="price"><span class="yen">¥</span>{{ b.amount }} <span class="tiny muted">佣金 ¥{{ b.commission }}</span></div>
        <button class="btn btn--primary btn--mini" @click="grab(b)">抢单</button>
      </div>
    </div>
    <div v-if="!pool.length" class="empty">抢单池暂无订单</div>

    <div class="sheet__title" style="margin: 4px 0 8px">进行中与今日</div>
    <div class="card">
      <div
        v-for="b in workingList"
        :key="b.id"
        class="list-row"
        style="cursor: pointer"
        @click="$router.push('/order/' + b.orderNo)"
      >
        <div class="list-row__main">
          <div class="list-row__title">{{ b.service }}</div>
          <div class="list-row__sub">{{ b.startAt }} · {{ b.customer }}</div>
        </div>
        <span class="tag" :class="statusClass(b.status)">{{ b.status }}</span>
      </div>
      <div v-if="!workingList.length" class="empty">今日暂无进行中订单</div>
    </div>

    <!-- 紧急上报弹层：类型 + 可选关联当前订单 + 位置文本；底部展示最近上报记录 -->
    <div v-if="sosVisible" class="sos-mask" @click.self="sosVisible = false">
      <div class="sos-sheet">
        <div class="sheet__title">紧急上报</div>
        <div class="sos-types">
          <button v-for="t in ALERT_TYPES" :key="t" class="sos-type" :class="{ active: sosType === t }" @click="sosType = t">{{ t }}</button>
        </div>
        <select v-model="sosOrderNo" class="sos-input">
          <option value="">不关联订单</option>
          <option v-for="b in workingList" :key="b.orderNo" :value="b.orderNo">关联：{{ b.service }}（{{ b.startAt }}）</option>
        </select>
        <input v-model="sosLocation" class="sos-input" placeholder="当前位置 / 所在门店（可留空）" maxlength="200" />
        <button class="btn btn--primary sos-submit" :disabled="sosSubmitting || !sosType" @click="submitSos">确认上报</button>
        <div v-if="alerts.length" class="sos-history">
          <div class="tiny muted" style="margin: 12px 0 6px">最近上报</div>
          <div v-for="a in alerts" :key="a.id" class="list-row">
            <div class="list-row__main">
              <div class="list-row__title">{{ a.detail }}</div>
              <div class="list-row__sub">{{ a.at }}<span v-if="a.orderNo"> · {{ a.orderNo }}</span></div>
            </div>
            <span class="tag" :class="a.status === '已处理' ? 'tag--green' : 'tag--red'">{{ a.status }}</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useToast } from '../composables/useToast'
import { STATUS_META, canTransition } from '../statusFlow'
import { loadTechnician } from '../api/auth'
import { skillsText } from '../composables/skillsText'
import { myOrders, poolOrders, acceptOrder, rejectOrder, grabOrder } from '../api/orders'
import { reportAlert, myAlerts, ALERT_TYPES } from '../api/emergency'

const { toast } = useToast()
// 登录后技师档案（localStorage 缓存）
const tech = loadTechnician() || {}

// 单一数据轨道：/orders/my + /orders/pool；失败报错不再降级演示数据
const realRows = ref([])
const realPool = ref([])

const ridOf = (row) => row._rid ?? row.id

// 日期口径：本地今天（toISOString 是 UTC 不能用）
const todayKey = computed(() => {
  const d = new Date()
  const p2 = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p2(d.getMonth() + 1)}-${p2(d.getDate())}`
})

const techLine = computed(() => [tech.level, tech.store, skillsText(tech.skills)].filter(Boolean).join(' · '))

const greeting = computed(() => {
  const h = new Date().getHours()
  if (h < 12) return '上午好'
  if (h < 18) return '下午好'
  return '晚上好'
})

const pending = computed(() => realRows.value.filter((b) => b.status === '待接单'))
// 抢单池：待派单未指派（STATUS_ACTIONS['待派单'].grab by:['mobile']）
const pool = computed(() => realPool.value.filter((b) => b.status === '待派单'))
const todayOrders = computed(() => realRows.value.filter((b) => b.date === todayKey.value))
const workingList = computed(() =>
  realRows.value.filter((b) => b.status === '服务中' || (b.status === '待服务' && b.date === todayKey.value))
)
const monthCommission = computed(() => {
  // 本月未取消/未退款订单佣金合计（后端无结算资源，前端按 55% 口径汇总）
  const m = todayKey.value.slice(0, 7)
  return realRows.value
    .filter((b) => (b.date || '').startsWith(m) && !['已取消', '已退款'].includes(b.status))
    .reduce((s, b) => s + Number(b.commission || 0), 0)
})

// 状态主题色 → 端内 tag 样式（语义口径来自 statusFlow.js::STATUS_META）
const TYPE_TAG = { warning: 'tag--orange', primary: 'tag--green', info: 'tag--gray', success: 'tag--green', danger: 'tag--red' }
function statusClass(s) {
  return TYPE_TAG[STATUS_META[s]?.type] || 'tag--gray'
}

// 错误统一提示：后端不可达报连接问题，其余报业务错误
function toastErr(e, fallback) {
  toast(e && e.isHttpDown ? '后端服务未连接，请检查后端 API 服务是否启动' : (e && e.message ? e.message : fallback))
}

async function loadAll() {
  try {
    const [my, poolRes] = await Promise.all([
      myOrders({ page: 1, per_page: 50 }),
      poolOrders({ page: 1, per_page: 50 })
    ])
    realRows.value = my.list
    realPool.value = poolRes.list
  } catch (e) {
    toastErr(e, '加载失败')
  }
}

async function accept(b) {
  try {
    await acceptOrder(b.orderNo)
    await loadAll()
    toast('已接单，记得按时上门')
  } catch (e) {
    toastErr(e, '操作失败')
  }
}

async function decline(b) {
  if (!window.confirm('确定拒绝该订单吗？拒绝后订单将回到平台派单池')) return
  try {
    await rejectOrder(b.orderNo, '技师拒绝')
    await loadAll()
    toast('已拒绝，订单回到派单池')
  } catch (e) {
    toastErr(e, '操作失败')
  }
}

// 抢单：待派单 --grab→ 待接单（指派给自己）--accept→ 待服务，两跳连贯且均在 TRANSITIONS 白名单内
async function grab(b) {
  if (!canTransition('待派单', '待接单') || !canTransition('待接单', '待服务')) {
    return toast('状态流转不允许')
  }
  if (!window.confirm(`确定抢下「${b.service}」吗？抢到后立即进入待服务`)) return
  try {
    await grabOrder(b.orderNo)
  } catch (e) {
    return toastErr(e, '抢单失败')
  }
  // grab 单跳（待派单→待接单）+ 自动接单到待服务
  try {
    await acceptOrder(b.orderNo)
  } catch (e) {
    await loadAll()
    return toast('已抢到，请在订单详情接单')
  }
  await loadAll()
  toast('抢单成功，记得按时上门')
}

// ---- 紧急上报（长按 1.5s 防误触，缺口补齐批次三）----
const HOLD_MS = 1500
const sosPressing = ref(false)
const sosVisible = ref(false)
const sosType = ref('')
const sosOrderNo = ref('')
const sosLocation = ref('')
const sosSubmitting = ref(false)
const alerts = ref([])
let holdTimer = null

function startHold() {
  if (sosVisible.value) return
  sosPressing.value = true
  clearTimeout(holdTimer)
  holdTimer = setTimeout(() => {
    sosPressing.value = false
    openSos()
  }, HOLD_MS)
}
function cancelHold() {
  sosPressing.value = false
  clearTimeout(holdTimer)
}

async function openSos() {
  sosType.value = ALERT_TYPES[0]
  sosOrderNo.value = workingList.value[0]?.orderNo || ''
  sosLocation.value = ''
  sosVisible.value = true
  // 最近上报记录拉取失败不阻塞上报主流程
  try {
    alerts.value = (await myAlerts()).list
  } catch (e) { /* 忽略 */ }
}

async function submitSos() {
  if (!sosType.value || sosSubmitting.value) return
  sosSubmitting.value = true
  try {
    await reportAlert({ type: sosType.value, orderNo: sosOrderNo.value, location: sosLocation.value })
    sosVisible.value = false
    toast('已上报，门店管理员将尽快与你联系')
    try {
      alerts.value = (await myAlerts()).list
    } catch (e) { /* 忽略 */ }
  } catch (e) {
    toastErr(e, '上报失败')
  } finally {
    sosSubmitting.value = false
  }
}

onBeforeUnmount(() => clearTimeout(holdTimer))
onMounted(loadAll)
</script>
