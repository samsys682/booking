<template>
  <div class="content">
    <div class="content-card content-card--list">
      <div class="action-bar">
        <h2 class="page-title">退款管理</h2>
        <div class="action-bar__left">
          <el-input v-model="query.keyword" placeholder="订单号 / 会员 / 退款单号" clearable style="width: 200px"
            @clear="handleSearch" @keyup.enter="handleSearch">
            <template #prefix><el-icon><Search /></el-icon></template>
          </el-input>
          <el-select v-model="query.channel" placeholder="退款渠道" clearable style="width: 130px" @change="handleSearch">
            <el-option label="微信支付" value="微信支付" />
            <el-option label="支付宝" value="支付宝" />
            <el-option label="储值抵扣" value="储值抵扣" />
          </el-select>
          <el-select v-model="query.status" placeholder="退款状态" clearable style="width: 120px" @change="handleSearch">
            <el-option label="已退款" value="已退款" />
            <el-option label="退款中" value="退款中" />
            <el-option label="已驳回" value="已驳回" />
          </el-select>
          <el-button :icon="Search" @click="handleSearch">查询</el-button>
        </div>
        <div class="action-bar__right">
          <el-button type="primary" :icon="Plus" @click="openCreate">新建退款</el-button>
        </div>
      </div>
      <div class="table-wrap" v-loading="loading">
        <el-table :data="pagedData" empty-text="暂无退款单">
          <el-table-column prop="order" label="订单号" min-width="150" />
          <el-table-column label="订单状态" width="100">
            <template #default="{ row }">
              <el-tag v-if="orderStatusOf(row)" size="small" :type="orderStatusType(row)" effect="plain">{{ orderStatusOf(row) }}</el-tag>
              <span v-else class="is-muted">-</span>
            </template>
          </el-table-column>
          <el-table-column prop="member" label="会员" min-width="90" />
          <el-table-column label="退款金额" width="110">
            <template #default="{ row }"><span class="is-minus">¥{{ row.amount }}</span></template>
          </el-table-column>
          <el-table-column label="渠道" width="100">
            <template #default="{ row }">
              <el-tag size="small" :type="channelType(row.channel)">{{ row.channel }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column prop="reason" label="退款原因" min-width="160" show-overflow-tooltip />
          <el-table-column prop="createdAt" label="申请时间" min-width="150" />
          <el-table-column prop="operator" label="操作人" width="90" />
          <el-table-column label="状态" width="100">
            <template #default="{ row }">
              <el-tag size="small" :type="statusType(row.status)">{{ row.status }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="退款单号 / 驳回原因" min-width="180" show-overflow-tooltip>
            <template #default="{ row }">{{ row.refundNo || row.reason2 || '-' }}</template>
          </el-table-column>
          <el-table-column label="操作" width="140" fixed="right">
            <template #default="{ row }">
              <el-button link type="primary" size="small" @click="openEdit(row)">处理</el-button>
              <el-button link type="danger" size="small" @click="handleDelete(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
        <el-empty v-if="!loading && filteredData.length === 0" description="暂无退款单">
          <el-button type="primary" @click="openCreate">新建退款</el-button>
        </el-empty>
      </div>
      <div class="pagination-bar">
        <el-pagination v-model:current-page="page" :page-size="pageSize"
          :total="total" layout="total, prev, pager, next" background size="small" />
      </div>
    </div>

    <el-dialog v-model="formVisible" :title="editing ? '处理退款' : '新建退款'" width="640px" class="list-dialog">
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top">
        <div class="form-section">退款信息</div>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="订单号" prop="order">
              <!-- 阶段 4 订单联动：从订单列表选单，自动带出会员/金额/渠道 -->
              <el-select v-model="form.order" filterable placeholder="选择订单" style="width: 100%"
                :disabled="editing" @change="onOrderChange">
                <el-option v-for="b in orderOptions" :key="b.id" :label="`${b.id} · ${b.customer} · ${b.status}`" :value="b.id" />
              </el-select>
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="会员" prop="member">
              <el-input v-model="form.member" maxlength="20" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="退款金额（元）" prop="amount">
              <el-input-number v-model="form.amount" :min="0" :precision="2" :controls="false" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="退款渠道" prop="channel">
              <el-select v-model="form.channel" style="width: 100%">
                <el-option label="微信支付" value="微信支付" />
                <el-option label="支付宝" value="支付宝" />
                <el-option label="储值抵扣" value="储值抵扣" />
              </el-select>
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="退款状态" prop="status">
              <el-select v-model="form.status" style="width: 100%">
                <el-option label="已退款" value="已退款" />
                <el-option label="退款中" value="退款中" />
                <el-option label="已驳回" value="已驳回" />
              </el-select>
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="操作人" prop="operator">
              <el-input v-model="form.operator" maxlength="20" />
            </el-form-item>
          </el-col>
        </el-row>
        <el-form-item label="退款原因" prop="reason">
          <el-input v-model="form.reason" type="textarea" :rows="2" placeholder="如：服务瑕疵补偿 / 客户取消预约" maxlength="100" />
        </el-form-item>
        <el-form-item label="退款单号 / 驳回原因">
          <el-input v-model="form.refundNo" placeholder="退款成功后由支付渠道回填，如：RF20260930001" maxlength="30" />
          <el-input v-model="form.reason2" type="textarea" :rows="2" placeholder="驳回时填写原因，如：已服务完成不可退" maxlength="100" style="margin-top: 8px" />
          <span class="form-tip">退款原路退回，金额只信服务端复算，不可超过订单实付；退款成功后对应优惠券与积分一并扣回，佣金同步冲销。新建即登记售后申请（订单进入「售后中」）；处理为「已退款」订单关闭，「已驳回」订单回到转售后前状态。</span>
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
import { canTransition, STATUS_META } from '../statusFlow'

const loading = computed(() => crud.state.loading)
const query = reactive({ keyword: '', channel: '', status: '' })
const page = ref(1)
const pageSize = 10
const crud = useCrud('refunds', { fieldMap: {"order":"order_no","createdAt":"refunded_at"}, moneyFields: ["amount"], keywordField: ["refund_no","order_no","member"] })
// 订单联动（阶段 4）：与 BookingList.vue/DispatchList.vue 共享 'bookings' store——必须同 resource 同参数
const bookCrud = useCrud('bookings', {
  keywordField: ['id', 'orderNo', 'customer', 'phone'],
  fieldMap: { amount: 'actual', payMethod: 'pay_channel' },
  moneyFields: ['amount', 'total', 'discount'],
  mapRow: (r) => {
    const out = { ...r }
    out._rid = r.id
    if (out.orderNo) out.id = out.orderNo
    out.payMethod = ({ alipay: '支付宝', wechat: '微信支付', stored: '储值卡', offline: '线下收款' })[out.payMethod] || out.payMethod
    return out
  }
})
const orderOptions = computed(() => bookCrud.state.rows)
const ridOf = (row) => row._rid ?? row.id
const total = computed(() => crud.state.total)
async function reload() {
  await crud.load({ ...query, page: page.value, per_page: pageSize })
  await bookCrud.load({ page: 1, per_page: 200 }) // 订单状态列/选单联动
}
const formVisible = ref(false)
const editing = ref(false)
const formRef = ref()
const editOldStatus = ref('') // 打开处理弹窗时的退款单状态（判状态迁移用）

const emptyForm = {
  id: '', order: '', member: '', amount: 0, channel: '微信支付', reason: '',
  createdAt: '', operator: '', status: '退款中', refundNo: '', reason2: ''
}
const form = reactive({ ...emptyForm })

const rules = {
  order: [{ required: true, message: '请选择订单', trigger: 'change' }],
  member: [{ required: true, message: '请输入会员', trigger: 'blur' }],
  amount: [{ required: true, message: '请输入退款金额', trigger: 'blur' }],
  channel: [{ required: true, message: '请选择退款渠道', trigger: 'change' }],
  reason: [{ required: true, message: '请输入退款原因', trigger: 'blur' }],
  operator: [{ required: true, message: '请输入操作人', trigger: 'blur' }],
  status: [{ required: true, message: '请选择退款状态', trigger: 'change' }]
}

function channelType(channel) {
  return { 微信支付: 'success', 支付宝: 'primary', 储值抵扣: 'warning' }[channel] || 'info'
}

function statusType(status) {
  return { 已退款: 'success', 退款中: 'warning', 已驳回: 'danger' }[status] || 'info'
}

// 订单状态列：从 bookings 共享 store 关联
function orderOf(row) {
  return orderOptions.value.find((b) => b.id === row.order)
}
function orderStatusOf(row) {
  return orderOf(row)?.status || ''
}
function orderStatusType(row) {
  return (STATUS_META[orderStatusOf(row)] || {}).type || 'info'
}

// 选单自动带出：会员/金额=订单实付/渠道=订单支付方式
function onOrderChange(orderId) {
  const b = orderOptions.value.find((x) => x.id === orderId)
  if (!b) return
  form.member = b.customer || form.member
  form.amount = Number(b.amount) || 0
  form.channel = ({ 支付宝: '支付宝', 微信支付: '微信支付', 储值卡: '储值抵扣' })[b.payMethod] || form.channel
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
  editOldStatus.value = row.status
  Object.assign(form, row)
  formVisible.value = true
  formRef.value?.clearValidate()
}

// 售后联动（阶段 4：售后申请→退款单→回写）——状态迁移一律走 statusFlow 字典断言
async function syncOrder(order, nextStatus, extra = {}) {
  if (!order) return true
  if (!canTransition(order.status, nextStatus)) {
    ElMessage.error(`订单「${order.id}」不允许从「${order.status}」流转到「${nextStatus}」`)
    return false
  }
  await bookCrud.patch(ridOf(order), { status: nextStatus, ...extra })
  return true
}

async function handleSave() {
  const valid = await formRef.value.validate().catch(() => false)
  if (!valid) return
  const order = orderOf(form)
  // 快照留存原状态：mock 分支 patch 仅 Object.assign 内部 store，旧引用读不到新写入的 preStatus
  const preStatus = order ? order.status : ''
  // 金额只信服务端复算口径：前端仅校验不可超过订单实付
  if (order && Number(form.amount) > Number(order.amount || 0)) {
    ElMessage.warning('退款金额不可超过订单实付（金额以服务端复算为准）')
    return
  }
  if (!editing.value) {
    // 新建 = 售后申请：订单先转「售后中」并记 preStatus（供驳回回退，与 BookingList.applyAftersale 同口径）
    if (order && !await syncOrder(order, '售后中', { preStatus, aftersaleReason: form.reason, aftersaleAt: nowText() })) return
    if (form.status === '已退款' && order) {
      // 一步到位的退款：订单 售后中→已退款（两次流转均在 TRANSITIONS 白名单内）
      if (!canTransition('售后中', '已退款')) return ElMessage.error('状态流转非法')
      await bookCrud.patch(ridOf(order), { status: '已退款' })
    }
    if (form.status === '已驳回' && order) {
      const back = preStatus
      if (!back || !canTransition('售后中', back)) {
        return ElMessage.error('无法驳回：原状态缺失或非法（仅可回 服务中/待确认/已完成/待评价/已评价）')
      }
      await bookCrud.patch(ridOf(order), { status: back, aftersaleResult: '驳回（' + nowText() + '）' })
    }
    await crud.save(null, { ...form })
    ElMessage.success('售后申请已登记' + (order ? '，订单状态已同步' : ''))
  } else {
    // 处理：仅状态发生迁移时回写订单（已退款→订单终态；已驳回→订单回 preStatus；退款中不动订单）
    if (order && form.status !== editOldStatus.value) {
      if (form.status === '已退款') {
        if (!await syncOrder(order, '已退款')) return
      } else if (form.status === '已驳回') {
        const back = order.preStatus
        if (!back || !canTransition('售后中', back)) {
          return ElMessage.error('无法驳回：原状态缺失或非法（仅可回 服务中/待确认/已完成/待评价/已评价）')
        }
        await bookCrud.patch(ridOf(order), { status: back, aftersaleResult: '驳回（' + nowText() + '）' })
      }
    }
    await crud.save(form.id, { ...form })
    ElMessage.success('保存成功' + (order && form.status !== editOldStatus.value ? '，订单状态已同步' : ''))
  }
  formVisible.value = false
  reload()
}

// 删除仅删退款单留痕，不回滚订单状态（口径：订单状态以状态机流转为准）
function handleDelete(row) {
  ElMessageBox.confirm(`确认删除订单「${row.order}」的退款单吗？已退款记录删除后无法恢复。`, '删除退款单', {
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
.is-muted {
  color: var(--text-tertiary);
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
