<template>
  <div class="content">
    <div class="content-card content-card--list">
      <div class="action-bar">
        <h2 class="page-title">发票管理</h2>
        <div class="action-bar__left">
          <el-input v-model="query.keyword" placeholder="订单号 / 抬头 / 会员" clearable style="width: 200px"
            @clear="handleSearch" @keyup.enter="handleSearch">
            <template #prefix><el-icon><Search /></el-icon></template>
          </el-input>
          <el-select v-model="query.type" placeholder="发票类型" clearable style="width: 130px" @change="handleSearch">
            <el-option label="电子普票" value="电子普票" />
            <el-option label="电子专票" value="电子专票" />
          </el-select>
          <el-select v-model="query.status" placeholder="开票状态" clearable style="width: 120px" @change="handleSearch">
            <el-option label="已开具" value="已开具" />
            <el-option label="开具中" value="开具中" />
            <el-option label="已驳回" value="已驳回" />
          </el-select>
          <el-button :icon="Search" @click="handleSearch">查询</el-button>
        </div>
        <div class="action-bar__right">
          <el-button type="primary" :icon="Plus" @click="openCreate">新建开票</el-button>
        </div>
      </div>
      <div class="table-wrap" v-loading="loading">
        <el-table :data="pagedData" empty-text="暂无开票记录">
          <el-table-column prop="order" label="订单号" min-width="150" />
          <el-table-column prop="member" label="会员" min-width="90" />
          <el-table-column label="类型" width="100">
            <template #default="{ row }">
              <el-tag size="small" :type="row.type === '电子专票' ? 'warning' : 'primary'">{{ row.type }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="抬头" min-width="180" show-overflow-tooltip>
            <template #default="{ row }">
              <el-tag size="small" type="info" style="margin-right: 6px">{{ row.titleType }}</el-tag>{{ row.title }}
            </template>
          </el-table-column>
          <el-table-column prop="taxNo" label="税号" min-width="170" show-overflow-tooltip>
            <template #default="{ row }">{{ row.taxNo || '-' }}</template>
          </el-table-column>
          <el-table-column label="金额" width="90">
            <template #default="{ row }">¥{{ row.amount }}</template>
          </el-table-column>
          <el-table-column label="状态" width="100">
            <template #default="{ row }">
              <el-tag size="small" :type="statusType(row.status)">{{ row.status }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column prop="invoiceNo" label="发票号码" min-width="190" show-overflow-tooltip>
            <template #default="{ row }">{{ row.invoiceNo || row.reason || '-' }}</template>
          </el-table-column>
          <el-table-column label="操作" width="220" fixed="right">
            <template #default="{ row }">
              <template v-if="row.status === '开具中'">
                <el-button link type="success" size="small" @click="handleIssue(row)">开具</el-button>
                <el-button link type="warning" size="small" @click="handleReject(row)">驳回</el-button>
              </template>
              <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
              <el-button link type="danger" size="small" @click="handleDelete(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
        <el-empty v-if="!loading && filteredData.length === 0" description="暂无开票记录">
          <el-button type="primary" @click="openCreate">新建开票</el-button>
        </el-empty>
      </div>
      <div class="pagination-bar">
        <el-pagination v-model:current-page="page" :page-size="pageSize"
          :total="total" layout="total, prev, pager, next" background size="small" />
      </div>
    </div>

    <el-dialog v-model="formVisible" :title="editing ? '编辑开票' : '新建开票'" width="640px" class="list-dialog">
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top">
        <div class="form-section">开票信息</div>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="订单号" prop="order">
              <el-input v-model="form.order" placeholder="如：BK20261003001" maxlength="20" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="会员" prop="member">
              <el-input v-model="form.member" maxlength="20" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="发票类型" prop="type">
              <el-radio-group v-model="form.type">
                <el-radio-button label="电子普票">电子普票</el-radio-button>
                <el-radio-button label="电子专票">电子专票</el-radio-button>
              </el-radio-group>
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="抬头类型" prop="titleType">
              <el-radio-group v-model="form.titleType">
                <el-radio-button label="个人">个人</el-radio-button>
                <el-radio-button label="企业">企业</el-radio-button>
              </el-radio-group>
            </el-form-item>
          </el-col>
        </el-row>
        <el-form-item label="发票抬头" prop="title">
          <el-input v-model="form.title" placeholder="个人填姓名，企业填公司全称" maxlength="50" />
        </el-form-item>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="纳税人识别号">
              <el-input v-model="form.taxNo" placeholder="企业抬头必填，个人留空" maxlength="30" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="开票金额（元）" prop="amount">
              <el-input-number v-model="form.amount" :min="0" :precision="2" :controls="false" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="开票状态" prop="status">
              <el-select v-model="form.status" style="width: 100%">
                <el-option label="已开具" value="已开具" />
                <el-option label="开具中" value="开具中" />
                <el-option label="已驳回" value="已驳回" />
              </el-select>
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="发票号码">
              <el-input v-model="form.invoiceNo" placeholder="开具成功后由税控系统回填" maxlength="30" />
            </el-form-item>
          </el-col>
        </el-row>
        <el-form-item label="备注 / 驳回原因">
          <el-input v-model="form.reason" type="textarea" :rows="2" placeholder="驳回时填写原因，如：抬头税号有误" maxlength="100" />
          <span class="form-tip">开票金额不得超过订单实付金额；已开具的发票需先冲红再修改抬头。电子发票开具后自动发送到会员微信。</span>
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
import { issueInvoice, rejectInvoice } from '../api/finance'

const loading = computed(() => crud.state.loading)
const query = reactive({ keyword: '', type: '', status: '' })
const page = ref(1)
const pageSize = 10
const crud = useCrud('invoices', { fieldMap: {"order":"order_no","reason":"reject_reason"}, moneyFields: ["amount"], keywordField: ["order_no","member","title"] })
const total = computed(() => crud.state.total)
async function reload() {
  await crud.load({ ...query, page: page.value, per_page: pageSize })
}
const formVisible = ref(false)
const editing = ref(false)
const formRef = ref()

const emptyForm = {
  id: '', order: '', member: '', type: '电子普票', titleType: '个人', title: '',
  taxNo: '', amount: 0, createdAt: '', status: '开具中', invoiceNo: '', reason: ''
}
const form = reactive({ ...emptyForm })

const rules = {
  order: [{ required: true, message: '请输入订单号', trigger: 'blur' }],
  member: [{ required: true, message: '请输入会员', trigger: 'blur' }],
  title: [{ required: true, message: '请输入发票抬头', trigger: 'blur' }],
  amount: [{ required: true, message: '请输入开票金额', trigger: 'blur' }],
  status: [{ required: true, message: '请选择开票状态', trigger: 'change' }]
}

function statusType(status) {
  return { 已开具: 'success', 开具中: 'warning', 已驳回: 'danger' }[status] || 'info'
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
  ElMessageBox.confirm(`确认删除订单「${row.order}」的开票记录吗？已开具发票需先冲红。`, '删除记录', {
    type: 'warning', confirmButtonText: '确认删除', cancelButtonText: '取消'
  }).then(async () => {
    await crud.remove(row.id)
    ElMessage.success('删除成功')
    reload()
  }).catch(() => {})
}

/** 开具发票（发票号可留空自动生成） */
async function handleIssue(row) {
  try {
    const { value } = await ElMessageBox.prompt('可填写发票号码，留空则由系统按 INV+日期+序号自动生成', '开具发票', {
      confirmButtonText: '确认开具', cancelButtonText: '取消', inputPlaceholder: '留空自动生成'
    })
    await issueInvoice(row.id, (value || '').trim())
    ElMessage.success('发票已开具')
    reload()
  } catch (e) {
    if (e !== 'cancel' && e !== 'close') ElMessage.error(e?.message || '开具失败')
  }
}

/** 驳回开票（必填原因） */
async function handleReject(row) {
  try {
    const { value } = await ElMessageBox.prompt('请填写驳回原因', '驳回开票', {
      confirmButtonText: '确认驳回', cancelButtonText: '取消', inputPlaceholder: '如：抬头税号有误',
      inputValidator: (v) => (v && v.trim() ? true : '驳回原因不能为空')
    })
    await rejectInvoice(row.id, value.trim())
    ElMessage.success('已驳回')
    reload()
  } catch (e) {
    if (e !== 'cancel' && e !== 'close') ElMessage.error(e?.message || '驳回失败')
  }
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
