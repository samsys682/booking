<template>
  <div>
    <div class="navbar">
      <button class="navbar__back" @click="$router.back()">‹</button>
      <span class="navbar__title">我的积分</span>
    </div>

    <div class="page page--nobar">
      <div class="card points-card">
        <div class="tiny" style="opacity: 0.85">当前积分</div>
        <div class="points-num">{{ points }}</div>
        <div class="tiny" style="opacity: 0.85">消费 1 元累计 1 积分，积分规则由商家后台维护</div>
      </div>

      <!-- 积分商城（兑换走 my/gifts/{id}/redeem，库存/余额服务端事务守恒） -->
      <div class="sheet__title" style="margin: 4px 0 8px">积分商城</div>
      <div class="card" v-if="gifts.length">
        <div v-for="(g, i) in gifts" :key="g.id" class="list-row">
          <div class="list-row__main">
            <div class="list-row__title">{{ g.name }}</div>
            <div class="list-row__sub">
              {{ g.detail || '商家兑换礼品' }} · 库存 {{ g.stock }}
              <span v-if="g.exchanged > 0"> · 已兑 {{ g.exchanged }}</span>
              <span v-if="Number(g.limit_per_user) > 0"> · 每人限兑 {{ g.limit_per_user }} 件</span>
              <span v-if="g.end_at"> · {{ g.end_at < nowStr ? '已截止' : '截止 ' + g.end_at.slice(0, 10) }}</span>
            </div>
          </div>
          <div style="text-align: right">
            <div class="price">{{ g.points }} 分</div>
            <button class="redeem-btn" :disabled="redeemingId === g.id || !canRedeem(g)" @click="openRedeem(g)">
              {{ redeemingId === g.id ? '兑换中…' : canRedeem(g) ? '兑换' : (g.end_at && g.end_at < nowStr) ? '已截止' : Number(g.stock) <= 0 ? '已兑完' : '积分不足' }}
            </button>
          </div>
        </div>
      </div>
      <div class="card" v-else>
        <div class="empty">{{ giftsLoaded ? '商家暂未上架礼品' : '礼品加载中…' }}</div>
      </div>

      <div class="sheet__title" style="margin: 12px 0 8px">我的兑换</div>
      <div class="card" v-if="exchanges.length">
        <div v-for="x in exchanges" :key="x.id" class="list-row">
          <div class="list-row__main">
            <div class="list-row__title">{{ x.giftName }} ×{{ x.qty }}</div>
            <div class="list-row__sub">
              {{ x.at }} · {{ x.points }} 积分
              <span v-if="x.expressNo"> · 物流 {{ x.expressNo }}</span>
              <span v-else-if="x.remark"> · {{ x.remark }}</span>
            </div>
          </div>
          <div style="text-align: right">
            <div class="tiny">{{ x.status }}</div>
            <div class="tiny muted">{{ x.exchangeNo }}</div>
          </div>
        </div>
      </div>
      <div class="card" v-else>
        <div class="empty">暂无兑换记录</div>
      </div>

      <div class="sheet__title" style="margin: 12px 0 8px">积分明细</div>
      <div class="card">
        <div v-for="(l, i) in logs" :key="i" class="list-row">
          <div class="list-row__main">
            <div class="list-row__title">{{ l.action }}</div>
            <div class="list-row__sub">{{ l.at }} · {{ l.detail }}</div>
          </div>
          <div style="text-align: right">
            <div :class="Number(l.points) >= 0 ? 'price' : 'muted'">{{ Number(l.points) >= 0 ? '+' : '' }}{{ l.points }}</div>
            <div class="tiny muted">余 {{ l.balance }}</div>
          </div>
        </div>
        <div v-if="!logs.length" class="empty">暂无积分记录</div>
      </div>
    </div>

    <!-- 兑换收货信息（积分商城兑换单：服务端事务守恒，取消可退积分） -->
    <div v-if="redeemTarget">
      <div class="mask" @click="redeemTarget = null"></div>
      <div class="sheet">
        <div class="sheet__title">兑换「{{ redeemTarget.name }}」</div>
        <div class="field">
          <label class="field__label">收货人</label>
          <input v-model="redeemForm.contact" class="field__input" placeholder="请输入收货人姓名" />
        </div>
        <div class="field">
          <label class="field__label">联系电话</label>
          <input v-model="redeemForm.contactPhone" class="field__input" maxlength="11" placeholder="请输入手机号" />
        </div>
        <div class="field">
          <label class="field__label">收货地址</label>
          <input v-model="redeemForm.address" class="field__input" placeholder="到店自提可留空" />
        </div>
        <div class="tiny muted" style="margin-bottom: 12px">消耗 {{ redeemTarget.points }} 积分 · 当前余额 {{ points }}</div>
        <div class="row" style="gap: 10px">
          <button class="btn" style="flex: 1" @click="redeemTarget = null">取消</button>
          <button class="btn btn--primary" style="flex: 1" :disabled="submitting" @click="submitRedeem">
            {{ submitting ? '提交中…' : '确认兑换' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useToast } from '../composables/useToast'
import { fetchPoints, fetchGifts, redeemGift, fetchGiftExchanges } from '../api/memberSelf'

const { toast } = useToast()

// 积分非金额；余额 = 服务端流水末条；失败报错不再降级演示数据

const logs = ref([])
const points = ref(0)
const gifts = ref([])
const giftsLoaded = ref(false)
const redeemingId = ref(null)
const exchanges = ref([])
const redeemTarget = ref(null)
const submitting = ref(false)
const redeemForm = reactive({ contact: '', contactPhone: '', address: '' })

// 服务器口径：end_at 过期不可兑（本地时钟对比，误差可接受）
const pad = (n) => String(n).padStart(2, '0')
const _d = new Date()
const nowStr = `${_d.getFullYear()}-${pad(_d.getMonth() + 1)}-${pad(_d.getDate())} ${pad(_d.getHours())}:${pad(_d.getMinutes())}:${pad(_d.getSeconds())}`

function canRedeem(g) {
  if (g.end_at && g.end_at < nowStr) return false
  return Number(g.stock) > 0 && points.value >= Number(g.points)
}

function openRedeem(g) {
  if (!canRedeem(g)) return
  redeemTarget.value = g
  redeemForm.contact = ''
  redeemForm.contactPhone = ''
  redeemForm.address = ''
}

async function submitRedeem() {
  const g = redeemTarget.value
  if (!g || submitting.value) return
  if (!redeemForm.contact.trim() || !redeemForm.contactPhone.trim()) {
    toast('请填写收货人姓名与联系电话')
    return
  }
  submitting.value = true
  redeemingId.value = g.id
  try {
    const data = await redeemGift(g.id, {
      contact: redeemForm.contact.trim(),
      contact_phone: redeemForm.contactPhone.trim(),
      address: redeemForm.address.trim()
    })
    points.value = Number(data && data.balance) || 0
    toast(`兑换成功${data && data.exchangeNo ? '（单号 ' + data.exchangeNo + '）' : ''}，商家发货后可查看物流`)
    redeemTarget.value = null
    loadGifts()
    loadExchanges()
  } catch (e) {
    toast(e && e.isHttpDown ? '后端服务未连接，请检查后端 API 服务是否启动' : (e && e.message ? e.message : '兑换失败'))
  } finally {
    submitting.value = false
    redeemingId.value = null
  }
}

async function loadExchanges() {
  try {
    const data = await fetchGiftExchanges()
    exchanges.value = (data && data.list) || []
  } catch (e) { /* 静默：兑换记录加载失败不阻塞积分页 */ }
}

async function loadGifts() {
  try {
    const data = await fetchGifts()
    gifts.value = (data && data.list) || []
    giftsLoaded.value = true
  } catch (e) {
    giftsLoaded.value = true
  }
}

async function load() {
  try {
    const data = await fetchPoints()
    points.value = Number(data.balance) || 0
    logs.value = data.logs || []
  } catch (e) {
    toast(e && e.isHttpDown ? '后端服务未连接，请检查后端 API 服务是否启动' : (e && e.message ? e.message : '加载失败'))
  }
}

onMounted(() => {
  load()
  loadGifts()
  loadExchanges()
})
</script>

<style scoped>
.points-card {
  background: linear-gradient(135deg, #c98a2e, #e0a94e);
  color: #fff;
}
.points-num { font-size: 30px; font-weight: 600; margin: 6px 0; }
.redeem-btn {
  margin-top: 4px;
  padding: 3px 12px;
  font-size: 12px;
  border: none;
  border-radius: 12px;
  background: #c98a2e;
  color: #fff;
}
.redeem-btn:disabled {
  background: #ddd;
  color: #999;
}
</style>
