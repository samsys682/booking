<template>
  <div>
    <div class="navbar">
      <button class="navbar__back" @click="$router.back()">‹</button>
      <span class="navbar__title">出勤打卡</span>
    </div>

    <div class="page page--nobar">
      <div class="card" style="text-align: center">
        <div class="tiny muted">今日（{{ today }}）</div>
        <div style="font-size: 26px; font-weight: 600; margin: 8px 0">{{ now }}</div>
        <div class="tiny muted" style="margin-bottom: 12px">
          {{ todayRow ? (todayRow.checkIn ? '上班 ' + todayRow.checkIn + (todayRow.checkOut ? ' · 下班 ' + todayRow.checkOut : '') : '今日休息') : '尚未打卡' }}
        </div>
        <div class="row" style="gap: 10px">
          <button class="btn btn--primary" style="flex: 1" :disabled="!!(todayRow && todayRow.checkIn)" @click="checkIn">
            上班打卡
          </button>
          <button class="btn" style="flex: 1" :disabled="!(todayRow && todayRow.checkIn) || !!(todayRow && todayRow.checkOut)" @click="checkOut">
            下班打卡
          </button>
        </div>
      </div>

      <div class="sheet__title" style="margin: 4px 0 8px">出勤记录</div>
      <div class="card">
        <div v-for="a in rows" :key="a.id" class="list-row">
          <div class="list-row__main">
            <div class="list-row__title">{{ a.date }}</div>
            <div class="list-row__sub">
              {{ a.status === '休息' ? '休息' : (a.checkIn || '--') + ' - ' + (a.checkOut || '未下班') + (a.hours ? ' · ' + a.hours + ' 小时' : '') }}
            </div>
          </div>
          <span class="tag" :class="statusClass(a.status)">{{ a.status }}</span>
        </div>
        <div v-if="!rows.length" class="empty">暂无出勤记录</div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useToast } from '../composables/useToast'
import { fetchAttendance, checkinAttendance, checkoutAttendance } from '../api/techSelf'

const { toast } = useToast()

// 本地今天（toISOString 为 UTC，须本地拼）
const dNow = new Date()
const today = dNow.getFullYear() + '-' + String(dNow.getMonth() + 1).padStart(2, '0') + '-' + String(dNow.getDate()).padStart(2, '0')
const now = ref(new Date().toTimeString().slice(0, 5))
setInterval(() => { now.value = new Date().toTimeString().slice(0, 5) }, 30000)

const rows = ref([])
const todayRow = computed(() => rows.value.find((a) => a.date === today))

function statusClass(s) {
  return { 打卡中: 'tag--green', 正常: 'tag--green', 缺卡: 'tag--red', 休息: 'tag--gray' }[s] || 'tag--gray'
}

// 错误统一提示：后端不可达报连接问题，其余报业务错误
function toastErr(e, fallback) {
  toast(e && e.isHttpDown ? '后端服务未连接，请检查后端 API 服务是否启动' : (e && e.message ? e.message : fallback))
}

async function load() {
  try {
    rows.value = await fetchAttendance()
  } catch (e) {
    toastErr(e, '加载失败')
  }
}

async function checkIn() {
  try {
    await checkinAttendance(now.value)
  } catch (e) {
    return toastErr(e, '打卡失败')
  }
  await load()
  toast('上班打卡成功')
}

async function checkOut() {
  const row = todayRow.value
  if (!row || !row.checkIn) return
  try {
    await checkoutAttendance(now.value)
  } catch (e) {
    return toastErr(e, '打卡失败')
  }
  await load()
  toast('下班打卡成功')
}

onMounted(load)
</script>
