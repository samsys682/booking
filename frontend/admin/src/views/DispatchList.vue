<template>
  <div class="page">
    <!-- 派单规则：抢单模式开关 + 推荐权重（dispatch_rule 可配） -->
    <el-card shadow="never" class="rule-card">
      <div class="rule-row">
        <div class="rule-item">
          <span class="rule-label">派单模式</span>
          <el-switch
            :model-value="dispatchRule.mode === '抢单'"
            active-text="抢单"
            inactive-text="指派"
            @change="toggleMode"
          />
          <span class="rule-tip">
            {{ dispatchRule.mode === '抢单' ? '抢单模式：订单进池后由技师端抢单，无需人工指派' : '指派模式：派单中心人工指派/改派技师' }}
          </span>
        </div>
        <div class="rule-item">
          <span class="rule-label">推荐权重</span>
          <span class="w-item">技能 <el-input-number v-model="dispatchRule.weights.skill" :min="0" :max="100" size="small" controls-position="right" /></span>
          <span class="w-item">档期 <el-input-number v-model="dispatchRule.weights.schedule" :min="0" :max="100" size="small" controls-position="right" /></span>
          <span class="w-item">负载 <el-input-number v-model="dispatchRule.weights.load" :min="0" :max="100" size="small" controls-position="right" /></span>
          <span class="w-item">评分 <el-input-number v-model="dispatchRule.weights.rating" :min="0" :max="100" size="small" controls-position="right" /></span>
          <span class="rule-tip">合计 {{ weightSum }}（越接近 100 权重越标准）</span>
        </div>
      </div>
    </el-card>

    <!-- 待派单池 -->
    <el-card shadow="never" class="section">
      <template #header>
        <div class="card-head">
          <span>派单池 · 待派单（{{ poolRows.length }}）</span>
          <span class="tiny muted">超时预警阈值 {{ dispatchRule.timeoutHours }} 小时 · 拒单回池订单自动排除原技师</span>
        </div>
      </template>
      <el-table :data="poolRows" v-loading="loading" stripe>
        <el-table-column label="订单号" width="150">
          <template #default="{ row }">{{ row.id }}</template>
        </el-table-column>
        <el-table-column label="客户" width="140">
          <template #default="{ row }">
            <div>{{ row.customer }}</div>
            <div class="tiny muted">{{ row.phone }}</div>
          </template>
        </el-table-column>
        <el-table-column prop="service" label="项目" min-width="130" />
        <el-table-column label="时段" width="150">
          <template #default="{ row }">{{ row.startAt }}</template>
        </el-table-column>
        <el-table-column label="实收" width="90" align="right">
          <template #default="{ row }">¥{{ row.amount }}</template>
        </el-table-column>
        <el-table-column label="等待" width="120">
          <template #default="{ row }">
            <el-tag :type="waitOf(row).over ? 'danger' : 'warning'" size="small">
              {{ waitOf(row).over ? '超时预警 ' : '' }}{{ waitOf(row).text }}
            </el-tag>
            <div v-if="row.preTechnician" class="tiny muted">
              <el-tag type="info" size="small">拒单回池</el-tag> 原技师 {{ row.preTechnician }}
            </div>
          </template>
        </el-table-column>
        <el-table-column label="推荐技师 Top3" min-width="240">
          <template #default="{ row }">
            <div v-for="(r, i) in recommend(row)" :key="r.tech.id" class="rec-line">
              <span class="rec-rank">{{ i + 1 }}</span>
              <span class="rec-name">{{ r.tech.name }}</span>
              <span class="rec-score">{{ r.score }} 分</span>
              <span class="tiny muted">{{ r.reason }}</span>
            </div>
            <div v-if="!recommend(row).length" class="tiny muted">暂无可推荐技师</div>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="140" fixed="right">
          <template #default="{ row }">
            <el-button type="primary" size="small" @click="openAssign(row)">指派</el-button>
            <el-button size="small" @click="openForm(row)">编辑</el-button>
          </template>
        </el-table-column>
      </el-table>
      <el-empty v-if="!poolRows.length && !loading" description="派单池为空，暂无待派单订单" />
    </el-card>

    <!-- 待接单（改派入口） -->
    <el-card shadow="never" class="section">
      <template #header>
        <div class="card-head">
          <span>待接单 · 等技师确认（{{ waitingRows.length }}）</span>
          <span class="tiny muted">技师拒单/超时未接会自动回池；改派 = 更换技师并保持「待接单」</span>
        </div>
      </template>
      <el-table :data="waitingRows" v-loading="loading" stripe>
        <el-table-column label="订单号" width="150">
          <template #default="{ row }">{{ row.id }}</template>
        </el-table-column>
        <el-table-column prop="customer" label="客户" width="110" />
        <el-table-column prop="service" label="项目" min-width="130" />
        <el-table-column label="已指派" width="110">
          <template #default="{ row }">{{ row.technician }}</template>
        </el-table-column>
        <el-table-column label="时段" width="150">
          <template #default="{ row }">{{ row.startAt }}</template>
        </el-table-column>
        <el-table-column label="指派时间" width="150">
          <template #default="{ row }">{{ row.dispatchAt || '—' }}</template>
        </el-table-column>
        <el-table-column label="操作" width="140" fixed="right">
          <template #default="{ row }">
            <el-button type="warning" size="small" @click="openAssign(row, 'reassign')">改派</el-button>
            <el-button size="small" @click="openForm(row)">编辑</el-button>
          </template>
        </el-table-column>
      </el-table>
      <el-empty v-if="!waitingRows.length && !loading" description="暂无待接单订单" />
    </el-card>

    <!-- 全部订单（CRUD + 12 态筛选） -->
    <el-card shadow="never" class="section">
      <template #header>
        <div class="card-head">
          <span>全部订单</span>
          <div class="filters">
            <el-select v-model="query.status" placeholder="全部状态" clearable style="width: 130px" size="small">
              <el-option v-for="s in ALL_STATUSES" :key="s" :label="s" :value="s" />
            </el-select>
            <el-input v-model="query.keyword" placeholder="订单号/客户/电话" clearable style="width: 190px" size="small" />
            <el-button type="primary" size="small" :icon="Plus" @click="openForm()">新建订单</el-button>
          </div>
        </div>
      </template>
      <el-table :data="filteredRows" v-loading="loading" stripe>
        <el-table-column label="订单号" width="150">
          <template #default="{ row }">{{ row.id }}</template>
        </el-table-column>
        <el-table-column prop="customer" label="客户" width="110" />
        <el-table-column prop="phone" label="电话" width="120" />
        <el-table-column prop="service" label="项目" min-width="130" />
        <el-table-column prop="technician" label="技师" width="100" />
        <el-table-column prop="startAt" label="时段" width="150" />
        <el-table-column label="实收" width="90" align="right">
          <template #default="{ row }">¥{{ row.amount }}</template>
        </el-table-column>
        <el-table-column label="状态" width="100">
          <template #default="{ row }">
            <el-tag :type="STATUS_META[row.status]?.type || 'info'" size="small">{{ row.status }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="220" fixed="right">
          <template #default="{ row }">
            <el-button
              v-if="canTransition(row.status, '已取消') && !isTerminal(row.status)"
              size="small"
              @click="cancelRow(row)"
            >取消</el-button>
            <el-button size="small" @click="openForm(row)">编辑</el-button>
            <el-button type="danger" size="small" @click="removeRow(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- 指派/改派弹窗 -->
    <el-dialog v-model="assignVisible" :title="assignMode === 'reassign' ? '改派技师' : '指派技师'" width="480px">
      <el-form label-width="80px">
        <el-form-item label="订单">
          <span>{{ assignRow?.id }} · {{ assignRow?.service }} · {{ assignRow?.startAt }}</span>
        </el-form-item>
        <el-form-item label="推荐">
          <div class="rec-box">
            <div v-for="(r, i) in assignRecommend" :key="r.tech.id" class="rec-line">
              <span class="rec-rank">{{ i + 1 }}</span>
              <span class="rec-name">{{ r.tech.name }}</span>
              <span class="rec-score">{{ r.score }} 分</span>
              <span class="tiny muted">{{ r.reason }}</span>
            </div>
            <div v-if="!assignRecommend.length" class="tiny muted">暂无可推荐技师，可直接在下方选择</div>
          </div>
        </el-form-item>
        <el-form-item label="技师" required>
          <el-select v-model="assignTechId" placeholder="选择技师" style="width: 100%">
            <el-option
              v-for="t in techRows"
              :key="t.id"
              :label="`${t.name}（${(t.skills || []).join('/')} · ${t.status}）`"
              :value="t.id"
            />
          </el-select>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="assignVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="confirmAssign">
          {{ assignMode === 'reassign' ? '确认改派' : '确认指派' }}
        </el-button>
      </template>
    </el-dialog>

    <!-- 新建/编辑表单 -->
    <el-dialog v-model="formVisible" :title="formRid ? '编辑订单' : '新建订单'" width="560px">
      <el-form :model="form" label-width="80px">
        <el-form-item label="客户" required>
          <el-input v-model="form.customer" placeholder="客户姓名" />
        </el-form-item>
        <el-form-item label="电话" required>
          <el-input v-model="form.phone" placeholder="联系电话" />
        </el-form-item>
        <el-form-item label="项目" required>
          <el-input v-model="form.service" placeholder="服务项目" />
        </el-form-item>
        <el-form-item label="技师">
          <el-select v-model="form.technicianId" placeholder="不指定（进派单池）" clearable style="width: 100%">
            <el-option v-for="t in techRows" :key="t.id" :label="t.name" :value="t.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="时段" required>
          <el-input v-model="form.startAt" placeholder="2026-10-04 10:00" />
        </el-form-item>
        <el-form-item label="地址" required>
          <el-input v-model="form.address" placeholder="服务地址" />
        </el-form-item>
        <el-form-item label="实收(元)" required>
          <el-input-number v-model="form.amount" :min="0" :precision="2" style="width: 180px" />
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="form.status" style="width: 100%">
            <el-option v-for="s in ALL_STATUSES" :key="s" :label="s" :value="s" />
          </el-select>
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="form.note" type="textarea" :rows="2" placeholder="备注（可选）" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="formVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="saveForm">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { computed, reactive, ref, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import { useCrud } from '../composables/useCrud'
import { ALL_STATUSES, STATUS_META, canTransition, TERMINAL_STATUSES } from '../statusFlow'

// 派单规则（界面态：抢单/指派切换、四维权重、超时阈值；默认值镜像 mock/data::dispatchRule，
// 接入后端后改读 dispatch-rule setting，切换单页内即时生效）
const dispatchRule = reactive({
  mode: '指派',
  weights: { skill: 40, schedule: 25, load: 20, rating: 15 },
  timeoutHours: 2
})

// ⚠️ 与 BookingList.vue 完全同口径：共享 'bookings' mock store
// amount ↔ actual、payMethod ↔ pay_channel；_rid=资源 id（patch/remove 用），id 显示订单号
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
const techCrud = useCrud('technicians', { keywordField: ['name', 'skills'] })

const loading = computed(() => crud.state.loading || techCrud.state.loading)
const techRows = computed(() => techCrud.state.rows)
const query = reactive({ keyword: '', status: '' })

const ridOf = (row) => row._rid ?? row.id
const isTerminal = (s) => TERMINAL_STATUSES.includes(s)

const weightSum = computed(() => {
  const w = dispatchRule.weights
  return Number(w.skill || 0) + Number(w.schedule || 0) + Number(w.load || 0) + Number(w.rating || 0)
})

function toggleMode(v) {
  dispatchRule.mode = v ? '抢单' : '指派'
  ElMessage.success(dispatchRule.mode === '抢单' ? '已切换抢单模式：订单进池后由技师端抢单' : '已切换指派模式：人工指派/改派')
}

// ---------- 派单池 / 待接单 ----------
const poolRows = computed(() => crud.state.rows.filter((r) => r.status === '待派单'))
const waitingRows = computed(() => crud.state.rows.filter((r) => r.status === '待接单'))

// 等待时长（createdAt → 小时），超过 dispatchRule.timeoutHours 标超时预警
function waitOf(row) {
  if (!row.createdAt) return { text: '—', over: false }
  const t = new Date(String(row.createdAt).replace(/-/g, '/'))
  const h = (Date.now() - t.getTime()) / 36e5
  if (Number.isNaN(h)) return { text: '—', over: false }
  const text = h < 1 ? `${Math.max(1, Math.round(h * 60))} 分钟` : `${h.toFixed(1)} 小时`
  return { text: `等待 ${text}`, over: h > Number(dispatchRule.timeoutHours || 2) }
}

// ---------- 推荐技师（四维加权：技能匹配 > 档期空闲 > 当日负载 > 评分）----------
// 事实源：方案 3.3 推荐排序；权重可配 dispatch_rule（dispatchRule.weights）
const SKILL_KEYWORDS = [
  { kw: '保洁', skill: '保洁' },
  { kw: '收纳', skill: '收纳' },
  { kw: '家电清洗', skill: '家电清洗' },
  { kw: '空调', skill: '家电清洗' },
  { kw: '油烟机', skill: '家电清洗' },
  { kw: '专项', skill: '专项清洁' },
  { kw: '玻璃', skill: '专项清洁' }
]

// 档期冲突精确口径（镜像 OrderService::hasConflict）：
// 同一技师、状态 ∈ ['待服务','服务中']、start_at < new.end && end_at > new.start、排除自身
function techBusy(tech, row) {
  const start = new Date(String(row.startAt).replace(/-/g, '/')).getTime()
  const end = start + 2 * 36e5 // mock 单据无 endAt 时按 2 小时窗口估算
  const realEnd = row.endAt ? new Date(String(row.endAt).replace(/-/g, '/')).getTime() : end
  return crud.state.rows.some((b) => {
    if (ridOf(b) === ridOf(row)) return false
    if (b.technicianId !== tech.id) return false
    if (!['待服务', '服务中'].includes(b.status)) return false
    const bs = new Date(String(b.startAt).replace(/-/g, '/')).getTime()
    const be = b.endAt ? new Date(String(b.endAt).replace(/-/g, '/')).getTime() : bs + 2 * 36e5
    return bs < realEnd && be > start
  })
}

function recommend(row) {
  const w = dispatchRule.weights
  const wSum = weightSum.value || 100
  const service = String(row.service || '')
  const matchedSkills = SKILL_KEYWORDS.filter((k) => service.includes(k.kw)).map((k) => k.skill)
  const day = String(row.startAt || '').slice(0, 10)
  const list = techRows.value
    // 拒单回池：排除原技师（preTechnician），避免重复派给同一个人
    .filter((t) => t.name !== row.preTechnician)
    .map((t) => {
      // 技能匹配：项目关键词 ∈ 技师技能；无法识别的项目给基准分 60
      const skillScore = matchedSkills.length
        ? (matchedSkills.some((s) => (t.skills || []).includes(s)) ? 100 : 0)
        : 60
      // 档期空闲：该时段无「待服务/服务中」冲突单
      const scheduleScore = techBusy(t, row) ? 0 : 100
      // 当日负载：当天订单越少分越高（每单 -25，下限 0）
      const dayLoad = crud.state.rows.filter(
        (b) => b.technicianId === t.id && String(b.startAt || '').slice(0, 10) === day
      ).length
      const loadScore = Math.max(0, 100 - dayLoad * 25)
      // 评分：4.0~5.0 线性映射 0~100
      const ratingScore = Math.max(0, Math.min(100, (Number(t.rating || 4.5) - 4) * 100))
      const score = Math.round(
        (skillScore * (w.skill || 0) + scheduleScore * (w.schedule || 0) +
          loadScore * (w.load || 0) + ratingScore * (w.rating || 0)) / wSum
      )
      const reasonBits = []
      if (skillScore >= 100) reasonBits.push('技能匹配')
      if (scheduleScore >= 100) reasonBits.push('档期空闲')
      else reasonBits.push('档期冲突')
      if (dayLoad === 0) reasonBits.push('当日空闲')
      else reasonBits.push(`当日 ${dayLoad} 单`)
      return { tech: t, score, reason: reasonBits.join(' · ') }
    })
    // 休假技师不参与推荐（仍可在弹窗手动选择）
    .filter((r) => r.tech.status !== '休假')
    .sort((a, b) => b.score - a.score)
  return list.slice(0, 3)
}

// ---------- 指派 / 改派 ----------
const assignVisible = ref(false)
const assignMode = ref('assign') // 'assign'=待派单→待接单；'reassign'=待接单换人保持状态
const assignRow = ref(null)
const assignTechId = ref(null)
const saving = ref(false)

const assignRecommend = computed(() => (assignRow.value ? recommend(assignRow.value) : []))

function openAssign(row, mode = 'assign') {
  assignRow.value = row
  assignMode.value = mode
  assignTechId.value = recommend(row)[0]?.tech.id ?? null
  assignVisible.value = true
}

async function confirmAssign() {
  const t = techRows.value.find((x) => x.id === assignTechId.value)
  if (!t) return ElMessage.warning('请先选择技师')
  const row = assignRow.value
  const from = row.status
  // 改派：状态保持「待接单」只换技师（方案 3.3）；指派：待派单 → 待接单（TRANSITIONS 白名单）
  const to = assignMode.value === 'reassign' ? '待接单' : '待接单'
  if (assignMode.value !== 'reassign' && !canTransition(from, to)) {
    return ElMessage.error(`状态不允许：${from} → ${to}`)
  }
  saving.value = true
  try {
    await crud.patch(ridOf(row), {
      technician: t.name,
      technicianId: t.id,
      status: to,
      dispatchAt: new Date().toLocaleString('zh-CN', { hour12: false }),
      rejectReason: '', // 清除拒单标记（回池重派后失效）
      preTechnician: '' // 清除原技师标记，推荐过滤恢复正常
    })
    await crud.load({ page: 1, per_page: 50 })
    assignVisible.value = false
    ElMessage.success(assignMode.value === 'reassign' ? `已改派给 ${t.name}（状态保持待接单）` : `已指派 ${t.name}，等待接单`)
  } finally {
    saving.value = false
  }
}

// ---------- 取消 / 删除 ----------
async function cancelRow(row) {
  if (!canTransition(row.status, '已取消')) return ElMessage.error(`状态不允许：${row.status} → 已取消`)
  await ElMessageBox.confirm(`确定取消订单 ${row.id} 吗？`, '取消订单', { type: 'warning' })
  await crud.patch(ridOf(row), { status: '已取消' })
  await crud.load({ page: 1, per_page: 50 })
  ElMessage.success('订单已取消')
}

async function removeRow(row) {
  await ElMessageBox.confirm(`确定删除订单 ${row.id} 吗？删除后不可恢复`, '删除订单', { type: 'warning' })
  await crud.remove(ridOf(row))
  await crud.load({ page: 1, per_page: 50 })
  ElMessage.success('订单已删除')
}

// ---------- 新建 / 编辑 ----------
const formVisible = ref(false)
const formRid = ref(null)
const form = reactive({
  customer: '', phone: '', service: '', technicianId: null,
  startAt: '', address: '', amount: 0, status: '待派单', note: ''
})

function openForm(row) {
  if (row) {
    formRid.value = ridOf(row)
    Object.assign(form, {
      customer: row.customer || '', phone: row.phone || '', service: row.service || '',
      technicianId: row.technicianId || null, startAt: row.startAt || '',
      address: row.address || '', amount: Number(row.amount || 0),
      status: row.status || '待派单', note: row.note || ''
    })
  } else {
    formRid.value = null
    Object.assign(form, {
      customer: '', phone: '', service: '', technicianId: null,
      startAt: '', address: '', amount: 0, status: '待派单', note: ''
    })
  }
  formVisible.value = true
}

async function saveForm() {
  if (!form.customer || !form.phone || !form.service || !form.startAt || !form.address) {
    return ElMessage.warning('客户/电话/项目/时段/地址为必填')
  }
  const t = techRows.value.find((x) => x.id === form.technicianId)
  const payload = {
    customer: form.customer,
    phone: form.phone,
    service: form.service,
    technician: t ? t.name : '待分配',
    technicianId: t ? t.id : 0,
    startAt: form.startAt,
    address: form.address,
    amount: form.amount, // 元（moneyFields 自动转分给后端）
    status: form.status,
    note: form.note
  }
  saving.value = true
  try {
    if (formRid.value) await crud.patch(formRid.value, payload)
    else await crud.save(null, { ...payload, createdAt: new Date().toLocaleString('zh-CN', { hour12: false }) })
    await crud.load({ page: 1, per_page: 50 })
    formVisible.value = false
    ElMessage.success(formRid.value ? '订单已更新' : '订单已创建')
  } finally {
    saving.value = false
  }
}

// ---------- 全部订单筛选（12 态 + 关键字）----------
const filteredRows = computed(() => {
  const kw = query.keyword.trim().toLowerCase()
  return crud.state.rows.filter((r) => {
    if (query.status && r.status !== query.status) return false
    if (!kw) return true
    return [r.id, r.customer, r.phone].some((v) => String(v || '').toLowerCase().includes(kw))
  })
})

onMounted(() => {
  crud.load({ page: 1, per_page: 50 })
  techCrud.load({ page: 1, per_page: 50 })
})
</script>

<style scoped>
.page { display: flex; flex-direction: column; gap: 16px; }
.rule-card :deep(.el-card__body) { padding: 14px 18px; }
.rule-row { display: flex; flex-wrap: wrap; gap: 24px; align-items: center; }
.rule-item { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.rule-label { font-weight: 600; color: var(--el-text-color-primary); }
.rule-tip { font-size: 12px; color: var(--el-text-color-secondary); }
.w-item { display: inline-flex; align-items: center; gap: 4px; font-size: 13px; color: var(--el-text-color-regular); }
.card-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.filters { display: flex; align-items: center; gap: 8px; }
.tiny { font-size: 12px; }
.muted { color: var(--el-text-color-secondary); }
.rec-line { display: flex; align-items: center; gap: 6px; line-height: 1.9; }
.rec-rank {
  display: inline-flex; align-items: center; justify-content: center;
  width: 16px; height: 16px; border-radius: 50%;
  background: var(--el-color-primary-light-9); color: var(--el-color-primary);
  font-size: 11px;
}
.rec-name { font-weight: 600; }
.rec-score { color: var(--el-color-primary); font-size: 12px; }
.rec-box { width: 100%; }
</style>
