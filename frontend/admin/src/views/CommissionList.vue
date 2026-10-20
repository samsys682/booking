<template>
  <div class="content">
    <div class="content-card content-card--list">
      <div class="action-bar">
        <h2 class="page-title">提成结算</h2>
        <div class="action-bar__left">
          <el-input v-model="query.keyword" placeholder="技师 / 结算月份" clearable style="width: 200px"
            @clear="handleSearch" @keyup.enter="handleSearch">
            <template #prefix><el-icon><Search /></el-icon></template>
          </el-input>
          <el-select v-model="query.status" placeholder="发放状态" clearable style="width: 130px" @change="handleSearch">
            <el-option label="待发放" value="待发放" />
            <el-option label="已发放" value="已发放" />
            <el-option label="已驳回" value="已驳回" />
          </el-select>
          <el-button :icon="Search" @click="handleSearch">查询</el-button>
          <span class="form-tip">提成比例</span>
          <el-input-number v-model="rate" :min="1" :max="50" :step="1" style="width: 110px" />
          <span class="form-tip">%（新建时按营收自动核算）</span>
        </div>
        <div class="action-bar__right">
          <el-button @click="settleAll">一键发放本月</el-button>
          <el-button type="primary" :icon="Plus" @click="openCreate">新建结算单</el-button>
        </div>
      </div>

      <div class="table-wrap" v-loading="loading">
        <el-table :data="pagedData" empty-text="暂无结算单">
          <el-table-column prop="name" label="技师" width="100" />
          <el-table-column prop="orders" label="完成单量" width="90" />
          <el-table-column label="营收（元）" min-width="100">
            <template #default="{ row }">￥{{ row.revenue }}</template>
          </el-table-column>
          <el-table-column label="提成（元）" min-width="100">
            <template #default="{ row }">￥{{ row.commission }}</template>
          </el-table-column>
          <el-table-column label="奖 / 扣（元）" min-width="110">
            <template #default="{ row }">+{{ row.bonus }} / -{{ row.penalty }}</template>
          </el-table-column>
          <el-table-column label="应发（元）" min-width="110">
            <template #default="{ row }"><span class="balance">￥{{ row.actual }}</span></template>
          </el-table-column>
          <el-table-column prop="month" label="结算月份" width="100" />
          <el-table-column label="状态" width="90">
            <template #default="{ row }">
              <el-tag :type="statusTag(row.status)" size="small">{{ row.status }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="180" fixed="right">
            <template #default="{ row }">
              <el-button v-if="row.status === '待发放'" type="primary" link size="small" @click="settle(row)">发放</el-button>
              <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
              <el-button link type="danger" size="small" @click="handleDelete(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
        <el-empty v-if="!loading && filteredData.length === 0" description="暂无结算单">
          <el-button type="primary" @click="openCreate">新建结算单</el-button>
        </el-empty>
      </div>
      <div class="pagination-bar">
        <el-pagination v-model:current-page="page" :page-size="pageSize"
          :total="total" layout="total, prev, pager, next" background size="small" />
      </div>
    </div>

    <el-dialog v-model="formVisible" :title="editing ? '编辑结算单' : '新建结算单'" width="620px" class="list-dialog">
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top">
        <div class="form-section">结算信息</div>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="技师" prop="name">
              <el-input v-model="form.name" placeholder="技师姓名" maxlength="20" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="结算月份" prop="month">
              <el-input v-model="form.month" placeholder="如：2026-10" maxlength="7" />
            </el-form-item>
          </el-col>
        </el-row>
        <el-row :gutter="16">
          <el-col :span="8">
            <el-form-item label="完成单量">
              <el-input-number v-model="form.orders" :min="0" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="营收（元）">
              <el-input-number v-model="form.revenue" :min="0" :precision="2" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="提成（元）">
              <el-input-number v-model="form.commission" :min="0" :precision="2" style="width: 100%" />
            </el-form-item>
          </el-col>
        </el-row>
        <el-row :gutter="16">
          <el-col :span="8">
            <el-form-item label="奖励（元）">
              <el-input-number v-model="form.bonus" :min="0" :precision="2" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="扣款（元）">
              <el-input-number v-model="form.penalty" :min="0" :precision="2" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="应发（元）">
              <el-input-number v-model="form.actual" :min="0" :precision="2" style="width: 100%" />
            </el-form-item>
          </el-col>
        </el-row>
        <el-form-item>
          <el-button size="small" @click="calcByRate">按 {{ rate }}% 核算提成</el-button>
          <el-button size="small" @click="calcActual">核算应发</el-button>
          <span class="form-tip">应发 = 提成 + 奖励 - 扣款</span>
        </el-form-item>
        <div class="form-section">状态与备注</div>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="发放状态">
              <el-select v-model="form.status" style="width: 100%">
                <el-option label="待发放" value="待发放" />
                <el-option label="已发放" value="已发放" />
                <el-option label="已驳回" value="已驳回" />
              </el-select>
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="备注">
              <el-input v-model="form.note" placeholder="选填" maxlength="50" />
            </el-form-item>
          </el-col>
        </el-row>
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
// 提成结算：比例可调、按营收核算、发放状态流转；结算单支持完整增删改查
import { computed, reactive, ref, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Search, Plus } from '@element-plus/icons-vue'
import { useCrud } from '../composables/useCrud'

const loading = computed(() => crud.state.loading)
const query = reactive({ keyword: '', status: '' })
const page = ref(1)
const pageSize = 10
const rate = ref(15)

const seed = [
  { id: 1, name: '王建国', orders: 18, revenue: 5400, commission: 810, bonus: 100, penalty: 0, actual: 910, month: '2026-10', status: '待发放', note: '' },
  { id: 2, name: '李秀兰', orders: 15, revenue: 4200, commission: 630, bonus: 0, penalty: 50, actual: 580, month: '2026-10', status: '待发放', note: '' },
  { id: 3, name: '张伟明', orders: 11, revenue: 3100, commission: 465, bonus: 0, penalty: 0, actual: 465, month: '2026-09', status: '已发放', note: '9月工资条' }
]

const crud = useCrud('commission-settlements', { fieldMap: { name: 'technician', revenue: 'gross' }, moneyFields: ["revenue","commission","bonus","penalty","actual"], keywordField: ["technician","month"] })
const total = computed(() => crud.state.total)
async function reload() {
  await crud.load({ ...query, page: page.value, per_page: pageSize })
}

const formVisible = ref(false)
const editing = ref(false)
const formRef = ref()

const emptyForm = { id: '', name: '', month: '', orders: 0, revenue: 0, commission: 0, bonus: 0, penalty: 0, actual: 0, status: '待发放', note: '' }
const form = reactive({ ...emptyForm })

const rules = {
  name: [{ required: true, message: '请输入技师姓名', trigger: 'blur' }],
  month: [{ required: true, message: '请输入结算月份', trigger: 'blur' }]
}

const filteredData = computed(() => crud.state.rows)

const pagedData = computed(() => crud.state.rows)

function statusTag(s) {
  return { 待发放: 'warning', 已发放: 'success', 已驳回: 'danger' }[s] || 'info'
}

function handleSearch() {
  page.value = 1
  reload()
}

function openCreate() {
  editing.value = false
  Object.assign(form, emptyForm)
  form.month = new Date().toISOString().slice(0, 7)
  formVisible.value = true
  formRef.value?.clearValidate()
}

function openEdit(row) {
  editing.value = true
  Object.assign(form, row)
  formVisible.value = true
  formRef.value?.clearValidate()
}

function calcByRate() {
  form.commission = Math.round((Number(form.revenue) || 0) * rate.value) / 100
  ElMessage.success(`提成已按 ${rate}% 核算：￥${form.commission}`)
}

function calcActual() {
  form.actual = (Number(form.commission) || 0) + (Number(form.bonus) || 0) - (Number(form.penalty) || 0)
  ElMessage.success(`应发已核算：￥${form.actual}`)
}

async function handleSave() {
  const valid = await formRef.value.validate().catch(() => false)
  if (!valid) return
  await crud.save(editing.value ? form.id : null, { ...form })
  ElMessage.success(editing.value ? '保存成功' : '新建成功')
  formVisible.value = false
  reload()
}

async function settle(row) {
  await crud.patch(row.id, { status: '已发放' })
  ElMessage.success(`${row.name} ${row.month} 工资单已发放：￥${row.actual}`)
  reload()
}

async function settleAll() {
  const pending = crud.state.rows.filter((t) => t.status === '待发放')
  if (!pending.length) {
    ElMessage.info('本月没有待发放的结算单')
    return
  }
  for (const t of pending) await crud.patch(t.id, { status: '已发放' })
  ElMessage.success(`已发放 ${pending.length} 份工资单`)
  reload()
}

function handleDelete(row) {
  ElMessageBox.confirm(`确认删除「${row.name} ${row.month}」的结算单吗？`, '删除结算单', {
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
.page-subtitle {
  color: var(--text-tertiary);
}

.balance {
  color: var(--danger);
  font-weight: 600;
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
  color: var(--text-tertiary);
  font-size: var(--font-size-body);
  line-height: 1.6;
}

:deep(.list-dialog .el-dialog__body) {
  max-height: calc(88vh - 110px);
  overflow-y: auto;
  padding-top: var(--space-sm);
}
</style>
