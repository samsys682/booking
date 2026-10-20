<template>
  <view class="page">
    <scroll-view scroll-x class="chips-scroll">
      <view class="chips">
        <text
          v-for="t in tabs"
          :key="t"
          class="chip"
          :class="{ 'chip--active': tab === t }"
          @tap="switchTab(t)"
        >{{ t }}</text>
      </view>
    </scroll-view>

    <view v-for="o in displayRows" :key="o.orderNo" class="card">
      <view class="row row--between" @tap="goDetail(o)">
        <view class="list-row__title">{{ o.service }}</view>
        <text class="tag" :class="statusTag(o.status)">{{ o.status }}</text>
      </view>
      <view class="tiny muted" style="margin: 6px 0" @tap="goDetail(o)">
        单号 {{ o.orderNo }}\n{{ o.startAt }} · {{ o.address }}
      </view>
      <view class="row row--between">
        <view class="price"><text class="yen">¥</text>{{ o.actual }}</view>
        <view class="row">
          <button
            v-for="a in listActions(o)"
            :key="a.key"
            class="btn btn--mini"
            :class="{ 'btn--primary': a.key === 'pay' }"
            @tap="doAction(a.key, o)"
          >{{ a.label }}</button>
          <button v-if="canReorder(o)" class="btn btn--mini" @tap="reorder(o)">再来一单</button>
          <button class="btn btn--mini" @tap="goDetail(o)">详情</button>
        </view>
      </view>
    </view>

    <view v-if="!displayRows.length && !loading" class="empty">
      暂无订单
      <view><button class="btn btn--primary empty__btn" @tap="goHome">去逛逛</button></view>
    </view>

    <PaySheet :show="showPay" :amount="payAmount" @pay="pay" @cancel="showPay = false" />
  </view>
</template>

<script setup>
// 订单列表 —— 顾客端小程序（对齐 user H5 OrdersView：状态分组 tab + 轻量动作）
import { computed, ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { toast } from '../../api/http'
import PaySheet from '../../components/PaySheet.vue'
import { myOrders, orderDetail, payConfirm, cancelOrder as apiCancelOrder, confirmOrder as apiConfirmOrder } from '../../api/orders'
import { statusTag, USER_ACTIONS, FINISHED_SET } from '../../statusMeta'
import { errText } from '../../utils'

const rows = ref([])
const loading = ref(false)

// 状态口径分组：「进行中」聚合 待派单/待接单/待服务/服务中；「售后/退款」聚合逆向流
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
  loading.value = true
  try {
    const data = await myOrders({ page: 1, per_page: 100 })
    rows.value = data.list
  } catch (e) {
    toast(errText(e, '加载失败'))
  } finally {
    loading.value = false
  }
}

function switchTab(t) {
  tab.value = t
  load()
}

// 列表页轻量动作；改约/评价/售后在详情页操作（口径=statusMeta.js USER_ACTIONS）
const LIST_ACTION_KEYS = ['pay', 'cancel', 'confirm']
function listActions(o) {
  return (USER_ACTIONS[o.status] || []).filter((a) => LIST_ACTION_KEYS.includes(a.key))
}
function canReorder(o) {
  return FINISHED_SET.includes(o.status)
}

function goDetail(o) {
  uni.navigateTo({ url: '/pages/order/detail?orderNo=' + o.orderNo })
}
function goHome() {
  uni.switchTab({ url: '/pages/index/index' })
}

function doAction(key, o) {
  if (key === 'pay') return openPay(o)
  if (key === 'cancel') return cancelOrder(o)
  if (key === 'confirm') return confirmOrder(o)
}

const showPay = ref(false)
const paying = ref(null)
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
    await payConfirm(o.orderNo, channel)
  } catch (e) {
    return toast(errText(e, '支付失败'))
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
  return hours <= 4 ? '\n距服务开始不足 4 小时，将收取订单金额 10% 违约金' : '\n当前时段取消免费'
}

function cancelOrder(o) {
  uni.showModal({
    title: '取消订单',
    content: '确定取消该订单吗？' + cancelFeeHint(o),
    success: async (r) => {
      if (!r.confirm) return
      try {
        // 违约金由服务端按取消时限计算（cancel_fee 单位分）
        const res = await apiCancelOrder(o.orderNo, '用户取消')
        const fee = Number(res?.cancel_fee || 0) / 100
        toast(fee > 0 ? `已取消，违约金 ¥${fee.toFixed(2)}` : '已取消')
      } catch (e) {
        return toast(errText(e, '取消失败'))
      }
      load()
    }
  })
}

async function confirmOrder(o) {
  try {
    await apiConfirmOrder(o.orderNo)
  } catch (e) {
    return toast(errText(e, '确认失败'))
  }
  toast('已确认完成，可去评价')
  load()
}

function reorder(o) {
  if (o.serviceId) uni.navigateTo({ url: '/pages/order/create?serviceId=' + o.serviceId })
  else uni.switchTab({ url: '/pages/index/index' })
}

onShow(() => {
  // 未登录静默空态（tabBar 页不强制跳登录）
  if (!uni.getStorageSync('bk_user_token')) {
    rows.value = []
    return
  }
  load()
})
</script>

<style scoped>
.chips-scroll { white-space: nowrap; }
</style>
