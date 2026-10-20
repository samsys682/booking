<template>
  <div class="content">
    <div class="content-card content-card--list">
      <div class="action-bar">
        <h2 class="page-title">优惠券</h2>
        <div class="action-bar__left">
          <span class="page-subtitle">满减/折扣/新人券：新建模板 → 发放 → 核销统计</span>
        </div>
        <div class="action-bar__right">
          <!-- 店员扫码/输码核销入口 -->
          <el-button :icon="Tickets" @click="openVerify">券码核销</el-button>
          <el-button type="primary" :icon="Plus" @click="openCreate">新建优惠券</el-button>
        </div>
      </div>

      <div class="table-wrap">
        <el-table :data="coupons" empty-text="暂无优惠券">
          <el-table-column prop="id" label="券号" width="80" />
          <el-table-column prop="name" label="券名称" min-width="150" />
          <el-table-column prop="type" label="类型" width="80">
            <template #default="{ row }"><el-tag size="small" effect="plain">{{ row.type }}</el-tag></template>
          </el-table-column>
          <el-table-column label="面额/折扣" width="90">
            <template #default="{ row }">{{ fmtValue(row) }}</template>
          </el-table-column>
          <el-table-column label="使用门槛" width="110">
            <template #default="{ row }">{{ fmtThreshold(row) }}</template>
          </el-table-column>
          <el-table-column label="已用 / 已发 / 总量" width="140">
            <template #default="{ row }">{{ row.used }} / {{ row.sent }} / {{ row.total }}</template>
          </el-table-column>
          <el-table-column prop="expires" label="有效期至" width="110" />
          <el-table-column prop="status" label="状态" width="90">
            <template #default="{ row }">
              <el-tag :type="row.status === '发放中' ? 'success' : row.status === '已结束' ? 'info' : 'warning'" size="small">{{ row.status }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="200" fixed="right">
            <template #default="{ row }">
              <el-button v-if="row.status === '发放中' && row.sent < row.total" type="primary" link size="small" @click="openSend(row)">发放</el-button>
              <el-button type="primary" link size="small" @click="openInstances(row)">卡券</el-button>
              <el-button type="primary" link size="small" @click="openLogs(row)">记录</el-button>
              <el-button v-if="row.status === '发放中'" type="danger" link size="small" @click="handleStop(row)">作废</el-button>
            </template>
          </el-table-column>
        </el-table>
      </div>

      <div class="pagination-bar">
        <el-pagination :total="coupons.length" :page-size="10" layout="total" background size="small" />
      </div>
    </div>

    <!-- 新建券 -->
    <el-dialog v-model="createVisible" title="新建优惠券" width="480px">
      <el-form :model="createForm" label-width="90px">
        <el-form-item label="券名称"><el-input v-model="createForm.name" placeholder="如：新人立减 30 元" /></el-form-item>
        <el-form-item label="类型">
          <el-radio-group v-model="createForm.type">
            <el-radio-button value="满减">满减</el-radio-button>
            <el-radio-button value="折扣">折扣</el-radio-button>
          </el-radio-group>
        </el-form-item>
        <el-form-item :label="createForm.type === '满减' ? '减免金额' : '折扣（%）'">
          <el-input-number v-model="createForm.value" :min="createForm.type === '满减' ? 1 : 50" :max="createForm.type === '满减' ? 9999 : 99" style="width: 100%" />
        </el-form-item>
        <el-form-item label="使用门槛">
          <el-input-number v-model="createForm.threshold" :min="0" :step="10" style="width: 100%" />
          <span class="form-tip">单位：元；填 0 表示不限门槛</span>
        </el-form-item>
        <el-form-item label="发放总量"><el-input-number v-model="createForm.total" :min="1" style="width: 100%" /></el-form-item>
        <el-form-item label="有效期至"><el-date-picker v-model="createForm.expires" type="date" value-format="YYYY-MM-DD" style="width: 100%" /></el-form-item>
      </el-form>
      <template #footer>
        <div class="modal-footer">
          <el-button @click="createVisible = false">取消</el-button>
          <el-button type="primary" @click="handleCreate">创建并开始发放</el-button>
        </div>
      </template>
    </el-dialog>

    <!-- 发放 -->
    <el-dialog v-model="sendVisible" title="发放优惠券" width="420px">
      <el-descriptions v-if="sendRow" :column="1" border size="small" style="margin-bottom: var(--space-md)">
        <el-descriptions-item label="券">{{ sendRow.name }}（{{ fmtValue(sendRow) }}）</el-descriptions-item>
        <el-descriptions-item label="剩余可发">{{ sendRow.total - sendRow.sent }} 张</el-descriptions-item>
      </el-descriptions>
      <el-form label-width="90px">
        <el-form-item label="发放对象"><el-input v-model="sendForm.to" placeholder="会员姓名 / 全部新客" /></el-form-item>
        <el-form-item label="发放数量"><el-input-number v-model="sendForm.count" :min="1" :max="sendRow ? sendRow.total - sendRow.sent : 1" style="width: 100%" /></el-form-item>
      </el-form>
      <template #footer>
        <div class="modal-footer">
          <el-button @click="sendVisible = false">取消</el-button>
          <el-button type="primary" @click="confirmSend">确认发放</el-button>
        </div>
      </template>
    </el-dialog>

    <!-- 券码核销（一券一码，输码/扫码 → 确认 → 关联订单） -->
    <el-dialog v-model="verifyVisible" title="券码核销" width="480px">
      <el-form label-width="90px">
        <el-form-item label="券码">
          <el-input v-model="verifyForm.code" placeholder="输入或扫码：CPN-XXXX-XXXX" clearable @input="onCodeInput" />
        </el-form-item>
      </el-form>
      <el-descriptions v-if="verifyMatch" :column="1" border size="small" style="margin-bottom: var(--space-md)">
        <el-descriptions-item label="券名称">{{ verifyMatch.couponName }}</el-descriptions-item>
        <el-descriptions-item label="持有人">{{ verifyMatch.member }}（{{ verifyMatch.phone }}）</el-descriptions-item>
        <el-descriptions-item label="状态">
          <el-tag :type="instanceTag(verifyMatch.status)" size="small">{{ verifyMatch.status }}</el-tag>
        </el-descriptions-item>
      </el-descriptions>
      <div v-else-if="verifyForm.code" class="form-tip">未找到该券码，请检查输入</div>
      <el-form v-if="verifyMatch && verifyMatch.status === '未使用'" label-width="90px">
        <el-form-item label="关联订单"><el-input v-model="verifyForm.orderNo" placeholder="预约订单号（可留空）" /></el-form-item>
      </el-form>
      <template #footer>
        <div class="modal-footer">
          <el-button @click="verifyVisible = false">取消</el-button>
          <el-button type="primary" :disabled="!verifyMatch || verifyMatch.status !== '未使用'" @click="confirmVerify">确认核销</el-button>
        </div>
      </template>
    </el-dialog>

    <!-- 卡券明细（每张券的卡密与状态，可单张核销） -->
    <el-dialog v-model="instanceVisible" :title="instanceRow ? `卡券明细 · ${instanceRow.name}` : '卡券明细'" width="760px">
      <el-table :data="rowInstances" size="small" max-height="400" empty-text="该券尚未发放卡券">
        <el-table-column prop="code" label="券码" width="150" />
        <el-table-column prop="member" label="持有人" width="90" />
        <el-table-column prop="source" label="来源" width="100" />
        <el-table-column prop="at" label="发放时间" width="140" />
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="instanceTag(row.status)" size="small">{{ row.status }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="orderNo" label="核销订单">
          <template #default="{ row }">{{ row.orderNo || row.usedAt || '—' }}</template>
        </el-table-column>
        <el-table-column label="操作" width="80">
          <template #default="{ row }">
            <el-button v-if="row.status === '未使用'" type="primary" link size="small" @click="openVerify(row.code)">核销</el-button>
          </template>
        </el-table-column>
      </el-table>
      <template #footer>
        <div class="modal-footer"><el-button @click="instanceVisible = false">关闭</el-button></div>
      </template>
    </el-dialog>

    <!-- 流水 -->
    <el-dialog v-model="logsVisible" title="券操作记录" width="560px">
      <el-table :data="currentLogs" size="small" max-height="360">
        <el-table-column prop="at" label="时间" width="150" />
        <el-table-column prop="action" label="动作" width="80" />
        <el-table-column prop="detail" label="说明" />
      </el-table>
      <template #footer>
        <div class="modal-footer"><el-button @click="logsVisible = false">关闭</el-button></div>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus, Tickets } from '@element-plus/icons-vue'
import { useCrud } from '../composables/useCrud'

// 三资源接线：券模板 / 券码实例 / 操作流水（API 优先，内存 mock 兜底）
const crudC = useCrud('coupons', {
  keywordField: ['name', 'code', 'type'],
  mapRow: (r) => {
    const out = { ...r }
    out._rid = out.id
    if (out.code) out.id = out.code // 显示券号优先用 code
    return out
  }
})
const crudI = useCrud('coupon-instances', {
  keywordField: ['code', 'couponName', 'member', 'phone'],
  mapRow: (r) => {
    const out = { ...r }
    out._rid = out.id
    return out
  }
})
const crudL = useCrud('coupon-logs', {
  keywordField: ['coupon', 'action', 'detail']
})

const coupons = computed(() => crudC.state.rows)
const ridOf = (row) => row._rid ?? row.id

onMounted(() => {
  crudC.load({ page: 1, per_page: 200 })
  crudI.load({ page: 1, per_page: 200 })
  crudL.load({ page: 1, per_page: 200 })
})

const createVisible = ref(false)
const sendVisible = ref(false)
const logsVisible = ref(false)
const verifyVisible = ref(false)
const instanceVisible = ref(false)
const sendRow = ref(null)
const instanceRow = ref(null)
const currentLogs = ref([])
const createForm = reactive({ name: '', type: '满减', value: 30, threshold: 0, total: 100, expires: '' })
const sendForm = reactive({ to: '', count: 1 })
const verifyForm = reactive({ code: '', orderNo: '' })

// 券码实例（一券一码；发放生成，核销关订单）
const verifyMatch = computed(() => {
  const code = verifyForm.code.trim().toUpperCase()
  if (!code) return null
  return crudI.state.rows.find((c) => String(c.code).toUpperCase() === code) || null
})

const rowInstances = computed(() =>
  instanceRow.value
    ? crudI.state.rows.filter((c) => String(c.couponId) === String(ridOf(instanceRow.value)))
    : []
)

function instanceTag(s) {
  return { 未使用: 'success', 已使用: 'info', 已过期: 'warning', 已作废: 'danger' }[s] || 'info'
}

// 券模板数值口径（与后端 MarketingService::quoteCoupon / MyController::mapCouponTpl 一致）：
//   value：满减存「分」；折扣存百分比（88 = 8.8 折）
//   threshold：门槛存「分」，0 = 不限
function fmtValue(row) {
  const v = Number(row?.value || 0)
  if (row?.type === '折扣') return `${(v / 10).toFixed(1).replace(/\.0$/, '')} 折`
  return `减 ¥${(v / 100).toFixed(2).replace(/\.00$/, '')}`
}
function fmtThreshold(row) {
  const t = Number(row?.threshold || 0)
  return t > 0 ? `满 ¥${(t / 100).toFixed(2).replace(/\.00$/, '')} 可用` : '不限'
}

// 券码生成：CPN-XXXX-XXXX（去除易混字符）
function genCode() {
  const chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'
  const seg = () => Array.from({ length: 4 }, () => chars[Math.floor(Math.random() * chars.length)]).join('')
  let code
  do {
    code = `CPN-${seg()}-${seg()}`
  } while (crudI.state.rows.some((c) => c.code === code))
  return code
}

function openCreate() {
  Object.assign(createForm, { name: '', type: '满减', value: 30, threshold: 0, total: 100, expires: '' })
  createVisible.value = true
}

async function handleCreate() {
  if (!createForm.name || !createForm.expires) {
    ElMessage.warning('请填写券名称和有效期')
    return
  }
  const code = 'CP' + String(Date.now()).slice(-8)
  // 数值口径：满减 value 存分（元×100）；折扣 value 存百分比；threshold 存分（0=不限）
  const value = createForm.type === '满减'
    ? Math.round(Number(createForm.value) * 100)
    : Math.round(Number(createForm.value))
  const threshold = Math.round(Number(createForm.threshold || 0) * 100)
  await crudC.save(null, {
    code,
    name: createForm.name,
    type: createForm.type,
    value,
    threshold,
    total: createForm.total,
    sent: 0,
    used: 0,
    expires: createForm.expires,
    status: '发放中'
  })
  await crudL.save(null, { coupon: code, action: '新建', count: createForm.total, detail: `${createForm.name}，总量 ${createForm.total} 张`, at: now() })
  createVisible.value = false
  ElMessage.success('优惠券已创建，可开始发放')
  crudC.load({ page: 1, per_page: 200 })
  crudL.load({ page: 1, per_page: 200 })
}

function openSend(row) {
  sendRow.value = row
  sendForm.to = ''
  sendForm.count = 1
  sendVisible.value = true
}

async function confirmSend() {
  const row = sendRow.value
  if (!row) return
  // 发放即生成卡密，一券一码
  for (let i = 0; i < sendForm.count; i++) {
    await crudI.save(null, {
      code: genCode(),
      couponId: ridOf(row),
      couponName: row.name,
      member: sendForm.to || '未指定',
      phone: '',
      source: '手工发放',
      at: now(),
      status: '未使用',
      usedAt: '',
      orderNo: ''
    })
  }
  row.sent += sendForm.count
  crudC.patch(ridOf(row), { sent: row.sent })
  await crudL.save(null, { coupon: row.id, action: '发放', count: sendForm.count, detail: `向「${sendForm.to || '未指定对象'}」发放 ×${sendForm.count}，生成券码 ${sendForm.count} 个`, at: now() })
  sendVisible.value = false
  ElMessage.success(`已发放 ${sendForm.count} 张，卡密已生成`)
  crudI.load({ page: 1, per_page: 200 })
  crudL.load({ page: 1, per_page: 200 })
}

// 券码核销
function openVerify(code) {
  verifyForm.code = code || ''
  verifyForm.orderNo = ''
  verifyVisible.value = true
}

function onCodeInput() {
  // verifyMatch 为 computed，输入即自动匹配；此处留空便于后续接扫码枪回调
}

async function confirmVerify() {
  const inst = verifyMatch.value
  if (!inst || inst.status !== '未使用') return
  inst.status = '已使用'
  inst.usedAt = now()
  inst.orderNo = verifyForm.orderNo || ''
  crudI.patch(ridOf(inst), { status: inst.status, usedAt: inst.usedAt, orderNo: inst.orderNo })
  const cp = coupons.value.find((c) => String(ridOf(c)) === String(inst.couponId))
  if (cp) {
    cp.used += 1
    crudC.patch(ridOf(cp), { used: cp.used })
  }
  await crudL.save(null, { coupon: cp ? cp.id : inst.couponName, action: '核销', count: 1, detail: `券码 ${inst.code}（${inst.member}）${inst.orderNo ? `订单 ${inst.orderNo} ` : ''}抵扣成功`, at: now() })
  verifyVisible.value = false
  ElMessage.success(`核销成功：${inst.couponName}`)
  crudL.load({ page: 1, per_page: 200 })
}

// 卡券明细
function openInstances(row) {
  instanceRow.value = row
  instanceVisible.value = true
}

function handleStop(row) {
  ElMessageBox.confirm(`确认作废「${row.name}」吗？未使用的券将全部失效。`, '作废优惠券', { type: 'warning' })
    .then(async () => {
      row.status = '已作废'
      crudC.patch(ridOf(row), { status: row.status })
      // 未使用的卡密一并作废
      for (const c of crudI.state.rows) {
        if (String(c.couponId) === String(ridOf(row)) && c.status === '未使用') {
          c.status = '已作废'
          crudI.patch(ridOf(c), { status: '已作废' })
        }
      }
      await crudL.save(null, { coupon: row.id, action: '作废', detail: `${row.name}作废，未使用 ${row.sent - row.used} 张失效`, at: now() })
      ElMessage.success('已作废')
      crudL.load({ page: 1, per_page: 200 })
    })
    .catch(() => {})
}

async function openLogs(row) {
  await crudL.load({ page: 1, per_page: 200 })
  currentLogs.value = crudL.state.rows.filter((l) => String(l.coupon) === String(row.id))
  logsVisible.value = true
}

function now() {
  return new Date().toLocaleString('zh-CN', { hour12: false })
}
</script>

<style scoped>
.page-subtitle {
  color: var(--text-tertiary);
  white-space: nowrap;
}
</style>
