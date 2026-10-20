<template>
  <div class="content">
    <div class="content-card content-card--list">
      <div class="action-bar">
        <h2 class="page-title">财务对账</h2>
        <div class="action-bar__left">
          <el-input v-model="query.keyword" placeholder="订单号" clearable style="width: 200px"
            @clear="handleSearch" @keyup.enter="handleSearch">
            <template #prefix><el-icon><Search /></el-icon></template>
          </el-input>
          <el-select v-model="query.channel" placeholder="支付渠道" clearable style="width: 130px" @change="handleSearch">
            <el-option label="微信支付" value="微信支付" />
            <el-option label="支付宝" value="支付宝" />
            <el-option label="储值抵扣" value="储值抵扣" />
          </el-select>
          <el-select v-model="query.status" placeholder="对账状态" clearable style="width: 120px" @change="handleSearch">
            <el-option label="已对账" value="已对账" />
            <el-option label="差异" value="差异" />
            <el-option label="待对账" value="待对账" />
          </el-select>
          <el-button :icon="Search" @click="handleSearch">查询</el-button>
        </div>
        <div class="action-bar__right">
          <el-button type="success" :icon="Refresh" @click="handleReconcile">一键对账</el-button>
          <el-button type="primary" :icon="Plus" @click="openCreate">补录流水</el-button>
        </div>
      </div>
      <div class="summary-bar">
        <div class="summary-item"><span class="summary-label">实收合计</span><span class="summary-value">¥{{ totalActual }}</span></div>
        <div class="summary-item"><span class="summary-label">手续费合计</span><span class="summary-value">¥{{ totalFee }}</span></div>
        <div class="summary-item"><span class="summary-label">净入账合计</span><span class="summary-value is-plus">¥{{ totalNet }}</span></div>
        <div class="summary-item"><span class="summary-label">差异笔数</span><span class="summary-value is-minus">{{ diffCount }}</span></div>
      </div>
      <div class="table-wrap" v-loading="loading">
        <el-table :data="pagedData" empty-text="暂无对账流水">
          <el-table-column prop="order" label="订单号" min-width="150" />
          <el-table-column label="渠道" width="100">
            <template #default="{ row }">
              <el-tag size="small" :type="channelType(row.channel)">{{ row.channel }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="订单金额" width="100">
            <template #default="{ row }">¥{{ row.gross }}</template>
          </el-table-column>
          <el-table-column label="优惠" width="90">
            <template #default="{ row }">{{ row.discount ? `-¥${row.discount}` : '-' }}</template>
          </el-table-column>
          <el-table-column label="实收" width="90">
            <template #default="{ row }">¥{{ row.actual }}</template>
          </el-table-column>
          <el-table-column label="手续费" width="90">
            <template #default="{ row }">¥{{ row.fee }}</template>
          </el-table-column>
          <el-table-column label="净入账" width="100">
            <template #default="{ row }"><span class="is-plus">¥{{ row.net }}</span></template>
          </el-table-column>
          <el-table-column prop="at" label="入账时间" min-width="150" />
          <el-table-column label="状态" width="100">
            <template #default="{ row }">
              <el-tag size="small" :type="statusType(row.status)">{{ row.status }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column prop="diff" label="差异原因" min-width="160" show-overflow-tooltip>
            <template #default="{ row }">{{ row.diff || '-' }}</template>
          </el-table-column>
          <el-table-column label="操作" width="230" fixed="right">
            <template #default="{ row }">
              <template v-if="row.status !== '已对账'">
                <el-button link type="success" size="small" @click="handleConfirm(row)">确认</el-button>
                <el-button link type="warning" size="small" @click="handleMarkDiff(row)">标记差异</el-button>
              </template>
              <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
              <el-button link type="danger" size="small" @click="handleDelete(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
        <el-empty v-if="!loading && filteredData.length === 0" description="暂无对账流水">
          <el-button type="primary" @click="openCreate">补录流水</el-button>
        </el-empty>
      </div>
      <div class="pagination-bar">
        <el-pagination v-model:current-page="page" :page-size="pageSize"
          :total="total" layout="total, prev, pager, next" background size="small" />
      </div>
    </div>

    <el-dialog v-model="formVisible" :title="editing ? '编辑流水' : '补录流水'" width="640px" class="list-dialog">
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top">
        <div class="form-section">流水信息</div>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="订单号" prop="order">
              <el-input v-model="form.order" placeholder="如：BK20261003001" maxlength="20" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="支付渠道" prop="channel">
              <el-select v-model="form.channel" style="width: 100%">
                <el-option label="微信支付" value="微信支付" />
                <el-option label="支付宝" value="支付宝" />
                <el-option label="储值抵扣" value="储值抵扣" />
              </el-select>
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="订单金额（元）" prop="gross">
              <el-input-number v-model="form.gross" :min="0" :precision="2" :controls="false" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="优惠（元）">
              <el-input-number v-model="form.discount" :min="0" :precision="2" :controls="false" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="手续费（元）">
              <el-input-number v-model="form.fee" :min="0" :precision="2" :controls="false" style="width: 100%" />
            </el-form-item>
          </el-col>
        </el-row>
        <div class="form-section">对账状态</div>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="对账状态" prop="status">
              <el-select v-model="form.status" style="width: 100%">
                <el-option label="已对账" value="已对账" />
                <el-option label="差异" value="差异" />
                <el-option label="待对账" value="待对账" />
              </el-select>
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="入账时间">
              <el-date-picker v-model="form.at" type="datetime" value-format="YYYY-MM-DD HH:mm"
                placeholder="选择入账时间" style="width: 100%" />
            </el-form-item>
          </el-col>
        </el-row>
        <el-form-item label="差异原因">
          <el-input v-model="form.diff" type="textarea" :rows="2" placeholder="如：第三方账单延迟 T+1" maxlength="100" />
          <span class="form-tip">净入账 = 实收 − 手续费，由服务端复算，前端只做展示；对账差异必须留痕原因，便于与第三方支付账单逐笔核对。</span>
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
import { Search, Plus, Refresh } from '@element-plus/icons-vue'
import { useCrud } from '../composables/useCrud'
import { runReconcile, confirmReconcile, markReconcileDiff } from '../api/finance'

const loading = computed(() => crud.state.loading)
const query = reactive({ keyword: '', channel: '', status: '' })
const page = ref(1)
const pageSize = 10
const crud = useCrud('finance-reconciles', { fieldMap: {"order":"order_no"}, moneyFields: ["gross","discount","actual","fee","net"], keywordField: ["order_no","channel"] })
const total = computed(() => crud.state.total)
async function reload() {
  await crud.load({ ...query, page: page.value, per_page: pageSize })
}
const formVisible = ref(false)
const editing = ref(false)
const formRef = ref()

const emptyForm = {
  id: '', order: '', channel: '微信支付', gross: 0, discount: 0, actual: 0,
  fee: 0, net: 0, at: '', status: '待对账', diff: ''
}
const form = reactive({ ...emptyForm })

const rules = {
  order: [{ required: true, message: '请输入订单号', trigger: 'blur' }],
  channel: [{ required: true, message: '请选择支付渠道', trigger: 'change' }],
  gross: [{ required: true, message: '请输入订单金额', trigger: 'blur' }],
  status: [{ required: true, message: '请选择对账状态', trigger: 'change' }]
}

function channelType(channel) {
  return { 微信支付: 'success', 支付宝: 'primary', 储值抵扣: 'warning' }[channel] || 'info'
}

function statusType(status) {
  return { 已对账: 'success', 差异: 'danger', 待对账: 'warning' }[status] || 'info'
}

const filteredData = computed(() => crud.state.rows)

const pagedData = computed(() => crud.state.rows)

const totalActual = computed(() => filteredData.value.reduce((s, r) => s + Number(r.actual || 0), 0).toFixed(2))
const totalFee = computed(() => filteredData.value.reduce((s, r) => s + Number(r.fee || 0), 0).toFixed(2))
const totalNet = computed(() => filteredData.value.reduce((s, r) => s + Number(r.net || 0), 0).toFixed(2))
const diffCount = computed(() => filteredData.value.filter((r) => r.status === '差异').length)

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
  const record = {
    ...form,
    actual: Number((form.gross - form.discount).toFixed(2)),
    net: Number((form.gross - form.discount - form.fee).toFixed(2))
  }
  await crud.save(editing.value ? form.id : null, record)
  ElMessage.success(editing.value ? '保存成功' : '新建成功')
  formVisible.value = false
  reload()
}

function handleDelete(row) {
  ElMessageBox.confirm(`确认删除订单「${row.order}」的对账流水吗？请先与第三方账单核对。`, '删除流水', {
    type: 'warning', confirmButtonText: '确认删除', cancelButtonText: '取消'
  }).then(async () => {
    await crud.remove(row.id)
    ElMessage.success('删除成功')
    reload()
  }).catch(() => {})
}

/** 一键对账：扫描近 30 天支付成功流水，按订单生成/刷新对账记录 */
async function handleReconcile() {
  try {
    await ElMessageBox.confirm(
      '将扫描近 30 天支付成功流水，按订单生成/刷新对账记录；流水金额与订单实收不一致将自动标记为差异。',
      '一键对账', { type: 'info', confirmButtonText: '开始对账', cancelButtonText: '取消' }
    )
  } catch (e) {
    return
  }
  try {
    const data = await runReconcile({})
    ElMessage.success(data && data.created !== undefined
      ? `对账完成：新增 ${data.created}，更新 ${data.updated}，差异 ${data.diff}`
      : '对账完成')
    reload()
  } catch (e) {
    ElMessage.error(e?.message || '对账失败')
  }
}

/** 人工确认对账（差异 / 待对账 → 已对账） */
async function handleConfirm(row) {
  try {
    await confirmReconcile(row.id)
    ElMessage.success('已确认对账')
    reload()
  } catch (e) {
    ElMessage.error(e?.message || '确认失败')
  }
}

/** 标记差异（必填说明） */
async function handleMarkDiff(row) {
  try {
    const { value } = await ElMessageBox.prompt('请填写差异说明', '标记差异', {
      confirmButtonText: '确认', cancelButtonText: '取消', inputPlaceholder: '如：第三方账单延迟 T+1',
      inputValidator: (v) => (v && v.trim() ? true : '差异说明不能为空')
    })
    await markReconcileDiff(row.id, value.trim())
    ElMessage.success('已标记为差异')
    reload()
  } catch (e) {
    if (e !== 'cancel' && e !== 'close') ElMessage.error(e?.message || '操作失败')
  }
}

onMounted(() => {
  reload()
})
</script>

<style scoped>
.summary-bar {
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
.summary-value.is-minus { color: var(--danger); }
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
