<template>
  <div class="content">
    <div class="content-card content-card--list">
      <div class="action-bar">
        <h2 class="page-title">安全中心</h2>
        <div class="action-bar__left">
          <el-input v-model="query.keyword" placeholder="订单号 / 技师 / 客户" clearable style="width: 200px"
            @clear="handleSearch" @keyup.enter="handleSearch">
            <template #prefix><el-icon><Search /></el-icon></template>
          </el-input>
          <el-select v-model="query.kind" placeholder="事件类型" clearable style="width: 150px" @change="handleSearch">
            <el-option label="一键报警" value="一键报警" />
            <el-option label="超时滞留预警" value="超时滞留预警" />
            <el-option label="行程分享" value="行程分享" />
            <el-option label="隐私号通话" value="隐私号通话" />
          </el-select>
          <el-select v-model="query.status" placeholder="处理状态" clearable style="width: 120px" @change="handleSearch">
            <el-option label="待处理" value="待处理" />
            <el-option label="处理中" value="处理中" />
            <el-option label="已处理" value="已处理" />
            <el-option label="正常" value="正常" />
          </el-select>
          <el-button :icon="Search" @click="handleSearch">查询</el-button>
        </div>
        <div class="action-bar__right">
          <el-badge v-if="pendingCount > 0" :value="pendingCount" type="danger" class="pending-badge">
            <el-tag type="danger" effect="plain">待处理报警</el-tag>
          </el-badge>
          <el-button type="primary" :icon="Plus" @click="openCreate">登记事件</el-button>
        </div>
      </div>
      <div class="table-wrap" v-loading="loading">
        <el-table :data="pagedData" empty-text="暂无安全事件">
          <el-table-column label="事件类型" width="130">
            <template #default="{ row }">
              <el-tag size="small" :type="kindType(row.kind)">{{ row.kind }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column prop="order" label="关联订单" min-width="150" />
          <el-table-column prop="technician" label="技师" min-width="90" />
          <el-table-column prop="customer" label="客户" min-width="90" />
          <el-table-column prop="at" label="发生时间" min-width="150" />
          <el-table-column prop="detail" label="事件详情" min-width="220" show-overflow-tooltip />
          <el-table-column label="状态" width="90">
            <template #default="{ row }">
              <el-tag size="small" :type="statusType(row.status)">{{ row.status }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="140" fixed="right">
            <template #default="{ row }">
              <el-button link type="primary" size="small" @click="openEdit(row)">处理</el-button>
              <el-button link type="danger" size="small" @click="handleDelete(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
        <el-empty v-if="!loading && filteredData.length === 0" description="暂无安全事件">
          <el-button type="primary" @click="openCreate">登记事件</el-button>
        </el-empty>
      </div>
      <div class="pagination-bar">
        <el-pagination v-model:current-page="page" :page-size="pageSize"
          :total="total" layout="total, prev, pager, next" background size="small" />
      </div>
    </div>

    <el-dialog v-model="formVisible" :title="editing ? '处理事件' : '登记事件'" width="640px" class="list-dialog">
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top">
        <div class="form-section">事件信息</div>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="事件类型" prop="kind">
              <el-select v-model="form.kind" style="width: 100%">
                <el-option label="一键报警" value="一键报警" />
                <el-option label="超时滞留预警" value="超时滞留预警" />
                <el-option label="行程分享" value="行程分享" />
                <el-option label="隐私号通话" value="隐私号通话" />
              </el-select>
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="关联订单" prop="order">
              <el-input v-model="form.order" placeholder="如：BK20260930004" maxlength="20" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="技师" prop="technician">
              <el-input v-model="form.technician" maxlength="20" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="客户" prop="customer">
              <el-input v-model="form.customer" maxlength="20" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="发生时间">
              <el-date-picker v-model="form.at" type="datetime" value-format="YYYY-MM-DD HH:mm"
                placeholder="选择发生时间" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="处理状态" prop="status">
              <el-select v-model="form.status" style="width: 100%">
                <el-option label="待处理" value="待处理" />
                <el-option label="处理中" value="处理中" />
                <el-option label="已处理" value="已处理" />
                <el-option label="正常" value="正常" />
              </el-select>
            </el-form-item>
          </el-col>
        </el-row>
        <el-form-item label="事件详情" prop="detail">
          <el-input v-model="form.detail" type="textarea" :rows="3" placeholder="如：技师触发报警，已联系双方核实为误触" maxlength="200" />
          <span class="form-tip">一键报警需 10 分钟内人工核实并回拨；超时滞留预警自动提醒调度。通话走隐私号中转，双方真实号码互不可见，通话记录仅用于纠纷取证。</span>
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
import { list as listResource } from '../api/resource'

const loading = computed(() => crud.state.loading)
const query = reactive({ keyword: '', kind: '', status: '' })
const page = ref(1)
const pageSize = 10
const crud = useCrud('security-events', { fieldMap: {"order":"order_no"}, keywordField: ["kind","order_no","customer"] })
const total = computed(() => crud.state.total)
async function reload() {
  await crud.load({ ...query, page: page.value, per_page: pageSize })
  loadPendingCount()
}
const formVisible = ref(false)
const editing = ref(false)
const formRef = ref()

const emptyForm = {
  id: '', kind: '一键报警', order: '', technician: '', customer: '',
  at: '', detail: '', status: '正常'
}
const form = reactive({ ...emptyForm })

const rules = {
  kind: [{ required: true, message: '请选择事件类型', trigger: 'change' }],
  order: [{ required: true, message: '请输入关联订单', trigger: 'blur' }],
  technician: [{ required: true, message: '请输入技师', trigger: 'blur' }],
  customer: [{ required: true, message: '请输入客户', trigger: 'blur' }],
  detail: [{ required: true, message: '请输入事件详情', trigger: 'blur' }],
  status: [{ required: true, message: '请选择处理状态', trigger: 'change' }]
}

function kindType(kind) {
  return { 一键报警: 'danger', 超时滞留预警: 'warning', 行程分享: 'primary', 隐私号通话: 'info' }[kind] || 'info'
}

// 处理状态流转：技师端一键上报落「待处理」→ 处理中 → 已处理（缺口补齐批次三）
function statusType(status) {
  return { 待处理: 'danger', 处理中: 'warning', 已处理: 'success', 正常: 'primary' }[status] || 'info'
}

// 待处理报警计数（独立轻量请求，不计入列表分页）
const pendingCount = ref(0)
async function loadPendingCount() {
  const res = await listResource('security-events', { status: '待处理', page: 1, per_page: 1 }).catch(() => null)
  pendingCount.value = Number(res?.total || 0)
}

const filteredData = computed(() => crud.state.rows)

const pagedData = computed(() => crud.state.rows)

function handleSearch() {
  page.value = 1
  reload()
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
  ElMessageBox.confirm(`确认删除「${row.kind}」事件记录吗？取证记录删除后无法恢复。`, '删除事件', {
    type: 'warning', confirmButtonText: '确认删除', cancelButtonText: '取消'
  }).then(async () => {
    await crud.remove(row.id)
    ElMessage.success('删除成功')
    reload()
  }).catch(() => {})
}

onMounted(() => {
  reload()
  loadPendingCount()
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
.pending-badge {
  margin-right: 12px;
  vertical-align: middle;
}
:deep(.list-dialog .el-dialog__body) {
  max-height: calc(88vh - 110px);
  overflow-y: auto;
  overflow-x: hidden;
  padding-top: var(--space-sm);
}
</style>
