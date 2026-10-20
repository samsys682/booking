<template>
  <div class="content">
    <div class="content-card content-card--list">
      <div class="action-bar">
        <h2 class="page-title">操作日志</h2>
        <div class="action-bar__left">
          <el-input v-model="query.keyword" placeholder="操作人 / 对象单号" clearable style="width: 200px"
            @clear="handleSearch" @keyup.enter="handleSearch">
            <template #prefix><el-icon><Search /></el-icon></template>
          </el-input>
          <el-select v-model="query.action" placeholder="动作" clearable style="width: 130px" @change="handleSearch">
            <el-option v-for="a in actions" :key="a" :label="a" :value="a" />
          </el-select>
          <el-button :icon="Search" @click="handleSearch">查询</el-button>
          <span class="page-subtitle">谁在什么时间对什么对象做了什么（含 IP），按时间倒序</span>
        </div>
      </div>
      <div class="table-wrap" v-loading="loading">
        <el-table :data="pagedData" empty-text="暂无日志">
          <el-table-column prop="createdAt" label="时间" width="160" sortable />
          <el-table-column prop="adminName" label="操作人" width="100" />
          <el-table-column prop="action" label="动作" width="150">
            <template #default="{ row }">
              <el-tag size="small" effect="plain" :type="actionType(row.action)">{{ row.action }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column prop="target" label="操作对象" min-width="150" show-overflow-tooltip />
          <el-table-column prop="content" label="变更摘要" min-width="240" show-overflow-tooltip />
          <el-table-column prop="ip" label="IP" width="130" />
        </el-table>
        <el-empty v-if="!loading && pagedData.length === 0" description="暂无日志" />
      </div>
      <div class="pagination-bar">
        <el-pagination v-model:current-page="page" :page-size="pageSize"
          :total="total" layout="total, prev, pager, next" background size="small" />
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, reactive, ref, onMounted } from 'vue'
import { Search } from '@element-plus/icons-vue'
import { useCrud } from '../composables/useCrud'

// audit-logs 只读资源（后端 AuditMiddleware 自动记录；本页仅查询，不可写）
const loading = computed(() => crud.state.loading)
const query = reactive({ keyword: '', action: '' })
const page = ref(1)
const pageSize = 20
const crud = useCrud('audit-logs', { fieldMap: { createdAt: 'created_at' } })
const total = computed(() => crud.state.total)
const pagedData = computed(() => crud.state.rows)

const actions = ['login', 'create', 'update', 'delete', 'export', 'refund', 'order.finish', 'order.confirm', 'order.dispatch']

function actionType(action) {
  if (action === 'delete') return 'danger'
  if (action === 'login') return 'info'
  if (String(action).startsWith('order.')) return 'warning'
  return 'primary'
}

function handleSearch() {
  page.value = 1
  reload()
}

function reload() {
  const params = { page: page.value, per_page: pageSize }
  if (query.action) params.action = query.action
  if (query.keyword) params.keyword = query.keyword
  crud.load(params)
}

onMounted(reload)
</script>
