<template>
  <div>
    <div class="navbar">
      <button class="navbar__back" @click="$router.back()">‹</button>
      <span class="navbar__title">确认预约</span>
    </div>

    <div class="page page--nobar" style="padding-bottom: 84px">
      <!-- 服务信息 -->
      <div v-if="service" class="card">
        <div class="row row--between">
          <div class="list-row__title">{{ service.name }}</div>
          <div class="price"><span class="yen">¥</span>{{ service.price }}</div>
        </div>
        <div class="tiny muted">约 {{ service.duration }} 分钟 / {{ service.unit }}</div>
      </div>

      <!-- 预约信息 -->
      <div class="card">
        <div class="sheet__title">预约信息</div>
        <div class="field">
          <label class="field__label">服务日期</label>
          <input v-model="form.date" type="date" class="field__input" />
        </div>
        <div class="field">
          <label class="field__label">服务时间</label>
          <input v-model="form.time" type="time" class="field__input" />
          <div class="tiny muted" style="margin-top: 4px">需提前 2 小时预约，最远可约 30 天</div>
        </div>
        <div class="field">
          <label class="field__label">指定技师（选填）</label>
          <select v-model="form.technicianId" class="field__input">
            <option value="">不指定，平台派单</option>
            <option v-for="t in techList" :key="t.id" :value="String(t.id)" :disabled="t.status === '休假'">
              {{ t.name }}（{{ skillsText(t.skills) }}·{{ t.rating }} 分{{ t.status === '休假' ? '·休假中' : '' }}）
            </option>
          </select>
          <div class="tiny muted" style="margin-top: 4px">指定技师支付后直接等技师接单；不指定则进入平台派单池</div>
        </div>
        <div class="field">
          <label class="field__label">联系人</label>
          <input v-model="form.contact" class="field__input" placeholder="请输入联系人" />
        </div>
        <div class="field">
          <label class="field__label">联系电话</label>
          <input v-model="form.phone" class="field__input" placeholder="请输入手机号" />
        </div>
        <div class="field">
          <label class="field__label">备注（选填）</label>
          <textarea v-model="form.note" class="field__input" placeholder="如：家里有宠物、需自带工具等"></textarea>
        </div>
      </div>

      <!-- 服务地址 -->
      <div class="card">
        <div class="row row--between">
          <div class="sheet__title">服务地址</div>
          <button class="btn btn--mini" @click="$router.push('/my/addresses')">管理地址</button>
        </div>
        <div
          v-for="a in addresses"
          :key="a.id"
          class="list-row"
          @click="form.addressId = ridOf(a)"
        >
          <span class="radio" :class="{ checked: String(form.addressId) === String(ridOf(a)) }"></span>
          <div class="list-row__main">
            <div class="list-row__title">{{ addrText(a) }}</div>
            <div class="list-row__sub">{{ a.name }} · {{ a.phone }}</div>
          </div>
          <span v-if="a.isDefault" class="tag tag--green">默认</span>
        </div>
        <div v-if="!addresses.length" class="empty">暂无地址，去「管理地址」新增</div>
      </div>

      <!-- 优惠券 -->
      <div class="card">
        <div class="sheet__title">优惠券</div>
        <select v-model="form.couponCode" class="field__input">
          <option value="">不使用优惠券</option>
          <option v-for="c in usableCoupons" :key="c.code" :value="c.code">
            {{ c.couponName }}（{{ c.code }}）
          </option>
        </select>
      </div>

      <!-- 电子服务协议（批次一 C：存在适用协议时必须阅读签署后才能下单） -->
      <div v-if="agreements.length" class="card">
        <div class="sheet__title">服务协议</div>
        <div v-for="a in agreements" :key="a.id" class="list-row" @click="openAgreement(a)">
          <span class="radio" :class="{ checked: !!signedTokens[a.id] }"></span>
          <div class="list-row__main">
            <div class="list-row__title">{{ a.name }}（V{{ a.version }}）</div>
            <div class="list-row__sub tiny muted">{{ signedTokens[a.id] ? '已同意并签署' : '点击阅读全文' }}</div>
          </div>
        </div>
      </div>

      <!-- 费用明细 -->
      <div class="card">
        <div class="row row--between"><span class="muted">服务费</span><span>¥{{ total }}</span></div>
        <div class="row row--between"><span class="muted">优惠抵扣</span><span class="price">-¥{{ discount }}</span></div>
        <div class="row row--between" style="margin-top: 6px"><b>实付</b><b class="price">¥{{ actual }}</b></div>
      </div>
    </div>

    <div class="action-bar">
      <div style="flex: 1; display: flex; align-items: center">
        <span class="tiny muted">合计</span>
        <span class="price" style="font-size: 19px; margin-left: 6px"><span class="yen">¥</span>{{ actual }}</span>
      </div>
      <button class="btn btn--primary" style="flex: none; padding: 11px 26px" :disabled="submitting" @click="submit">
        提交订单
      </button>
    </div>

    <PaySheet :show="showPay" :amount="payAmount" @pay="pay" @cancel="showPay = false" />

    <!-- 协议阅读弹窗：全文阅读 → 同意并签署（换取一次性下单凭证） -->
    <div v-if="readerAgreement" class="reader-mask" @click.self="readerAgreement = null">
      <div class="reader">
        <div class="reader__head">
          <span>{{ readerAgreement.name }}（V{{ readerAgreement.version }}）</span>
          <button class="reader__close" @click="readerAgreement = null">×</button>
        </div>
        <div class="reader__body" v-html="readerAgreement.content"></div>
        <div class="reader__foot">
          <button class="btn btn--primary" style="width: 100%" :disabled="signing" @click="agreeAndSign">
            {{ signing ? '签署中…' : '我已阅读并同意' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useCrud } from '../composables/useCrud'
import { useToast } from '../composables/useToast'
import PaySheet from '../components/PaySheet.vue'
import { createOrder, payConfirm } from '../api/orders'
import { fetchActiveAgreements, signAgreement } from '../api/agreements'
import { loadMember } from '../api/auth'
import { fetchAddresses, fetchMyCoupons } from '../api/memberSelf'
import { canTransition } from '../statusFlow'

const route = useRoute()
const router = useRouter()
const { toast } = useToast()

const crudS = useCrud('services', { moneyFields: ['price'] })
// 技师目录（后端公开只读已放行）
const crudT = useCrud('technicians', { keywordField: ['name', 'skills'] })
// 会员券走 my/coupons（member token 无权访 admin 资源 coupon-instances）
const couponRows = ref([])

const ridOf = (row) => row.id

const service = ref(null)
const addresses = ref([])
const addrText = (a) => a.address || [a.region, a.detail].filter(Boolean).join(' ')
const techList = computed(() => crudT.state.rows)

// 技师技能展示：后端存 JSON 数组串（如 ["空调清洗","油烟机清洗"]），解析为「、」分隔；
// 兼容逗号/顿号分隔的存量数据，解析失败原样返回
function skillsText(raw) {
  const s = String(raw || '').trim()
  if (!s) return '综合服务'
  try {
    const arr = JSON.parse(s)
    if (Array.isArray(arr)) return arr.filter(Boolean).join('、') || '综合服务'
  } catch (e) { /* 非 JSON 走下面的分隔符兼容 */ }
  return s.split(/[,，、]/).filter(Boolean).join('、') || '综合服务'
}
const usableCoupons = computed(() => couponRows.value.filter((c) => c.status === '未使用'))

// 联系人默认带登录会员资料（未登录时留空由用户填写）
const me = loadMember() || {}
const form = reactive({
  date: new Date(Date.now() + 86400000).toISOString().slice(0, 10),
  time: '10:00',
  contact: me.name || '',
  phone: me.phone || '',
  note: '',
  addressId: '',
  couponCode: '',
  technicianId: ''
})

const total = computed(() => Number(service.value?.price ?? 0))
const discount = computed(() => {
  const c = couponRows.value.find((x) => x.code === form.couponCode)
  return discountOf(c, total.value)
})
const actual = computed(() => Math.max(0, Math.round((total.value - discount.value) * 100) / 100))

/** 券抵扣金额：value 文案「减 ¥30」= 满减；「8.8 折」= 折扣；threshold「满 100 可用」限制门槛 */
function discountOf(coupon, t) {
  if (!coupon) return 0
  const threshold = String(coupon.threshold || '').match(/(\d+(?:\.\d+)?)/)
  const off = String(coupon.value || '').match(/减\s*¥?\s*(\d+(?:\.\d+)?)/)
  if (off) {
    if (threshold && t < Number(threshold[1])) return 0
    return Math.min(Number(off[1]), t)
  }
  const zhe = String(coupon.value || '').match(/(\d+(?:\.\d+)?)\s*折/)
  if (zhe) return Math.round(t * (1 - Number(zhe[1]) / 10) * 100) / 100
  return 0
}

function orderNoOf() {
  const d = new Date()
  const pad = (n) => String(n).padStart(2, '0')
  return 'BK' + d.getFullYear() + pad(d.getMonth() + 1) + pad(d.getDate()) + pad(d.getHours()) + pad(d.getMinutes()) + pad(d.getSeconds())
}

const submitting = ref(false)
const showPay = ref(false)
const createdRid = ref('')
const createdOrderNo = ref('')
const createdTech = ref('')

// 电子服务协议（批次一 C）：适用协议预取 + 已签 token 缓存（一次性，下单消费后作废）
const agreements = ref([])
const signedTokens = reactive({})
const readerAgreement = ref(null)
const signing = ref(false)

async function loadAgreements(serviceId) {
  try {
    const res = await fetchActiveAgreements(serviceId)
    agreements.value = Array.isArray(res) ? res : []
  } catch (e) {
    // 协议预取失败不阻塞页面（服务端下单时仍会强校验，缺失时由后端报错提示）
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
// 支付金额取服务端核算
const payAmount = ref(0)

// 预约时间窗（事实源 OrderService::assertBookingWindow：提前 2 小时 / 最远 30 天）
function bookingWindowError(startAt) {
  const ts = new Date(String(startAt).replace(/-/g, '/')).getTime()
  if (Number.isNaN(ts)) return '时间格式不正确'
  if (ts < Date.now() + 2 * 3600000) return '需提前 2 小时预约'
  if (ts > Date.now() + 30 * 86400000) return '最远可预约 30 天内的服务'
  return ''
}

function fmtPlus(startAt, minutes) {
  const d = new Date(String(startAt).replace(/-/g, '/'))
  d.setMinutes(d.getMinutes() + minutes)
  const p = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}

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
  const endAt = fmtPlus(startAt, Number(service.value.duration) || 120)
  const tech = techList.value.find((t) => String(t.id) === String(form.technicianId))
  // 技师时间冲突由服务端 409 拦截，前端不做本地预检
  submitting.value = true
  try {
    const addr = addresses.value.find((a) => String(ridOf(a)) === String(form.addressId))
    // 真实链路：POST /orders（订单号/金额/状态由服务端生成，member_id 取自 token）
    try {
      const row = await createOrder({
        service_id: service.value.id,
        start_at: /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/.test(startAt) ? startAt + ':00' : startAt,
        duration: Number(service.value.duration) || 120,
        customer: String(form.contact).trim(),
        phone: String(form.phone).trim(),
        address: addr ? addrText(addr) : '',
        lng: 0,
        lat: 0,
        remark: form.note,
        technician_id: tech ? tech.id : 0,
        coupon_code: form.couponCode,
        // 协议签署凭证（存在适用协议时必传，服务端校验后消费）
        agreement_token: agreements.value.length ? (signedTokens[agreements.value[0].id] || '') : '',
        source: '用户端 H5'
      })
      createdRid.value = row.id ?? ''
      createdOrderNo.value = row.orderNo
      createdTech.value = tech ? tech.name : ''
      // 定金尾款启用时展示当前应付（定金）；关闭时即实付全额
      const payMeta = row.payment
      payAmount.value = payMeta && payMeta.depositEnabled && Number(payMeta.payable) > 0
        ? Number(payMeta.payable)
        : Number(row.actual ?? actual.value)
      showPay.value = true
      return
    } catch (e) {
      // 下单失败一律报错（含后端不可达），不再本地造单兜底
      toast(e.isHttpDown ? '后端服务未连接，请检查后端 API 服务是否启动' : (e.message || '下单失败'))
      return
    }
  } finally {
    submitting.value = false
  }
}

async function pay(channel) {
  // 真实链路：POST /pay/confirm（储值抵扣/落支付流水/状态分流均由服务端完成，
  // 不再本地扣余额/写假流水；后端不可达时报错）
  try {
    await payConfirm(createdOrderNo.value, channel)
  } catch (e) {
    toast(e.isHttpDown ? '后端服务未连接，请检查后端 API 服务是否启动' : (e.message || '支付失败'))
    return
  }
  showPay.value = false
  toast('支付成功')
  router.replace('/order/' + createdOrderNo.value)
}

onMounted(async () => {
  await Promise.all([
    crudS.load({ page: 1, per_page: 200 }),
    crudT.load({ page: 1, per_page: 50 })
  ])
  // 地址与券都走 my/* 会员专属接口（member token 无权访 admin 资源）
  try {
    const res = await fetchAddresses()
    addresses.value = (res?.list ?? []).map((a) => ({ ...a }))
  } catch (e) {
    addresses.value = []
  }
  try {
    const res = await fetchMyCoupons()
    couponRows.value = (res?.list ?? []).map((c) => ({ ...c }))
  } catch (e) {
    couponRows.value = []
  }
  service.value = crudS.state.rows.find((s) => String(s.id) === String(route.query.serviceId)) || null
  if (service.value) loadAgreements(service.value.id)
  const def = addresses.value.find((a) => a.isDefault) || addresses.value[0]
  if (def) form.addressId = ridOf(def)
})
</script>

<style scoped>
.radio {
  width: 16px;
  height: 16px;
  border-radius: 50%;
  border: 1px solid var(--border);
  flex: none;
}
.radio.checked { border: 5px solid var(--primary); }

/* 协议阅读弹窗 */
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
  background: #fff;
  border-radius: 12px;
  width: 100%;
  max-width: 480px;
  max-height: 80vh;
  display: flex;
  flex-direction: column;
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
  border: none;
  background: none;
  font-size: 22px;
  line-height: 1;
  cursor: pointer;
  color: var(--text-secondary, #666);
}
.reader__body {
  padding: 12px 16px;
  overflow-y: auto;
  flex: 1;
  font-size: 14px;
  line-height: 1.7;
  color: #333;
  word-break: break-word;
}
.reader__body :deep(img) { max-width: 100%; }
.reader__foot {
  padding: 12px 16px;
  border-top: 1px solid var(--border);
}
</style>
