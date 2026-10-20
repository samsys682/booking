<template>
  <div class="content">
    <div class="content-card content-card--list">
      <div class="action-bar">
        <h2 class="page-title">定金尾款</h2>
        <div class="action-bar__left">
          <el-input v-model="query.keyword" placeholder="订单号" clearable style="width: 200px"
            @clear="handleSearch" @keyup.enter="handleSearch">
            <template #prefix><el-icon><Search /></el-icon></template>
          </el-input>
          <el-select v-model="query.kind" placeholder="类型" clearable style="width: 110px" @change="handleSearch">
            <el-option label="定金" value="定金" />
            <el-option label="尾款" value="尾款" />
          </el-select>
          <el-select v-model="query.status" placeholder="收款状态" clearable style="width: 120px" @change="handleSearch">
            <el-option label="待支付" value="待支付" />
            <el-option label="已支付" value="已支付" />
            <el-option label="已退款" value="已退款" />
          </el-select>
          <el-button :icon="Search" @click="handleSearch">查询</el-button>
        </div>
        <div class="action-bar__right">
          <el-button type="primary" :icon="Plus" :disabled="!depositEnabled" @click="openCreate">新建收款单</el-button>
        </div>
      </div>

      <el-alert
        v-if="!depositEnabled"
        type="warning"
        :closable="false"
        show-icon
        style="margin-bottom: var(--space-md)"
        title="定金尾款已关闭"
        description="订单按全额一次付清收款。如需启用定金/尾款分阶段收款，请前往「系统设置 → 预约规则」打开开关。"
      />

      <div class="table-wrap" v-loading="loading">
        <el-table :data="pagedData" empty-text="暂无定金尾款记录">
          <el-table-column prop="orderNo" label="订单号" width="160" />
          <el-table-column label="类型" width="90">
            <template #default="{ row }">
              <el-tag size="small" :type="row.kind === '定金' ? 'warning' : 'primary'" effect="plain">{{ row.kind }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="金额（元）" min-width="110">
            <template #default="{ row }"><span class="balance">￥{{ row.amount }}</span></template>
          </el-table-column>
          <el-table-column label="支付方式" width="110">
            <template #default="{ row }">{{ channelLabel(row.payChannel) }}</template>
          </el-table-column>
          <el-table-column label="状态" width="100">
            <template #default="{ row }">
              <el-tag :type="statusTag(row.status)" size="small">{{ row.status }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column prop="paidAt" label="收款时间" width="160" />
          <el-table-column label="操作" width="180" fixed="right">
            <template #default="{ row }">
              <el-button v-if="row.status === '待支付'" type="primary" link size="small" @click="pay(row)">收款</el-button>
              <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
              <el-button link type="danger" size="small" @click="handleDelete(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
        <el-empty v-if="!loading && filteredData.length === 0" description="暂无定金尾款记录">
          <el-button type="primary" @click="openCreate">新建收款单</el-button>
        </el-empty>
      </div>
      <div class="pagination-bar">
        <el-pagination v-model:current-page="page" :page-size="pageSize"
          :total="total" layout="total, prev, pager, next" background size="small" />
      </div>
    </div>

    <el-dialog v-model="payVisible" title="确认收款" width="420px">
      <el-descriptions v-if="payRow" :column="1" border size="small" style="margin-bottom: var(--space-md)">
        <el-descriptions-item label="订单">{{ payRow.orderNo }}</el-descriptions-item>
        <el-descriptions-item label="类型">{{ payRow.kind }}</el-descriptions-item>
        <el-descriptions-item label="金额">￥{{ payRow.amount }}</el-descriptions-item>
      </el-descriptions>
      <el-form label-width="90px">
        <el-form-item label="支付方式">
          <el-radio-group v-model="payMethod">
            <el-radio-button value="wechat">微信</el-radio-button>
            <el-radio-button value="alipay">支付宝</el-radio-button>
            <el-radio-button value="stored">储值</el-radio-button>
            <el-radio-button value="offline">线下</el-radio-button>
          </el-radio-group>
        </el-form-item>
      </el-form>
      <template #footer>
        <div class="modal-footer">
          <el-button @click="payVisible = false">取消</el-button>
          <el-button type="primary" :loading="paying" @click="confirmPay">确认收款</el-button>
        </div>
      </template>
    </el-dialog>

    <el-dialog v-model="formVisible" :title="editing ? '编辑收款单' : '新建收款单'" width="560px" class="list-dialog">
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top">
        <div class="form-section">收款信息</div>
        <el-form-item label="订单号" prop="orderNo">
          <el-input v-model="form.orderNo" placeholder="如：BK20261003021" maxlength="32" />
        </el-form-item>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="类型" prop="kind">
              <el-radio-group v-model="form.kind">
                <el-radio-button value="定金">定金</el-radio-button>
                <el-radio-button value="尾款">尾款</el-radio-button>
              </el-radio-group>
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="金额（元）" prop="amount">
              <el-input-number v-model="form.amount" :min="0" :precision="2" style="width: 100%" />
            </el-form-item>
          </el-col>
        </el-row>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="支付方式">
              <el-select v-model="form.payChannel" style="width: 100%">
                <el-option label="未收款" value="" />
                <el-option label="微信支付" value="wechat" />
                <el-option label="支付宝" value="alipay" />
                <el-option label="储值支付" value="stored" />
                <el-option label="线下收款" value="offline" />
              </el-select>
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="收款状态">
              <el-select v-model="form.status" style="width: 100%">
                <el-option label="待支付" value="待支付" />
                <el-option label="已支付" value="已支付" />
                <el-option label="已退款" value="已退款" />
              </el-select>
            </el-form-item>
          </el-col>
        </el-row>
        <el-form-item label="收款时间">
          <el-input v-model="form.paidAt" placeholder="如：2026-10-03 18:30（未收款可留空）" />
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
// 定金/尾款收款：收款推进支付状态，收款单支持完整增删改查
import { computed, reactive, ref, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Search, Plus } from '@element-plus/icons-vue'
import { useCrud } from '../composables/useCrud'
import { list as listResource } from '../api/resource'
import { request } from '../api/http'

const loading = computed(() => crud.state.loading)
const query = reactive({ keyword: '', kind: '', status: '' })
const page = ref(1)
const pageSize = 10

const crud = useCrud('deposits', { fieldMap: { orderNo: 'order_no', payChannel: 'pay_channel', paidAt: 'paid_at' }, moneyFields: ["amount"], keywordField: ["order_no"] })
const total = computed(() => crud.state.total)
async function reload() {
  await crud.load({ ...query, page: page.value, per_page: pageSize })
}

// 定金尾款开关：关闭时后端拒绝新增（resources.php require_setting），前端同步禁用新建入口
const depositEnabled = ref(true)
async function loadSwitch() {
  try {
    const res = await listResource('settings', { keyword: 'deposit_enabled', per_page: 20 })
    const row = (res.list || []).find((x) => x.key === 'deposit_enabled')
    depositEnabled.value = !!row && ['1', 'true', 'on', 'yes'].includes(String(row.value))
  } catch (e) {
    depositEnabled.value = true // 读取失败不误伤（后端仍会兜底拦截）
  }
}

const payVisible = ref(false)
const paying = ref(false)
const payMethod = ref('wechat')
const payRow = ref(null)

const formVisible = ref(false)
const editing = ref(false)
const formRef = ref()

const emptyForm = { id: '', orderNo: '', kind: '定金', amount: 0, payChannel: '', status: '待支付', paidAt: '' }
const form = reactive({ ...emptyForm })

const rules = {
  orderNo: [{ required: true, message: '请输入订单号', trigger: 'blur' }],
  kind: [{ required: true, message: '请选择类型', trigger: 'change' }],
  amount: [{ required: true, message: '请输入金额', trigger: 'blur' }]
}

const filteredData = computed(() => crud.state.rows)

const pagedData = computed(() => crud.state.rows)

function statusTag(s) {
  return { 待支付: 'danger', 已支付: 'success', 已退款: 'info' }[s] || 'info'
}

function channelLabel(c) {
  return { wechat: '微信支付', alipay: '支付宝', stored: '储值支付', offline: '线下收款' }[c] || '未收款'
}

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
}

function pay(row) {
  payRow.value = row
  payMethod.value = 'wechat'
  payVisible.value = true
}

async function confirmPay() {
  const row = payRow.value
  if (!row) return
  paying.value = true
  try {
    // 真实链路：定金/尾款收款走 /pay/confirm（服务端 payableFor 判定当前阶段 + 推进订单状态 + 累加 booking.paid）
    const res = await request('/pay/confirm', { method: 'POST', body: { order_no: row.orderNo, channel: payMethod.value } })
    const kind = res?.kind || row.kind
    const amount = Number(res?.amount ?? Math.round(Number(row.amount) * 100)) / 100
    ElMessage.success(`${kind} ￥${amount} 已收款（${channelLabel(payMethod.value)}）`)
    payVisible.value = false
    reload()
  } catch (e) {
    ElMessage.error(e?.message || '收款失败')
  } finally {
    paying.value = false
  }
}

function handleDelete(row) {
  ElMessageBox.confirm(`确认删除订单「${row.orderNo}」的${row.kind}记录吗？`, '删除收款单', {
    type: 'warning', confirmButtonText: '确认删除', cancelButtonText: '取消'
  }).then(async () => {
    await crud.remove(row.id)
    ElMessage.success('删除成功')
    reload()
  }).catch(() => {})
}

onMounted(() => {
  loadSwitch()
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
