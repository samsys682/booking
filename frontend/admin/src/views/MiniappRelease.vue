<template>
  <div class="content">
    <div class="content-card content-card--list">
      <div class="action-bar">
        <h2 class="page-title">发布管理</h2>
        <div class="action-bar__left">
          <span class="page-subtitle">小程序版本提交审核、发布与回滚</span>
        </div>
      </div>

      <div class="table-wrap">
        <el-table :data="versions" empty-text="暂无版本">
          <el-table-column prop="version" label="版本号" width="100" />
          <el-table-column prop="desc" label="版本说明" min-width="180" />
          <el-table-column prop="at" label="提交时间" width="150" />
          <el-table-column label="状态" width="100">
            <template #default="{ row }">
              <el-tag :type="statusTag(row.status)" size="small">{{ row.status }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="150" fixed="right">
            <template #default="{ row }">
              <el-button v-if="row.status === '开发中'" type="primary" link size="small" @click="submitReview(row)">提交审核</el-button>
              <el-button v-else-if="row.status === '审核中'" type="primary" link size="small" @click="release(row)">发布</el-button>
              <el-button v-else-if="row.status === '已发布'" type="warning" link size="small" @click="rollback(row)">回滚</el-button>
              <span v-else class="form-tip">已回滚</span>
            </template>
          </el-table-column>
        </el-table>
      </div>
    </div>
  </div>
</template>

<script setup>
// 核查：版本状态机 开发中→审核中→已发布→（可回滚）
import { reactive } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'

const versions = reactive([
  { version: '1.2.0', desc: '新增优惠券领取页、服务详情改版', at: '2026-10-03 10:00', status: '审核中' },
  { version: '1.1.0', desc: '加钟功能上线、会员卡中心', at: '2026-09-25 14:00', status: '已发布' },
  { version: '1.0.1', desc: '修复支付回调偶发失败', at: '2026-09-18 09:30', status: '已发布' }
])

function statusTag(s) {
  return { 开发中: 'info', 审核中: 'warning', 已发布: 'success' }[s] || 'danger'
}

function submitReview(row) {
  row.status = '审核中'
  ElMessage.success(`v${row.version} 已提交微信审核（mock）`)
}

function release(row) {
  row.status = '已发布'
  versions.filter((v) => v !== row && v.status === '已发布').forEach((v) => (v.status = '已回滚'))
  ElMessage.success(`v${row.version} 已发布，旧版本自动下线`)
}

function rollback(row) {
  ElMessageBox.confirm(`确认回滚 v${row.version} 吗？线上将恢复上一个版本。`, '回滚版本', { type: 'warning' })
    .then(() => {
      row.status = '已回滚'
      ElMessage.success('已回滚')
    })
    .catch(() => {})
}
</script>

<style scoped>
.page-subtitle {
  color: var(--text-tertiary);
}
</style>
