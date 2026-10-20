<template>
  <view class="page" style="padding-bottom: 84px">
    <!-- 服务信息 -->
    <view v-if="service" class="card">
      <view class="row row--between">
        <view class="list-row__title">{{ service.name }}</view>
        <view class="price"><text class="yen">¥</text>{{ service.price }}</view>
      </view>
      <view class="tiny muted">约 {{ service.duration }} 分钟 / {{ service.unit }}</view>
    </view>

    <!-- 预约信息 -->
    <view class="card">
      <view class="sheet__title">预约信息</view>
      <view class="field">
        <text class="field__label">服务日期</text>
        <picker mode="date" :value="form.date" :start="today" :end="maxDate" @change="(e) => (form.date = e.detail.value)">
          <view class="field__input">{{ form.date || '请选择日期' }}</view>
        </picker>
      </view>
      <view class="field">
        <text class="field__label">服务时间</text>
        <picker mode="time" :value="form.time" @change="(e) => (form.time = e.detail.value)">
          <view class="field__input">{{ form.time || '请选择时间' }}</view>
        </picker>
        <view class="tiny muted" style="margin-top: 4px">需提前 2 小时预约，最远可约 30 天</view>
      </view>
      <view class="field">
        <text class="field__label">指定技师（选填）</text>
        <picker :range="techOptions" range-key="label" @change="(e) => (form.technicianId = techOptions[Number(e.detail.value)].id)">
          <view class="field__input">{{ techLabel || '不指定，平台派单' }}</view>
        </picker>
        <view class="tiny muted" style="margin-top: 4px">指定技师支付后直接等技师接单；不指定则进入平台派单池</view>
      </view>
      <view class="field">
        <text class="field__label">联系人</text>
        <input v-model="form.contact" class="field__input" placeholder="请输入联系人" />
      </view>
      <view class="field">
        <text class="field__label">联系电话</text>
        <input v-model="form.phone" class="field__input" type="number" maxlength="11" placeholder="请输入手机号" />
      </view>
      <view class="field">
        <text class="field__label">备注（选填）</text>
        <textarea v-model="form.note" class="field__input field__input--textarea" placeholder="如：家里有宠物、需自带工具等"></textarea>
      </view>
    </view>

    <!-- 服务地址 -->
    <view class="card">
      <view class="row row--between">
        <view class="sheet__title">服务地址</view>
        <button class="btn btn--mini" @tap="goAddresses">管理地址</button>
      </view>
      <view v-for="a in addresses" :key="a.id" class="list-row" @tap="form.addressId = a.id">
        <view class="radio" :class="{ 'radio--checked': String(form.addressId) === String(a.id) }"></view>
        <view class="list-row__main">
          <view class="list-row__title">{{ a.address }}</view>
          <view class="list-row__sub">{{ a.name }} · {{ a.phone }}</view>
        </view>
        <text v-if="a.isDefault" class="tag tag--green">默认</text>
      </view>
      <view v-if="!addresses.length" class="empty">暂无地址，去「管理地址」新增</view>
    </view>

    <!-- 优惠券 -->
    <view class="card">
      <view class="sheet__title">优惠券</view>
      <picker :range="couponOptions" range-key="label" @change="(e) => (form.couponCode = couponOptions[Number(e.detail.value)].code)">
        <view class="field__input">{{ couponLabel }}</view>
      </picker>
    </view>

    <!-- 电子服务协议（批次一 C：存在适用协议时必须阅读签署后才能下单） -->
    <view v-if="agreements.length" class="card">
      <view class="sheet__title">服务协议</view>
      <view v-for="a in agreements" :key="a.id" class="list-row" @tap="openAgreement(a)">
        <view class="radio" :class="{ 'radio--checked': !!signedTokens[a.id] }"></view>
        <view class="list-row__main">
          <view class="list-row__title">{{ a.name }}（V{{ a.version }}）</view>
          <view class="list-row__sub">{{ signedTokens[a.id] ? '已同意并签署' : '点击阅读全文' }}</view>
        </view>
      </view>
    </view>

    <!-- 费用明细 -->
    <view class="card">
      <view class="row row--between"><text class="muted">服务费</text><text>¥{{ total }}</text></view>
      <view class="row row--between"><text class="muted">优惠抵扣</text><text class="price">-¥{{ discount }}</text></view>
      <view class="row row--between" style="margin-top: 6px"><text><b>实付</b></text><text class="price"><b>¥{{ actual }}</b></text></view>
    </view>

    <view class="action-bar">
      <view style="flex: 1; display: flex; align-items: center">
        <text class="tiny muted">合计</text>
        <view class="price" style="font-size: 19px; margin-left: 6px"><text class="yen">¥</text>{{ actual }}</view>
      </view>
      <button class="btn btn--primary" style="flex: none; padding: 11px 26px" :disabled="submitting" @tap="submit">
        提交订单
      </button>
    </view>

    <PaySheet :show="showPay" :amount="payAmount" @pay="pay" @cancel="showPay = false" />

    <!-- 协议阅读弹窗：全文阅读 → 同意并签署（换取一次性下单凭证） -->
    <view v-if="readerAgreement">
      <view class="mask" @tap="readerAgreement = null"></view>
      <view class="reader">
        <view class="reader__head">
          <text>{{ readerAgreement.name }}（V{{ readerAgreement.version }}）</text>
          <text class="reader__close" @tap="readerAgreement = null">×</text>
        </view>
        <scroll-view scroll-y class="reader__body">
          <rich-text :nodes="readerAgreement.content || ''"></rich-text>
        </scroll-view>
        <view class="reader__foot">
          <button class="btn btn--primary btn--block" :disabled="signing" @tap="agreeAndSign">
            {{ signing ? '签署中…' : '我已阅读并同意' }}
          </button>
        </view>
      </view>
    </view>
  </view>
