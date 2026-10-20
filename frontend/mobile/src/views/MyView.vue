<template>
  <div class="page">
    <div class="card">
      <div class="row">
        <div class="avatar">{{ tech.name.slice(0, 1) }}</div>
        <div style="flex: 1">
          <div class="row">
            <b style="font-size: 17px">{{ tech.name }}</b>
            <span class="tag tag--green" style="margin-left: 8px">{{ tech.status }}</span>
          </div>
          <div class="tiny muted" style="margin-top: 4px">{{ techLine1 }}</div>
          <div class="tiny muted" style="margin-top: 2px">{{ techLine2 }}</div>
        </div>
      </div>
      <div class="row row--between" style="margin-top: 12px">
        <div style="text-align: center; flex: 1">
          <div style="font-size: 18px; font-weight: 600">{{ tech.rating }}</div>
          <div class="tiny muted">评分</div>
        </div>
        <div style="text-align: center; flex: 1">
          <div style="font-size: 18px; font-weight: 600">{{ tech.monthOrders }}</div>
          <div class="tiny muted">本月完成</div>
        </div>
        <div style="text-align: center; flex: 1">
          <div style="font-size: 18px; font-weight: 600">{{ todayStatus }}</div>
          <div class="tiny muted">今日出勤</div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="list-row" style="cursor: pointer" @click="$router.push('/my/attendance')">
        <div class="list-row__main">
          <div class="list-row__title">出勤打卡</div>
          <div class="list-row__sub">上下班打卡与出勤记录</div>
        </div>
        <span class="muted">›</span>
      </div>
      <div class="list-row" style="cursor: pointer" @click="$router.push('/schedule')">
        <div class="list-row__main">
          <div class="list-row__title">我的排班</div>
          <div class="list-row__sub">查看排班，设置休息/接单</div>
        </div>
        <span class="muted">›</span>
      </div>
      <div class="list-row" style="cursor: pointer" @click="$router.push('/income')">
        <div class="list-row__main">
          <div class="list-row__title">我的收入</div>
          <div class="list-row__sub">佣金结算与计佣规则</div>
        </div>
        <span class="muted">›</span>
      </div>
      <div class="list-row" style="cursor: pointer" @click="$router.push('/my/reviews')">
        <div class="list-row__main">
          <div class="list-row__title">收到的评价</div>
          <div class="list-row__sub">客户评价与商家回复</div>
        </div>
        <span class="muted">›</span>
      </div>
      <div class="list-row" style="cursor: pointer" @click="$router.push('/my/services')">
        <div class="list-row__main">
          <div class="list-row__title">我的服务</div>
          <div class="list-row__sub">勾选可承接的服务项目</div>
        </div>
        <span class="muted">›</span>
      </div>
    </div>

    <button class="btn btn--block" style="color: var(--danger)" @click="logout">退出登录</button>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { skillsText } from '../composables/skillsText'
import { fetchAttendance } from '../api/techSelf'
import { setToken } from '../api/http'
import { loadTechnician, clearTechnician } from '../api/auth'

const router = useRouter()
// 登录后技师档案（localStorage 缓存）
const tech = loadTechnician() || {}

// 空段过滤：真实档案字段可能缺失，不显示孤立分隔点
const techLine1 = computed(() => [tech.phone, tech.level].filter(Boolean).join(' · '))
const techLine2 = computed(() => [tech.store, tech.area, skillsText(tech.skills)].filter(Boolean).join(' · '))

// 今日打卡状态：走 /tech/attendance 专属接口（按当前技师过滤；
// 通用资源端点 attendances 无数据范围过滤，技师会看到全店考勤，已弃用）
const attRows = ref([])

const todayStatus = computed(() => {
  const d = new Date()
  const today = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0')
  const row = attRows.value.find((a) => a.date === today)
  return row ? row.status : '未打卡'
})

function logout() {
  if (!window.confirm('确定退出登录吗？')) return
  setToken('')
  clearTechnician()
  router.replace('/login')
}

onMounted(async () => {
  try {
    attRows.value = await fetchAttendance()
  } catch (e) {
    /* 状态保持未打卡；错误不打断页面 */
  }
})
</script>

<style scoped>
.avatar {
  width: 52px;
  height: 52px;
  border-radius: 50%;
  background: var(--primary-light);
  color: var(--primary);
  font-size: 20px;
  font-weight: 600;
  display: flex;
  align-items: center;
  justify-content: center;
}
</style>
