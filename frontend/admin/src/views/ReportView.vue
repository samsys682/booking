<template>
  <div class="content">
    <div class="content-card content-card--list">
      <div class="action-bar">
        <h2 class="page-title">经营报表</h2>
        <div class="action-bar__left">
          <span class="page-subtitle">本月概览与服务项目排行（实时汇总自订单/服务数据，可导出 CSV）</span>
        </div>
        <div class="action-bar__right">
          <el-button :icon="Download" @click="exportReport">导出报表</el-button>
        </div>
      </div>

      <!-- 概览：由订单数据实时汇总 -->
      <div class="stat-grid" v-loading="loading">
        <div v-for="s in stats" :key="s.label" class="stat-item">
          <div class="stat-label">{{ s.label }}</div>
          <div class="stat-value">{{ s.value }}</div>
        </div>
      </div>

      <div class="table-wrap" v-loading="loading">
        <el-table :data="serviceRank" empty-text="暂无数据">
          <el-table-column type="index" label="排名" width="70" />
          <el-table-column prop="name" label="服务项目" min-width="160" />
          <el-table-column prop="sales" label="销量" width="100" sortable />
          <el-table-column prop="revenue" label="营收（元）" min-width="110" sortable>
            <template #default="{ row }">￥{{ row.revenue }}</template>
          </el-table-column>
        </el-table>
      </div>
    </div>
  </div>
</template>

<script setup>
// 经营报表：由 bookings / services 资源实时汇总（API 优先 + mock 兜底）
// CSV 导出走后端 /reports/export（授权版门禁 report.export，服务端聚合 + 真实附件下载）
import { computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { Download } from '@element-plus/icons-vue'
import { useCrud } from '../composables/useCrud'
import { getToken } from '../api/http'
import { promptUpgrade } from '../store/upgrade'

const bookingCrud = useCrud('bookings', {
  moneyFields: ['amount', 'total', 'discount', 'actual'],
  keywordField: ['orderNo', 'customer', 'service']
})
const serviceCrud = useCrud('services', {
  moneyFields: ['price'],
  keywordField: ['name', 'category']
})

const loading = computed(() => bookingCrud.state.loading || serviceCrud.state.loading)

const PAID = ['待服务', '服务中', '已完成']

const paid = computed(() => bookingCrud.state.rows.filter((b) => PAID.includes(b.status)))

const stats = computed(() => {
  const revenue = paid.value.reduce((s, b) => s + Number(b.amount || b.actual || 0), 0)
  const count = paid.value.length
  const all = bookingCrud.state.rows.length
  const cancel = bookingCrud.state.rows.filter((b) => b.status === '已取消').length
  return [
    { label: '本月营收', value: '￥' + Math.round(revenue) },
    { label: '有效订单', value: count + ' 单' },
    { label: '客单价', value: count ? '￥' + Math.round(revenue / count) : '—' },
    { label: '取消率', value: all ? Math.round((cancel / all) * 100) + '%' : '—' }
  ]
})

const serviceRank = computed(() =>
  [...serviceCrud.state.rows]
    .sort((a, b) => (Number(b.sales) || 0) - (Number(a.sales) || 0))
    .slice(0, 8)
    .map((s) => ({ name: s.name, sales: Number(s.sales) || 0, revenue: Math.round((Number(s.sales) || 0) * (Number(s.price) || 0)) }))
)

async function loadAll() {
  await Promise.all([
    bookingCrud.load({ page: 1, per_page: 100 }),
    serviceCrud.load({ page: 1, per_page: 100 })
  ])
}

// 导出 CSV：调后端 /reports/export（服务端聚合 + 附件下载）
// 开源版后端返回 403 + upgrade → 统一弹升级引导（付费转化路径）
async function exportReport() {
  const BASE = import.meta.env.VITE_API_BASE || '/api/v1'
  try {
    const res = await fetch(`${BASE}/reports/export?type=bookings`, {
      headers: { Authorization: 'Bearer ' + getToken() }
    })
    const ct = res.headers.get('content-type') || ''
    if (!ct.includes('text/csv')) {
      // 非 CSV → 业务错误（403 版本门禁 / 401 等）
      const json = await res.json().catch(() => null)
      if (json && json.code === 403 && json.data && json.data.upgrade) {
        promptUpgrade(json.data.upgrade)
        return
      }
      throw new Error((json && json.msg) || '导出失败')
    }
    // 附件名优先取后端 Content-Disposition
    let filename = `经营报表_${new Date().toISOString().slice(0, 10)}.csv`
    const cd = res.headers.get('content-disposition') || ''
    const m = cd.match(/filename="?([^"]+)"?/)
    if (m) filename = m[1]
    const blob = await res.blob()
    const a = document.createElement('a')
    a.href = URL.createObjectURL(blob)
    a.download = filename
    a.click()
    URL.revokeObjectURL(a.href)
    ElMessage.success('报表已导出')
  } catch (e) {
    ElMessage.error(e.message || '导出失败，请稍后重试')
  }
}

onMounted(loadAll)
</script>

<style scoped>
.page-subtitle {
  color: var(--text-tertiary);
}

.stat-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: var(--space-md);
  padding-bottom: var(--space-md);
}

.stat-item {
  border: 1px solid var(--border);
  border-radius: var(--radius-md, 8px);
  padding: var(--space-md);
}

.stat-label {
  color: var(--text-tertiary);
  font-size: var(--font-size-body);
  margin-bottom: var(--space-xs);
}

.stat-value {
  font-size: var(--font-size-title);
  font-weight: 600;
  color: var(--text-primary);
}

@media (max-width: 900px) {
  .stat-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}
</style>
