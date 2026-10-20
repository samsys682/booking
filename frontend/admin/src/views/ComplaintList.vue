<template>
  <div class="content">
    <div class="content-card content-card--list">
      <div class="action-bar">
        <h2 class="page-title">投诉工单</h2>
        <div class="action-bar__left">
          <el-input v-model="query.keyword" placeholder="订单号 / 客户 / 被投诉人" clearable style="width: 200px"
            @clear="handleSearch" @keyup.enter="handleSearch">
            <template #prefix><el-icon><Search /></el-icon></template>
          </el-input>
          <el-select v-model="query.kind" placeholder="投诉类型" clearable style="width: 130px" @change="handleSearch">
            <el-option label="服务质量" value="服务质量" />
            <el-option label="服务态度" value="服务态度" />
            <el-option label="收费争议" value="收费争议" />
          </el-select>
          <el-select v-model="query.status" placeholder="处理状态" clearable style="width: 120px" @change="handleSearch">
            <el-option label="待受理" value="待受理" />
            <el-option label="处理中" value="处理中" />
            <el-option label="已完成" value="已完成" />
          </el-select>
          <el-button :icon="Search" @click="handleSearch">查询</el-button>
        </div>
        <div class="action-bar__right">
          <el-button type="primary" :icon="Plus" @click="openCreate">新建工单</el-button>
        </div>
      </div>
      <div class="remind-bar" v-if="remind.today > 0 || remind.overdue > 0">
        <el-icon><Bell /></el-icon>
        <span v-if="remind.today > 0" class="remind-bar__item remind-bar__item--today">今日新增待受理 <b>{{ remind.today }}</b> 条</span>
        <span v-if="remind.overdue > 0" class="remind-bar__item remind-bar__item--overdue">已超时 <b>{{ remind.overdue }}</b> 条（受理 24h / 处理 72h）</span>
      </div>
      <div class="table-wrap" v-loading="loading">
        <el-table :data="pagedData" empty-text="暂无投诉工单">
          <el-table-column prop="order" label="关联订单" min-width="150" />
          <el-table-column prop="customer" label="客户" min-width="85" />
          <el-table-column prop="target" label="被投诉人" min-width="85" />
          <el-table-column label="类型" width="100">
            <template #default="{ row }">
              <el-tag size="small" :type="kindType(row.kind)">{{ row.kind }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="等级" width="80">
            <template #default="{ row }">
              <el-tag size="small" :type="row.level === '严重' ? 'danger' : 'warning'">{{ row.level }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column prop="content" label="投诉内容" min-width="190" show-overflow-tooltip />
          <el-table-column prop="createdAt" label="投诉时间" min-width="150" />
          <el-table-column prop="owner" label="处理人" width="90" />
          <el-table-column label="状态" width="100">
            <template #default="{ row }">
              <el-tag size="small" :type="statusType(row.status)">{{ row.status }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column prop="resolution" label="处理结果" min-width="180" show-overflow-tooltip>
            <template #default="{ row }">{{ row.resolution || '-' }}</template>
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
        <el-empty v-if="!loading && filteredData.length === 0" description="暂无投诉工单">
          <el-button type="primary" @click="openCreate">新建工单</el-button>
        </el-empty>
      </div>
      <div class="pagination-bar">
        <el-pagination v-model:current-page="page" :page-size="pageSize"
          :total="total" layout="total, prev, pager, next" background size="small" />
      </div>
    </div>

    <el-dialog v-model="formVisible" :title="editing ? '编辑投诉' : '新建工单'" width="620px" class="list-dialog">
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top">
        <div class="form-section">投诉信息</div>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="关联订单" prop="order">
              <el-input v-model="form.order" placeholder="如：BK20261002005" maxlength="20" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="客户" prop="customer">
              <el-input v-model="form.customer" maxlength="20" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="被投诉人" prop="target">
              <el-input v-model="form.target" placeholder="技师 / 商家" maxlength="20" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="投诉类型" prop="kind">
              <el-select v-model="form.kind" style="width: 100%">
                <el-option label="服务质量" value="服务质量" />
                <el-option label="服务态度" value="服务态度" />
                <el-option label="收费争议" value="收费争议" />
              </el-select>
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="严重等级" prop="level">
              <el-radio-group v-model="form.level">
                <el-radio-button label="一般">一般</el-radio-button>
                <el-radio-button label="严重">严重</el-radio-button>
              </el-radio-group>
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="处理状态" prop="status">
              <el-select v-model="form.status" style="width: 100%">
                <el-option label="待受理" value="待受理" />
                <el-option label="处理中" value="处理中" />
                <el-option label="已完成" value="已完成" />
              </el-select>
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="处理人" prop="owner">
              <el-input v-model="form.owner" maxlength="20" />
            </el-form-item>
          </el-col>
        </el-row>
        <el-form-item label="投诉内容" prop="content">
          <el-input v-model="form.content" type="textarea" :rows="2" placeholder="客户投诉的具体情况" maxlength="200" />
        </el-form-item>
        <el-form-item label="处理结果">
          <el-input v-model="form.resolution" type="textarea" :rows="2" placeholder="如：退还 ¥50 并对技师记过一次" maxlength="200" />
          <span class="form-tip">升级链路：受理 → 处理 → 完成。状态流转请在列表「处理」中操作（服务端校验合法性并留痕）；严重投诉请同步在回访任务中创建跟进。</span>
        </el-form-item>
      </el-form>
      <template #footer>
        <div class="modal-footer">
          <el-button @click="formVisible = false">取消</el-button>
          <el-button type="primary" @click="handleSave">保存</el-button>
        </div>
      </template>
    </el-dialog>

    <!-- 处理（状态流转）：目标状态由服务端状态机给出，完成必须填写处理结果 -->
    <el-dialog v-model="transVisible" title="处理投诉工单" width="520px" class="list-dialog">
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
        <el-form-item label="处理人">
          <el-input v-model="transForm.owner" maxlength="20" placeholder="留空则保持当前处理人（流转到处理中时默认记为当前操作人）" />
        </el-form-item>
        <el-form-item :label="`处理结果${needResult ? '（必填）' : ''}`">
          <el-input v-model="transForm.resolution" type="textarea" :rows="3" maxlength="200"
            placeholder="如：退还 ¥50 并对技师记过一次" />
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
            <span v-if="log.owner" class="tl-owner">处理人：{{ log.owner }}</span>
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

const RESOURCE = 'complaints'
const loading = computed(() => crud.state.loading)
const query = reactive({ keyword: '', kind: '', status: '' })
const page = ref(1)
const pageSize = 10
const crud = useCrud(RESOURCE, { fieldMap: { order: 'order_no', createdAt: 'created_at' }, keywordField: ['order_no', 'customer'] })
const total = computed(() => crud.state.total)
async function reload() {
  await crud.load({ ...query, page: page.value, per_page: pageSize })
}
const formVisible = ref(false)
const editing = ref(false)
const formRef = ref()

const emptyForm = {
  id: '', order: '', customer: '', target: '', kind: '服务质量', level: '一般',
  content: '', createdAt: '', owner: '', status: '待受理', resolution: ''
}
const form = reactive({ ...emptyForm })

const rules = {
  order: [{ required: true, message: '请输入关联订单', trigger: 'blur' }],
  customer: [{ required: true, message: '请输入客户', trigger: 'blur' }],
  target: [{ required: true, message: '请输入被投诉人', trigger: 'blur' }],
  kind: [{ required: true, message: '请选择投诉类型', trigger: 'change' }],
  content: [{ required: true, message: '请输入投诉内容', trigger: 'blur' }],
  owner: [{ required: true, message: '请输入处理人', trigger: 'blur' }]
}

function kindType(kind) {
  return { 服务质量: 'warning', 服务态度: 'danger', 收费争议: 'primary' }[kind] || 'info'
}

function statusType(status) {
  return { 待受理: 'warning', 处理中: 'primary', 已完成: 'success' }[status] || 'info'
}

// ===== 提醒流：超时统计（服务端 workflow/overdue 口径） =====
const remind = reactive({ today: 0, overdue: 0 })
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

// ===== 状态机（服务端返回） =====
const meta = ref({})
async function loadMeta() {
  try {
    const res = await fetchWorkflowMeta()
    const found = (res?.resources || []).find((r) => r.resource === RESOURCE)
    if (found) meta.value = found
  } catch (e) {
    // 降级为不可流转
  }
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
  ElMessageBox.confirm(`确认删除投诉工单「${row.id}」吗？处理记录将一并移除。`, '删除工单', {
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
const transRow = reactive({ id: '', order: '', customer: '', status: '' })
const transForm = reactive({ to: '', owner: '', resolution: '' })

const transTargets = computed(() => (meta.value?.flow?.[transRow.status] || []))
const needResult = computed(() => transForm.to === '已完成')

function openTransition(row) {
  transRow.id = row.id
  transRow.order = row.order
  transRow.customer = row.customer
  transRow.status = row.status
  transForm.to = (meta.value?.flow?.[row.status] || [])[0] || ''
  transForm.owner = row.owner || ''
  transForm.resolution = ''
  transVisible.value = true
}

async function submitTransition() {
  if (!transForm.to) return
  if (needResult.value && !transForm.resolution.trim()) {
    ElMessage.warning('完成投诉必须填写处理结果')
    return
  }
  try {
    await transitionWorkflow(RESOURCE, transRow.id, {
      to: transForm.to,
      resolution: transForm.resolution,
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

// ===== 指派处理人 =====
function handleAssign(row) {
  ElMessageBox.prompt('请输入处理人姓名', `指派工单「${row.order}」`, {
    inputValue: row.owner || '',
    confirmButtonText: '指派',
    cancelButtonText: '取消',
    inputValidator: (v) => (v && v.trim() ? true : '处理人不能为空')
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
  if (log.action === 'create') return '创建工单'
  if (log.action === 'assign') return `指派处理人：${log.owner || '-'}`
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
