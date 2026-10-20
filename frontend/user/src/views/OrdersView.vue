<template>
  <div class="page">
    <div class="chips">
      <span
        v-for="t in tabs"
        :key="t"
        class="chip"
        :class="{ active: tab === t }"
        @click="switchTab(t)"
      >{{ t }}</span>
    </div>

    <div v-for="o in displayRows" :key="o.orderNo" class="card">
      <div class="row row--between" @click="$router.push('/order/' + o.orderNo)">
        <div class="list-row__title">{{ o.service }}</div>
        <span class="tag" :class="statusClass(o.status)">{{ o.status }}</span>
      </div>
      <div class="tiny muted" style="margin: 6px 0" @click="$router.push('/order/' + o.orderNo)">
        单号 {{ o.orderNo }}<br />
        {{ o.startAt }} · {{ o.address }}
      </div>
      <div class="row row--between">
        <div class="price"><span class="yen">¥</span>{{ o.actual }}</div>
        <div class="row">
          <button
            v-for="a in listActions(o)"
            :key="a.key"
            class="btn btn--mini"
            :class="{ 'btn--primary': a.key === 'pay' }"
            @click="doAction(a.key, o)"
          >{{ actionLabel[a.key] }}</button>
          <button v-if="canReorder(o)" class="btn btn--mini" @click="reorder(o)">再来一单</button>
          <button class="btn btn--mini" @click="$router.push('/order/' + o.orderNo)">查看详情</button>
        </div>
      </div>
    </div>

    <div v-if="!displayRows.length && !loading" class="empty">
      暂无订单
      <div><button class="btn btn--primary empty__btn" @click="$router.push('/home')">去逛逛</button></div>
    </div>

    <PaySheet :show="showPay" :amount="payAmount" @pay="pay" @cancel="showPay = false" />
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useToast } from '../composables/useToast'
import PaySheet from '../components/PaySheet.vue'
import { allowedActions, canTransition, isFinishedSettled, STATUS_META } from '../statusFlow'
import {
  myOrders,
  orderDetail,
  payConfirm,
  cancelOrder as apiCancelOrder,
  confirmOrder as apiConfirmOrder
} from '../api/orders'
const router = useRouter()
const { toast } = useToast()

// 单一数据轨道：/orders/my；失败报错不再降级演示数据
const rows = ref([])
const loading = ref(false)

// 状态主题色 → 端内 tag 样式（语义口径来自 statusFlow.js::STATUS_META，此处仅做样式适配）
const TYPE_TAG = { warning: 'tag--orange', primary: 'tag--green', info: 'tag--gray', success: 'tag--green', danger: 'tag--red' }
function statusClass(s) {
  return TYPE_TAG[STATUS_META[s]?.type] || 'tag--gray'
}

// 状态口径分组浏览：「进行中」聚合 待派单/待接单/待服务/服务中，「售后/退款」聚合 逆向流售后中/已退款
const tabs = ['全部', '待支付', '进行中', '待确认', '待评价', '已完成', '售后/退款', '已取消']
const IN_PROGRESS = ['待派单', '待接单', '待服务', '服务中']
const IN_REVERSE = ['售后中', '已退款']
const tab = ref('全部')

const displayRows = computed(() => {
  if (tab.value === '全部') return rows.value
  if (tab.value === '进行中') return rows.value.filter((o) => IN_PROGRESS.includes(o.status))
  if (tab.value === '售后/退款') return rows.value.filter((o) => IN_REVERSE.includes(o.status))
  return rows.value.filter((o) => o.status === tab.value)
})

async function load() {
  // 全量拉取，tab 本地过滤（九态分组无法用单值 status 参数表达）
  try {
    loading.value = true
    const data = await myOrders({ page: 1, per_page: 100 })
    rows.value = data.list
  } catch (e) {
    toast(e && e.isHttpDown ? '后端服务未连接，请检查后端 API 服务是否启动' : (e && e.message ? e.message : '加载失败'))
  } finally {
    loading.value = false
  }
}

