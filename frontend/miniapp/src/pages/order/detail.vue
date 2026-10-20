<template>
  <view class="page" style="padding-bottom: 80px">
    <template v-if="order">
      <view class="card">
        <view class="row row--between">
          <text class="tag" :class="statusTag(order.status)">{{ order.status }}</text>
          <text class="tiny muted">单号 {{ order.orderNo }}</text>
        </view>
        <view class="price" style="font-size: 22px; margin-top: 10px">
          <text class="yen">¥</text>{{ order.actual }}
        </view>
        <view v-if="order.status === '已取消' && order.cancelReason" class="tiny muted" style="margin-top: 6px">
          取消原因：{{ order.cancelReason }}
        </view>
        <view v-if="order.status === '售后中'" class="tiny muted" style="margin-top: 6px">
          售后原因：{{ order.aftersaleReason || '处理中' }}
        </view>
      </view>

      <view class="card">
        <view class="sheet__title">服务信息</view>
        <view class="row row--between info-row"><text class="muted">服务项目</text><text>{{ order.service }}</text></view>
        <view class="row row--between info-row"><text class="muted">服务时间</text><text>{{ order.startAt }}</text></view>
        <view class="row row--between info-row"><text class="muted">技师</text><text>{{ order.technician || '待派单' }}</text></view>
        <view class="row row--between info-row">
          <text class="muted">服务地址</text>
          <text style="text-align: right; max-width: 62%">{{ order.address }}</text>
        </view>
      </view>

      <view class="card">
        <view class="sheet__title">费用与支付</view>
        <view class="row row--between info-row"><text class="muted">服务费</text><text>¥{{ order.total }}</text></view>
        <view class="row row--between info-row"><text class="muted">优惠抵扣</text><text class="price">-¥{{ order.discount }}</text></view>
        <view class="row row--between info-row"><text class="muted">实付金额</text><text class="price"><b>¥{{ order.actual }}</b></text></view>
        <!-- 定金尾款：开启时展示分阶段收款与结清状态 -->
        <template v-if="order.payment && order.payment.depositEnabled">
          <view class="row row--between info-row">
            <text class="muted">定金（{{ order.payment.rate }}%）</text>
            <text>¥{{ order.payment.deposit }} <text class="tiny muted">{{ order.payment.depositStatus || '—' }}</text></text>
          </view>
          <view class="row row--between info-row">
            <text class="muted">尾款</text>
            <text>¥{{ order.payment.balance }} <text class="tiny muted">{{ order.payment.balanceStatus || '—' }}</text></text>
          </view>
          <view class="row row--between info-row"><text class="muted">已收合计</text><text>¥{{ order.payment.paidSum }}</text></view>
        </template>
        <view class="row row--between info-row"><text class="muted">支付方式</text><text>{{ order.payChannel || '未支付' }}</text></view>
        <view v-if="order.couponCode" class="row row--between info-row"><text class="muted">优惠券</text><text>{{ order.couponCode }}</text></view>
      </view>

      <view class="card">
        <view class="sheet__title">联系人</view>
        <view class="row row--between info-row"><text class="muted">联系人</text><text>{{ order.customer }}</text></view>
        <view class="row row--between info-row"><text class="muted">联系电话</text><text>{{ order.phone }}</text></view>
      </view>

      <!-- 已评价：展示我的评价（本次会话提交的行快照） -->
      <view v-if="myReview" class="card">
        <view class="sheet__title">我的评价</view>
        <view class="row row--between">
          <text class="tag tag--green">{{ myReview.rating }} 星</text>
          <text class="tiny muted">{{ myReview.createdAt }}</text>
        </view>
        <view class="tiny" style="margin-top: 8px">{{ myReview.content || '（未填写文字评价）' }}</view>
        <view v-if="myReview.reply" class="tiny muted" style="margin-top: 8px">商家回复：{{ myReview.reply }}</view>
      </view>
    </template>

    <view v-else-if="!loading" class="empty">未找到该订单</view>

    <view v-if="order" class="action-bar">
      <button
        v-for="a in detailActions"
        :key="a.key"
        class="btn"
        :class="{ 'btn--primary': PRIMARY_ACTIONS.includes(a.key) }"
        @tap="doAction(a.key)"
      >{{ a.label }}</button>
      <button v-if="isFinished" class="btn" @tap="reorder">再来一单</button>
      <button v-if="!detailActions.length && !isFinished" class="btn btn--block" @tap="goBack">返回</button>
    </view>

    <!-- 改约弹层 -->
    <view v-if="showReschedule">
      <view class="mask" @tap="showReschedule = false"></view>
      <view class="sheet">
        <view class="sheet__title">改约服务时间</view>
        <view class="field">
          <text class="field__label">服务日期</text>
          <picker mode="date" :value="rescheduleForm.date" :start="today" :end="maxDate" @change="(e) => (rescheduleForm.date = e.detail.value)">
            <view class="field__input">{{ rescheduleForm.date || '请选择日期' }}</view>
          </picker>
        </view>
        <view class="field">
          <text class="field__label">服务时间</text>
          <picker mode="time" :value="rescheduleForm.time" @change="(e) => (rescheduleForm.time = e.detail.value)">
            <view class="field__input">{{ rescheduleForm.time || '请选择时间' }}</view>
          </picker>
        </view>
        <view class="tiny muted">需提前 2 小时预约，最远可约 30 天</view>
        <button class="btn btn--primary btn--block" style="margin-top: 12px" @tap="confirmReschedule">确认改约</button>
        <button class="btn btn--block" style="margin-top: 8px" @tap="showReschedule = false">取消</button>
      </view>
    </view>

    <PaySheet :show="showPay" :amount="payableAmount" @pay="pay" @cancel="showPay = false" />

    <!-- 评价弹层（星级/文字 + 评价有礼） -->
    <RateSheet
      :show="showReviewForm"
      :order-no="order?.orderNo || ''"
      :service="order?.service || ''"
      :reward-text="rewardText"
      @submit="submitReview"
      @cancel="showReviewForm = false"
    />
  </view>
