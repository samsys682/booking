<template>
  <div>
    <div class="navbar">
      <button class="navbar__back" @click="$router.back()">‹</button>
      <span class="navbar__title">订单详情</span>
    </div>

    <div class="page page--nobar" style="padding-bottom: 80px">
      <template v-if="order">
        <div class="card">
          <div class="row row--between">
            <span class="tag" :class="statusClass(order.status)">{{ order.status }}</span>
            <span class="tiny muted">单号 {{ order.orderNo }}</span>
          </div>
          <div class="price" style="font-size: 22px; margin-top: 10px">
            <span class="yen">¥</span>{{ order.actual }}
          </div>
          <div v-if="order.status === '已取消' && order.cancelReason" class="tiny muted" style="margin-top: 6px">
            取消原因：{{ order.cancelReason }}
          </div>
          <div v-if="order.status === '售后中'" class="tiny muted" style="margin-top: 6px">
            售后原因：{{ order.aftersaleReason || '处理中' }}
          </div>
        </div>

        <div class="card">
          <div class="sheet__title">服务信息</div>
          <div class="row row--between info-row"><span class="muted">服务项目</span><span>{{ order.service }}</span></div>
          <div class="row row--between info-row"><span class="muted">服务时间</span><span>{{ order.startAt }}</span></div>
          <div class="row row--between info-row"><span class="muted">技师</span><span>{{ order.technician }}</span></div>
          <div class="row row--between info-row"><span class="muted">服务地址</span><span style="text-align: right; max-width: 62%">{{ order.address }}</span></div>
        </div>

        <div class="card">
          <div class="sheet__title">费用与支付</div>
          <div class="row row--between info-row"><span class="muted">服务费</span><span>¥{{ order.total }}</span></div>
          <div class="row row--between info-row"><span class="muted">优惠抵扣</span><span class="price">-¥{{ order.discount }}</span></div>
          <div class="row row--between info-row"><span class="muted">实付金额</span><b class="price">¥{{ order.actual }}</b></div>
          <!-- 定金尾款：开启时展示分阶段收款与结清状态 -->
          <template v-if="order.payment && order.payment.depositEnabled">
            <div class="row row--between info-row">
              <span class="muted">定金（{{ order.payment.rate }}%）</span>
              <span>¥{{ order.payment.deposit }} <span class="tiny muted">{{ order.payment.depositStatus || '—' }}</span></span>
            </div>
            <div class="row row--between info-row">
              <span class="muted">尾款</span>
              <span>¥{{ order.payment.balance }} <span class="tiny muted">{{ order.payment.balanceStatus || '—' }}</span></span>
            </div>
            <div class="row row--between info-row">
              <span class="muted">已收合计</span><span>¥{{ order.payment.paidSum }}</span>
            </div>
          </template>
          <div class="row row--between info-row"><span class="muted">支付方式</span><span>{{ order.payChannel || '未支付' }}</span></div>
          <div v-if="order.couponCode" class="row row--between info-row"><span class="muted">优惠券</span><span>{{ order.couponCode }}</span></div>
        </div>

        <div class="card">
          <div class="sheet__title">联系人</div>
          <div class="row row--between info-row"><span class="muted">联系人</span><span>{{ order.customer }}</span></div>
          <div class="row row--between info-row"><span class="muted">联系电话</span><span>{{ order.phone }}</span></div>
        </div>

        <!-- 评价表单走 RateSheet 弹层（rate → 待评价并弹出；submitReview → 写评价并流转已评价） -->

        <!-- 已评价：展示我的评价 -->
        <div v-if="myReview" class="card">
          <div class="sheet__title">我的评价</div>
          <div class="row row--between">
            <span class="tag tag--green">{{ myReview.rating }} 星</span>
            <span class="tiny muted">{{ myReview.createdAt }}</span>
          </div>
          <div class="tiny" style="margin-top: 8px">{{ myReview.content || '（未填写文字评价）' }}</div>
          <div v-if="myReview.images && myReview.images.length" class="rate-images">
            <img v-for="(img, i) in myReview.images" :key="i" :src="img" class="rate-thumb" alt="评价图片" />
          </div>
          <div v-if="myReview.reply" class="tiny muted" style="margin-top: 8px">商家回复：{{ myReview.reply }}</div>
        </div>
      </template>

      <div v-else-if="!loading" class="empty">
        未找到该订单
        <div><button class="btn empty__btn" @click="$router.push('/orders')">返回订单列表</button></div>
      </div>
    </div>

    <div v-if="order" class="action-bar">
      <button
        v-for="a in detailActions"
        :key="a.key"
        class="btn"
        :class="{ 'btn--primary': PRIMARY_ACTIONS.includes(a.key) }"
        @click="doAction(a.key)"
      >{{ actionLabel[a.key] || a.label }}</button>
      <button v-if="order && isFinishedSettled(order.status)" class="btn" @click="reorder">再来一单</button>
      <button v-if="!detailActions.length" class="btn btn--block" @click="$router.push('/orders')">返回订单列表</button>
    </div>

    <!-- 改约弹层 -->
    <template v-if="showReschedule">
      <div class="mask" @click="showReschedule = false"></div>
      <div class="sheet">
        <div class="sheet__title">改约服务时间</div>
        <div class="field">
          <label class="field__label">服务日期</label>
          <input v-model="rescheduleForm.date" type="date" class="field__input" />
        </div>
        <div class="field">
          <label class="field__label">服务时间</label>
          <input v-model="rescheduleForm.time" type="time" class="field__input" />
        </div>
        <div class="tiny muted">需提前 2 小时预约，最远可约 30 天</div>
        <button class="btn btn--primary btn--block" style="margin-top: 12px" @click="confirmReschedule">确认改约</button>
        <button class="btn btn--block" style="margin-top: 8px" @click="showReschedule = false">取消</button>
      </div>
    </template>

    <PaySheet :show="showPay" :amount="payableAmount" @pay="pay" @cancel="showPay = false" />

    <!-- 评价弹层（星级/文字/晒图 + 评价有礼） -->
    <RateSheet
      :show="showReviewForm"
      :order-no="order?.orderNo ?? ''"
      :service="order?.service ?? ''"
      :reward-text="REWARD_TEXT"
      @submit="submitReview"
      @cancel="showReviewForm = false"
    />
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from '../composables/useToast'
import PaySheet from '../components/PaySheet.vue'
import RateSheet from '../components/RateSheet.vue'
import { allowedActions, canTransition, isFinishedSettled, STATUS_META } from '../statusFlow'
import {
  orderDetail,
  payConfirm,
  cancelOrder as apiCancelOrder,
  rescheduleOrder as apiRescheduleOrder,
  confirmOrder as apiConfirmOrder,
  aftersaleOrder as apiAftersaleOrder,
  createReview
} from '../api/orders'
import { fetchReviewReward } from '../api/memberSelf'

