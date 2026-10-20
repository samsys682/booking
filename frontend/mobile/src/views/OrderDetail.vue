<template>
  <div>
    <div class="navbar">
      <button class="navbar__back" @click="$router.back()">‹</button>
      <span class="navbar__title">订单详情</span>
    </div>

    <div class="page page--nobar" style="padding-bottom: 80px">
      <div v-if="order" class="card">
        <div class="row row--between">
          <div class="list-row__title">{{ order.service }}</div>
          <span class="tag" :class="statusClass(order.status)">{{ order.status }}</span>
        </div>
        <div class="tiny muted" style="margin-top: 6px">订单号 {{ order.orderNo }}</div>
      </div>

      <div v-if="order" class="card">
        <div class="sheet__title">服务信息</div>
        <div class="list-row">
          <div class="list-row__main">
            <div class="list-row__title">{{ order.startAt }}</div>
            <div class="list-row__sub">预约上门时间</div>
          </div>
        </div>
        <div class="list-row">
          <div class="list-row__main">
            <div class="list-row__title">{{ order.customer }} {{ order.phone }}</div>
            <div class="list-row__sub">{{ order.address }}</div>
          </div>
          <a class="btn btn--mini" :href="'tel:' + String(order.phone).replace(/\*/g, '')">联系客户</a>
        </div>
        <div v-if="order.note" class="list-row">
          <div class="list-row__main">
            <div class="list-row__title">备注</div>
            <div class="list-row__sub">{{ order.note }}</div>
          </div>
        </div>
        <div v-if="order.cancelReason" class="list-row">
          <div class="list-row__main">
            <div class="list-row__title">取消/拒单原因</div>
            <div class="list-row__sub">{{ order.cancelReason }}</div>
          </div>
        </div>
      </div>

      <div v-if="order" class="card">
        <div class="sheet__title">费用与佣金</div>
        <div class="row row--between" style="margin-bottom: 6px">
          <span class="muted">订单实付</span>
          <span class="price"><span class="yen">¥</span>{{ order.amount }}</span>
        </div>
        <div class="row row--between">
          <span class="muted">本单预估佣金</span>
          <span class="price"><span class="yen">¥</span>{{ order.commission }}</span>
        </div>
      </div>

      <div v-if="order" class="card">
        <div class="sheet__title">服务打卡</div>
        <div v-if="checkins.length" class="timeline">
          <div v-for="c in checkins" :key="c.id" class="timeline__item">
            <div class="list-row__title">{{ c.action }}</div>
            <div class="list-row__sub">{{ c.at }}<span v-if="c.note"> · {{ c.note }}</span></div>
          </div>
        </div>
        <div v-else class="empty" style="padding: 20px 0">暂无打卡记录</div>
      </div>

      <div v-if="!order" class="empty">订单不存在</div>
    </div>

    <div v-if="order" class="action-bar">
      <template v-if="order.status === '待接单'">
        <button class="btn" @click="decline">拒绝</button>
        <button class="btn btn--primary" @click="accept">接单</button>
      </template>
      <template v-else-if="order.status === '待服务'">
        <button class="btn" @click="check('到达打卡')">到达打卡</button>
        <button class="btn btn--primary" @click="check('开始服务')">开始服务</button>
      </template>
      <template v-else-if="order.status === '服务中'">
        <button class="btn btn--primary" @click="check('完成服务')">完成服务</button>
      </template>
      <template v-else>
        <button class="btn btn--block" @click="$router.push('/home')">返回工作台</button>
      </template>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from '../composables/useToast'
import { STATUS_META } from '../statusFlow'
import { orderDetail, acceptOrder, rejectOrder, checkinOrder, finishOrder } from '../api/orders'

const route = useRoute()
const router = useRouter()
const { toast } = useToast()

// 单一数据轨道：详情接口返回订单 + 打卡记录；失败报错不再降级演示数据
const order = ref(null)
const checkins = ref([])

// 状态主题色 → 端内 tag 样式（语义口径来自 statusFlow.js::STATUS_META）
const TYPE_TAG = { warning: 'tag--orange', primary: 'tag--green', info: 'tag--gray', success: 'tag--green', danger: 'tag--red' }
function statusClass(s) {
  return TYPE_TAG[STATUS_META[s]?.type] || 'tag--gray'
}

// 打卡 kind 映射（与 api/orders.js::KIND_ACTION 口径一致）
const ACTION_KIND = { 到达打卡: '上门', 开始服务: '开始', 完成服务: '结束' }

// 错误统一提示：后端不可达报连接问题，其余报业务错误
function toastErr(e, fallback) {
  toast(e && e.isHttpDown ? '后端服务未连接，请检查后端 API 服务是否启动' : (e && e.message ? e.message : fallback))
}

async function load() {
  try {
    const data = await orderDetail(String(route.params.id))
    order.value = data
    checkins.value = (data.checkins || []).slice().sort((a, b) => String(a.at).localeCompare(String(b.at)))
  } catch (e) {
    toastErr(e, '加载失败')
  }
}

async function accept() {
  try {
    await acceptOrder(order.value.orderNo)
    await load() // 重读详情刷新状态与打卡
    return toast('已接单')
  } catch (e) {
    toastErr(e, '操作失败')
  }
}

async function decline() {
  if (!window.confirm('确定拒绝该订单吗？拒绝后订单将回到平台派单池')) return
  try {
    await rejectOrder(order.value.orderNo, '技师拒绝')
  } catch (e) {
    return toastErr(e, '操作失败')
  }
  // 拒单后订单已释放回派单池，本技师不再有归属，详情会 403 → 回工作台
  toast('已拒绝，订单回到派单池')
  return router.replace('/home')
}

// 服务打卡链路：到达打卡 / 开始服务→服务中 / 完成服务→待确认（用户确认后流转已完成）
async function check(action) {
  try {
    await checkinOrder(order.value.orderNo, ACTION_KIND[action] || action)
    if (action === '完成服务') {
      await finishOrder(order.value.orderNo) // 结束打卡 + 完单（服务中→待确认）
    }
    await load() // checkin 返回不含 status，必须重读详情（口径与 user 端一致）
    return toast(action + '成功')
  } catch (e) {
    toastErr(e, '操作失败')
  }
}

onMounted(load)
</script>
