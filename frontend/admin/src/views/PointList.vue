<template>
  <div class="content">
    <div class="content-card content-card--list">
      <el-tabs v-model="tab" class="sub-tabs">
        <el-tab-pane label="积分规则" name="rules">
          <div class="action-bar">
            <h2 class="page-title">积分规则</h2>
            <div class="action-bar__left">
              <el-input v-model="ruleQuery.keyword" placeholder="规则名称" clearable style="width: 200px"
                @clear="rulePage = 1" @keyup.enter="rulePage = 1">
                <template #prefix><el-icon><Search /></el-icon></template>
              </el-input>
              <el-select v-model="ruleQuery.kind" placeholder="规则类型" clearable style="width: 130px" @change="rulePage = 1">
                <el-option label="获取" value="获取" />
                <el-option label="抵扣" value="抵扣" />
                <el-option label="兑换" value="兑换" />
              </el-select>
            </div>
            <div class="action-bar__right">
              <span class="page-subtitle">发放比例：实付 1 元 =
                <el-input-number v-model="pointRate" :min="0" :max="100" :step="0.5" size="small" style="width: 100px; margin: 0 4px" />
                积分（确认完成时自动发放，<el-button link type="primary" size="small" @click="savePointRate">保存</el-button>）
              </span>
              <el-button type="primary" :icon="Plus" @click="openCreate">新建规则</el-button>
            </div>
          </div>
          <div class="table-wrap" v-loading="loading">
            <el-table :data="pagedRules" empty-text="暂无积分规则">
              <el-table-column prop="name" label="规则名称" min-width="140" />
              <el-table-column label="类型" width="90">
                <template #default="{ row }">
                  <el-tag size="small" :type="ruleKindType(row.kind)">{{ row.kind }}</el-tag>
                </template>
              </el-table-column>
              <el-table-column prop="rule" label="规则内容" min-width="200" show-overflow-tooltip />
              <el-table-column prop="limit" label="限制" min-width="170" show-overflow-tooltip />
              <el-table-column label="状态" width="90">
                <template #default="{ row }">
                  <el-switch v-model="row.status" @change="handleToggle(row)" />
                </template>
              </el-table-column>
              <el-table-column label="操作" width="140" fixed="right">
                <template #default="{ row }">
                  <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
                  <el-button link type="danger" size="small" @click="handleDelete(row)">删除</el-button>
                </template>
              </el-table-column>
            </el-table>
            <el-empty v-if="!loading && filteredRules.length === 0" description="暂无积分规则">
              <el-button type="primary" @click="openCreate">新建规则</el-button>
            </el-empty>
          </div>
          <div class="pagination-bar">
            <el-pagination v-model:current-page="rulePage" :page-size="pageSize"
              :total="filteredRules.length" layout="total, prev, pager, next" background size="small" />
          </div>
        </el-tab-pane>

        <el-tab-pane label="积分流水" name="logs">
          <div class="action-bar">
            <h2 class="page-title">积分流水</h2>
            <div class="action-bar__left">
              <el-input v-model="logQuery.keyword" placeholder="会员 / 手机号" clearable style="width: 200px"
                @clear="logPage = 1" @keyup.enter="logPage = 1">
                <template #prefix><el-icon><Search /></el-icon></template>
              </el-input>
              <el-select v-model="logQuery.action" placeholder="变动类型" clearable style="width: 130px" @change="logPage = 1">
                <el-option label="获取" value="获取" />
                <el-option label="抵扣" value="抵扣" />
                <el-option label="过期" value="过期" />
              </el-select>
            </div>
            <div class="action-bar__right">
              <el-button :icon="Search" @click="logPage = 1">查询</el-button>
            </div>
          </div>
          <div class="table-wrap" v-loading="loading">
            <el-table :data="pagedLogs" empty-text="暂无积分流水">
              <el-table-column prop="at" label="时间" min-width="150" />
              <el-table-column prop="member" label="会员" min-width="100" />
              <el-table-column prop="phone" label="手机号" min-width="120" />
              <el-table-column label="变动" width="100">
                <template #default="{ row }">
                  <el-tag size="small" :type="row.action === '获取' ? 'success' : row.action === '抵扣' ? 'warning' : 'info'">{{ row.action }}</el-tag>
                </template>
              </el-table-column>
              <el-table-column label="积分" width="100">
                <template #default="{ row }">
                  <span :class="row.points > 0 ? 'is-plus' : 'is-minus'">{{ row.points > 0 ? '+' : '' }}{{ row.points }}</span>
                </template>
              </el-table-column>
              <el-table-column prop="balance" label="余额" width="100" />
              <el-table-column prop="detail" label="说明" min-width="220" show-overflow-tooltip />
            </el-table>
            <el-empty v-if="!loading && filteredLogs.length === 0" description="暂无积分流水" />
          </div>
          <div class="pagination-bar">
            <el-pagination v-model:current-page="logPage" :page-size="pageSize"
              :total="filteredLogs.length" layout="total, prev, pager, next" background size="small" />
          </div>
        </el-tab-pane>
      </el-tabs>
    </div>

    <el-dialog v-model="formVisible" :title="editing ? '编辑规则' : '新建规则'" width="620px" class="list-dialog">
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top">
        <div class="form-section">规则信息</div>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="规则名称" prop="name">
              <el-input v-model="form.name" placeholder="如：消费得积分" maxlength="20" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="规则类型" prop="kind">
              <el-radio-group v-model="form.kind">
                <el-radio-button label="获取">获取</el-radio-button>
                <el-radio-button label="抵扣">抵扣</el-radio-button>
                <el-radio-button label="兑换">兑换</el-radio-button>
              </el-radio-group>
            </el-form-item>
          </el-col>
        </el-row>
        <el-form-item label="规则内容" prop="rule">
          <el-input v-model="form.rule" placeholder="如：实付 ¥1 = 1 积分" maxlength="50" />
        </el-form-item>
        <el-form-item label="限制说明">
          <el-input v-model="form.limit" placeholder="如：单笔最多抵扣订单金额 20%" maxlength="50" />
          <span class="form-tip">积分的获取与抵扣口径在此维护，会员端「积分说明」按此展示；数值口径改动只影响新订单。</span>
        </el-form-item>
        <div class="form-section">状态</div>
        <el-form-item label="启用状态">
          <el-switch v-model="form.status" />
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
import { list as listResource, create as createSetting, update as updateSetting } from '../api/resource'