</template>

<script setup>
// 下单页 —— 顾客端小程序（对齐 user H5 OrderCreate：时间窗/技师/地址/券/协议签署/提交）
import { computed, onMounted, reactive, ref } from 'vue'
import { onLoad, onShow } from '@dcloudio/uni-app'
import { toast } from '../../api/http'
import { list } from '../../api/resource'
import { createOrder, payConfirm } from '../../api/orders'
import { fetchActiveAgreements, signAgreement } from '../../api/agreements'
import { loadMember } from '../../api/auth'
import { fetchAddresses, fetchMyCoupons } from '../../api/memberSelf'
import { bookingWindowError, discountOf, fmtPlus, skillsText } from '../../utils'
import PaySheet from '../../components/PaySheet.vue'

let serviceId = ''
onLoad((q) => {
  serviceId = String(q.serviceId || '')
})

const service = ref(null)
const addresses = ref([])
const techList = ref([])
const couponRows = ref([])

const me = loadMember() || {}
const form = reactive({
  date: new Date(Date.now() + 86400000).toISOString().slice(0, 10),
  time: '10:00',
  contact: me.name || '',
  phone: me.phone && /^1\d{10}$/.test(String(me.phone)) ? String(me.phone) : '',
  note: '',
  addressId: '',
  couponCode: '',
  technicianId: ''
})

const today = new Date().toISOString().slice(0, 10)
const maxDate = new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 10)

// 技师选项（picker 数据源；休假不可选）
const techOptions = computed(() => [
  { id: '', label: '不指定，平台派单' },
  ...techList.value.map((t) => ({
    id: String(t.id),
    label: `${t.name}（${skillsText(t.skills)}·${t.rating} 分${t.status === '休假' ? '·休假中' : ''}）`,
    disabled: t.status === '休假'
  }))
])
const techLabel = computed(() => techOptions.value.find((o) => o.id === String(form.technicianId))?.label || '')

const couponOptions = computed(() => [
  { code: '', label: '不使用优惠券' },
  ...couponRows.value
    .filter((c) => c.status === '未使用')
    .map((c) => ({ code: c.code, label: `${c.couponName}（${c.code}）` }))
])
const couponLabel = computed(() => couponOptions.value.find((o) => o.code === form.couponCode)?.label || '不使用优惠券')

const total = computed(() => Number(service.value?.price ?? 0))
const discount = computed(() => {
  const c = couponRows.value.find((x) => x.code === form.couponCode)
  return discountOf(c, total.value)
})
const actual = computed(() => Math.max(0, Math.round((total.value - discount.value) * 100) / 100))

// 电子服务协议：适用协议预取 + 已签 token 缓存（一次性，下单消费后作废）
const agreements = ref([])
const signedTokens = reactive({})
const readerAgreement = ref(null)
const signing = ref(false)

async function loadAgreements(sid) {
  try {
    const res = await fetchActiveAgreements(sid)
    agreements.value = Array.isArray(res) ? res : []
  } catch (e) {
    // 协议预取失败不阻塞页面（服务端下单时仍会强校验）
    agreements.value = []
  }
}

function openAgreement(a) {
  readerAgreement.value = a
}

async function agreeAndSign() {
  const a = readerAgreement.value
  if (!a || signedTokens[a.id]) return
  signing.value = true
  try {
    const res = await signAgreement(a.id)
    signedTokens[a.id] = res.sign_token
    readerAgreement.value = null
    toast('已签署《' + a.name + '》')
  } catch (e) {
    toast(e.message || '签署失败，请重试')
  } finally {
    signing.value = false
  }
}

const submitting = ref(false)
const showPay = ref(false)
const createdOrderNo = ref('')
const payAmount = ref(0)

