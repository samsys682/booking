<template>
  <div class="content">
    <div class="content-card content-card--list">
      <div class="action-bar">
        <h2 class="page-title">储值卡与等级</h2>
        <div class="action-bar__left">
          <span class="page-subtitle">会员储值充值、消费扣款与等级，余额随流水实时变动</span>
        </div>
        <div class="action-bar__right">
          <!-- 套餐是商家资产，必须可设置，不能写死在前端 -->
          <el-button :icon="Setting" @click="planVisible = true">套餐设置</el-button>
          <el-button :icon="Setting" @click="openLevelRules">等级设置</el-button>
        </div>
      </div>

      <div class="table-wrap">
        <el-table :data="storedAccounts" empty-text="暂无储值账户">
          <el-table-column prop="id" label="账户号" width="90" />
          <el-table-column prop="member" label="会员" width="90" />
          <el-table-column prop="phone" label="电话" width="120" />
          <el-table-column prop="level" label="等级" width="100">
            <template #default="{ row }"><el-tag size="small" effect="plain">{{ row.level }}</el-tag></template>
          </el-table-column>
          <el-table-column prop="balance" label="余额" width="100" sortable>
            <template #default="{ row }"><span class="balance">￥{{ row.balance }}</span></template>
          </el-table-column>
          <!-- min-width 列吸收富余宽度，宽屏下表格铺满不挤左侧 -->
          <el-table-column prop="totalRecharge" label="累计充值" min-width="100" sortable>
            <template #default="{ row }">￥{{ row.totalRecharge }}</template>
          </el-table-column>
          <el-table-column prop="totalConsume" label="累计消费" min-width="100" sortable>
            <template #default="{ row }">￥{{ row.totalConsume }}</template>
          </el-table-column>
          <el-table-column label="操作" width="175" fixed="right">
            <template #default="{ row }">
              <el-button type="primary" link size="small" @click="openRecharge(row)">充值</el-button>
              <el-button type="warning" link size="small" :disabled="row.balance <= 0" @click="openConsume(row)">消费扣款</el-button>
              <el-button type="primary" link size="small" @click="openLogs(row)">记录</el-button>
            </template>
          </el-table-column>
        </el-table>
      </div>

      <div class="pagination-bar">
        <el-pagination :total="storedAccounts.length" :page-size="10" layout="total" background size="small" />
      </div>
    </div>

    <!-- 充值 -->
    <!-- 加宽到 560px，四个套餐档位一行放下 -->
    <el-dialog v-model="rechargeVisible" title="会员充值" width="560px">
      <el-descriptions v-if="currentRow" :column="1" border size="small" style="margin-bottom: var(--space-md)">
        <el-descriptions-item label="会员">{{ currentRow.member }}（{{ currentRow.level }}）</el-descriptions-item>
        <el-descriptions-item label="当前余额">￥{{ currentRow.balance }}</el-descriptions-item>
      </el-descriptions>
      <el-form label-width="90px">
        <!-- 套餐来自商家设置（mock/data rechargePlans），不再写死 -->
        <el-form-item label="充值套餐">
          <el-radio-group v-model="rechargeForm.plan" @change="onPlanChange">
            <el-radio-button v-for="p in rechargePlans" :key="p.money" :value="p.money">¥{{ p.money }} 送 {{ p.gift }}</el-radio-button>
            <el-radio-button value="custom">自定义</el-radio-button>
          </el-radio-group>
        </el-form-item>
        <template v-if="rechargeForm.plan === 'custom'">
          <el-form-item label="充值金额"><el-input-number v-model="rechargeForm.money" :min="1" :step="100" style="width: 100%" /></el-form-item>
          <el-form-item label="赠送金额"><el-input-number v-model="rechargeForm.gift" :min="0" :step="10" style="width: 100%" /></el-form-item>
        </template>
        <el-form-item label="到账合计"><span class="balance">￥{{ (rechargeForm.money || 0) + (rechargeForm.gift || 0) }}</span></el-form-item>
      </el-form>
      <template #footer>
        <div class="modal-footer">
          <el-button @click="rechargeVisible = false">取消</el-button>
          <el-button type="primary" @click="confirmRecharge">确认充值</el-button>
        </div>
      </template>
    </el-dialog>

    <!-- 充值套餐设置（商家自维护，会员端充值页同步展示） -->
    <el-dialog v-model="planVisible" title="充值套餐设置" width="520px">
      <div class="form-tip">会员端充值页按此套餐展示，保存后立即生效；最多 6 个档位</div>
      <div v-for="(p, i) in rechargePlans" :key="i" class="plan-row">
        <span>充 ¥</span>
        <el-input-number v-model="p.money" :min="1" :max="99999" :step="100" />
        <span>送 ¥</span>
        <el-input-number v-model="p.gift" :min="0" :max="99999" :step="10" />
        <el-button type="danger" link @click="rechargePlans.splice(i, 1)">删除</el-button>
      </div>
      <el-button v-if="rechargePlans.length < 6" plain class="plan-add" @click="rechargePlans.push({ money: rechargePlans.length ? rechargePlans[rechargePlans.length - 1].money + 500 : 500, gift: 0 })">+ 添加套餐</el-button>
      <template #footer>
        <div class="modal-footer">
          <el-button @click="planVisible = false">取消</el-button>
          <el-button type="primary" @click="savePlans">保存</el-button>
        </div>
      </template>
    </el-dialog>

    <!-- 等级规则设置（setting: level_rules；会员档案等级下拉与等级展示联动） -->
    <el-dialog v-model="levelVisible" title="会员等级规则设置" width="520px">
      <div class="form-tip">按会员累计消费金额（元）自动匹配等级；会员档案页的等级选项与此处联动，保存后立即生效</div>
      <div v-for="(lv, i) in levelRows" :key="i" class="plan-row">
        <el-input v-model="lv.name" maxlength="20" placeholder="等级名" style="width: 150px" />
        <span>累计消费 ≥ ¥</span>
        <el-input-number v-model="lv.threshold" :min="0" :max="999999" :step="100" />
        <el-button type="danger" link @click="levelRows.splice(i, 1)">删除</el-button>
      </div>
      <el-button plain class="plan-add" @click="levelRows.push({ name: '', threshold: levelRows.length ? levelRows[levelRows.length - 1].threshold + 1000 : 0 })">+ 添加等级</el-button>
      <template #footer>
        <div class="modal-footer">
          <el-button @click="levelVisible = false">取消</el-button>
          <el-button type="primary" @click="saveLevelRules">保存</el-button>
        </div>
      </template>
    </el-dialog>

    <!-- 消费扣款 -->
    <el-dialog v-model="consumeVisible" title="储值消费扣款" width="420px">
      <el-form label-width="90px">
        <el-form-item label="扣款金额"><el-input-number v-model="consumeForm.money" :min="1" :max="currentRow ? currentRow.balance : 0" style="width: 100%" /></el-form-item>
        <el-form-item label="关联订单"><el-input v-model="consumeForm.orderNo" placeholder="预约订单号（可留空）" /></el-form-item>
      </el-form>
      <template #footer>
        <div class="modal-footer">
          <el-button @click="consumeVisible = false">取消</el-button>
          <el-button type="primary" @click="confirmConsume">确认扣款</el-button>
        </div>
      </template>
    </el-dialog>

    <!-- 流水 -->
    <el-dialog v-model="logsVisible" title="储值流水" width="560px">
      <el-table :data="currentLogs" size="small" max-height="360">
        <el-table-column prop="at" label="时间" width="150" />
        <el-table-column prop="action" label="动作" width="90" />
        <el-table-column prop="detail" label="说明" />
      </el-table>
      <template #footer>
        <div class="modal-footer"><el-button @click="logsVisible = false">关闭</el-button></div>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { Setting } from '@element-plus/icons-vue'
