<template>
  <div class="content">
    <div class="content-card content-card--list">
      <div class="action-bar">
        <h2 class="page-title">数据备份</h2>
        <div class="action-bar__left">
          <el-input v-model="query.keyword" placeholder="备份文件名 / 操作人" clearable style="width: 200px"
            @clear="handleSearch" @keyup.enter="handleSearch">
            <template #prefix><el-icon><Search /></el-icon></template>
          </el-input>
          <el-select v-model="query.kind" placeholder="备份类型" clearable style="width: 120px" @change="handleSearch">
            <el-option label="全量" value="全量" />
            <el-option label="增量" value="增量" />
          </el-select>
          <el-select v-model="query.status" placeholder="执行结果" clearable style="width: 120px" @change="handleSearch">
            <el-option label="成功" value="成功" />
            <el-option label="失败" value="失败" />
          </el-select>
          <el-button :icon="Search" @click="handleSearch">查询</el-button>
        </div>
        <div class="action-bar__right">
          <el-button :icon="Search" @click="handleRestore(latestRestorable)">恢复数据</el-button>
          <el-button type="primary" :icon="Plus" @click="handleBackup">立即备份</el-button>
        </div>
      </div>
      <div class="table-wrap" v-loading="loading">
        <el-table :data="pagedData" empty-text="暂无备份记录">
          <el-table-column prop="name" label="备份文件" min-width="230" show-overflow-tooltip />
          <el-table-column label="类型" width="90">
            <template #default="{ row }">
              <el-tag size="small" :type="row.kind === '全量' ? 'primary' : 'warning'">{{ row.kind }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column prop="size" label="大小" width="100" />
          <el-table-column prop="tables" label="表数量" width="80" />
          <el-table-column prop="at" label="备份时间" min-width="150" />
          <el-table-column prop="operator" label="操作人" width="100" />
          <el-table-column label="结果" width="90">
            <template #default="{ row }">
              <el-tag size="small" :type="row.status === '成功' ? 'success' : 'danger'">{{ row.status }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="保留" width="80">
            <template #default="{ row }">
              <el-switch v-model="row.keep" @change="handleKeep(row)" />
            </template>
          </el-table-column>
          <el-table-column prop="note" label="备注" min-width="150" show-overflow-tooltip>
            <template #default="{ row }">{{ row.note || '-' }}</template>
          </el-table-column>
          <el-table-column label="操作" width="240" fixed="right">
            <template #default="{ row }">
              <el-button link type="primary" size="small" :disabled="row.status !== '成功'" @click="handleDownload(row)">下载</el-button>
              <el-button link type="warning" size="small" :disabled="row.status !== '成功'" @click="handleRestore(row)">恢复</el-button>
              <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
              <el-button link type="danger" size="small" @click="handleDelete(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
        <el-empty v-if="!loading && filteredData.length === 0" description="暂无备份记录">
          <el-button type="primary" @click="handleBackup">立即备份</el-button>
        </el-empty>
      </div>
      <div class="pagination-bar">
        <el-pagination v-model:current-page="page" :page-size="pageSize"
          :total="total" layout="total, prev, pager, next" background size="small" />
      </div>
    </div>

    <el-dialog v-model="formVisible" title="编辑备份记录" width="620px" class="list-dialog">
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top">
        <div class="form-section">备份信息</div>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="备份文件名" prop="name">
              <el-input v-model="form.name" maxlength="50" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="备份类型" prop="kind">
              <el-radio-group v-model="form.kind">
                <el-radio-button label="全量">全量</el-radio-button>
                <el-radio-button label="增量">增量</el-radio-button>
              </el-radio-group>
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="执行结果" prop="status">
              <el-select v-model="form.status" style="width: 100%">
                <el-option label="成功" value="成功" />
                <el-option label="失败" value="失败" />
              </el-select>
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="操作人">
              <el-input v-model="form.operator" maxlength="20" />
            </el-form-item>
          </el-col>
        </el-row>
        <el-form-item label="备注">
          <el-input v-model="form.note" type="textarea" :rows="2" placeholder="失败时记录原因，如：磁盘空间不足" maxlength="100" />
          <span class="form-tip">备份文件落 data/backups 目录，不入代码仓库；建议保留最近 7 天全量备份，恢复前请先做一次全量备份兜底。</span>
        </el-form-item>
        <div class="form-section">保留策略</div>
        <el-form-item label="长期保留">
          <el-switch v-model="form.keep" />
          <span class="form-tip">开启后自动清理任务会跳过该备份；关闭则按保留天数自动清理。</span>
        </el-form-item>
      </el-form>
      <template #footer>
        <div class="modal-footer">
          <el-button @click="formVisible = false">取消</el-button>
          <el-button type="primary" @click="handleSave">保存</el-button>
        </div>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { computed, reactive, ref, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Search, Plus } from '@element-plus/icons-vue'
import { useCrud } from '../composables/useCrud'
import { runBackup, restoreBackup, downloadBackup } from '../api/backup'

const loading = computed(() => crud.state.loading)
const query = reactive({ keyword: '', kind: '', status: '' })
const page = ref(1)
const pageSize = 10
const crud = useCrud('backups', { fieldMap: {}, keywordField: ["name","operator"] })
const total = computed(() => crud.state.total)
async function reload() {
  await crud.load({ ...query, page: page.value, per_page: pageSize })
}
const formVisible = ref(false)
const formRef = ref()

const emptyForm = {
  id: '', name: '', kind: '全量', size: '', tables: 0, at: '',
  operator: '', status: '成功', keep: false, note: ''
}
const form = reactive({ ...emptyForm })

const rules = {
  name: [{ required: true, message: '请输入备份文件名', trigger: 'blur' }],
  kind: [{ required: true, message: '请选择备份类型', trigger: 'change' }],
  status: [{ required: true, message: '请选择执行结果', trigger: 'change' }]
}

const filteredData = computed(() => crud.state.rows)

const pagedData = computed(() => crud.state.rows)

// 顶部「恢复数据」按钮恢复最近一次成功备份（也可在行内点「恢复」指定某条）
const latestRestorable = computed(() => crud.state.rows.find((r) => r.status === '成功') || null)

function handleSearch() {
  page.value = 1
  reload()
}

function nowText() {
  const d = new Date()
  const p = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}

// 立即备份：调后端真实执行 mysqldump（不再写死假记录）
function handleBackup() {
  ElMessageBox.confirm('确认立即执行一次全量备份吗？备份期间建议避开业务高峰。', '立即备份', {
    type: 'info', confirmButtonText: '开始备份', cancelButtonText: '取消'
  }).then(async () => {
    try {
      const r = await runBackup('manual')
      ElMessage.success(`备份完成（${r.size} / ${r.tables} 表）`)
      reload()
    } catch (e) {
      ElMessage.error(e.message || '备份失败')
      reload()
    }
  }).catch(() => {})
}

// 恢复：选中某条备份后执行（高风险，二次确认 + 后端 confirm=1）
function handleRestore(row) {
  if (!row) {
    return ElMessage.warning('请先选择要恢复的备份记录')
  }
  ElMessageBox.confirm(
    `恢复「${row.name}」会覆盖当前数据库全部数据（回到该备份时点），确认继续吗？`,
    '恢复数据',
    { type: 'warning', confirmButtonText: '确认恢复', cancelButtonText: '取消' }
  ).then(async () => {
    try {
      await restoreBackup(row.id)
      ElMessage.success('数据已恢复')
      reload()
    } catch (e) {
      ElMessage.error(e.message || '恢复失败')
    }
  }).catch(() => {})
}

// 下载备份文件
async function handleDownload(row) {
  try {
    await downloadBackup(row.id, row.name)
  } catch (e) {
    ElMessage.error(e.message || '下载失败')
  }
}

async function handleKeep(row) {
  await crud.patch(row.id, { keep: row.keep })
  ElMessage.success(`「${row.name}」已${row.keep ? '设为长期保留' : '取消长期保留'}`)
}

function openEdit(row) {
  Object.assign(form, row)
  formVisible.value = true
  formRef.value?.clearValidate()
}

async function handleSave() {
  const valid = await formRef.value.validate().catch(() => false)
  if (!valid) return
  await crud.save(form.id || null, { ...form })
  ElMessage.success('保存成功')
  formVisible.value = false
  reload()
}

function handleDelete(row) {
  ElMessageBox.confirm(`确认删除备份「${row.name}」吗？删除后无法恢复该备份点。`, '删除备份', {
    type: 'warning', confirmButtonText: '确认删除', cancelButtonText: '取消'
  }).then(async () => {
    await crud.remove(row.id)
    ElMessage.success('删除成功')
    reload()
  }).catch(() => {})
}

onMounted(() => {
  reload()
})
</script>

<style scoped>
.form-section {
  font-size: var(--font-size-heading);
  font-weight: 600;
  color: var(--text-primary);
  margin: 0 0 var(--space-sm);
  padding-left: 8px;
  border-left: 3px solid var(--primary);
  line-height: 1.2;
}
.form-tip {
  width: 100%;
  flex-basis: 100%;
  color: var(--text-tertiary);
  font-size: var(--font-size-body);
  line-height: 1.6;
  margin-top: 4px;
}
:deep(.list-dialog) {
  margin-top: 4vh;
}
:deep(.list-dialog .el-dialog__body) {
  max-height: calc(88vh - 110px);
  overflow-y: auto;
  overflow-x: hidden;
  padding-top: var(--space-sm);
}
</style>
