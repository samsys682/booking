<template>
  <span class="iebar">
    <el-tooltip content="下载 CSV 导入模板（含示例行）" placement="top">
      <el-button :icon="Download" :loading="busy === 'tpl'" @click="downloadTemplate">模板</el-button>
    </el-tooltip>
    <el-tooltip content="按模板批量导入（默认跳过错误行，返回失败明细）" placement="top">
      <el-button :icon="Upload" :loading="busy === 'import'" @click="fileInput?.click()">导入</el-button>
    </el-tooltip>
    <el-tooltip content="全量导出 CSV（Excel 直开不乱码）" placement="top">
      <el-button :icon="Download" :loading="busy === 'export'" @click="exportCsv">导出</el-button>
    </el-tooltip>
    <input ref="fileInput" type="file" accept=".csv,text/csv" style="display: none" @change="onFile" />

    <el-dialog v-model="resultVisible" title="导入结果" width="600px" class="iebar-dialog">
      <div class="iebar-summary">
        <span>共 <b>{{ result?.total ?? 0 }}</b> 行</span>
        <span class="ok">成功 <b>{{ result?.success ?? 0 }}</b></span>
        <span class="bad">失败 <b>{{ result?.fail ?? 0 }}</b></span>
      </div>
      <el-table v-if="(result?.errors || []).length" :data="result.errors" max-height="300" size="small">
        <el-table-column prop="row" label="数据行号" width="90" />
        <el-table-column prop="reason" label="失败原因" min-width="280" show-overflow-tooltip />
      </el-table>
      <el-empty v-else description="全部导入成功" :image-size="60" />
      <template #footer>
        <el-button type="primary" @click="resultVisible = false">知道了</el-button>
      </template>
    </el-dialog>
  </span>
</template>

<script setup>
// =====================================================================
// 批量导入导出工具条（缺口补齐批次二；嵌入会员/服务/技师三页操作栏）
// - 模板/导出：fetch + Bearer 下载 CSV 附件（request() 只走 JSON，故此处自取流）
// - 导入：FileReader 读文本 → POST /import/{resource}（request() 走全局 403 升级引导）
// - 403 版本门禁：request() 全局弹升级引导；流式下载路径手动识别 upgrade 载荷
// =====================================================================
import { ref } from 'vue'
import { ElMessage } from 'element-plus'
import { Download, Upload } from '@element-plus/icons-vue'
import { request, getToken, BizError } from '../api/http'
import { promptUpgrade } from '../store/upgrade'

const props = defineProps({
  resource: { type: String, required: true }, // members | services | technicians
  label: { type: String, default: '数据' }
})
const emit = defineEmits(['imported'])

const BASE = import.meta.env.VITE_API_BASE || '/api/v1'
const busy = ref('') // tpl | import | export
const fileInput = ref(null)
const resultVisible = ref(false)
const result = ref(null)

/** 流式下载 CSV（403 时解析升级载荷并弹引导） */
async function fetchCsv(path, fallbackName) {
  const res = await fetch(BASE + path, {
    headers: { ...(getToken() ? { Authorization: 'Bearer ' + getToken() } : {}) }
  })
  const type = res.headers.get('content-type') || ''
  if (!res.ok || type.includes('application/json')) {
    const j = await res.json().catch(() => null)
    if (j && j.code === 403 && j.data && j.data.upgrade) promptUpgrade(j.data.upgrade)
    throw new Error((j && j.msg) || '下载失败')
  }
  // 后端 Content-Disposition 带 ASCII 文件名，直接复用兜底名即可
  const blob = await res.blob()
  const a = document.createElement('a')
  a.href = URL.createObjectURL(blob)
  a.download = fallbackName
  a.click()
  URL.revokeObjectURL(a.href)
}

async function downloadTemplate() {
  busy.value = 'tpl'
  try {
    await fetchCsv(`/import-template/${props.resource}`, `import_template_${props.resource}.csv`)
    ElMessage.success('模板已下载，按示例行填写后导入')
  } catch (e) {
    ElMessage.error(e.message || '模板下载失败')
  } finally {
    busy.value = ''
  }
}

async function exportCsv() {
  busy.value = 'export'
  try {
    await fetchCsv(`/export/${props.resource}`, `export_${props.resource}_${Date.now()}.csv`)
  } catch (e) {
    ElMessage.error(e.message || '导出失败')
  } finally {
    busy.value = ''
  }
}

async function onFile(e) {
  const file = e.target.files && e.target.files[0]
  e.target.value = ''
  if (!file) return
  if (file.size > 5 * 1024 * 1024) {
    ElMessage.error('文件过大（上限 5MB），请分批导入')
    return
  }
  busy.value = 'import'
  try {
    const content = await file.text()
    const res = await request(`/import/${props.resource}`, { method: 'POST', body: { content, mode: 'skip' } })
    result.value = res
    resultVisible.value = true
    if (Number(res.success) > 0) emit('imported')
  } catch (err) {
    ElMessage.error(err instanceof BizError ? err.message : (err.message || '导入失败'))
  } finally {
    busy.value = ''
  }
}
</script>

<style scoped>
.iebar {
  display: inline-flex;
  gap: 0;
  margin-right: 8px;
}
.iebar-summary {
  display: flex;
  gap: 18px;
  margin-bottom: 10px;
  font-size: 14px;
}
.iebar-summary .ok b { color: var(--el-color-success, #67c23a); }
.iebar-summary .bad b { color: var(--el-color-danger, #f56c6c); }
</style>
