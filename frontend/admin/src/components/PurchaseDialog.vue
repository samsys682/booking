<template>
  <el-dialog v-model="visible" title="购买授权 · 在线支付" width="640px" :close-on-click-modal="false" @closed="stopPolling">
    <el-steps :active="step" align-center finish-status="success" class="pay-steps">
      <el-step title="选择版本" />
      <el-step title="支付宝支付" />
      <el-step title="平台发卡" />
    </el-steps>

    <!-- 步骤一：选择版本套餐 -->
    <div v-if="step === 0" class="plan-grid">
      <div
        v-for="p in plans"
        :key="p.key"
        class="plan-card"
        :class="{ 'plan-card--active': form.plan === p.key }"
        @click="form.plan = p.key"
      >
        <div class="plan-card__head">
          <span class="plan-card__name">{{ p.name }}</span>
          <el-tag v-if="p.hot" size="small" type="warning" effect="plain">推荐</el-tag>
        </div>
        <div class="plan-card__price">
          ￥{{ p.price.toLocaleString() }}<span class="plan-card__unit">/ {{ p.unit }}</span>
        </div>
        <ul class="plan-card__features">
          <li v-for="f in p.features" :key="f">{{ f }}</li>
        </ul>
      </div>
    </div>

    <!-- 步骤二：支付宝支付 -->
    <div v-else-if="step === 1" class="pay-area">
      <div class="pay-summary">
        <span>{{ currentPlan.name }}（{{ currentPlan.unit }}）</span>
        <span class="pay-summary__price">￥{{ currentPlan.price.toLocaleString() }}</span>
      </div>

      <el-radio-group v-model="form.payMethod" class="pay-methods" @change="recreateOrder">
        <el-radio-button value="face_to_face">支付宝扫码支付</el-radio-button>
        <el-radio-button value="page_pay">支付宝网页支付</el-radio-button>
        <el-radio-button value="wap_pay">手机支付</el-radio-button>
      </el-radio-group>

      <div v-if="creating" class="pay-wait">
        <el-icon class="is-loading"><Loading /></el-icon> 正在创建支付订单…
      </div>

      <div v-else-if="order && form.payMethod === 'face_to_face'" class="qr-box">
        <img v-if="order.qr_code_image" :src="order.qr_code_image" class="qr-box__img" alt="支付宝收款二维码" />
        <div v-else class="qr-box__placeholder">
          <el-icon :size="28"><CreditCard /></el-icon>
          <div>支付宝收款二维码</div>
          <div class="qr-box__mock">{{ channelTip }}</div>
        </div>
        <div v-if="!order.qr_code_image && order.qr_code_url" class="qr-box__order">收款链接：{{ order.qr_code_url }}</div>
        <div class="qr-box__order">订单号：{{ order.order_no }}</div>
        <div class="qr-box__status">
          <el-icon v-if="paying" class="is-loading"><Loading /></el-icon>
          {{ payStatusText }}
        </div>
      </div>

      <div v-else-if="order" class="pay-page-box">
        <div class="pay-page-box__hint">
          {{ form.payMethod === 'wap_pay' ? '点击下方按钮在新窗口打开手机收银台完成支付，支付完成后返回本页。' : '点击下方按钮跳转支付宝收银台完成支付，支付完成后自动返回。' }}
        </div>
        <el-button type="primary" @click="goPayPage">前往支付宝支付</el-button>
        <div class="qr-box__order">订单号：{{ order.order_no }}</div>
        <div class="qr-box__status">
          <el-icon v-if="paying" class="is-loading"><Loading /></el-icon>
          {{ payStatusText }}
        </div>
      </div>
    </div>

    <!-- 步骤三：支付成功，平台发卡（v1.14 卡密制：凭卡号+卡密在设置页激活） -->
    <div v-else class="pay-success">
      <el-result icon="success" title="支付成功" sub-title="平台核实到账后发放卡密，凭卡号 + 卡密在 设置 → 版本与授权 完成激活，菜单功能即联动解锁。">
        <template #extra>
          <el-button type="primary" @click="visible = false">完成</el-button>
        </template>
      </el-result>
    </div>

    <template #footer>
      <div class="modal-footer">
        <template v-if="step === 0">
          <el-button @click="visible = false">取消</el-button>
          <el-button type="primary" @click="startPay">立即支付</el-button>
        </template>
        <template v-else-if="step === 1">
          <el-button @click="step = 0">上一步</el-button>
          <el-button @click="checkPaid">我已完成支付</el-button>
        </template>
      </div>
      <div v-if="step === 1" class="pay-channel-tip">{{ channelTip }}</div>
    </template>
  </el-dialog>
