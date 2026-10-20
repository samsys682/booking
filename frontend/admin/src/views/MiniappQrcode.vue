<template>
  <div class="content">
    <div class="content-card content-card--list">
      <div class="action-bar">
        <h2 class="page-title">小程序码</h2>
        <div class="action-bar__left">
          <span class="page-subtitle">门店码/渠道码生成，扫码来源带参统计</span>
        </div>
      </div>

      <div style="padding: 0 0 var(--space-md)">
        <el-form inline>
          <el-form-item label="渠道名称">
            <el-input v-model="form.name" placeholder="如：传单 / 门店A桌贴" style="width: 180px" />
          </el-form-item>
          <el-form-item>
            <el-button type="primary" @click="generate">生成小程序码</el-button>
          </el-form-item>
        </el-form>
      </div>

      <div class="table-wrap">
        <el-table :data="codes" empty-text="暂无小程序码">
          <el-table-column prop="name" label="渠道" min-width="120" />
          <el-table-column prop="scene" label="带参" min-width="120" />
          <el-table-column label="码值" min-width="140">
            <template #default="{ row }"><span class="code-text">{{ row.sceneValue }}</span></template>
          </el-table-column>
          <el-table-column prop="scans" label="扫码次数" width="100" sortable />
          <el-table-column prop="at" label="生成时间" width="150" />
          <el-table-column label="操作" width="90" fixed="right">
            <template #default="{ row }">
              <el-button type="primary" link size="small" @click="download(row)">下载</el-button>
            </template>
          </el-table-column>
        </el-table>
      </div>
    </div>
  </div>
</template>

<script setup>
// 核查：带参码可生成、扫码统计、可下载（mock）
import { ref, reactive } from 'vue'
import { ElMessage } from 'element-plus'

const form = reactive({ name: '' })

const codes = reactive([
  { name: '门店桌贴', scene: '门店码', sceneValue: 'scene=store-001', scans: 128, at: '2026-09-20 10:00' },
  { name: '十一传单', scene: '渠道码', sceneValue: 'scene=leaflet-101', scans: 42, at: '2026-09-28 15:30' }
])

function generate() {
  if (!form.name) {
    ElMessage.warning('请填写渠道名称')
    return
  }
  const key = 'ch-' + Math.random().toString(36).slice(2, 8)
  codes.unshift({ name: form.name, scene: '渠道码', sceneValue: `scene=${key}`, scans: 0, at: new Date().toLocaleString('zh-CN', { hour12: false }) })
  form.name = ''
  ElMessage.success('小程序码已生成（mock，接后端后调用微信 getUnlimited 接口）')
}

function download(row) {
  ElMessage.success(`「${row.name}」小程序码已下载（mock）`)
}
</script>

<style scoped>
.page-subtitle {
  color: var(--text-tertiary);
}

.code-text {
  font-family: monospace;
  color: var(--text-secondary);
}
</style>
