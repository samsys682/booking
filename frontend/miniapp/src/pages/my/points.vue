<template>
  <view class="page">
    <view class="card points-card">
      <view class="tiny" style="opacity: 0.85">当前积分</view>
      <view class="points-num">{{ points }}</view>
      <view class="tiny" style="opacity: 0.85">消费 1 元累计 1 积分，积分规则由商家后台维护</view>
    </view>

    <!-- 积分商城（兑换走 my/gifts/{id}/redeem，库存/余额服务端事务守恒） -->
    <view class="sheet__title" style="margin: 4px 0 8px">积分商城</view>
    <view v-if="gifts.length" class="card">
      <view v-for="g in gifts" :key="g.id" class="list-row">
        <view class="list-row__main">
          <view class="list-row__title">{{ g.name }}</view>
          <view class="list-row__sub">
            {{ g.detail || '商家兑换礼品' }} · 库存 {{ g.stock }}
            <text v-if="Number(g.exchanged) > 0"> · 已兑 {{ g.exchanged }}</text>
            <text v-if="Number(g.limit_per_user) > 0"> · 每人限兑 {{ g.limit_per_user }} 件</text>
            <text v-if="g.end_at"> · {{ g.end_at < nowStr ? '已截止' : '截止 ' + g.end_at.slice(0, 10) }}</text>
          </view>
        </view>
        <view style="text-align: right">
          <view class="price">{{ g.points }} 分</view>
          <button
            class="redeem-btn"
            :disabled="redeemingId === g.id || !canRedeem(g)"
            @tap="openRedeem(g)"
          >{{ redeemingId === g.id ? '兑换中…' : canRedeem(g) ? '兑换' : (g.end_at && g.end_at < nowStr) ? '已截止' : Number(g.stock) <= 0 ? '已兑完' : '积分不足' }}</button>
        </view>
      </view>
    </view>
    <view v-else class="card">
      <view class="empty">{{ giftsLoaded ? '商家暂未上架礼品' : '礼品加载中…' }}</view>
    </view>

    <view class="sheet__title" style="margin: 12px 0 8px">我的兑换</view>
    <view v-if="exchanges.length" class="card">
      <view v-for="x in exchanges" :key="x.id" class="list-row">
        <view class="list-row__main">
          <view class="list-row__title">{{ x.giftName }} ×{{ x.qty }}</view>
          <view class="list-row__sub">
            {{ x.at }} · {{ x.points }} 积分
            <text v-if="x.expressNo"> · 物流 {{ x.expressNo }}</text>
            <text v-else-if="x.remark"> · {{ x.remark }}</text>
          </view>
        </view>
        <view style="text-align: right">
          <view class="tiny">{{ x.status }}</view>
          <view class="tiny muted">{{ x.exchangeNo }}</view>
        </view>
      </view>
    </view>
    <view v-else class="card">
      <view class="empty">暂无兑换记录</view>
    </view>

    <view class="sheet__title" style="margin: 12px 0 8px">积分明细</view>
    <view class="card">
      <view v-for="(l, i) in logs" :key="i" class="list-row">
        <view class="list-row__main">
          <view class="list-row__title">{{ l.action }}</view>
          <view class="list-row__sub">{{ l.at }} · {{ l.detail }}</view>
        </view>
        <view style="text-align: right">
          <view :class="Number(l.points) >= 0 ? 'price' : 'muted'">{{ Number(l.points) >= 0 ? '+' : '' }}{{ l.points }}</view>
          <view class="tiny muted">余 {{ l.balance }}</view>
        </view>
      </view>
      <view v-if="!logs.length" class="empty">暂无积分记录</view>
    </view>

    <!-- 兑换收货信息（兑换单：服务端事务守恒，取消可退积分） -->
    <view v-if="redeemTarget">
      <view class="mask" @tap="redeemTarget = null"></view>
      <view class="sheet">
        <view class="sheet__title">兑换「{{ redeemTarget.name }}」</view>
        <view class="field">
          <text class="field__label">收货人</text>
          <input v-model="redeemForm.contact" class="field__input" placeholder="请输入收货人姓名" />
        </view>
        <view class="field">
          <text class="field__label">联系电话</text>
          <input v-model="redeemForm.contactPhone" class="field__input" type="number" maxlength="11" placeholder="请输入手机号" />
        </view>
        <view class="field">
          <text class="field__label">收货地址</text>
          <input v-model="redeemForm.address" class="field__input" placeholder="到店自提可留空" />
        </view>
        <view class="tiny muted" style="margin-bottom: 12px">消耗 {{ redeemTarget.points }} 积分 · 当前余额 {{ points }}</view>
        <view class="row" style="gap: 10px">
          <button class="btn" style="flex: 1" @tap="redeemTarget = null">取消</button>
          <button class="btn btn--primary" style="flex: 1" :disabled="submitting" @tap="submitRedeem">
            {{ submitting ? '提交中…' : '确认兑换' }}
          </button>
        </view>
      </view>
    </view>
  </view>
</template>

<script setup>
// 我的积分 —— 顾客端小程序（对齐 user H5 PointsView：积分/商城兑换/兑换记录/明细）
import { onMounted, reactive, ref } from 'vue'
import { toast } from '../../api/http'
import { fetchPoints, fetchGifts, redeemGift, fetchGiftExchanges } from '../../api/memberSelf'

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
    return toast('请填写收货人姓名与联系电话')
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
  line-height: 1.6;
}
.redeem-btn[disabled] { background: #ddd; color: #999; }
</style>
