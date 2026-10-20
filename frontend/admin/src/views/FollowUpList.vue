<template>
  <div class="content">
    <div class="content-card content-card--list">
      <div class="action-bar">
        <h2 class="page-title">回访任务</h2>
        <div class="action-bar__left">
          <el-input v-model="query.keyword" placeholder="订单号 / 客户 / 技师" clearable style="width: 200px"
            @clear="handleSearch" @keyup.enter="handleSearch">
            <template #prefix><el-icon><Search /></el-icon></template>
          </el-input>
          <el-select v-model="query.kind" placeholder="任务类型" clearable style="width: 140px" @change="handleSearch">
            <el-option label="服务后回访" value="服务后回访" />
            <el-option label="投诉跟进" value="投诉跟进" />
            <el-option label="流失唤醒" value="流失唤醒" />
            <el-option label="会员到期提醒" value="会员到期提醒" />
          </el-select>
          <el-select v-model="query.status" placeholder="处理状态" clearable style="width: 120px" @change="handleSearch">
            <el-option label="待处理" value="待处理" />
            <el-option label="处理中" value="处理中" />
            <el-option label="已完成" value="已完成" />
            <el-option label="已关闭" value="已关闭" />
          </el-select>
          <el-input v-model="query.owner" placeholder="负责人" clearable style="width: 120px"
            @clear="handleSearch" @keyup.enter="handleSearch" />
          <el-button :icon="Search" @click="handleSearch">查询</el-button>
        </div>
        <div class="action-bar__right">
          <el-button type="primary" :icon="Plus" @click="openCreate">新建任务</el-button>
        </div>
      </div>
      <div class="remind-bar" v-if="remind.today > 0 || remind.overdue > 0">
        <el-icon><Bell /></el-icon>
        <span v-if="remind.today > 0" class="remind-bar__item remind-bar__item--today">今日待回访 <b>{{ remind.today }}</b> 条</span>
        <span v-if="remind.overdue > 0" class="remind-bar__item remind-bar__item--overdue">已逾期 <b>{{ remind.overdue }}</b> 条，请尽快跟进</span>
      </div>
      <div class="table-wrap" v-loading="loading">
        <el-table :data="pagedData" empty-text="暂无回访任务">
          <el-table-column prop="order" label="关联订单" min-width="150" />
          <el-table-column prop="customer" label="客户" min-width="90" />
          <el-table-column prop="technician" label="技师" min-width="90" />
          <el-table-column label="任务类型" width="120">
            <template #default="{ row }">
              <el-tag size="small" :type="kindType(row.kind)">{{ row.kind }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="计划时间" min-width="170">
            <template #default="{ row }">
              <span>{{ row.dueAt }}</span>
              <el-tag v-if="dueFlag(row)" size="small" :type="dueFlag(row).type" style="margin-left: 6px">{{ dueFlag(row).label }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column prop="owner" label="负责人" width="90" />
          <el-table-column label="状态" width="100">
            <template #default="{ row }">
              <el-tag size="small" :type="statusType(row.status)">{{ row.status }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column prop="result" label="处理结果" min-width="180" show-overflow-tooltip>
            <template #default="{ row }">{{ row.result || '-' }}</template>
          </el-table-column>
          <el-table-column label="操作" width="230" fixed="right">
            <template #default="{ row }">
              <el-button link type="primary" size="small" @click="openTransition(row)">处理</el-button>
              <el-button link type="primary" size="small" @click="handleAssign(row)">指派</el-button>
              <el-button link type="info" size="small" @click="openTimeline(row)">时间线</el-button>
              <el-button link type="danger" size="small" @click="handleDelete(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
        <el-empty v-if="!loading && filteredData.length === 0" description="暂无回访任务">
          <el-button type="primary" @click="openCreate">新建任务</el-button>
        </el-empty>
      </div>
      <div class="pagination-bar">
        <el-pagination v-model:current-page="page" :page-size="pageSize"
          :total="total" layout="total, prev, pager, next" background size="small" />
      </div>
    </div>

    <el-dialog v-model="formVisible" :title="editing ? '编辑任务' : '新建任务'" width="620px" class="list-dialog">
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top">
        <div class="form-section">任务信息</div>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="关联订单" prop="order">
              <el-input v-model="form.order" placeholder="如：BK20261003001" maxlength="20" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="任务类型" prop="kind">
              <el-select v-model="form.kind" style="width: 100%">
                <el-option label="服务后回访" value="服务后回访" />
                <el-option label="投诉跟进" value="投诉跟进" />
                <el-option label="流失唤醒" value="流失唤醒" />
                <el-option label="会员到期提醒" value="会员到期提醒" />
              </el-select>
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="客户" prop="customer">
              <el-input v-model="form.customer" maxlength="20" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="技师">
              <el-input v-model="form.technician" placeholder="可留空" maxlength="20" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="计划时间" prop="dueAt">
              <el-date-picker v-model="form.dueAt" type="datetime" value-format="YYYY-MM-DD HH:mm"
                placeholder="选择计划回访时间" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="负责人" prop="owner">
              <el-input v-model="form.owner" maxlength="20" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="处理状态" prop="status">
              <el-select v-model="form.status" style="width: 100%">
                <el-option label="待处理" value="待处理" />
                <el-option label="处理中" value="处理中" />
                <el-option label="已完成" value="已完成" />
                <el-option label="已关闭" value="已关闭" />
              </el-select>
            </el-form-item>
          </el-col>
        </el-row>
        <el-form-item label="处理结果">
          <el-input v-model="form.result" type="textarea" :rows="3" placeholder="记录回访沟通结果，如：客户表示满意，下月复购" maxlength="200" />
          <span class="form-tip">服务完成后系统按规则自动生成回访任务，也可在此手动创建；状态流转请在列表「处理」中操作（服务端校验合法性并留痕）。</span>
        </el-form-item>
      </el-form>
      <template #footer>
        <div class="modal-footer">
          <el-button @click="formVisible = false">取消</el-button>
          <el-button type="primary" @click="handleSave">保存</el-button>
        </div>
      </template>
    </el-dialog>

    <!-- 处理（状态流转）：目标状态由服务端状态机给出，终态必须填写处理结果 -->
    <el-dialog v-model="transVisible" title="处理回访任务" width="520px" class="list-dialog">
      <div class="flow-head">
        <span class="flow-head__label">当前状态</span>
        <el-tag size="small" :type="statusType(transRow.status)">{{ transRow.status }}</el-tag>
        <span class="flow-head__order">{{ transRow.order }} · {{ transRow.customer }}</span>
      </div>
      <el-form label-position="top">
        <el-form-item label="流转到">
          <el-select v-model="transForm.to" placeholder="选择目标状态" style="width: 100%" :disabled="transTargets.length === 0">
            <el-option v-for="s in transTargets" :key="s" :label="s" :value="s" />
          </el-select>
          <span v-if="transTargets.length === 0" class="form-tip">「{{ transRow.status }}」为终态，不可再流转。</span>
        </el-form-item>
        <el-form-item label="负责人">
          <el-input v-model="transForm.owner" maxlength="20" placeholder="留空则保持当前负责人（流转到处理中时默认记为当前操作人）" />
        </el-form-item>
        <el-form-item :label="`处理结果${needResult ? '（必填）' : ''}`">
          <el-input v-model="transForm.result" type="textarea" :rows="3" maxlength="200"
            placeholder="记录回访沟通结果，如：客户表示满意，下月复购" />
        </el-form-item>
      </el-form>
      <template #footer>
        <div class="modal-footer">
          <el-button @click="transVisible = false">取消</el-button>
          <el-button type="primary" :disabled="transTargets.length === 0" @click="submitTransition">确认流转</el-button>
        </div>
      </template>
    </el-dialog>

    <!-- 流转时间线 -->
    <el-dialog v-model="timelineVisible" title="流转时间线" width="560px" class="list-dialog">
      <el-timeline v-if="timeline.length">
        <el-timeline-item v-for="log in timeline" :key="log.id" :timestamp="log.createdAt" placement="top"
          :type="log.action === 'create' ? 'info' : 'primary'">
          <div class="tl-line">
            <b>{{ actionLabel(log) }}</b>
            <span v-if="log.owner" class="tl-owner">负责人：{{ log.owner }}</span>
          </div>
          <div v-if="log.remark" class="tl-remark">{{ log.remark }}</div>
          <div class="tl-op">操作人：{{ log.operator || '-' }}</div>
        </el-timeline-item>
      </el-timeline>
      <el-empty v-else description="暂无流转记录" />
    </el-dialog>
  </div>
</template>

<script setup>
import { computed, reactive, ref, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Search, Plus, Bell } from '@element-plus/icons-vue'
import { useCrud } from '../composables/useCrud'
import {
  fetchWorkflowMeta, fetchOverdue, fetchWorkflowLogs,
  transitionWorkflow, assignWorkflow
} from '../api/workflow'

const RESOURCE = 'follow-up-tasks'
const loading = computed(() => crud.state.loading)
const query = reactive({ keyword: '', kind: '', status: '', owner: '' })
const page = ref(1)
const pageSize = 10
const crud = useCrud(RESOURCE, { fieldMap: { order: 'order_no' }, keywordField: ['order_no', 'customer'] })
const total = computed(() => crud.state.total)
async function reload() {
  await crud.load({ ...query, page: page.value, per_page: pageSize })
}
const formVisible = ref(false)
const editing = ref(false)
const formRef = ref()

const emptyForm = {
  id: '', order: '', customer: '', technician: '', kind: '服务后回访',
  dueAt: '', owner: '', status: '待处理', result: ''
}
const form = reactive({ ...emptyForm })

const rules = {
  order: [{ required: true, message: '请输入关联订单', trigger: 'blur' }],
  customer: [{ required: true, message: '请输入客户', trigger: 'blur' }],
  kind: [{ required: true, message: '请选择任务类型', trigger: 'change' }],
  dueAt: [{ required: true, message: '请选择计划时间', trigger: 'change' }],
  owner: [{ required: true, message: '请输入负责人', trigger: 'blur' }]
}

// ===== 提醒流：今日待访 / 逾期未处理统计（服务端 workflow/overdue 口径） =====
const remind = reactive({ today: 0, overdue: 0 })
function dateOnly(v) {
  return String(v || '').slice(0, 10)
}
function todayStr() {
  const d = new Date()
  const p = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
}
function dueFlag(row) {
  if (!row.dueAt || row.status === '已完成' || row.status === '已关闭') return null
  const due = dateOnly(row.dueAt)
  const today = todayStr()
  if (due < today) {
    const days = Math.round((new Date(today) - new Date(due)) / 86400000)
    return { label: `逾期 ${days} 天`, type: 'danger' }
  }
  if (due === today) return { label: '今日', type: 'warning' }
  return null
}
async function loadRemind() {
  try {
    const res = await fetchOverdue(RESOURCE)
    const scan = res?.scan?.[RESOURCE] || {}
    remind.today = Number(scan.today || 0)
    remind.overdue = Number(scan.overdue || 0)
  } catch (e) {
    // 统计失败不阻塞列表
  }
}

// ===== 状态机（服务端返回，前端只做渲染） =====
const meta = ref({})
async function loadMeta() {
  try {
    const res = await fetchWorkflowMeta()
    const found = (res?.resources || []).find((r) => r.resource === RESOURCE)
    if (found) meta.value = found
  } catch (e) {
    // 元数据失败时降级为「不可流转」，不影响列表
  }
}

function kindType(kind) {
  return { 服务后回访: 'primary', 投诉跟进: 'danger', 流失唤醒: 'warning', 会员到期提醒: 'info' }[kind] || 'info'
}

function statusType(status) {
  return { 待处理: 'warning', 处理中: 'primary', 已完成: 'success', 已关闭: 'info' }[status] || 'info'
}

const filteredData = computed(() => crud.state.rows)

const pagedData = computed(() => crud.state.rows)

function handleSearch() {
  page.value = 1
  reload()
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
  loadRemind()
}

function handleDelete(row) {
  ElMessageBox.confirm(`确认删除订单「${row.order}」的回访任务吗？`, '删除任务', {
    type: 'warning', confirmButtonText: '确认删除', cancelButtonText: '取消'
  }).then(async () => {
    await crud.remove(row.id)
    ElMessage.success('删除成功')
    reload()
    loadRemind()
  }).catch(() => {})
}

// ===== 处理（状态流转） =====
const transVisible = ref(false)
const transRow = reactive({ id: '', order: '', customer: '', status: '', owner: '' })
const transForm = reactive({ to: '', owner: '', result: '' })

const transTargets = computed(() => (meta.value?.flow?.[transRow.status] || []))
const needResult = computed(() => transTargets.value.includes('已完成') && transForm.to === '已完成'
  || transTargets.value.includes('已关闭') && transForm.to === '已关闭')

function openTransition(row) {
  transRow.id = row.id
  transRow.order = row.order
  transRow.customer = row.customer
  transRow.status = row.status
  transRow.owner = row.owner
  transForm.to = (meta.value?.flow?.[row.status] || [])[0] || ''
  transForm.owner = row.owner || ''
  transForm.result = ''
  transVisible.value = true
}

async function submitTransition() {
  if (!transForm.to) return
  if (needResult.value && !transForm.result.trim()) {
    ElMessage.warning('流转到终态必须填写处理结果')
    return
  }
  try {
    await transitionWorkflow(RESOURCE, transRow.id, {
      to: transForm.to,
      result: transForm.result,
      owner: transForm.owner
    })
    ElMessage.success(`状态已更新为「${transForm.to}」`)
    transVisible.value = false
    reload()
    loadRemind()
  } catch (e) {
    // request 已提示错误
  }
}

// ===== 指派负责人 =====
function handleAssign(row) {
  ElMessageBox.prompt('请输入负责人姓名', `指派「${row.order}」`, {
    inputValue: row.owner || '',
    confirmButtonText: '指派',
    cancelButtonText: '取消',
    inputValidator: (v) => (v && v.trim() ? true : '负责人不能为空')
  }).then(async ({ value }) => {
    try {
      await assignWorkflow(RESOURCE, row.id, value.trim())
      ElMessage.success(`已指派给 ${value.trim()}`)
      reload()
    } catch (e) {
      // request 已提示错误
    }
  }).catch(() => {})
}

// ===== 时间线 =====
const timelineVisible = ref(false)
const timeline = ref([])

function actionLabel(log) {
  if (log.action === 'create') return '创建任务'
  if (log.action === 'assign') return `指派负责人：${log.owner || '-'}`
  return `状态流转：${log.fromStatus || '-'} → ${log.toStatus || '-'}`
}

async function openTimeline(row) {
  timeline.value = []
  timelineVisible.value = true
  try {
    const res = await fetchWorkflowLogs(RESOURCE, row.id)
    timeline.value = (res?.logs || []).map((l) => ({
      id: l.id,
      action: l.action,
      fromStatus: l.from_status,
      toStatus: l.to_status,
      owner: l.owner,
      remark: l.remark,
      operator: l.operator,
      createdAt: l.created_at
    }))
  } catch (e) {
    // request 已提示错误
  }
}

onMounted(() => {
  reload()
  loadRemind()
  loadMeta()
})
</script>

<style scoped>
.remind-bar {
  display: flex;
  align-items: center;
  gap: var(--space-md);
  padding: 10px 14px;
  margin-bottom: var(--space-sm);
  border-radius: 6px;
  background: var(--warning-light, #fdf6ec);
  color: var(--text-primary);
  font-size: var(--font-size-body);
}
.remind-bar__item b {
  margin: 0 2px;
}
.remind-bar__item--today {
  color: var(--el-color-warning);
}
.remind-bar__item--overdue {
  color: var(--el-color-danger);
}
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
.flow-head {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: var(--space-sm);
  padding: 8px 12px;
  border-radius: 6px;
  background: var(--bg-subtle, #f5f7fa);
}
.flow-head__label {
  color: var(--text-tertiary);
  font-size: var(--font-size-body);
}
.flow-head__order {
  margin-left: auto;
  color: var(--text-secondary);
  font-size: var(--font-size-body);
}
.tl-line {
  display: flex;
  align-items: center;
  gap: 10px;
}
.tl-owner {
  color: var(--text-tertiary);
  font-size: var(--font-size-body);
}
.tl-remark {
  margin-top: 2px;
  color: var(--text-secondary);
  font-size: var(--font-size-body);
}
.tl-op {
  margin-top: 2px;
  color: var(--text-tertiary);
  font-size: var(--font-size-body);
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