function switchTab(t) {
  tab.value = t
  load()
}

// 列表页轻量动作；改约/评价/售后等复杂动作在详情页操作（口径同 statusFlow.js::STATUS_ACTIONS）
const LIST_ACTION_KEYS = ['pay', 'cancel', 'confirm']
const actionLabel = { pay: '去支付', cancel: '取消', confirm: '确认完成' }
function listActions(o) {
  return allowedActions(o.status, 'user').filter((a) => LIST_ACTION_KEYS.includes(a.key))
}
function canReorder(o) {
  return isFinishedSettled(o.status)
}

function doAction(key, o) {
  if (key === 'pay') return openPay(o)
  if (key === 'cancel') return cancelOrder(o)
  if (key === 'confirm') return confirmOrder(o)
}

const showPay = ref(false)
const paying = ref(null)
// 当前应付（元）：定金尾款启用时取详情里的 payment.payable，否则取实付全额
const payAmount = ref(0)

function openPay(o) {
  paying.value = o
  payAmount.value = Number(o.actual ?? 0)
  showPay.value = true
  // 列表行不含收款摘要：拉一次详情拿到当前应付（定金/尾款阶段）
  orderDetail(o.orderNo)
    .then((d) => {
      const p = d?.payment
      payAmount.value = p && p.depositEnabled && Number(p.payable) > 0 ? Number(p.payable) : Number(d?.actual ?? o.actual ?? 0)
    })
    .catch(() => { /* 详情失败保持全额兜底，服务端仍按 payableFor 收款 */ })
}

async function pay(channel) {
  const o = paying.value
  if (!o) return
  try {
    // 真实链路：储值抵扣/落支付流水/状态分流均由服务端完成（余额不足由服务端拦截）
    // 不传金额：服务端 payableFor 判定当前应付（全额 / 定金 / 尾款）
    await payConfirm(o.orderNo, channel)
  } catch (e) {
    return toast(e && e.isHttpDown ? '后端服务未连接，请检查后端 API 服务是否启动' : (e && e.message ? e.message : '支付失败'))
  }
  showPay.value = false
  toast('支付成功')
  load()
}

// 违约金规则（事实源 OrderService::calcCancelFee：cancel_limit_hours=4 / cancel_fee_rate=10）
function cancelFeeHint(o) {
  if (!Number(o.paid)) return ''
  const start = new Date(String(o.startAt).replace(/-/g, '/')).getTime()
  if (Number.isNaN(start)) return ''
  const hours = (start - Date.now()) / 3600000
  return hours <= 4
    ? `\n距服务开始不足 4 小时，按规则将收取订单金额 10% 违约金（￥${(Number(o.actual) * 0.1).toFixed(2)}）`
    : '\n当前时段取消免费'
}

async function cancelOrder(o) {
  if (!window.confirm('确定取消该订单吗？' + cancelFeeHint(o))) return
  try {
    // 真实链路：违约金由服务端按取消时限计算（cancel_fee 单位分）
    const res = await apiCancelOrder(o.orderNo, '用户取消')
    const fee = Number(res?.cancel_fee || 0) / 100
    toast(fee > 0 ? `已取消，违约金 ¥${fee.toFixed(2)}` : '已取消')
  } catch (e) {
    return toast(e && e.isHttpDown ? '后端服务未连接，请检查后端 API 服务是否启动' : (e && e.message ? e.message : '取消失败'))
  }
  load()
}

async function confirmOrder(o) {
  if (!canTransition(o.status, '已完成')) return toast('当前状态不可确认完成')
  try {
    await apiConfirmOrder(o.orderNo)
  } catch (e) {
    return toast(e && e.isHttpDown ? '后端服务未连接，请检查后端 API 服务是否启动' : (e && e.message ? e.message : '确认失败'))
  }
  toast('已确认完成，可去评价')
  load()
}

function reorder(o) {
  if (o.serviceId) router.push('/order/create?serviceId=' + o.serviceId)
  else router.push('/home')
}

onMounted(load)
</script>