</template>

<script setup>
// 订单详情 —— 顾客端小程序（对齐 user H5 OrderDetail：动作字典驱动/改约/评价/售后）
import { computed, reactive, ref } from 'vue'
import { onLoad, onShow } from '@dcloudio/uni-app'
import { toast } from '../../api/http'
import PaySheet from '../../components/PaySheet.vue'
import RateSheet from '../../components/RateSheet.vue'
import {
  orderDetail,
  payConfirm,
  cancelOrder as apiCancelOrder,
  rescheduleOrder as apiRescheduleOrder,
  confirmOrder as apiConfirmOrder,
  aftersaleOrder as apiAftersaleOrder,
  createReview
} from '../../api/orders'
import { fetchReviewReward } from '../../api/memberSelf'
import { statusTag, USER_ACTIONS, FINISHED_SET } from '../../statusMeta'
import { bookingWindowError, errText } from '../../utils'

let orderNo = ''
onLoad((q) => {
  orderNo = String(q.orderNo || '')
})

const order = ref(null)
const loading = ref(true)
const showPay = ref(false)

// 评价文案：读当前启用的评价有礼规则（无规则时后端返回默认文案）
const rewardText = ref('评价将随订单展示，感谢您的反馈')
fetchReviewReward()
  .then((d) => { if (d && d.rewardText) rewardText.value = d.rewardText })
  .catch(() => { /* 接口失败保持默认文案 */ })

// 本次会话提交的评价快照（reviews 列表对 member 不开放）
const realReview = ref(null)
const myReview = computed(() => realReview.value)

async function load() {
  try {
    order.value = await orderDetail(orderNo)
  } catch (e) {
    order.value = null
    toast(errText(e, '加载失败'))
  }
  loading.value = false
}

// 操作区字典驱动（口径=statusMeta.js USER_ACTIONS 详情动作）
const PRIMARY_ACTIONS = ['pay', 'confirm', 'rate', 'submitReview']
const detailActions = computed(() => (order.value ? (USER_ACTIONS[order.value.status] || []).filter((a) => a.detail !== false || PRIMARY_ACTIONS.includes(a.key)) : []))
const isFinished = computed(() => order.value && FINISHED_SET.includes(order.value.status))

// 当前应付（元）：定金尾款启用时取 payment.payable（定金或尾款），否则取实付全额
const payableAmount = computed(() => {
  const p = order.value?.payment
  if (p && p.depositEnabled && Number(p.payable) > 0) return Number(p.payable)
  return Number(order.value?.actual ?? 0)
})