import { useCrud } from '../composables/useCrud'
import { list as listResource, create as createSetting, update as updateSetting } from '../api/resource'
import { request } from '../api/http'
import { payConfirm } from '../api/orders'

// 三资源接线：储值账户 / 充值套餐 / 储值流水（API 优先，内存 mock 兜底）
const crudA = useCrud('stored-accounts', {
  keywordField: ['id', 'accountNo', 'member', 'phone'],
  moneyFields: ['balance', 'totalRecharge', 'totalConsume'],
  mapRow: (r) => {
    const out = { ...r }
    out._rid = out.id
    if (out.accountNo) out.id = out.accountNo // 账户号显示
    return out
  }
})
const crudP = useCrud('recharge-plans', {
  moneyFields: ['money', 'gift'],
  mapRow: (r) => {
    const out = { ...r }
    out._rid = out.id
    return out
  }
})
const crudL = useCrud('stored-logs', {
  keywordField: ['account', 'accountNo', 'action', 'detail', 'orderNo'],
  moneyFields: ['amount', 'balance']
})

// 模板会直接 push/splice 的数组必须保持稳定引用（useCrud.load 会重置 state.rows）
const storedAccounts = reactive([])
const rechargePlans = reactive([])
const ridOf = (row) => row._rid ?? row.id
let planOrigIds = []

