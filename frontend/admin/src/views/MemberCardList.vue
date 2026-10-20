<template>
  <div class="content">
    <div class="content-card content-card--list">
      <div class="action-bar">
        <h2 class="page-title">会员卡</h2>
        <div class="action-bar__left">
          <span class="page-subtitle">次卡/时长卡开卡与核销；月卡到期自动重置次数，会员端可自助购卡/续卡，此处可人工重置</span>
        </div>
        <div class="action-bar__right">
          <el-button type="primary" :icon="Plus" @click="openCreate">开卡</el-button>
        </div>
      </div>

      <div class="table-wrap">
        <el-table :data="memberCards" empty-text="暂无会员卡">
          <el-table-column prop="id" label="卡号" width="90" />
          <el-table-column prop="member" label="会员" width="90" />
          <el-table-column prop="phone" label="电话" width="120" />
          <el-table-column prop="card" label="卡种" min-width="140" />
          <el-table-column label="剩余 / 总次数" width="120">
            <template #default="{ row }">{{ row.total - row.used }} / {{ row.total }}</template>
          </el-table-column>
          <el-table-column prop="amount" label="实收" width="90">
            <template #default="{ row }">￥{{ row.amount }}</template>
          </el-table-column>
          <el-table-column prop="expires" label="到期日" width="110" />
          <el-table-column prop="status" label="状态" width="90">
            <template #default="{ row }">
              <el-tag :type="row.status === '使用中' ? 'success' : 'info'" size="small">{{ row.status }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="180" fixed="right">
            <template #default="{ row }">
              <el-button v-if="row.status === '使用中'" type="primary" link size="small" @click="openWriteOff(row)">核销</el-button>
              <el-button v-if="row.cycle === 'monthly'" type="warning" link size="small" @click="handleReset(row)">重置次数</el-button>
              <el-button type="primary" link size="small" @click="openLogs(row)">记录</el-button>
              <el-button v-if="row.status === '使用中'" type="danger" link size="small" @click="handleRefund(row)">退卡</el-button>
            </template>
          </el-table-column>
        </el-table>
      </div>

      <div class="pagination-bar">
        <el-pagination :total="memberCards.length" :page-size="10" layout="total" background size="small" />
      </div>
    </div>

    <!-- 开卡 -->
    <el-dialog v-model="createVisible" title="会员开卡" width="460px">
      <el-form :model="createForm" label-width="80px">
        <el-form-item label="会员姓名"><el-input v-model="createForm.member" placeholder="会员姓名" /></el-form-item>
        <el-form-item label="电话"><el-input v-model="createForm.phone" placeholder="联系电话" /></el-form-item>
        <el-form-item label="卡种">
          <el-select v-model="createForm.card" style="width: 100%" @change="onCardChange">
            <el-option v-for="c in cardTypes" :key="c.name" :label="`${c.name} · ￥${c.price}`" :value="c.name" />
          </el-select>
        </el-form-item>
        <el-form-item label="实收金额"><el-input-number v-model="createForm.amount" :min="0" style="width: 100%" /></el-form-item>
        <el-form-item label="有效期"><el-date-picker v-model="createForm.expires" type="date" value-format="YYYY-MM-DD" style="width: 100%" /></el-form-item>
      </el-form>
      <template #footer>
        <div class="modal-footer">
          <el-button @click="createVisible = false">取消</el-button>
          <el-button type="primary" @click="handleCreate">确认开卡</el-button>
        </div>
      </template>
    </el-dialog>

    <!-- 核销 -->
    <el-dialog v-model="writeOffVisible" title="服务核销" width="420px">
      <el-descriptions v-if="writeOffRow" :column="1" border size="small">
        <el-descriptions-item label="卡号">{{ writeOffRow.id }}</el-descriptions-item>
        <el-descriptions-item label="会员">{{ writeOffRow.member }}（{{ writeOffRow.card }}）</el-descriptions-item>
        <el-descriptions-item label="剩余次数">{{ writeOffRow.total - writeOffRow.used }} 次</el-descriptions-item>
        <el-descriptions-item label="关联订单号"><el-input v-model="writeOffOrderNo" placeholder="预约订单号（可留空）" /></el-descriptions-item>
      </el-descriptions>
      <template #footer>
        <div class="modal-footer">
          <el-button @click="writeOffVisible = false">取消</el-button>
          <el-button type="primary" @click="confirmWriteOff">确认核销 1 次</el-button>
        </div>
      </template>
    </el-dialog>

    <!-- 流水 -->
    <el-dialog v-model="logsVisible" title="卡操作记录" width="560px">
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
import { Plus } from '@element-plus/icons-vue'
import { useCrud } from '../composables/useCrud'
import { request } from '../api/http'
import { list as apiList, create as apiCreate } from '../api/resource'

// 两资源接线：会员卡 / 卡操作流水（API 优先，内存 mock 兜底）
// 注意：total/used 是「次数」不是金额，禁入 moneyFields；amount 实收是金额
const crudC = useCrud('member-cards', {
  keywordField: ['id', 'cardNo', 'member', 'phone', 'card'],
  fieldMap: { card: 'name' },
  moneyFields: ['amount'],
  mapRow: (r) => {
    const out = { ...r }
    out._rid = out.id
    if (out.cardNo) out.id = out.cardNo // 卡号显示
    return out
  }
})
const crudL = useCrud('member-card-logs', {
  keywordField: ['card', 'cardNo', 'action', 'detail', 'orderNo']
})

const memberCards = computed(() => crudC.state.rows)
const ridOf = (row) => row._rid ?? row.id

onMounted(() => {
  crudC.load({ page: 1, per_page: 200 })
  crudL.load({ page: 1, per_page: 200 })
})

const cardTypes = [
  // cycle: once=次数用完即止；monthly=按月自动重置次数（会员端也可自助续卡/重置）
  { name: '保洁次卡 10 次', total: 10, price: 980, cycle: 'once' },
  { name: '保洁次卡 20 次', total: 20, price: 1880, cycle: 'once' },
  { name: '月度 4 次卡', total: 4, price: 499, cycle: 'monthly' },
  { name: '家电清洗 5 次卡', total: 5, price: 699, cycle: 'once' }
]

const createVisible = ref(false)
const createForm = reactive({ member: '', phone: '', card: '', amount: 0, expires: '' })
const writeOffVisible = ref(false)
const writeOffRow = ref(null)
const writeOffOrderNo = ref('')
const logsVisible = ref(false)
const currentLogs = ref([])

function onCardChange(name) {
  const t = cardTypes.find((c) => c.name === name)
  if (t) createForm.amount = t.price
}

function openCreate() {
  Object.assign(createForm, { member: '', phone: '', card: '', amount: 0, expires: '' })
  createVisible.value = true
}

async function handleCreate() {
  if (!createForm.member || !createForm.phone || !createForm.card) {
    ElMessage.warning('请填写会员、电话并选择卡种')
    return
  }
  const t = cardTypes.find((c) => c.name === createForm.card)
  try {
    // 开卡必须绑定会员档案（member_id）——否则会员端「我的卡」看不到、也无法自助核销。
    // 按手机号查会员，未建档则自动建档（与会员端登录建档口径一致）。
    const memberId = await resolveMemberId(createForm.member, createForm.phone)
    // 走动作端点：服务端生成卡号 + 开卡流水同事务（通用 CRUD 直写会漏 member_id、卡号）
    await request('/member-cards/open', {
      method: 'POST',
      body: {
        member_id: memberId,
        name: createForm.card,
        type: '次卡',
        cycle: t ? t.cycle : 'once',
        total: t ? t.total : 10,
        amount: Math.round(Number(createForm.amount || 0) * 100),
        expires: createForm.expires || '',
        cross_store: 0
      }
    })
  } catch (e) {
    ElMessage.error((e && e.message) || '开卡失败')
    return
  }
  createVisible.value = false
  ElMessage.success('开卡成功')
  crudC.load({ page: 1, per_page: 200 })
  crudL.load({ page: 1, per_page: 200 })
}

/** 按手机号解析会员 id；未建档则建档（name+phone） */
async function resolveMemberId(name, phone) {
  const found = await apiList('members', { phone, per_page: 1 })
  const hit = (found && found.list && found.list[0]) || null
  if (hit) return Number(hit.id)
  const created = await apiCreate('members', { name, phone, status: 1 })
  return Number(created && created.id)
}

/** 写卡操作流水（card_id/card_no 对齐后端列，勿用 card/cardNo 之外的键） */
async function addCardLog(row, action, times, detail) {
  await crudL.save(null, {
    cardId: ridOf(row),
    cardNo: row.cardNo || '',
    action,
    times,
    orderNo: '',
    detail,
    at: now()
  })
}

function openWriteOff(row) {
  writeOffRow.value = row
  writeOffOrderNo.value = ''
  writeOffVisible.value = true
}

async function confirmWriteOff() {
  const row = writeOffRow.value
  if (!row) return
  if (row.used >= row.total) {
    ElMessage.warning('次数已用完')
    return
  }
  try {
    // 走动作端点：服务端校验剩余次数/有效期并写核销流水（同事务）
    await request(`/member-cards/${ridOf(row)}/redeem`, {
      method: 'POST',
      body: { times: 1, order_no: writeOffOrderNo.value || '' }
    })
  } catch (e) {
    ElMessage.error((e && e.message) || '核销失败')
    return
  }
  writeOffVisible.value = false
  ElMessage.success('核销成功')
  crudC.load({ page: 1, per_page: 200 })
  crudL.load({ page: 1, per_page: 200 })
}

function handleRefund(row) {
  ElMessageBox.confirm(`确认为 ${row.member} 退卡 ${row.card} 吗？退卡后不可恢复。`, '退卡', { type: 'warning' })
    .then(async () => {
      try {
        await crudC.patch(ridOf(row), { status: '已退卡' })
        await addCardLog(row, '退卡', 0, `${row.card}退卡，剩余 ${row.total - row.used} 次作废`)
      } catch (e) {
        ElMessage.error((e && e.message) || '退卡失败')
        return
      }
      row.status = '已退卡'
      ElMessage.success('已退卡（退款走收款原路）')
    })
    .catch(() => {})
}

async function openLogs(row) {
  await crudL.load({ page: 1, per_page: 200 })
  currentLogs.value = crudL.state.rows.filter(
    (l) => String(l.cardId) === String(ridOf(row)) || String(l.cardNo || '') === String(row.cardNo || '')
  )
  logsVisible.value = true
}

// 周期重置：月卡到期后次数重置（线上由系统按周期自动重置，此处供客服人工触发）
function handleReset(row) {
  ElMessageBox.confirm(`确认为 ${row.member} 的「${row.card}」重置次数吗？已用 ${row.used} 次将清零，恢复到 ${row.total} 次。`, '重置次数', { type: 'warning' })
    .then(async () => {
      const nextStatus = row.status === '已用完' ? '使用中' : row.status
      try {
        await crudC.patch(ridOf(row), { used: 0, status: nextStatus })
        await addCardLog(row, '重置', 0, `${row.card}周期重置，次数恢复 ${row.total} 次`)
      } catch (e) {
        ElMessage.error((e && e.message) || '重置失败')
        return
      }
      row.used = 0
      row.status = nextStatus
      ElMessage.success('已重置，次数恢复 ' + row.total + ' 次')
    })
    .catch(() => {})
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