const route = useRoute()
const router = useRouter()
const { toast } = useToast()

// 评价文案：读当前启用的评价有礼规则（无规则时后端返回默认文案）
const REWARD_TEXT = ref('评价将随订单展示，感谢您的反馈')
fetchReviewReward()
  .then((d) => { if (d && d.rewardText) REWARD_TEXT.value = d.rewardText })
  .catch(() => { /* 接口失败保持默认文案 */ })

// 真实链路提交的评价（reviews 列表对 member 不开放，行快照本地留存展示）
const realReview = ref(null)

// 错误统一提示：后端不可达报连接问题，其余报业务错误
function toastErr(e, fallback) {
  toast(e && e.isHttpDown ? '后端服务未连接，请检查后端 API 服务是否启动' : (e && e.message ? e.message : fallback))
}

async function load() {
  // 真实链路：GET /orders/{orderNo}（member 限本人单）；失败报错不再降级演示数据
  try {
    order.value = await orderDetail(route.params.id)
  } catch (e) {
    order.value = null
    toastErr(e, '加载失败')
  }
  loading.value = false
}

const order = ref(null)
const loading = ref(true)
const showPay = ref(false)

// reviews 真实行 → 视图形状（真实行 snake，images 为 json 串）
function toReviewView(row) {
  const r = row || {}
  let images = r.images
  if (typeof images === 'string') {
    try { images = JSON.parse(images) } catch (e) { images = [] }
  }
  if (!Array.isArray(images)) images = []
  return {
    id: r.id ?? '',
    order: r.order_no ?? r.order ?? r.orderNo ?? '',
    service: r.service || '',
    rating: Number(r.rating ?? 5),
    content: r.content || '',
    images,
    reply: r.reply || '',
    status: r.status || '',
    createdAt: r.created_at ?? r.createdAt ?? ''
  }
}

// 状态主题色 → 端内 tag 样式（语义口径来自 statusFlow.js::STATUS_META）
const TYPE_TAG = { warning: 'tag--orange', primary: 'tag--green', info: 'tag--gray', success: 'tag--green', danger: 'tag--red' }
function statusClass(s) {
  return TYPE_TAG[STATUS_META[s]?.type] || 'tag--gray'
}

// 操作区字典驱动（口径=statusFlow.js::STATUS_ACTIONS，按 user 过滤）
const PRIMARY_ACTIONS = ['pay', 'confirm', 'rate']
const actionLabel = { pay: '去支付', cancel: '取消订单', reschedule: '改约', confirm: '确认完成', rate: '去评价', submitReview: '填写评价', aftersale: '申请售后' }
const detailActions = computed(() => (order.value ? allowedActions(order.value.status, 'user') : []))

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
  if (key === 'rate') return startReview()
  if (key === 'submitReview') return (showReviewForm.value = true)
  if (key === 'aftersale') return applyAftersale()
}