async function loadAccounts() {
  await crudA.load({ page: 1, per_page: 200 })
  storedAccounts.splice(0, storedAccounts.length, ...crudA.state.rows)
}

async function loadPlans() {
  await crudP.load({ page: 1, per_page: 50 })
  rechargePlans.splice(0, rechargePlans.length, ...crudP.state.rows)
  planOrigIds = crudP.state.rows.map(ridOf).filter((v) => v !== undefined && v !== null)
}

onMounted(() => {
  loadAccounts()
  loadPlans()
  crudL.load({ page: 1, per_page: 200 })
})

const planVisible = ref(false)

// 等级规则（setting: level_rules）：档案页等级下拉与等级标签联动的配置源
const levelVisible = ref(false)
const levelRows = ref([])
let levelSettingId = null
async function openLevelRules() {
  levelRows.value = []
  levelSettingId = null
  try {
    const res = await listResource('settings', { page: 1, per_page: 200, key: 'level_rules' })
    const row = (res.list || []).find((x) => x.key === 'level_rules')
    if (row) {
      levelSettingId = row.id
      levelRows.value = JSON.parse(row.value || '[]')
    }
  } catch (e) {
    console.warn('[level-rules]', e && e.message)
  }
  if (!levelRows.value.length) levelRows.value = [{ name: '普通会员', threshold: 0 }]
  levelVisible.value = true
}
async function saveLevelRules() {
  if (levelRows.value.some((lv) => !lv.name)) {
    ElMessage.warning('等级名不能为空')
    return
  }
  if (new Set(levelRows.value.map((lv) => lv.name)).size !== levelRows.value.length) {
    ElMessage.warning('等级名不能重复')
    return
  }
  levelRows.value.sort((a, b) => a.threshold - b.threshold)
  const value = JSON.stringify(levelRows.value)
  if (levelSettingId) {
    await updateSetting('settings', levelSettingId, { value })
  } else {
    await createSetting('settings', { group: 'member', key: 'level_rules', value, type: 'json', remark: '会员等级规则：累计消费满 threshold 元自动升为对应等级' })
  }
  levelVisible.value = false
  ElMessage.success('等级规则已保存，会员档案等级选项同步生效')
}
const rechargeVisible = ref(false)
const consumeVisible = ref(false)
const logsVisible = ref(false)
const currentRow = ref(null)
const currentLogs = ref([])
const rechargeForm = reactive({ plan: 1000, money: 1000, gift: 100 })
const consumeForm = reactive({ money: 100, orderNo: '' })

function onPlanChange(val) {
  if (val === 'custom') {
    rechargeForm.money = 0
    rechargeForm.gift = 0
    return
  }
  const p = rechargePlans.find((x) => x.money === val)
  if (p) {
    rechargeForm.money = p.money
    rechargeForm.gift = p.gift
  }
}

function openRecharge(row) {
  currentRow.value = row
  const first = rechargePlans[0]
  rechargeForm.plan = first ? first.money : 'custom'
  rechargeForm.money = first ? first.money : 0
  rechargeForm.gift = first ? first.gift : 0
  rechargeVisible.value = true
}

// 套餐校验：金额>0、档位不重复；保存时 diff 增改删（套餐是商家资产，必须落库）
async function savePlans() {
  if (!rechargePlans.length) {
    ElMessage.warning('至少保留一个套餐')
    return
  }
  const moneys = rechargePlans.map((p) => p.money)
  if (moneys.some((m) => !m || m <= 0)) {
    ElMessage.warning('充值金额必须大于 0')
    return
  }
  if (new Set(moneys).size !== moneys.length) {
    ElMessage.warning('充值金额档位不能重复')
    return
  }
  rechargePlans.sort((a, b) => a.money - b.money)
  for (const p of rechargePlans) {
    await crudP.save(ridOf(p) ?? null, { money: p.money, gift: p.gift, sort: 0, status: 1 })
  }
  const keepIds = new Set(rechargePlans.map(ridOf).filter((v) => v !== undefined && v !== null))
  for (const id of planOrigIds) {
    if (!keepIds.has(id)) await crudP.remove(id)
  }
  await loadPlans()
  planVisible.value = false
  ElMessage.success('套餐已保存，会员端充值页同步生效')
}

