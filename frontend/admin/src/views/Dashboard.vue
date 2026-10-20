<template>
  <div class="content">
    <!-- 限额 80% 黄条提醒 + 升级入口（附一 P0；支付开通后自动消失，） -->
    <el-alert v-if="!license.isPro && orderLimitRatio >= 0.8" type="warning" :closable="false" class="limit-alert">
      <template #title>
        本月订单已达 {{ license.limits.monthlyOrders.used }}/{{ license.limits.monthlyOrders.max }} 单（{{ Math.round(orderLimitRatio * 100) }}%），
        升级授权版可解除限制
        <el-button type="warning" link size="small" @click="showUpgrade">立即升级</el-button>
      </template>
    </el-alert>

    <div class="content-card">
      <h2 class="page-title">首页看板</h2>

      <!-- 统计卡 -->
      <div class="stat-grid" v-loading="loading">
        <div v-for="item in dashboardStats" :key="item.label" class="stat-card">
          <div class="stat-card__icon" :class="`stat-card__icon--${item.tone}`">
            <el-icon :size="18"><component :is="item.icon" /></el-icon>
          </div>
          <div class="stat-card__body">
            <div class="stat-card__value">
              {{ item.value }}<span class="stat-card__unit">{{ item.unit }}</span>
            </div>
            <div class="stat-card__label">{{ item.label }}</div>
          </div>
        </div>
      </div>
    </div>

    <div class="content-card" style="margin-top: var(--space-md)">
      <div class="panel-header">最近预约</div>
      <el-table :data="recentBookings" v-loading="loading" empty-text="暂无预约记录">
        <el-table-column prop="id" label="订单号" width="150" />
        <el-table-column prop="customer" label="客户" width="90" />
        <el-table-column prop="service" label="服务项目" min-width="160" show-overflow-tooltip />
        <el-table-column prop="technician" label="技师" width="90" />
        <el-table-column prop="startAt" label="上门时间" width="140" />
        <el-table-column prop="amount" label="金额" width="90">
          <template #default="{ row }">￥{{ row.amount }}</template>
        </el-table-column>
        <el-table-column prop="status" label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="bookingStatusMap[row.status]" size="small">{{ row.status }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="90">
          <template #default="{ row }">
            <el-button type="primary" link size="small" @click="$router.push({ path: '/bookings', query: { keyword: row.id } })">详情</el-button>
          </template>
        </el-table-column>
      </el-table>
    </div>

    <!-- 收费功能灰色锁定卡 + 价值说明 + 升级入口（附一③；受限功能购买后解锁） -->
    <div class="content-card" style="margin-top: var(--space-md)">
      <div class="panel-header">增长工具</div>
      <div v-if="!license.isPro" class="locked-hint">以下功能当前受限，购买开放版本后可查看使用。</div>
      <div class="locked-grid">
        <div v-for="tool in lockedTools" :key="tool.title" class="locked-card">
          <div class="locked-card__title">
            <el-icon v-if="!license.isPro"><Lock /></el-icon>
            {{ tool.title }}
            <el-tag v-if="!license.isPro" size="small" type="info" effect="plain">授权版功能</el-tag>
            <el-tag v-else size="small" type="success" effect="plain">已解锁</el-tag>
          </div>
          <div class="locked-card__desc">{{ tool.desc }}</div>
          <el-button v-if="!license.isPro" size="small" @click="showUpgrade">购买解锁</el-button>
          <el-button v-else size="small" type="primary" plain @click="$router.push('/feature/coupon')">去使用</el-button>
        </div>
      </div>
    </div>

    <!-- 购买授权在线支付（对接 OA 支付宝支付） -->
    <PurchaseDialog v-model="purchaseVisible" />
  </div>
</template>

<script setup>
import { computed, ref, onMounted, markRaw } from 'vue'
import {
  Ticket, Clock, Calendar, Money, Van, ChatDotRound, User, RefreshLeft
} from '@element-plus/icons-vue'
import { request } from '../api/http'
import { useLicenseStore } from '../store/license'
// 状态→标签色映射（单源展示配置，与 BookingList/Settings 同源；清 mock 时误删致看板最近预约表格崩潧）
import { bookingStatusMap } from '../mock/data'
import PurchaseDialog from '../components/PurchaseDialog.vue'

const loading = ref(true)
const purchaseVisible = ref(false)
const license = useLicenseStore()

const orderLimitRatio = computed(() => license.orderLimitRatio)

// 统计卡 + 最近预约：接后端 /dashboard/stats，后端不可达时报错不再展示假数据
const dashboardStats = ref([])
const recentBookings = ref([])

const lockedTools = [
  { title: '营销中心', desc: '优惠券、拼团、老带新等营销活动，帮助门店持续获客。' },
  { title: '经营报表', desc: '营收趋势、服务项目排行、技师绩效等多维分析报表。' },
  { title: '提成结算', desc: '按单提成、阶梯提成规则自动核算，一键生成工资单。' }
]

function showUpgrade() {
  purchaseVisible.value = true
}

// stats 键 → 统计卡（icon 本地引入；金额分→元；icon 用 markRaw 包裹避免被 reactive 化产生无谓开销）
function mapStats(s) {
  return [
    { label: '今日订单', value: s.todayOrders, unit: '单', icon: markRaw(Ticket), tone: 'primary' },
    { label: '今日待服务', value: s.todayPending, unit: '单', icon: markRaw(Clock), tone: 'warning' },
    { label: '本月订单', value: s.monthOrders, unit: '单', icon: markRaw(Calendar), tone: 'primary' },
    { label: '本月营收', value: (s.monthRevenue || 0) / 100, unit: '元', icon: markRaw(Money), tone: 'success' },
    { label: '待派单', value: s.pendingDispatch, unit: '单', icon: markRaw(Van), tone: 'warning' },
    { label: '待回复评价', value: s.pendingReviews, unit: '条', icon: markRaw(ChatDotRound), tone: 'info' },
    { label: '本月新会员', value: s.monthMembers, unit: '人', icon: markRaw(User), tone: 'success' },
    { label: '退款处理中', value: s.pendingRefunds, unit: '单', icon: markRaw(RefreshLeft), tone: 'info' }
  ]
}

async function load() {
  loading.value = true
  try {
    const data = await request('/dashboard/stats')
    // 授权信息随 stats 一并下发：回写 store，看板限额黄条/版本标识即时联动
    if (data.license) license.applyApi(data.license)
    if (data.stats) dashboardStats.value = mapStats(data.stats)
    if (Array.isArray(data.recent)) {
      recentBookings.value = data.recent.map(r => ({
        id: r.order_no,
        customer: r.customer,
        service: r.service,
        technician: r.technician || '待派单',
        startAt: (r.start_at || '').slice(0, 16),
        amount: (r.actual || 0) / 100,
        status: r.status
      }))
    }
  } catch (e) {
    if (e.isHttpDown) {
      ElMessage.error('后端服务未连接，请检查后端 API 服务是否启动')
    } else {
      console.warn('[dashboard]', e.message)
    }
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<style scoped>
.limit-alert {
  margin-bottom: var(--space-md);
}

.stat-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: var(--space-md);
  min-height: 90px;
}

.stat-card {
  display: flex;
  align-items: center;
  gap: var(--space-sm);
  padding: var(--space-md);
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
}

.stat-card__icon {
  width: 36px;
  height: 36px;
  border-radius: var(--radius-md);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.stat-card__icon--primary { background: var(--primary-light); color: var(--primary); }
.stat-card__icon--warning { background: #FDF6EC; color: var(--warning); }
.stat-card__icon--success { background: #F0F9EB; color: var(--success); }
.stat-card__icon--info { background: #F4F4F5; color: var(--info); }

.stat-card__value {
  font-size: 20px;
  font-weight: 600;
  line-height: 1.2;
}

.stat-card__unit {
  font-size: var(--font-size-body);
  font-weight: 400;
  color: var(--text-tertiary);
  margin-left: 2px;
}

.stat-card__label {
  color: var(--text-secondary);
}

.locked-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: var(--space-md);
}

.locked-hint {
  color: var(--text-tertiary);
  margin-bottom: var(--space-sm);
}

@media (max-width: 1024px) {
  .stat-grid,
  .locked-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}

@media (max-width: 768px) {
  .stat-grid,
  .locked-grid {
    grid-template-columns: 1fr;
  }
}
</style>