async function submit() {
  if (!service.value) return toast('服务不存在')
  if (!form.date || !form.time) return toast('请选择服务时间')
  if (!String(form.contact).trim()) return toast('请填写联系人')
  if (!/^1\d{10}$/.test(String(form.phone).trim())) return toast('请填写 11 位手机号')
  if (!form.addressId) return toast('请选择服务地址')
  // 存在适用协议时必须全部签署（签署 token 一次性，随订单提交消费）
  if (agreements.value.length) {
    const unsigned = agreements.value.find((a) => !signedTokens[a.id])
    if (unsigned) {
      openAgreement(unsigned)
      return toast('请先阅读并签署《' + unsigned.name + '》')
    }
  }
  const startAt = form.date + ' ' + form.time
  const werr = bookingWindowError(startAt)
  if (werr) return toast(werr)
  const tech = techList.value.find((t) => String(t.id) === String(form.technicianId))
  // 技师时间冲突由服务端 409 拦截，前端不做本地预检
  submitting.value = true
  try {
    const addr = addresses.value.find((a) => String(a.id) === String(form.addressId))
    // 真实链路：POST /orders（订单号/金额/状态由服务端生成，member_id 取自 token）
    const row = await createOrder({
      service_id: service.value.id,
      start_at: /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/.test(startAt) ? startAt + ':00' : startAt,
      duration: Number(service.value.duration) || 120,
      customer: String(form.contact).trim(),
      phone: String(form.phone).trim(),
      address: addr ? addr.address : '',
      lng: 0,
      lat: 0,
      remark: form.note,
      technician_id: tech ? tech.id : 0,
      coupon_code: form.couponCode,
      // 协议签署凭证（存在适用协议时必传，服务端校验后消费）
      agreement_token: agreements.value.length ? (signedTokens[agreements.value[0].id] || '') : '',
      source: '顾客端小程序'
    })
    createdOrderNo.value = row.orderNo
    // 定金尾款启用时展示当前应付（定金）；关闭时即实付全额
    const payMeta = row.payment
    payAmount.value = payMeta && payMeta.depositEnabled && Number(payMeta.payable) > 0
      ? Number(payMeta.payable)
      : Number(row.actual ?? actual.value)
    showPay.value = true
  } catch (e) {
    // 下单失败一律报错（含后端不可达）
    toast(e && e.isHttpDown ? '后端服务未连接，请检查后端 API 服务是否启动' : (e.message || '下单失败'))
  } finally {
    submitting.value = false
  }
}

async function pay(channel) {
  try {
    // 真实链路：POST /pay/confirm（到店付/储值线下通道，服务端落账与状态分流）
    await payConfirm(createdOrderNo.value, channel)
  } catch (e) {
    toast(e && e.isHttpDown ? '后端服务未连接，请检查后端 API 服务是否启动' : (e.message || '支付失败'))
    return
  }
  showPay.value = false
  toast('支付成功')
  uni.redirectTo({ url: '/pages/order/detail?orderNo=' + createdOrderNo.value })
}

const addrVersion = ref(0)
onShow(() => {
  // 从地址管理页返回时刷新地址列表
  if (service.value) reloadAddresses()
})

async function reloadAddresses() {
  try {
    const res = await fetchAddresses()
    addresses.value = res?.list ?? []
    const def = addresses.value.find((a) => a.isDefault) || addresses.value[0]
    if (def && !form.addressId) form.addressId = def.id
  } catch (e) {
    addresses.value = []
  }
}

onMounted(async () => {
  try {
    const [svcData, techData] = await Promise.all([
      list('services', { page: 1, per_page: 200 }),
      list('technicians', { page: 1, per_page: 50 })
    ])
    service.value = (svcData.list || []).map((r) => ({ ...r, price: Math.round(Number(r.price || 0)) / 100 }))
      .find((s) => String(s.id) === serviceId) || null
    techList.value = (techData.list || []).filter((t) => t.status !== '离职')
  } catch (e) {
    toast(e && e.isHttpDown ? '后端服务未连接，请检查后端 API 服务是否启动' : (e.message || '加载失败'))
    return
  }
  await reloadAddresses()
  try {
    const res = await fetchMyCoupons()
    couponRows.value = res?.list ?? []
  } catch (e) {
    couponRows.value = []
  }
  if (service.value) loadAgreements(service.value.id)
})
</script>

<style scoped>
.reader-mask {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.5);
  z-index: 100;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
}
.reader {
  position: fixed;
  left: 16px;
  right: 16px;
  top: 10vh;
  background: #fff;
  border-radius: 12px;
  max-height: 80vh;
  display: flex;
  flex-direction: column;
  z-index: 101;
  overflow: hidden;
}
.reader__head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px 16px;
  font-weight: 600;
  border-bottom: 1px solid var(--border);
}
.reader__close {
  font-size: 22px;
  line-height: 1;
  color: #666;
  padding: 0 4px;
}
.reader__body {
  padding: 12px 16px;
  flex: 1;
  max-height: 56vh;
  font-size: 14px;
  line-height: 1.7;
  color: #333;
}
.reader__foot {
  padding: 12px 16px;
  border-top: 1px solid var(--border);
}
</style>