async function pay(channel) {
  const o = order.value
  if (!o) return
  try {
    // 真实链路：储值抵扣/落支付流水/状态分流均由服务端完成（指定技师→待接单；未指定→待派单）
    // 定金尾款启用时按当前应付（定金/尾款）收款，服务端 payableFor 自动判定阶段
    await payConfirm(o.orderNo, channel, payableAmount.value)
  } catch (e) {
    return toastErr(e, '支付失败')
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
  return hours <= 4
    ? `\n距服务开始不足 4 小时，按规则将收取订单金额 10% 违约金（￥${(Number(o.actual) * 0.1).toFixed(2)}）`
    : '\n当前时段取消免费'
}

async function cancelOrder() {
  if (!window.confirm('确定取消该订单吗？' + cancelFeeHint())) return
  try {
    // 真实链路：违约金由服务端 calcCancelFee 核算（计入 discount）
    const res = await apiCancelOrder(order.value.orderNo, '用户取消')
    const fee = Number(res?.cancel_fee || 0) / 100
    toast(fee > 0 ? `已取消，违约金 ¥${fee.toFixed(2)}` : '已取消')
  } catch (e) {
    return toastErr(e, '取消失败')
  }
  load()
}

async function confirmOrder() {
  if (!canTransition(order.value.status, '已完成')) return toast('当前状态不可确认完成')
  try {
    // 真实链路：服务端流转 待确认→已完成，并累计会员消费统计
    await apiConfirmOrder(order.value.orderNo)
    toast('已确认完成，可去评价')
  } catch (e) {
    return toastErr(e, '操作失败')
  }
  load()
}

// ===== 改约（仅改时间不改状态，镜像后端 OrderController::reschedule） =====
const showReschedule = ref(false)
const rescheduleForm = reactive({ date: '', time: '' })

// 预约时间窗（事实源 OrderService::assertBookingWindow：提前 2 小时 / 最远 30 天）
function bookingWindowError(startAt) {
  const ts = new Date(String(startAt).replace(/-/g, '/')).getTime()
  if (Number.isNaN(ts)) return '时间格式不正确'
  if (ts < Date.now() + 2 * 3600000) return '需提前 2 小时预约'
  if (ts > Date.now() + 30 * 86400000) return '最远可预约 30 天内的服务'
  return ''
}

// 技师时间冲突由服务端 409 拦截（OrderService::hasConflict），前端不做本地预检
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
    // 真实链路：仅调时间不改状态，end_at 由服务端按 booking.duration 推算（冲突 409 / 时间窗 400 直接提示）
    await apiRescheduleOrder(o.orderNo, /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/.test(startAt) ? startAt + ':00' : startAt)
  } catch (e) {
    return toastErr(e, '改约失败')
  }
  showReschedule.value = false
  toast('改约成功')
  load()
}

// ===== 评价（已完成 --rate→ 待评价 --submitReview→ 已评价；真实模式状态流转由后端 POST /reviews 自动完成） =====
const showReviewForm = ref(false)
// 我的评价：取本次会话提交的行快照（reviews 列表对 member 不开放）
const myReview = computed(() => realReview.value)

async function startReview() {
  // rate 动作：弹出评价弹层（提交评价时后端一次走完 已完成→待评价→已评价）
  if (!canTransition(order.value.status, '待评价')) return toast('当前状态不可评价')
  showReviewForm.value = true
}

async function submitReview(payload) {
  const o = order.value
  // 评价内容后端必填（ReviewController::create），前端先拦一道
  if (!payload.content || !String(payload.content).trim()) return toast('请填写评价内容')
  try {
    // 真实链路：后端校验状态/防重复/归属，并自动流转 已完成→待评价→已评价，前端不再 patch 状态
    realReview.value = toReviewView(await createReview({
      orderNo: o.orderNo,
      rating: payload.rating,
      content: payload.content,
      images: payload.images
    }))
    showReviewForm.value = false
    toast('评价成功，感谢您的反馈')
    load()
  } catch (e) {
    toastErr(e, '评价失败')
  }
}

// ===== 售后（申请售后 → 售后中，记录 preStatus 供驳回回退） =====
async function applyAftersale() {
  const o = order.value
  const reason = window.prompt('请描述遇到的问题，申请售后：')
  if (!reason || !reason.trim()) return
  if (!canTransition(o.status, '售后中')) return toast('当前状态不可申请售后')
  try {
    // 真实链路：服务端流转 售后中 并记录 pre_status 供客服驳回回退（reason 必填）
    await apiAftersaleOrder(o.orderNo, reason.trim())
  } catch (e) {
    return toastErr(e, '售后申请失败')
  }
  toast('售后申请已提交，客服将尽快处理')
  load()
}

function reorder() {
  const o = order.value
  if (o && o.serviceId) router.push('/order/create?serviceId=' + o.serviceId)
  else router.push('/home')
}

onMounted(load)
</script>

<style scoped>
.info-row { padding: 6px 0; }
.rate-images { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
.rate-thumb { width: 56px; height: 56px; border-radius: 8px; object-fit: cover; display: block; }
</style>