</template>

<script setup>
import { computed, reactive, ref, watch, onBeforeUnmount } from 'vue'
import { ElMessage } from 'element-plus'
import { Loading, CreditCard } from '@element-plus/icons-vue'
import { createPayOrder, queryPayOrder, isRealPay } from '../api/payment'

// 价格对齐《开发计划》产品定价表
const plans = [
  {
    key: 'pro',
    name: '授权版',
    price: 599,
    unit: '年',
    hot: true,
    features: [
      '￥599/年',
      '解除门店 / 技师 / 订单限额',
      '营销中心、经营报表、提成结算',
      '自定义品牌（去除 Powered by 标识）',
      '一年升级与技术支持'
    ]
  },
  {
    key: 'source',
    name: '源码版',
    // 2026-10-10 
    price: 4980,
    unit: '买断',
    hot: false,
    features: [
      '包含授权版全部功能',
      '完整源码交付，支持二次开发',
      '私有化部署，数据自持',
      '终身授权，永久使用，不限续费'
    ]
  }
]

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  defaultPlan: { type: String, default: 'pro' }
})
const emit = defineEmits(['update:modelValue'])

const visible = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v)
})

const step = ref(0)
const creating = ref(false)
const paying = ref(false)
const order = ref(null)
let pollTimer = null

const form = reactive({ plan: props.defaultPlan, payMethod: 'face_to_face' })

const currentPlan = computed(() => plans.find((p) => p.key === form.plan) || plans[0])

// 通道标识（）：real=OA 支付宝真实收银台；mock=本地模拟（8 秒自动确认支付）
const channelTip = computed(() =>
  isRealPay ? '支付通道：支付宝（OA 统一支付）' : '当前为模拟支付通道：下单 8 秒后自动确认支付'
)

const payStatusText = computed(() => (paying.value ? '等待支付，结果自动确认中…' : '请使用支付宝扫码完成支付'))

watch(visible, (v) => {
  if (v) {
    step.value = 0
    order.value = null
    paying.value = false
    form.plan = props.defaultPlan
    form.payMethod = 'face_to_face'
  } else {
    stopPolling()
  }
})

async function startPay() {
  step.value = 1
  await createOrder()
}

async function createOrder() {
  creating.value = true
  try {
    const res = await createPayOrder({
      subject: `${currentPlan.value.name}（${currentPlan.value.unit}）`,
      totalAmount: currentPlan.value.price,
      payMethod: form.payMethod,
      plan: form.plan
    })
    order.value = res.data
    paying.value = true
    startPolling()
  } catch (e) {
    ElMessage.error(e.message || '创建支付订单失败')
  } finally {
    creating.value = false
  }
}

// 切换支付方式需按新 pay_method 重新下单（扫码/网页/手机的收银台地址不同）
function recreateOrder() {
  if (step.value === 1) createOrder()
}

function startPolling() {
  stopPolling()
  pollTimer = setInterval(checkPaid, 3000)
}

function stopPolling() {
  if (pollTimer) {
    clearInterval(pollTimer)
    pollTimer = null
  }
}

