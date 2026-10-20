<template>
  <div class="content">
    <div class="content-card content-card--list">
      <div class="action-bar">
        <h2 class="page-title">版本升级</h2>
        <div class="action-bar__left">
          <el-input v-model="query.keyword" placeholder="版本号 / 操作人 / 变更说明" clearable style="width: 200px"
            @clear="handleSearch" @keyup.enter="handleSearch">
            <template #prefix><el-icon><Search /></el-icon></template>
          </el-input>
          <el-select v-model="query.status" placeholder="升级结果" clearable style="width: 120px" @change="handleSearch">
            <el-option label="成功" value="成功" />
            <el-option label="失败" value="失败" />
          </el-select>
          <el-button :icon="Search" @click="handleSearch">查询</el-button>
        </div>
        <div class="action-bar__right">
          <el-button :icon="Search" :loading="checking" @click="handleCheck">检查更新</el-button>
          <el-button v-if="hasUpdate" type="warning" :icon="Upload" :loading="upgrading" @click="handleUpgrade">立即升级</el-button>
          <el-button type="primary" :icon="Plus" @click="openCreate">补录记录</el-button>
        </div>
      </div>
      <div class="version-bar">
        <div class="summary-item"><span class="summary-label">当前版本</span><span class="summary-value">{{ installedText || '—' }}</span></div>
        <div class="summary-item"><span class="summary-label">最新版本</span><span class="summary-value">{{ latestText || '—' }}</span></div>
        <div class="summary-item">
          <span class="summary-label">更新状态</span>
          <span class="summary-value" :class="hasUpdate ? 'is-warn' : 'is-plus'">
            {{ hasUpdate ? ('可升级 ' + (pending.length) + ' 个版本') : '已是最新' }}
          </span>
        </div>
      </div>
      <div class="table-wrap" v-loading="loading">
        <el-table :data="pagedData" empty-text="暂无升级记录">
          <el-table-column prop="from" label="原版本" width="100" />
          <el-table-column prop="to" label="目标版本" width="100" />
          <el-table-column prop="at" label="升级时间" min-width="150" />
          <el-table-column prop="operator" label="操作人" width="100" />
          <el-table-column prop="duration" label="耗时" width="100" />
          <el-table-column label="结果" width="90">
            <template #default="{ row }">
              <el-tag size="small" :type="row.status === '成功' ? 'success' : 'danger'">{{ row.status }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column prop="note" label="变更说明" min-width="220" show-overflow-tooltip />
          <el-table-column label="操作" width="180" fixed="right">
            <template #default="{ row }">
              <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
              <el-button v-if="row.status === '成功' && canRollback(row)" link type="warning" size="small"
                @click="handleRollback(row)">回滚</el-button>
              <el-button link type="danger" size="small" @click="handleDelete(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
        <el-empty v-if="!loading && filteredData.length === 0" description="暂无升级记录">
          <el-button type="primary" @click="openCreate">补录记录</el-button>
        </el-empty>
      </div>
      <div class="pagination-bar">
        <el-pagination v-model:current-page="page" :page-size="pageSize"
          :total="total" layout="total, prev, pager, next" background size="small" />
      </div>
    </div>

    <el-dialog v-model="formVisible" :title="editing ? '编辑记录' : '补录记录'" width="620px" class="list-dialog">
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top">
        <div class="form-section">升级信息</div>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="原版本" prop="from">
              <el-input v-model="form.from" placeholder="如：v1.2.0" maxlength="15" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="目标版本" prop="to">
              <el-input v-model="form.to" placeholder="如：v1.3.0" maxlength="15" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="升级时间">
              <el-date-picker v-model="form.at" type="datetime" value-format="YYYY-MM-DD HH:mm"
                placeholder="选择升级时间" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="操作人" prop="operator">
              <el-input v-model="form.operator" maxlength="20" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="耗时">
              <el-input v-model="form.duration" placeholder="如：2 分 14 秒" maxlength="15" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="升级结果" prop="status">
              <el-select v-model="form.status" style="width: 100%">
                <el-option label="成功" value="成功" />
                <el-option label="失败" value="失败" />
              </el-select>
            </el-form-item>
          </el-col>
        </el-row>
        <el-form-item label="变更说明">
          <el-input v-model="form.note" type="textarea" :rows="3" placeholder="如：新增次卡跨店通用、派单权重配置" maxlength="200" />
          <span class="form-tip">在线升级前系统自动做一次全量备份；升级失败会自动回滚到升级前版本，失败原因记入变更说明。</span>
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
import { Search, Plus, Upload } from '@element-plus/icons-vue'
import { useCrud } from '../composables/useCrud'
import { checkUpgrade, runUpgrade, rollbackUpgrade, upgradeStatus } from '../api/upgrade'

const loading = computed(() => crud.state.loading)
const query = reactive({ keyword: '', status: '' })
const page = ref(1)
const pageSize = 10
const crud = useCrud('upgrade-records', { fieldMap: {"from":"from_version","to":"to_version"}, keywordField: ["operator","note"] })
const total = computed(() => crud.state.total)
async function reload() {
  await crud.load({ ...query, page: page.value, per_page: pageSize })
}

// ---- 在线升级状态 ----
const installedText = ref('')
const latestText = ref('')
const hasUpdate = ref(false)
const pending = ref([])
const checking = ref(false)
const upgrading = ref(false)

async function loadStatus() {
  try {
    const s = await upgradeStatus()
    installedText.value = s.installed_text || ('v' + s.installed)
    latestText.value = s.latest_text || ('v' + s.latest)
    hasUpdate.value = !!s.hasUpdate
    pending.value = s.pending || []
  } catch (e) { /* 后端不可达时静默（列表兜底 mock 已给出提示） */ }
}

/** 仅「含升级前备份」的成功记录可回滚 */
function canRollback(row) {
  return /升级前备份/.test(row.note || '')
}

const formVisible = ref(false)
const editing = ref(false)
const formRef = ref()

const emptyForm = {
  id: '', from: '', to: '', at: '', operator: '', duration: '', status: '成功', note: ''
}
const form = reactive({ ...emptyForm })

const rules = {
  from: [{ required: true, message: '请输入原版本', trigger: 'blur' }],
  to: [{ required: true, message: '请输入目标版本', trigger: 'blur' }],
  operator: [{ required: true, message: '请输入操作人', trigger: 'blur' }],
  status: [{ required: true, message: '请选择升级结果', trigger: 'change' }]
}

const filteredData = computed(() => crud.state.rows)

const pagedData = computed(() => crud.state.rows)

function handleSearch() {
  page.value = 1
  reload()
}

function handleCheck() {
  checking.value = true
  checkUpgrade().then((r) => {
    hasUpdate.value = !!r.hasUpdate
    pending.value = r.pending || []
    installedText.value = 'v' + r.installed
    latestText.value = 'v' + r.latest
    if (r.hasUpdate) {
      const to = (r.pending || []).map((p) => 'v' + p.version).join('、')
      ElMessage.warning(`发现新版本 ${to}，可点击「立即升级」在线升级`)
    } else {
      ElMessage.success('当前已是最新版本 ' + installedText.value)
    }
  }).catch(() => {
    ElMessage.error('检查更新失败')
  }).finally(() => { checking.value = false })
}

function handleUpgrade() {
  const to = pending.value.length ? ('v' + pending.value[pending.value.length - 1].version) : latestText.value
  const notes = pending.value.map((p) => `v${p.version}：${p.note || '—'}`).join('\n')
  ElMessageBox.confirm(
    `将升级到 ${to}。\n\n${notes}\n\n升级前系统会自动做一次全量备份；升级失败可回滚到备份。`,
    '在线升级',
    { type: 'warning', confirmButtonText: '开始升级', cancelButtonText: '取消' }
  ).then(async () => {
    upgrading.value = true
    try {
      const res = await runUpgrade('')
      if (res.ok) {
        ElMessage.success(`已升级到 v${res.to}（耗时 ${res.duration}）`)
      } else {
        ElMessage.error('升级失败：' + (res.error || '未知错误'))
      }
      await loadStatus()
      reload()
    } catch (e) {
      ElMessage.error(e.message || '升级失败')
    } finally {
      upgrading.value = false
    }
  }).catch(() => {})
}

function handleRollback(row) {
  ElMessageBox.confirm(
    `确认回滚「${row.from_version} → ${row.to_version}」这次升级？\n将恢复升级前的全量备份（表结构与数据一并回退）。`,
    '回滚升级',
    { type: 'warning', confirmButtonText: '确认回滚', cancelButtonText: '取消' }
  ).then(async () => {
    try {
      const res = await rollbackUpgrade(row.id)
      ElMessage.success('已回滚到 ' + res.version)
      await loadStatus()
      reload()
    } catch (e) {
      ElMessage.error(e.message || '回滚失败')
    }
  }).catch(() => {})
}

function nowText() {
  const d = new Date()
  const p = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}

function openCreate() {
  editing.value = false
  Object.assign(form, emptyForm)
  formVisible.value = true
  formRef.value?.clearValidate()
}

function openEdit(row) {
  editing.value = true
  Object.assign(form, row)
  formVisible.value = true
  formRef.value?.clearValidate()
}

async function handleSave() {
  const valid = await formRef.value.validate().catch(() => false)
  if (!valid) return
  await crud.save(editing.value ? form.id : null, { ...form })
  ElMessage.success(editing.value ? '保存成功' : '新建成功')
  formVisible.value = false
  reload()
}

function handleDelete(row) {
  ElMessageBox.confirm(`确认删除「${row.from} → ${row.to}」的升级记录吗？`, '删除记录', {
    type: 'warning', confirmButtonText: '确认删除', cancelButtonText: '取消'
  }).then(async () => {
    await crud.remove(row.id)
    ElMessage.success('删除成功')
    reload()
  }).catch(() => {})
}

onMounted(() => {
  reload()
  loadStatus()
})
</script>

<style scoped>
.version-bar {
  display: flex;
  gap: var(--space-lg);
  padding: var(--space-sm) var(--space-md);
  margin-bottom: var(--space-sm);
  background: var(--bg-page);
  border-radius: var(--radius-sm);
}
.summary-item {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.summary-label {
  color: var(--text-tertiary);
  font-size: var(--font-size-body);
}
.summary-value {
  font-size: var(--font-size-heading);
  font-weight: 600;
  color: var(--text-primary);
}
.summary-value.is-plus { color: var(--success); }
.summary-value.is-warn { color: var(--warning); }
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