function doAction(key) {
  if (key === 'pay') return (showPay.value = true)
  if (key === 'cancel') return cancelOrder()
  if (key === 'reschedule') return openReschedule()
  if (key === 'confirm') return confirmOrder()
  if (key === 'rate' || key === 'submitReview') return startReview()
  if (key === 'aftersale') return applyAftersale()
}

async function pay(channel) {
  const o = order.value
  if (!o) return
  try {
    // 到店付/储值线下通道；定金尾款启用时按当前应付收款，服务端 payableFor 判定阶段
    await payConfirm(o.orderNo, channel, payableAmount.value)
  } catch (e) {
    return toast(errText(e, '支付失败'))
  }
  showPay.value = false
  toast('支付成功')
  load()
}

// 违约金规则（事实源 OrderService::calcCancelFee：cancel_limit_hours=4 / cancel_fee_rate=10）
function cancelFeeHint() {
  const o = order.value
  if (!o || !Number(o.paid)) return ''
  const start = new Date(String(o.startAt).replace(/-/g, '/')).getTime()
  if (Number.isNaN(start)) return ''
  const hours = (start - Date.now()) / 3600000
  return hours <= 4 ? '\n距服务开始不足 4 小时，将收取订单金额 10% 违约金' : '\n当前时段取消免费'
}

function cancelOrder() {
  uni.showModal({
    title: '取消订单',
    content: '确定取消该订单吗？' + cancelFeeHint(),
    success: async (r) => {
      if (!r.confirm) return
      try {
        const res = await apiCancelOrder(order.value.orderNo, '用户取消')
        const fee = Number(res?.cancel_fee || 0) / 100
        toast(fee > 0 ? `已取消，违约金 ¥${fee.toFixed(2)}` : '已取消')
      } catch (e) {
        return toast(errText(e, '取消失败'))
      }
      load()
    }
  })
}

async function confirmOrder() {
  try {
    await apiConfirmOrder(order.value.orderNo)
    toast('已确认完成，可去评价')
  } catch (e) {
    return toast(errText(e, '操作失败'))
  }
  load()
}

// ===== 改约（仅改时间不改状态，end_at 由服务端按 booking.duration 推算） =====
const showReschedule = ref(false)
const rescheduleForm = reactive({ date: '', time: '' })
const today = new Date().toISOString().slice(0, 10)
const maxDate = new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 10)

function openReschedule() {
  const [d, t] = String(order.value.startAt).split(' ')
  rescheduleForm.date = d || ''
  rescheduleForm.time = t || ''
  showReschedule.value = true
}

async function confirmReschedule() {
  const o = order.value
  const startAt = rescheduleForm.date + ' ' + rescheduleForm.time
  const err = bookingWindowError(startAt)
  if (err) return toast(err)
  try {
    await apiRescheduleOrder(o.orderNo, /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/.test(startAt) ? startAt + ':00' : startAt)
  } catch (e) {
    return toast(errText(e, '改约失败'))
  }
  showReschedule.value = false
  toast('改约成功')
  load()
}

// ===== 评价（真实链路：后端校验/防重复/归属，并自动流转 已完成→待评价→已评价） =====
const showReviewForm = ref(false)

function startReview() {
  showReviewForm.value = true
}

async function submitReview(payload) {
  const o = order.value
  try {
    realReview.value = await createReview({
      orderNo: o.orderNo,
      rating: payload.rating,
      content: payload.content,
      images: []
    })
    realReview.value = { rating: payload.rating, content: payload.content, createdAt: '刚刚', reply: '' }
    showReviewForm.value = false
    toast('评价成功，感谢您的反馈')
    load()
  } catch (e) {
    toast(errText(e, '评价失败'))
  }
}

// ===== 售后（申请售后 → 售后中，服务端记录 preStatus 供驳回回退） =====
function applyAftersale() {
  uni.showModal({
    title: '申请售后',
    editable: true,
    placeholderText: '请描述遇到的问题',
    success: async (r) => {
      if (!r.confirm || !String(r.content || '').trim()) return
      const o = order.value
      try {
        await apiAftersaleOrder(o.orderNo, String(r.content).trim())
      } catch (e) {
        return toast(errText(e, '售后申请失败'))
      }
      toast('售后申请已提交，客服将尽快处理')
      load()
    }
  })
}

function goBack() {
  uni.navigateBack()
}

onShow(() => {
  if (orderNo) load()
})
</script>

<style scoped>
.info-row { padding: 6px 0; }
</style>