async function checkPaid() {
  if (!order.value) return
  try {
    const res = await queryPayOrder(order.value.order_no)
    if (res.data.trade_status === 'TRADE_SUCCESS' || res.data.trade_status === 'TRADE_FINISHED') {
      stopPolling()
      paying.value = false
      // v1.14 卡密制：支付成功不再自动激活（旧版伪激活 card_no='OA-'+order_no 已摘除），
      // 平台核实到账后发卡，客户凭卡号+卡密在 设置→版本与授权 激活
      step.value = 2
      ElMessage.success('支付成功，平台核实后将发放卡密，请在设置页激活授权')
    } else if (res.data.trade_status === 'ALIPAY_ERROR') {
      stopPolling()
      paying.value = false
      ElMessage.error('支付通道异常，请稍后重试或更换支付方式')
    }
  } catch (e) {
    // 网络抖动不中断轮询，下次继续确认
    console.warn('[payment] 查询支付结果失败：', e.message)
  }
}

function goPayPage() {
  // 真实跳转 OA 支付返回的收银台（pay_url 直接跳转；alipay_form_html 为自动提交表单）
  if (order.value && order.value.pay_url) {
    window.open(order.value.pay_url, '_blank')
    return
  }
  if (order.value && order.value.alipay_form_html) {
    const div = document.createElement('div')
    div.style.display = 'none'
    div.innerHTML = order.value.alipay_form_html
    document.body.appendChild(div)
    const formEl = div.querySelector('form')
    if (formEl) {
      formEl.target = '_blank'
      formEl.submit()
    }
    return
  }
  ElMessage.info('模拟支付通道无需跳转，等待自动确认即可（mock）')
}

onBeforeUnmount(stopPolling)
</script>

<style scoped>
.pay-steps {
  margin-bottom: var(--space-lg);
}

.plan-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: var(--space-md);
}

.plan-card {
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  padding: var(--space-md);
  cursor: pointer;
  transition: border-color 0.15s, box-shadow 0.15s;
}

.plan-card:hover {
  border-color: var(--primary-hover);
}

.plan-card--active {
  border-color: var(--primary);
  box-shadow: 0 0 0 1px var(--primary) inset;
}

.plan-card__head {
  display: flex;
  align-items: center;
  gap: var(--space-xs);
}

.plan-card__name {
  font-size: var(--font-size-heading);
  font-weight: 600;
}

.plan-card__price {
  margin: var(--space-xs) 0;
  font-size: 20px;
  font-weight: 600;
  color: var(--danger);
}

.plan-card__unit {
  font-size: var(--font-size-body);
  font-weight: 400;
  color: var(--text-tertiary);
}

.plan-card__features {
  margin: 0;
  padding-left: 16px;
  color: var(--text-secondary);
  line-height: 1.8;
}

.pay-area {
  min-height: 280px;
}

.pay-summary {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: var(--space-sm) var(--space-md);
  background: #FAFAFA;
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  margin-bottom: var(--space-md);
}

.pay-summary__price {
  font-size: 18px;
  font-weight: 600;
  color: var(--danger);
}

.pay-methods {
  margin-bottom: var(--space-md);
}

.pay-wait {
  display: flex;
  align-items: center;
  gap: var(--space-xs);
  justify-content: center;
  color: var(--text-secondary);
  padding: var(--space-xl) 0;
}

.qr-box,
.pay-page-box {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: var(--space-xs);
  padding: var(--space-md) 0;
}

.qr-box__img {
  width: 180px;
  height: 180px;
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
}

.qr-box__placeholder {
  width: 180px;
  height: 180px;
  border: 1px dashed var(--border);
  border-radius: var(--radius-md);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 6px;
  color: var(--text-secondary);
}

.qr-box__mock {
  font-size: var(--font-size-body);
  color: var(--text-tertiary);
}

.qr-box__order {
  color: var(--text-tertiary);
}

.qr-box__status {
  display: flex;
  align-items: center;
  gap: 4px;
  color: var(--warning);
}

.pay-page-box__hint {
  color: var(--text-secondary);
  margin-bottom: var(--space-xs);
}

.pay-channel-tip {
  text-align: center;
  color: var(--text-tertiary);
  margin-top: var(--space-xs);
}

@media (max-width: 768px) {
  .plan-grid {
    grid-template-columns: 1fr;
  }
}
</style>