// 积分比例（setting: point_rate）：后端 OrderService 按此比例在订单确认完成时发放
const pointRate = ref(1)
let pointRateId = null
async function loadPointRate() {
  try {
    const res = await listResource('settings', { page: 1, per_page: 200, key: 'point_rate' })
    const row = (res.list || []).find((x) => x.key === 'point_rate')
    if (row) {
      pointRateId = row.id
      pointRate.value = Number(row.value) || 1
    }
  } catch (e) {
    console.warn('[point-rate]', e && e.message)
  }
}
async function savePointRate() {
  const value = String(pointRate.value)
  if (pointRateId) {
    await updateSetting('settings', pointRateId, { value })
  } else {
    await createSetting('settings', { group: 'member', key: 'point_rate', value, type: 'number', remark: '积分比例：实付 1 元 = N 积分（订单确认完成时自动发放）' })
    pointRateId = 'new'
  }
  ElMessage.success('积分比例已保存，后续完成订单按新比例发放')
}

// 积分规则 / 积分流水接线（API 优先，内存 mock 兜底）
// 注意：points/balance 是「积分」不是金额，禁入 moneyFields
const crudR = useCrud('point-rules', {
  keywordField: ['name', 'kind', 'rule']
})
const crudG = useCrud('point-logs', {
  keywordField: ['member', 'phone', 'action', 'detail']
})
const ridOf = (row) => row._rid ?? row.id
const editRid = ref(null)

