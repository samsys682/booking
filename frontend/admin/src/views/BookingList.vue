<template>
  <div class="content">
    <div class="content-card content-card--list">
      <!-- 标题 + 搜索 + 新建一行（） -->
      <div class="action-bar">
        <h2 class="page-title">预约订单</h2>
        <div class="action-bar__left">
          <el-input
            v-model="query.keyword"
            placeholder="订单号 / 客户 / 电话"
            clearable
            style="width: 200px"
            @clear="handleSearch"
            @keyup.enter="handleSearch"
          >
            <template #prefix><el-icon><Search /></el-icon></template>
          </el-input>
          <el-select v-model="query.status" placeholder="订单状态" clearable style="width: 120px" @change="handleSearch">
            <el-option v-for="(type, label) in bookingStatusMap" :key="label" :label="label" :value="label" />
          </el-select>
          <el-date-picker
            v-model="query.dateRange"
            type="daterange"
            start-placeholder="开始日期"
            end-placeholder="结束日期"
            style="width: 240px"
            @change="handleSearch"
          />
          <el-button :icon="Search" @click="handleSearch">查询</el-button>
        </div>
        <div class="action-bar__right">
          <!-- 派单设置（菜单重排拍板：派单管理改为对话框，开启后自动派单） -->
          <el-button :icon="Setting" @click="openDispatchSetting">派单设置</el-button>
          <el-button :icon="Download" @click="handleExport">导出</el-button>
          <el-button type="primary" :icon="Plus" @click="openCreate">新建预约</el-button>
        </div>
      </div>

      <div class="table-wrap" v-loading="loading">
        <el-table :data="pagedData" empty-text="暂无符合条件的预约订单">
          <el-table-column prop="id" label="订单号" width="150" fixed />
          <el-table-column prop="customer" label="客户" width="90" />
          <el-table-column prop="phone" label="电话" width="120" />
          <el-table-column prop="service" label="服务项目" min-width="160" show-overflow-tooltip />
          <el-table-column prop="technician" label="技师" width="90" />
          <el-table-column prop="startAt" label="上门时间" width="140" sortable />
          <el-table-column prop="address" label="地址" min-width="200" show-overflow-tooltip />
          <el-table-column prop="amount" label="金额" width="90" sortable>
            <template #default="{ row }">￥{{ row.amount }}</template>
          </el-table-column>
          <el-table-column prop="status" label="状态" width="90">
            <template #default="{ row }">
              <el-tag :type="bookingStatusMap[row.status]" size="small">{{ row.status }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="230" fixed="right">
            <template #default="{ row }">
              <!-- 业务错位整改①：按钮按 statusFlow.js::STATUS_ACTIONS 字典渲染（admin 角色），勿再手写状态分叉 -->
              <el-button
                v-for="a in allowedActions(row.status, 'admin')"
                :key="a.key"
                :type="actionMeta[a.key]?.type || 'primary'"
                link
                size="small"
                @click="doAction(a.key, row)"
              >{{ actionMeta[a.key]?.label || a.label }}</el-button>
              <el-button type="primary" link size="small" @click="openDetail(row)">详情</el-button>
            </template>
          </el-table-column>
        </el-table>

        <el-empty v-if="!loading && filteredData.length === 0" description="暂无符合条件的预约订单">
          <el-button type="primary" @click="openCreate">新建预约</el-button>
        </el-empty>
      </div>

      <div class="pagination-bar">
        <el-pagination
          v-model:current-page="page"
          :page-size="pageSize"
          :total="filteredData.length"
          layout="total, prev, pager, next"
          background
          size="small"
        />
      </div>
    </div>

    <!-- 新建预约弹窗 -->
    <el-dialog v-model="createVisible" title="新建预约" width="520px">
      <el-form ref="createFormRef" :model="createForm" :rules="createRules" label-width="80px">
        <el-form-item label="客户" required>
          <el-input v-model="createForm.customer" placeholder="客户姓名" />
        </el-form-item>
        <el-form-item label="电话" required>
          <el-input v-model="createForm.phone" placeholder="联系电话" />
        </el-form-item>
        <el-form-item label="服务项目" required>
          <el-select v-model="createForm.service" placeholder="选择服务项目" style="width: 100%">
            <el-option v-for="s in services" :key="s.id" :label="s.name" :value="s.name" />
          </el-select>
        </el-form-item>
        <el-form-item label="上门时间" required>
          <el-date-picker v-model="createForm.startAt" type="datetime" placeholder="选择上门时间" style="width: 100%" />
          <div class="form-unit">需提前 {{ RULES.advanceHours }} 小时预约，最远可约 {{ RULES.maxAdvanceDays }} 天</div>
        </el-form-item>
        <el-form-item label="指定技师">
          <el-select v-model="createForm.technician" placeholder="不选则支付后进入派单池（待派单）" clearable style="width: 100%">
            <el-option v-for="t in techOptions" :key="t.name" :label="t.name" :value="t.name" />
          </el-select>
          <div class="form-unit">指定技师的订单支付后直达「待接单」，由技师确认接单</div>
        </el-form-item>
        <el-form-item label="地址" required>
          <el-input v-model="createForm.address" type="textarea" :rows="2" placeholder="服务地址" />
        </el-form-item>
      </el-form>
      <template #footer>
        <div class="modal-footer">
          <el-button @click="createVisible = false">取消</el-button>
          <el-button type="primary" @click="handleCreate">保存</el-button>
        </div>
      </template>
    </el-dialog>

    <!-- 加钟弹窗（-->
    <el-dialog v-model="extendVisible" title="服务加钟" width="420px">
      <template v-if="extendRow">
        <el-descriptions :column="1" border size="small" style="margin-bottom: var(--space-md)">
          <el-descriptions-item label="订单号">{{ extendRow.id }}</el-descriptions-item>
          <el-descriptions-item label="服务项目">{{ extendRow.service }}</el-descriptions-item>
          <el-descriptions-item label="当前金额">￥{{ extendRow.amount }}</el-descriptions-item>
        </el-descriptions>
        <el-form label-width="90px">
          <el-form-item label="加钟时长">
            <el-input-number v-model="extendHours" :min="0.5" :max="8" :step="0.5" />
            <span class="form-unit">小时</span>
          </el-form-item>
          <el-form-item label="加钟费用">
            <el-input-number v-model="extendAmount" :min="0" :step="10" />
            <span class="form-unit">元（默认按服务单价小时价自动算）</span>
          </el-form-item>
        </el-form>
      </template>
      <template #footer>
        <div class="modal-footer">
          <el-button @click="extendVisible = false">取消</el-button>
          <el-button type="primary" @click="confirmExtend">确认加钟</el-button>
        </div>
      </template>
    </el-dialog>

    <!-- 派单/改派弹窗（业务错位整改④：待派单→待接单；待接单改派仅换技师；冲突校验与后端 OrderService::hasConflict 同口径） -->
    <el-dialog v-model="dispatchVisible" :title="dispatchRow?.status === '待接单' ? '改派技师' : '指派技师'" width="420px">
      <template v-if="dispatchRow">
        <el-descriptions :column="1" border size="small" style="margin-bottom: var(--space-md)">
          <el-descriptions-item label="订单号">{{ dispatchRow.id }}</el-descriptions-item>
          <el-descriptions-item label="服务项目">{{ dispatchRow.service }}</el-descriptions-item>
          <el-descriptions-item label="上门时间">{{ dispatchRow.startAt }}</el-descriptions-item>
          <el-descriptions-item label="当前技师">{{ dispatchRow.technician === '待分配' ? '未分配' : dispatchRow.technician }}</el-descriptions-item>
        </el-descriptions>
        <el-form label-width="90px">
          <el-form-item label="选择技师" required>
            <el-select v-model="dispatchTech" placeholder="选择技师" style="width: 100%">
              <el-option v-for="t in techOptions" :key="t.name" :label="t.name + '（' + (Array.isArray(t.skills) ? t.skills.join('/') : t.skills) + '）'" :value="t.name" />
            </el-select>
          </el-form-item>
        </el-form>
      </template>
      <template #footer>
        <div class="modal-footer">
          <el-button @click="dispatchVisible = false">取消</el-button>
          <el-button type="primary" @click="confirmDispatch">确认派单</el-button>
        </div>
      </template>
    </el-dialog>

    <!-- 改约弹窗（业务错位整改⑤：改约=改上门时间，允许待接单/待服务改约，冲突校验排除自身） -->
    <el-dialog v-model="rescheduleVisible" title="改约上门时间" width="420px">
      <template v-if="rescheduleRow">
        <el-descriptions :column="1" border size="small" style="margin-bottom: var(--space-md)">
          <el-descriptions-item label="订单号">{{ rescheduleRow.id }}</el-descriptions-item>
          <el-descriptions-item label="服务项目">{{ rescheduleRow.service }}</el-descriptions-item>
          <el-descriptions-item label="原上门时间">{{ rescheduleRow.startAt }}</el-descriptions-item>
        </el-descriptions>
        <el-form label-width="90px">
          <el-form-item label="新上门时间" required>
            <el-date-picker v-model="rescheduleAt" type="datetime" placeholder="选择新上门时间" style="width: 100%" />
            <div class="form-unit">需提前 {{ RULES.advanceHours }} 小时预约；技师时段冲突将被拦截</div>
          </el-form-item>
        </el-form>
      </template>
      <template #footer>
        <div class="modal-footer">
          <el-button @click="rescheduleVisible = false">取消</el-button>
          <el-button type="primary" @click="confirmReschedule">确认改约</el-button>
        </div>
      </template>
    </el-dialog>

    <!-- 订单收款弹窗（在线支付对接支付宝+微信，付费版可开；开源版记录线下收款） -->
    <el-dialog v-model="payVisible" title="订单收款" width="420px">
      <template v-if="payRow">
        <el-descriptions :column="1" border size="small" style="margin-bottom: var(--space-md)">
          <el-descriptions-item label="订单号">{{ payRow.id }}</el-descriptions-item>
          <el-descriptions-item label="客户">{{ payRow.customer }}（{{ payRow.phone }}）</el-descriptions-item>
          <el-descriptions-item label="服务项目">{{ payRow.service }}</el-descriptions-item>
          <el-descriptions-item label="应收金额">
            <span class="pay-amount">￥{{ payRow.amount }}</span>
          </el-descriptions-item>
        </el-descriptions>
        <template v-if="license.isPro && payMethods.length">
          <div class="pay-method-label">选择在线支付方式</div>
          <el-radio-group v-model="payMethod">
            <el-radio-button v-for="m in payMethods" :key="m.key" :value="m.key">{{ m.label }}</el-radio-button>
          </el-radio-group>
          <div class="form-unit" style="margin-top: var(--space-xs)">
            支付方式可在 系统设置 → 在线支付 中开通与配置（付费版）
          </div>
        </template>
        <el-alert v-else type="info" :closable="false" :title="license.isPro ? '未开通在线支付：请先在 系统设置 → 在线支付 中开启支付宝或微信支付' : '在线支付为付费版功能，开源版请使用线下收款记录'" />
      </template>
      <template #footer>
        <div class="modal-footer">
          <el-button @click="payVisible = false">取消</el-button>
          <el-button type="primary" :loading="paying" @click="confirmPay">{{ license.isPro && payMethods.length ? '发起收款' : '记录线下收款' }}</el-button>
        </div>
      </template>
    </el-dialog>

    <!-- 详情弹窗 -->
    <el-dialog v-model="detailVisible" title="预约详情" width="520px">
      <el-descriptions v-if="current" :column="1" border size="small">
        <el-descriptions-item label="订单号">{{ current.id }}</el-descriptions-item>
        <el-descriptions-item label="客户">{{ current.customer }}（{{ current.phone }}）</el-descriptions-item>
        <el-descriptions-item label="服务项目">{{ current.service }}</el-descriptions-item>
        <el-descriptions-item label="技师">{{ current.technician }}</el-descriptions-item>
        <el-descriptions-item label="上门时间">{{ current.startAt }}</el-descriptions-item>
        <el-descriptions-item label="地址">{{ current.address }}</el-descriptions-item>
        <el-descriptions-item label="金额">￥{{ current.amount }}</el-descriptions-item>
        <el-descriptions-item label="状态">
          <el-tag :type="bookingStatusMap[current.status]" size="small">{{ current.status }}</el-tag>
        </el-descriptions-item>
        <el-descriptions-item v-if="current.payMethod" label="支付方式">{{ current.payMethod }}</el-descriptions-item>
        <el-descriptions-item v-if="current.extends && current.extends.length" label="加钟记录">
          <div v-for="(e, i) in current.extends" :key="i">{{ e.at }} 延长 {{ e.hours }} 小时，加收 ￥{{ e.amount }}</div>
        </el-descriptions-item>
      </el-descriptions>
      <template #footer>
        <div class="modal-footer">
          <el-button @click="detailVisible = false">关闭</el-button>
        </div>
      </template>
    </el-dialog>

    <!-- 派单设置弹窗（业务错位整改：设置后自动派单） -->
    <el-dialog v-model="dispatchSettingVisible" title="派单设置" width="480px">
      <el-form label-width="100px">
        <el-form-item label="派单方式">
          <el-radio-group v-model="dispatchMode">
            <el-radio-button value="手动指派">手动指派</el-radio-button>
            <el-radio-button value="自动派单">自动派单</el-radio-button>
          </el-radio-group>
        </el-form-item>
        <template v-if="dispatchMode === '自动派单'">
          <el-form-item v-for="w in dispatchWeightItems" :key="w.key" :label="w.label">
            <el-input-number v-model="dispatchWeights[w.key]" :min="0" :max="100" :step="5" />
            <span class="form-unit" style="margin-left: 10px">{{ w.tip }}</span>
          </el-form-item>
          <el-form-item label="权重合计">
            <el-tag :type="weightTotal === 100 ? 'success' : 'danger'" size="small">{{ weightTotal }} / 100</el-tag>
          </el-form-item>
        </template>
        <el-form-item v-else label="">
          <span class="form-unit">新支付订单进入「待派单」，在订单列表手动指派技师</span>
        </el-form-item>
      </el-form>
      <template #footer>
        <div class="modal-footer">
          <el-button @click="dispatchSettingVisible = false">取消</el-button>
          <el-button type="primary" @click="saveDispatchSetting">保存</el-button>
        </div>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { computed, reactive, ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Search, Plus, Download, Setting } from '@element-plus/icons-vue'
import { useCrud } from '../composables/useCrud'
import { ordersApi, payConfirm } from '../api/orders'
import { list as listResource, create as createSetting, update as updateSetting } from '../api/resource'
import { useLicenseStore } from '../store/license'
import { usePaymentSettingsStore } from '../store/paymentSettings'
import { allowedActions, canTransition } from '../statusFlow'
// 状态→标签色映射为静态展示配置（mock/data 保留）
import { bookingStatusMap } from '../mock/data'

// 预约规则：读后端 setting（与 Settings 面板同一批键），镜像 OrderService::assertBookingWindow /
// calcCancelFee / markNoshow；此前绑定前端 mock 对象，改设置页对校验提示无效。
const RULES = reactive({
  advanceHours: 2,
  maxAdvanceDays: 30,
  cancelLimitHours: 4,
  cancelFeeRate: 10,
  noShowLimit: 3,
  noShowFreezeDays: 7,
  waitlist: false
})
const RULE_KEYS = {
  advance_hours: (v) => (RULES.advanceHours = Number(v) || 0),
  max_advance_days: (v) => (RULES.maxAdvanceDays = Number(v) || 0),
  cancel_limit_hours: (v) => (RULES.cancelLimitHours = Number(v) || 0),
  cancel_fee_rate: (v) => (RULES.cancelFeeRate = Number(v) || 0),
  noshow_limit: (v) => (RULES.noShowLimit = Number(v) || 0),
  noshow_freeze_days: (v) => (RULES.noShowFreezeDays = Number(v) || 0),
  queue_enabled: (v) => (RULES.waitlist = v === '1' || v === 1 || v === true)
}

async function loadRules() {
  try {
    const res = await listResource('settings', { page: 1, per_page: 200 })
    for (const r of res.list || []) {
      const apply = RULE_KEYS[r.key]
      if (apply && r.value !== undefined && r.value !== null) apply(String(r.value))
    }
  } catch (e) {
    console.warn('[booking-rules]', e && e.message)
  }
}

// 按钮展示口径（admin 语境文案，key 与 statusFlow.js::STATUS_ACTIONS 对齐）
const actionMeta = {
  pay: { label: '收款', type: 'danger' },
  cancel: { label: '取消', type: 'danger' },
  dispatch: { label: '派单', type: 'primary' },
  reschedule: { label: '改约', type: 'warning' },
  start: { label: '开始服务', type: 'success' },
  extend: { label: '加钟', type: 'warning' },
  finish: { label: '完成服务', type: 'success' },
  aftersale: { label: '转售后', type: 'warning' },
  noshow: { label: '客户爽约', type: 'info' },
  refund: { label: '退款', type: 'warning' },
  rejectAftersale: { label: '售后驳回', type: 'info' }
}

const license = useLicenseStore()
const paymentSettings = usePaymentSettingsStore()
const payMethods = computed(() => paymentSettings.methods)

// bookings：金额视图「元」↔ 后端「分」；amount ↔ actual、payMethod ↔ pay_channel
// _rid=后端资源 id（保存/删除用），id 显示订单号（orderNo）
const crud = useCrud('bookings', {
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
// 加钟留痕（order-extends）：仅追加
const extCrud = useCrud('order-extends', { moneyFields: ['price'] })
// 服务项目（新建/加钟单价来源）
const svcCrud = useCrud('services', { moneyFields: ['price'], keywordField: ['name'] })
const services = computed(() => svcCrud.state.rows)
// 技师（派单/指定技师选项；开源版上限 5 人，见《功能矩阵》〇章）
const techCrud = useCrud('technicians', { keywordField: ['name', 'skills'] })
const techOptions = computed(() => techCrud.state.rows)
// 退款单（售后联动，阶段 4）：与 RefundList.vue 共享 'refunds' store——必须同 resource 同参数
const refundCrud = useCrud('refunds', { fieldMap: { order: 'order_no', createdAt: 'refunded_at' }, moneyFields: ['amount'], keywordField: ['refund_no', 'order_no', 'member'] })

const loading = computed(() => crud.state.loading || svcCrud.state.loading)
const query = reactive({ keyword: '', status: '', dateRange: null })
const page = ref(1)
const pageSize = 10

const createVisible = ref(false)
const detailVisible = ref(false)
const current = ref(null)
const createFormRef = ref(null)
const createForm = reactive({ customer: '', phone: '', service: '', startAt: '', address: '', technician: '' })
const createRules = {
  customer: [{ required: true, message: '请填写客户姓名', trigger: 'blur' }],
  phone: [{ required: true, message: '请填写联系电话', trigger: 'blur' }],
  service: [{ required: true, message: '请选择服务项目', trigger: 'change' }],
  startAt: [{ required: true, message: '请选择上门时间', trigger: 'change' }],
  address: [{ required: true, message: '请填写服务地址', trigger: 'blur' }]
}

// 订单收款（）
const payVisible = ref(false)
const payRow = ref(null)
const payMethod = ref('alipay')
const paying = ref(false)

// 加钟（）：服务中延长时长并加收差价，留痕到订单
const extendVisible = ref(false)
const extendRow = ref(null)
const extendHours = ref(1)
const extendAmount = ref(0)

// 派单/改派（业务错位整改④）
const dispatchVisible = ref(false)
const dispatchRow = ref(null)
const dispatchTech = ref('')

// 派单设置（菜单重排拍板：setting dispatch_mode / dispatch_weights，与后端 autoDispatch 同源）
const dispatchSettingVisible = ref(false)
const dispatchMode = ref('手动指派')
const dispatchWeights = reactive({ skill: 40, schedule: 25, load: 20, rating: 15 })
const dispatchWeightItems = [
  { key: 'skill', label: '技能匹配', tip: '服务项目命中技师技能词加分' },
  { key: 'schedule', label: '档期空闲', tip: '该时段无冲突订单加分' },
  { key: 'load', label: '负载均衡', tip: '当前在忙订单越少分越高' },
  { key: 'rating', label: '服务评分', tip: '技师评分折算加分' }
]
const weightTotal = computed(() =>
  Number(dispatchWeights.skill) + Number(dispatchWeights.schedule) + Number(dispatchWeights.load) + Number(dispatchWeights.rating)
)
let dispatchModeSettingId = null
let dispatchWeightsSettingId = null
async function openDispatchSetting() {
  dispatchModeSettingId = null
  dispatchWeightsSettingId = null
  try {
    const res = await listResource('settings', { page: 1, per_page: 200 })
    for (const row of res.list || []) {
      if (row.key === 'dispatch_mode') {
        dispatchModeSettingId = row.id
        dispatchMode.value = row.value || '手动指派'
      }
      if (row.key === 'dispatch_weights') {
        dispatchWeightsSettingId = row.id
        try { Object.assign(dispatchWeights, JSON.parse(row.value || '{}')) } catch (e) { /* 容错默认值 */ }
      }
    }
  } catch (e) {
    console.warn('[dispatch-setting]', e && e.message)
  }
  dispatchSettingVisible.value = true
}
async function saveDispatchSetting() {
  if (dispatchMode.value === '自动派单' && weightTotal.value !== 100) {
    ElMessage.warning(`自动派单权重合计需为 100（当前 ${weightTotal.value}）`)
    return
  }
  const weights = JSON.stringify({ skill: dispatchWeights.skill, schedule: dispatchWeights.schedule, load: dispatchWeights.load, rating: dispatchWeights.rating })
  if (dispatchModeSettingId) {
    await updateSetting('settings', dispatchModeSettingId, { value: dispatchMode.value })
  } else {
    await createSetting('settings', { group: 'order', key: 'dispatch_mode', value: dispatchMode.value, type: 'string', remark: '派单方式：手动指派/自动派单（支付成功后对待派单订单生效）' })
  }
  if (dispatchWeightsSettingId) {
    await updateSetting('settings', dispatchWeightsSettingId, { value: weights })
  } else {
    await createSetting('settings', { group: 'order', key: 'dispatch_weights', value: weights, type: 'json', remark: '自动派单评分权重：技能/档期/负载/评分，合计 100' })
  }
  dispatchSettingVisible.value = false
  ElMessage.success(dispatchMode.value === '自动派单' ? '已开启自动派单：新支付订单将按权重自动指派技师' : '已切回手动指派')
}

// 改约（业务错位整改⑤）
const rescheduleVisible = ref(false)
const rescheduleRow = ref(null)
const rescheduleAt = ref('')

const rid = (row) => row._rid ?? row.id
const pad = (n) => String(n).padStart(2, '0')

// 后端动作直达：不再做内存 mock 兜底（mockFn 参数仅为兼容历史调用点保留，不执行）
// apiFn 为 null（前端数据不足以调后端）或后端不可达（HttpDownError）→ 明确报错；
// 业务错误（BizError，如状态机拒绝/档期冲突）→ 提示并返回 false，不吞错
async function runAction(apiFn, mockFn) {
  if (!apiFn) {
    ElMessage.error('该操作需要后端支持，请检查后端 API 服务')
    return false
  }
  try {
    await apiFn()
    return true
  } catch (e) {
    if (e && e.isHttpDown) {
      ElMessage.error('后端服务未连接，请检查后端 API 服务是否启动')
      return false
    }
    ElMessage.error((e && e.message) || '操作失败')
    return false
  }
}
function nowText() {
  const d = new Date()
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`
}

const filteredData = computed(() => {
  let list = crud.state.rows
  if (query.keyword) {
    const kw = query.keyword.toLowerCase()
    list = list.filter(
      (b) =>
        String(b.id).toLowerCase().includes(kw) ||
        String(b.customer || '').includes(query.keyword) ||
        String(b.phone || '').includes(query.keyword)
    )
  }
  if (query.status) list = list.filter((b) => b.status === query.status)
  if (query.dateRange && query.dateRange.length === 2) {
    const [s, e] = query.dateRange
    list = list.filter((b) => {
      const d = String(b.startAt || '').slice(0, 10)
      const sd = new Date(s).toISOString().slice(0, 10)
      const ed = new Date(e).toISOString().slice(0, 10)
      return d >= sd && d <= ed
    })
  }
  return list
})

const pagedData = computed(() => {
  const start = (page.value - 1) * pageSize
  return filteredData.value.slice(start, start + pageSize)
})

function handleSearch() {
  page.value = 1
}

function openCreate() {
  createVisible.value = true
}

async function handleCreate() {
  const f = createForm
  if (!f.customer || !f.phone || !f.service || !f.startAt || !f.address) {
    ElMessage.warning('请填写完整预约信息')
    return
  }
  const d = new Date(f.startAt)
  // 预约时间窗校验（镜像后端 assertBookingWindow）
  const windowErr = bookingWindowError(f.startAt)
  if (windowErr) {
    ElMessage.warning(windowErr)
    return
  }
  const svc = services.value.find((s) => s.name === f.service) || {}
  const endAt = fmtDate(new Date(d.getTime() + (Number(svc.duration) || 120) * 60000))
  // 技师时段冲突校验（镜像后端 hasConflict：同技师、待服务/服务中、时间窗重叠）
  if (f.technician && hasConflictLocal(f.technician, fmtDate(d), endAt)) {
    ElMessage.warning(`技师 ${f.technician} 在该时段已有订单，请换时间或换技师`)
    return
  }
  const orderNo = 'BK' + d.getFullYear() + pad(d.getMonth() + 1) + pad(d.getDate()) + String(Date.now()).slice(-3)
  const price = svc.price || 0
  // 后端下单（服务计价/档期校验/订单号生成均以服务端为准）；后端不可达兜底本地创建
  const techInfo = f.technician ? techOptions.value.find((t) => t.name === f.technician) : null
  const created = await runAction(
    Number(svc.id) > 0
      ? () => ordersApi.create({
          service_id: Number(svc.id),
          start_at: fmtDate(d) + ':00',
          customer: f.customer,
          phone: f.phone,
          address: f.address,
          technician_id: Number(techInfo && techInfo.id) || 0,
          source: 'admin'
        })
      : null,
    () => crud.save(null, {
      orderNo,
      customer: f.customer,
      phone: f.phone,
      service: f.service,
      technician: f.technician || '待分配',
      startAt: fmtDate(d),
      endAt,
      address: f.address,
      total: price,
      discount: 0,
      amount: price,
      status: '待支付',
      paid: false,
      source: 'admin'
    })
  )
  if (!created) return
  createVisible.value = false
  ElMessage.success(f.technician ? '预约已创建（已指定技师），待支付后由技师接单' : '预约已创建，等待客户支付（待支付）')
  Object.assign(createForm, { customer: '', phone: '', service: '', startAt: '', address: '', technician: '' })
  reload()
}

// 收款：在线（支付宝/微信）或线下记录（pay_channel: alipay/wechat/stored/offline）
function openPay(row) {
  payRow.value = row
  payMethod.value = payMethods.value.length ? payMethods.value[0].key : ''
  payVisible.value = true
}

async function confirmPay() {
  const row = payRow.value
  if (!row) return
  paying.value = true
  const online = license.isPro && payMethod.value
  // 支付后分流（镜像 PayController/OrderController 口径）：指定技师→待接单；未指定→进派单池
  const hasTech = row.technician && row.technician !== '待分配' && row.technician !== '待派单'
  const next = hasTech ? '待接单' : '待派单'
  try {
    if (!canTransition(row.status, next)) {
      ElMessage.error(`订单状态不允许从「${row.status}」流转到「${next}」`)
      return
    }
    // 收款确认走后端（金额/分流状态服务端判定）；后端不可达兜底本地记录
    const paid = await runAction(
      () => payConfirm({ order_no: row.orderNo || row.id, channel: online ? payMethod.value : 'offline' }),
      () => crud.patch(rid(row), {
        status: next,
        payMethod: online ? payMethod.value : 'offline',
        paid: true
      })
    )
    if (!paid) return
    const label = online ? (payMethod.value === 'alipay' ? '支付宝' : '微信支付') : '线下收款'
    ElMessage.success(online ? `${label}支付成功，订单进入${next}` : `已记录线下收款，订单进入${next}`)
    payVisible.value = false
    reload()
  } finally {
    paying.value = false
  }
}

// ===== 状态机动作（业务错位整改①：一律走字典 + canTransition 断言，禁手写状态分叉） =====

function doAction(key, row) {
  switch (key) {
    case 'pay': return openPay(row)
    case 'cancel': return handleCancel(row)
    case 'noshow': return handleNoshow(row)
    case 'dispatch': return openDispatch(row)
    case 'reschedule': return openReschedule(row)
    case 'start': return transitionTo(row, '服务中', '已开始服务')
    case 'extend': return openExtend(row)
    case 'finish': return transitionTo(row, '待确认', '服务已完成，等待用户确认')
    case 'aftersale': return applyAftersale(row)
    case 'refund': return handleRefund(row)
    case 'rejectAftersale': return handleRejectAftersale(row)
    default: return ElMessage.info(`动作「${key}」暂未开放`)
  }
}

async function transitionTo(row, next, msg) {
  if (!canTransition(row.status, next)) {
    ElMessage.error(`订单状态不允许从「${row.status}」流转到「${next}」`)
    return
  }
  // 动作映射（后端状态机口径）：开始服务=checkin(kind:开始) 流转 待服务→服务中；完成服务=finish 流转 服务中→待确认
  const orderNo = row.orderNo || row.id
  const apiFn = next === '服务中'
    ? () => ordersApi.checkin(orderNo, { kind: '开始' })
    : next === '待确认'
      ? () => ordersApi.finish(orderNo)
      : null
  const done = await runAction(apiFn, () => crud.patch(rid(row), { status: next }))
  if (!done) return
  ElMessage.success(msg)
  reload()
}

// 派单/改派：待派单→待接单（首次指派写状态）；待接单改派仅换技师（镜像 OrderController/dispatch）
function openDispatch(row) {
  dispatchRow.value = row
  dispatchTech.value = row.technician !== '待分配' ? row.technician : ''
  dispatchVisible.value = true
}

async function confirmDispatch() {
  const row = dispatchRow.value
  const tech = dispatchTech.value
  if (!row || !tech) return ElMessage.warning('请选择技师')
  const endAt = row.endAt || fmtDate(new Date(new Date(row.startAt).getTime() + 120 * 60000))
  if (hasConflictLocal(tech, row.startAt, endAt, row.orderNo || row.id)) {
    return ElMessage.warning(`技师 ${tech} 在该时段已有订单，请换其他技师`)
  }
  const patch = { technician: tech }
  if (row.status === '待派单') {
    if (!canTransition('待派单', '待接单')) return ElMessage.error('状态流转非法')
    patch.status = '待接单'
  }
  // 派单走后端（技师时段冲突服务端校验）；技师名映射 id，映射不到兜底本地
  const techInfo = techOptions.value.find((t) => t.name === tech)
  const techId = Number(techInfo && techInfo.id) || 0
  const done = await runAction(
    techId ? () => ordersApi.dispatch(row.orderNo || row.id, techId) : null,
    () => crud.patch(rid(row), patch)
  )
  if (!done) return
  dispatchVisible.value = false
  ElMessage.success(row.status === '待派单' ? `已指派 ${tech}，等待接单` : `已改派给 ${tech}`)
  reload()
}

// 改约：允许待接单/待服务（镜像 OrderController/reschedule），仅改时间不改状态
function openReschedule(row) {
  rescheduleRow.value = row
  rescheduleAt.value = row.startAt
  rescheduleVisible.value = true
}

async function confirmReschedule() {
  const row = rescheduleRow.value
  if (!row || !rescheduleAt.value) return ElMessage.warning('请选择新上门时间')
  const windowErr = bookingWindowError(rescheduleAt.value)
  if (windowErr) return ElMessage.warning(windowErr)
  const endAt = row.endAt || fmtDate(new Date(new Date(row.startAt).getTime() + 120 * 60000))
  const newEnd = fmtDate(new Date(new Date(rescheduleAt.value).getTime() + (new Date(endAt) - new Date(row.startAt))))
  if (row.technician && row.technician !== '待分配' && hasConflictLocal(row.technician, rescheduleAt.value, newEnd, row.orderNo || row.id)) {
    return ElMessage.warning(`技师 ${row.technician} 在新时段已有订单，请换时间`)
  }
  // 改约走后端（预约时间窗/档期冲突服务端校验）；后端不可达兜底本地改期
  // 时间归一：值可能已是 Y-m-d H:i:s（后端行）或 Y-m-d H:i（本地选择器），仅缺秒时补秒
  const startAtFull = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/.test(rescheduleAt.value) ? rescheduleAt.value + ':00' : rescheduleAt.value
  const done = await runAction(
    () => ordersApi.reschedule(row.orderNo || row.id, startAtFull),
    () => crud.patch(rid(row), { startAt: rescheduleAt.value, endAt: newEnd })
  )
  if (!done) return
  rescheduleVisible.value = false
  ElMessage.success('已改约')
  reload()
}

// 售后入口（阶段 4 建售后管理域，此处先落状态与原因）：记录 preStatus 供驳回回退
async function applyAftersale(row) {
  let reason = ''
  try {
    const r = await ElMessageBox.prompt('请填写转售后原因（纠纷/退款诉求等）', '转售后', {
      type: 'warning',
      confirmButtonText: '确认转售后',
      cancelButtonText: '再想想',
      inputPlaceholder: '售后原因',
      inputValidator: (v) => (v && v.trim() ? true : '请填写原因')
    })
    reason = r.value
  } catch (e) {
    return
  }
  if (!canTransition(row.status, '售后中')) return ElMessage.error(`「${row.status}」不允许转售后`)
  // 转售后走后端（记录 pre_status 供驳回回退）；后端不可达兜底本地落状态
  const done = await runAction(
    () => ordersApi.aftersale(row.orderNo || row.id, reason),
    () => crud.patch(rid(row), { status: '售后中', preStatus: row.status, aftersaleReason: reason, aftersaleAt: nowText() })
  )
  if (!done) return
  ElMessage.success('已转入售后处理')
  reload()
}

async function handleRefund(row) {
  if (!canTransition(row.status, '已退款')) {
    return ElMessage.error(`「${row.status}」不允许退款（仅售后中可退款）`)
  }
  try {
    await ElMessageBox.confirm(`确认对订单 ${row.id} 执行退款吗？退款后订单进入「已退款」终态。`, '售后退款', {
      type: 'warning',
      confirmButtonText: '确认退款',
      cancelButtonText: '再想想'
    })
  } catch (e) {
    return
  }
  // 售后联动（阶段 4：售后申请→退款单→回写）：生成退款单，RefundList 可查
  // 退款走后端（金额只信服务端按实付全额退，事务落 refund 单）；后端不可达兜底本地生成退款单
  const done = await runAction(
    () => ordersApi.refund(row.orderNo || row.id, row.aftersaleReason || '售后退款'),
    async () => {
      await refundCrud.save(null, {
        order: row.id,
        member: row.customer,
        amount: Number(row.amount) || 0,
        channel: row.payMethod || '微信支付',
        reason: row.aftersaleReason || '售后退款',
        createdAt: nowText(),
        operator: '系统',
        status: '已退款',
        refundNo: 'RF' + Date.now().toString().slice(-10),
        reason2: ''
      })
      await crud.patch(rid(row), { status: '已退款' })
    }
  )
  if (!done) return
  ElMessage.success('已退款，订单关闭')
  refundCrud.load({ page: 1, per_page: 200 })
  reload()
}

async function handleRejectAftersale(row) {
  const back = row.preStatus
  if (!back || !canTransition('售后中', back)) {
    return ElMessage.error('无法驳回：原状态缺失或非法（仅可回 待确认/已完成/待评价/已评价）')
  }
  try {
    await ElMessageBox.confirm(`驳回售后申请，订单回到「${back}」。`, '售后驳回', {
      type: 'warning',
      confirmButtonText: '确认驳回',
      cancelButtonText: '再想想'
    })
  } catch (e) {
    return
  }
  // 售后驳回走后端（回 pre_status 并落 aftersale_result）；后端不可达兜底本地回退
  const done = await runAction(
    () => ordersApi.aftersaleReject(row.orderNo || row.id, '后台驳回'),
    () => crud.patch(rid(row), { status: back, aftersaleResult: '驳回（' + nowText() + '）' })
  )
  if (!done) return
  ElMessage.success(`售后已驳回，订单回到「${back}」`)
  reload()
}

// ===== 校验工具（只镜像后端规则，不另立口径） =====

/** 格式化 Y-m-d H:i */
function fmtDate(d) {
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`
}

/** 预约时间窗（镜像 OrderService::assertBookingWindow）：返回错误文案或空串 */
function bookingWindowError(startAt) {
  const ts = new Date(startAt).getTime()
  if (Number.isNaN(ts)) return '预约时间格式无效'
  const earliest = Date.now() + RULES.advanceHours * 3600000
  const latest = Date.now() + RULES.maxAdvanceDays * 86400000
  if (ts < earliest) return `需提前 ${RULES.advanceHours} 小时预约`
  if (ts > latest) return `最远可预约 ${RULES.maxAdvanceDays} 天内的服务`
  return ''
}

/**
 * 技师时段冲突（镜像 OrderService::hasConflict：同技师、待服务/服务中、existing.start < new.end AND existing.end > new.start、排除自身）
 * mock 按技师名匹配；后端按 technician_id，接入后端后以前端校验为提示、后端校验为准
 */
function hasConflictLocal(tech, startAt, endAt, excludeOrderNo = '') {
  const s = new Date(startAt).getTime()
  const e = new Date(endAt).getTime()
  return crud.state.rows.some((b) => {
    if (b.technician !== tech) return false
    if (!['待服务', '服务中'].includes(b.status)) return false
    const no = b.orderNo || b.id
    if (excludeOrderNo && String(no) === String(excludeOrderNo)) return false
    const bs = new Date(b.startAt).getTime()
    const be = new Date(b.endAt || new Date(new Date(b.startAt).getTime() + 120 * 60000)).getTime()
    return bs < e && be > s
  })
}

function openExtend(row) {
  extendRow.value = row
  extendHours.value = 1
  const svc = services.value.find((s) => s.name === row.service)
  const perHour = svc && svc.duration ? Math.round(svc.price / (svc.duration / 60)) : 60
  extendAmount.value = perHour
}

async function confirmExtend() {
  const row = extendRow.value
  if (!row || extendHours.value <= 0) return
  const hours = extendHours.value
  const add = extendAmount.value
  // 加钟走后端（差价服务端按服务时长比例复算，写 order_extend 留痕）；后端不可达兜底本地留痕
  const done = await runAction(
    () => ordersApi.extend(row.orderNo || row.id, {
      minutes: Math.round(hours * 60),
      reason: `加钟 ${hours} 小时`,
      operator: '李掌柜',
      confirm: 1
    }),
    async () => {
      await extCrud.save(null, {
        orderNo: row.orderNo || row.id,
        minutes: Math.round(hours * 60),
        price: add,
        reason: `加钟 ${hours} 小时`,
        operator: '李掌柜',
        status: '已生效',
        at: nowText()
      })
      await crud.patch(rid(row), { amount: (Number(row.amount) || 0) + add })
    }
  )
  if (!done) return
  extendVisible.value = false
  ElMessage.success(`已加钟 ${hours} 小时，订单金额已更新`)
  reload()
  extCrud.load({ page: 1, per_page: 50 })
}

function openDetail(row) {
  current.value = row
  detailVisible.value = true
}

async function handleCancel(row) {
  // 违约金提示（镜像 OrderService::calcCancelFee：距开始不足 cancelLimitHours 小时按 cancelFeeRate% 收取）
  const hoursToStart = (new Date(row.startAt).getTime() - Date.now()) / 3600000
  const feeTip = hoursToStart <= RULES.cancelLimitHours
    ? `距服务开始不足 ${RULES.cancelLimitHours} 小时，按规则收取订单金额 ${RULES.cancelFeeRate}% 违约金（￥${Math.round((Number(row.amount) || 0) * RULES.cancelFeeRate) / 100}）`
    : '当前时段取消免费'
  try {
    await ElMessageBox.confirm(`确认取消订单 ${row.id} 吗？取消后不可恢复。${feeTip}。`, '取消预约', {
      type: 'warning',
      confirmButtonText: '确认取消',
      cancelButtonText: '再想想'
    })
  } catch (e) {
    return
  }
  // 取消走后端（违约金服务端复算落账）；后端不可达兜底本地取消
  const done = await runAction(
    () => ordersApi.cancel(row.orderNo || row.id, '后台取消'),
    () => crud.patch(rid(row), { status: '已取消', cancelReason: '后台取消' })
  )
  if (!done) return
  ElMessage.success('订单已取消')
  reload()
}

// 标记客户爽约（技师已到场而客户未到）：待服务 → 已取消；后端累计月内爽约次数，
// 达 noshow_limit 后按 noshow_freeze_days 冻结该会员下单（见 OrderService::markNoshow）
async function handleNoshow(row) {
  try {
    await ElMessageBox.confirm(
      `确认将订单 ${row.id} 标记为「客户爽约」吗？该单将转已取消，并累计该客户本月爽约次数（达上限后按规则限制其下单）。`,
      '标记客户爽约',
      { type: 'warning', confirmButtonText: '确认爽约', cancelButtonText: '再想想' }
    )
  } catch (e) {
    return
  }
  const done = await runAction(
    () => ordersApi.noshow(row.orderNo || row.id, '后台标记：技师到场客户未到'),
    () => crud.patch(rid(row), { status: '已取消', cancelReason: '客户爽约' })
  )
  if (!done) return
  ElMessage.success('已记录客户爽约')
  reload()
}

// 导出当前筛选结果 CSV（与表格列一致）
function handleExport() {
  const rows = filteredData.value
  if (!rows.length) {
    ElMessage.warning('暂无数据可导出')
    return
  }
  const header = ['订单号', '客户', '电话', '服务项目', '技师', '上门时间', '地址', '金额', '状态']
  const lines = rows.map((r) =>
    [r.id, r.customer, r.phone, r.service, r.technician, r.startAt, r.address, r.amount, r.status]
      .map((v) => `"${String(v ?? '').replace(/"/g, '""')}"`)
      .join(',')
  )
  const csv = '\uFEFF' + [header.join(','), ...lines].join('\r\n')
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' })
  const a = document.createElement('a')
  a.href = URL.createObjectURL(blob)
  a.download = `预约订单_${new Date().toISOString().slice(0, 10)}.csv`
  a.click()
  URL.revokeObjectURL(a.href)
  ElMessage.success('导出成功')
}

function reload() {
  return crud.load({ page: 1, per_page: 200 })
}

onMounted(() => {
  // 首页看板「详情」跳转带 keyword 定位订单
  const kw = useRoute().query.keyword
  if (kw) query.keyword = String(kw)
  reload()
  loadRules()
  svcCrud.load({ page: 1, per_page: 100 })
  techCrud.load({ page: 1, per_page: 50 })
})
</script>

<style scoped>
/* 分页条样式统一在 tokens.css .pagination-bar（固定高度常驻底部） */
.pay-amount {
  color: var(--danger);
  font-weight: 600;
  font-size: 14px;
}

.pay-method-label {
  margin-bottom: var(--space-xs);
  color: var(--text-secondary);
}
</style>