/** 套餐档位金额（元）→ 套餐 id */
function planIdOf(money) {
  const p = rechargePlans.find((x) => Number(x.money) === Number(money))
  return p ? Number(ridOf(p)) : 0
}

async function confirmRecharge() {
  const row = currentRow.value
  if (!row) return
  const accId = Number(ridOf(row))
  const isCustom = rechargeForm.plan === 'custom'
  if (isCustom && (!rechargeForm.money || rechargeForm.money <= 0)) {
    ElMessage.warning('充值金额必须大于 0')
    return
  }
  try {
    // 金额只信服务端：走动作端点按套餐核算赠送并生成「待支付」交易单
    // （直改余额会绕过收款留痕与赠送核算，属资金红线）
    const body = isCustom
      ? { money: Math.round(Number(rechargeForm.money) * 100) }
      : { plan_id: planIdOf(rechargeForm.plan) }
    if (!isCustom && !body.plan_id) {
      ElMessage.warning('请选择有效的充值套餐')
      return
    }
    const tx = await request(`/stored-accounts/${accId}/recharge`, { method: 'POST', body })
    // 线下收款确认入账：服务端写余额/累计充值 + 储值流水（同事务）
    await payConfirm({ order_no: tx.out_trade_no, channel: 'offline' })
  } catch (e) {
    ElMessage.error((e && e.message) || '充值失败')
    return
  }
  rechargeVisible.value = false
  ElMessage.success('充值成功，余额已更新')
  await loadAccounts()
  crudL.load({ page: 1, per_page: 200 })
}

function openConsume(row) {
  currentRow.value = row
  consumeForm.money = 100
  consumeForm.orderNo = ''
  consumeVisible.value = true
}

async function confirmConsume() {
  const row = currentRow.value
  if (!row) return
  if (consumeForm.money > row.balance) {
    ElMessage.warning('余额不足')
    return
  }
  const accId = Number(ridOf(row))
  const nextBalance = Math.round((row.balance - consumeForm.money) * 100) / 100
  const nextConsume = Math.round((row.totalConsume + consumeForm.money) * 100) / 100
  try {
    await crudA.patch(accId, { balance: nextBalance, totalConsume: nextConsume })
    // 流水必须带 account_id（后端列名），否则记录成为孤儿无法按账户对账
    await crudL.save(null, {
      accountId: accId,
      accountNo: row.accountNo || '',
      action: '消费扣款',
      amount: consumeForm.money,
      balance: nextBalance,
      orderNo: consumeForm.orderNo || '',
      detail: `${consumeForm.orderNo ? `订单 ${consumeForm.orderNo} ` : ''}抵扣 ¥${consumeForm.money}，余额 ¥${nextBalance}`,
      at: now()
    })
  } catch (e) {
    ElMessage.error((e && e.message) || '扣款失败')
    return
  }
  row.balance = nextBalance
  row.totalConsume = nextConsume
  consumeVisible.value = false
  ElMessage.success(`扣款成功，余额 ¥${nextBalance}`)
}

async function openLogs(row) {
  await crudL.load({ page: 1, per_page: 200 })
  currentLogs.value = crudL.state.rows.filter(
    (l) => String(l.accountId) === String(ridOf(row)) || String(l.accountNo || '') === String(row.accountNo || '')
  )
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

.balance {
  color: var(--danger);
  font-weight: 600;
}

/* 套餐设置弹窗行布局 */
.form-tip {
  color: var(--text-tertiary);
  font-size: var(--font-size-body);
  margin-bottom: var(--space-sm);
}

.plan-row {
  display: flex;
  align-items: center;
  gap: var(--space-xs);
  margin-bottom: var(--space-sm);
}

.plan-row .el-input-number {
  width: 120px;
}

.plan-row span {
  color: var(--text-secondary);
  white-space: nowrap;
}

.plan-add {
  width: 100%;
  margin-top: var(--space-xs);
  border-style: dashed;
}
</style>