const loading = ref(true)
const tab = ref('rules')
const pageSize = 10
const rulePage = ref(1)
const logPage = ref(1)
const formVisible = ref(false)
const editing = ref(false)
const formRef = ref()

const ruleQuery = reactive({ keyword: '', kind: '' })
const logQuery = reactive({ keyword: '', action: '' })

const emptyForm = { id: '', name: '', kind: '获取', rule: '', limit: '无上限', status: true }
const form = reactive({ ...emptyForm })

const rules = {
  name: [{ required: true, message: '请输入规则名称', trigger: 'blur' }],
  rule: [{ required: true, message: '请输入规则内容', trigger: 'blur' }]
}

function ruleKindType(kind) {
  return { 获取: 'success', 抵扣: 'warning', 兑换: 'primary' }[kind] || 'info'
}

const filteredRules = computed(() => {
  const kw = ruleQuery.keyword.trim().toLowerCase()
  return crudR.state.rows.filter((r) => {
    const matchKw = !kw || r.name.toLowerCase().includes(kw)
    const matchKind = !ruleQuery.kind || r.kind === ruleQuery.kind
    return matchKw && matchKind
  })
})
const pagedRules = computed(() => {
  const start = (rulePage.value - 1) * pageSize
  return filteredRules.value.slice(start, start + pageSize)
})

const filteredLogs = computed(() => {
  const kw = logQuery.keyword.trim().toLowerCase()
  return crudG.state.rows.filter((l) => {
    const matchKw = !kw || l.member.toLowerCase().includes(kw) || l.phone.includes(kw)
    const matchAction = !logQuery.action || l.action === logQuery.action
    return matchKw && matchAction
  })
})
const pagedLogs = computed(() => {
  const start = (logPage.value - 1) * pageSize
  return filteredLogs.value.slice(start, start + pageSize)
})

function openCreate() {
  editing.value = false
  Object.assign(form, emptyForm)
  formVisible.value = true
  formRef.value?.clearValidate()
}

function openEdit(row) {
  editing.value = true
  editRid.value = ridOf(row)
  Object.assign(form, row)
  formVisible.value = true
  formRef.value?.clearValidate()
}

async function handleSave() {
  const valid = await formRef.value.validate().catch(() => false)
  if (!valid) return
  // 显式挑字段（openEdit 会把 _rid/__mockId 带进 form）
  const payload = { name: form.name, kind: form.kind, rule: form.rule, limit: form.limit, status: form.status }
  await crudR.save(editing.value ? editRid.value : null, payload)
  await crudR.load({ page: 1, per_page: 200 })
  ElMessage.success(editing.value ? '保存成功' : '新建成功')
  formVisible.value = false
}

async function handleToggle(row) {
  await crudR.patch(ridOf(row), { status: row.status })
  ElMessage.success(`「${row.name}」已${row.status ? '启用' : '停用'}`)
}

function handleDelete(row) {
  ElMessageBox.confirm(`确认删除规则「${row.name}」吗？已产生的积分不受影响。`, '删除规则', {
    type: 'warning', confirmButtonText: '确认删除', cancelButtonText: '取消'
  }).then(async () => {
    await crudR.remove(ridOf(row))
    await crudR.load({ page: 1, per_page: 200 })
    ElMessage.success('删除成功')
  }).catch(() => {})
}

onMounted(async () => {
  try {
    await Promise.all([crudR.load({ page: 1, per_page: 200 }), crudG.load({ page: 1, per_page: 200 }), loadPointRate()])
  } catch (e) {
    console.warn('[points]', e && e.message)
  } finally {
    loading.value = false
  }
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
.is-plus {
  color: var(--success, #67c23a);
  font-weight: 600;
}
.is-minus {
  color: var(--danger, #f56c6c);
  font-weight: 600;
}
.sub-tabs {
  padding: 0 var(--space-md);
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
