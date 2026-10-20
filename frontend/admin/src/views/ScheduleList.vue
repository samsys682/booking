<template>
  <div class="content">
    <div class="content-card content-card--list">
      <div class="action-bar">
        <h2 class="page-title">排班与休息</h2>
        <div class="action-bar__left">
          <el-input v-model="query.keyword" placeholder="技师 / 日期" clearable style="width: 180px"
            @clear="handleSearch" @keyup.enter="handleSearch">
            <template #prefix><el-icon><Search /></el-icon></template>
          </el-input>
          <el-select v-model="query.shift" placeholder="班次" clearable style="width: 110px" @change="handleSearch">
            <el-option label="早班" value="早班" />
            <el-option label="午班" value="午班" />
            <el-option label="晚班" value="晚班" />
            <el-option label="全天" value="全天" />
            <el-option label="休息" value="休息" />
            <el-option label="请假" value="请假" />
          </el-select>
          <el-select v-model="query.status" placeholder="审批状态" clearable style="width: 120px" @change="handleSearch">
            <el-option label="待审批" value="待审批" />
            <el-option label="已批准" value="已批准" />
            <el-option label="已驳回" value="已驳回" />
          </el-select>
          <el-button :icon="Search" @click="handleSearch">查询</el-button>
        </div>
        <div class="action-bar__right">
          <el-radio-group v-model="viewMode" size="small" @change="onViewMode">
            <el-radio-button label="list">列表</el-radio-button>
            <el-radio-button label="calendar">日历</el-radio-button>
          </el-radio-group>
          <el-button type="primary" :icon="Plus" @click="openCreate">新建排班</el-button>
        </div>
      </div>

      <template v-if="viewMode === 'list'">
      <div class="table-wrap" v-loading="loading">
        <el-table :data="pagedData" empty-text="暂无排班">
          <el-table-column prop="date" label="日期" width="110" />
          <el-table-column prop="name" label="技师" width="90" fixed="left" />
          <el-table-column label="班次" width="90">
            <template #default="{ row }">
              <el-tag size="small" :type="row.shift === '请假' ? 'warning' : row.isRest ? 'info' : 'primary'" effect="plain">{{ row.shift }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column prop="startAt" label="开始" width="150" />
          <el-table-column prop="endAt" label="结束" width="150" />
          <el-table-column label="上班" width="80">
            <template #default="{ row }">
              <el-switch :model-value="!row.isRest" @change="toggleRest(row)" />
            </template>
          </el-table-column>
          <el-table-column label="审批状态" width="100">
            <template #default="{ row }">
              <el-tag :type="{ 待审批: 'warning', 已批准: 'success', 已驳回: 'danger' }[row.status] || 'info'" size="small">{{ row.status || '—' }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column prop="reason" label="事由 / 备注" min-width="130" show-overflow-tooltip />
          <el-table-column label="操作" width="210" fixed="right">
            <template #default="{ row }">
              <template v-if="row.status === '待审批'">
                <el-button type="primary" link size="small" @click="approve(row, true)">批准</el-button>
                <el-button type="danger" link size="small" @click="approve(row, false)">驳回</el-button>
              </template>
              <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
              <el-button link type="danger" size="small" @click="handleDelete(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
        <el-empty v-if="!loading && filteredData.length === 0" description="暂无排班">
          <el-button type="primary" @click="openCreate">新建排班</el-button>
        </el-empty>
      </div>
      <div class="pagination-bar">
        <el-pagination v-model:current-page="page" :page-size="pageSize"
          :total="total" layout="total, prev, pager, next" background size="small" />
      </div>
      </template>

      <!-- 日历视图：技师×日期周矩阵，点格新建/编辑排班（复用 schedules/technicians 只读，不动后端） -->
      <div v-if="viewMode === 'calendar'" class="calendar-wrap" v-loading="calLoading">
        <div class="calendar-nav">
          <el-button size="small" @click="shiftWeek(-1)">上一周</el-button>
          <el-button size="small" @click="goThisWeek">本周</el-button>
          <el-button size="small" @click="shiftWeek(1)">下一周</el-button>
          <span class="calendar-range">{{ rangeLabel }}</span>
          <el-button size="small" :icon="Refresh" @click="loadCalendar">刷新</el-button>
        </div>
        <div class="cal-grid">
          <div class="cal-row cal-row--head">
            <div class="cal-tech-cell">技师</div>
            <div v-for="d in weekDays" :key="d.date" class="cal-day-cell" :class="{ 'is-today': d.isToday }">{{ d.label }}</div>
          </div>
          <div v-if="techs.length === 0" class="cal-empty">暂无技师，请先在「技师管理」添加技师</div>
          <div v-for="t in techs" :key="t.id" class="cal-row">
            <div class="cal-tech-cell cal-tech-name">{{ t.name }}</div>
            <div v-for="d in weekDays" :key="d.date" class="cal-day-cell">
              <div class="cal-slot" :class="slotClass(getSched(t.name, d.date))" @click="cellClick(t, d.date)">
                <template v-if="getSched(t.name, d.date)">
                  <span class="cal-shift">{{ getSched(t.name, d.date).shift }}</span>
                  <span v-if="getSched(t.name, d.date).is_rest" class="cal-badge">休</span>
                  <span v-else-if="getSched(t.name, d.date).status === '待审批'" class="cal-badge cal-badge--warn">审</span>
                </template>
                <span v-else class="cal-todo">待排</span>
              </div>
            </div>
          </div>
        </div>
        <div class="cal-legend">
          <span><i class="lg lg--work"></i>已排班</span>
          <span><i class="lg lg--rest"></i>休息 / 请假</span>
          <span><i class="lg lg--todo"></i>待排</span>
          <span class="cal-tip">点击任意格子即可新建或编辑该技师当天排班</span>
        </div>
      </div>
    </div>

    <el-dialog v-model="formVisible" :title="editing ? '编辑排班' : '新建排班'" width="620px" class="list-dialog">
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top">
        <div class="form-section">排班信息</div>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="技师" prop="name">
              <el-input v-model="form.name" placeholder="技师姓名" maxlength="20" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="日期" prop="date">
              <el-input v-model="form.date" placeholder="如：2026-10-06" maxlength="10" />
            </el-form-item>
          </el-col>
        </el-row>
        <el-row :gutter="16">
          <el-col :span="8">
            <el-form-item label="班次">
              <el-select v-model="form.shift" style="width: 100%">
                <el-option label="早班" value="早班" />
                <el-option label="午班" value="午班" />
                <el-option label="晚班" value="晚班" />
                <el-option label="全天" value="全天" />
                <el-option label="休息" value="休息" />
                <el-option label="请假" value="请假" />
              </el-select>
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="开始时间">
              <el-input v-model="form.startAt" placeholder="如：2026-10-06 09:00" />
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="结束时间">
              <el-input v-model="form.endAt" placeholder="如：2026-10-06 13:00" />
            </el-form-item>
          </el-col>
        </el-row>
        <div class="form-section">状态</div>
        <el-row :gutter="16">
          <el-col :span="8">
            <el-form-item label="休息日">
              <el-switch v-model="form.isRest" />
              <span class="form-tip">开启后当天不可约</span>
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="审批状态">
              <el-select v-model="form.status" style="width: 100%">
                <el-option label="待审批" value="待审批" />
                <el-option label="已批准" value="已批准" />
                <el-option label="已驳回" value="已驳回" />
              </el-select>
            </el-form-item>
          </el-col>
        </el-row>
        <el-form-item label="事由 / 备注">
          <el-input v-model="form.reason" placeholder="请假事由或调休原因，选填" maxlength="100" />
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
// 排班与休息：上班/休息一键切换，请假申请可审批；排班记录支持完整增删改查
import { computed, reactive, ref, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Search, Plus, Refresh } from '@element-plus/icons-vue'
import { useCrud } from '../composables/useCrud'
import { list as listResource } from '../api/resource'

const loading = computed(() => crud.state.loading)
const query = reactive({ keyword: '', shift: '', status: '' })
const page = ref(1)
const pageSize = 10

const seed = [
  { id: 1, name: '王建国', date: '2026-10-06', shift: '早班', startAt: '2026-10-06 09:00', endAt: '2026-10-06 13:00', isRest: false, status: '已批准', reason: '' },
  { id: 2, name: '李秀兰', date: '2026-10-06', shift: '午班', startAt: '2026-10-06 13:00', endAt: '2026-10-06 18:00', isRest: false, status: '已批准', reason: '' },
  { id: 3, name: '张伟明', date: '2026-10-06', shift: '休息', startAt: '', endAt: '', isRest: true, status: '已批准', reason: '调休' },
  { id: 4, name: '王建国', date: '2026-10-08', shift: '请假', startAt: '2026-10-08 09:00', endAt: '2026-10-09 18:00', isRest: true, status: '待审批', reason: '家中有事' },
  { id: 5, name: '李秀兰', date: '2026-10-10', shift: '请假', startAt: '2026-10-10 09:00', endAt: '2026-10-10 12:00', isRest: true, status: '待审批', reason: '体检' }
]

const crud = useCrud('schedules', { fieldMap: { name: 'technician', reason: 'note' }, keywordField: ["technician","date","shift"] })
const total = computed(() => crud.state.total)
async function reload() {
  await crud.load({ ...query, page: page.value, per_page: pageSize })
}

const formVisible = ref(false)
const editing = ref(false)
const formRef = ref()

const emptyForm = { id: '', name: '', date: '', shift: '早班', startAt: '', endAt: '', isRest: false, status: '已批准', reason: '' }
const form = reactive({ ...emptyForm })

const rules = {
  name: [{ required: true, message: '请输入技师姓名', trigger: 'blur' }],
  date: [{ required: true, message: '请输入日期', trigger: 'blur' }]
}

const filteredData = computed(() => crud.state.rows)

const pagedData = computed(() => crud.state.rows)

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
  await refreshCurrent()
}

async function toggleRest(row) {
  await crud.patch(row.id, { isRest: !row.isRest })
  ElMessage.success(`${row.name} ${row.date}已改为${row.isRest ? '休息' : '上班'}`)
  reload()
}

async function approve(row, ok) {
  await crud.patch(row.id, { status: ok ? '已批准' : '已驳回' })
  ElMessage.success(`${row.name} 的请假申请已${ok ? '批准' : '驳回'}`)
  reload()
}

function handleDelete(row) {
  ElMessageBox.confirm(`确认删除「${row.name} ${row.date}」的排班吗？`, '删除排班', {
    type: 'warning', confirmButtonText: '确认删除', cancelButtonText: '取消'
  }).then(async () => {
    await crud.remove(row.id)
    ElMessage.success('删除成功')
    reload()
  }).catch(() => {})
}

// ==================================================================
// 日历视图（周矩阵）：纯前端读取，复用 schedules/technicians 两个只读接口
// ==================================================================
const viewMode = ref('list')
const techs = ref([])
const schedMap = ref({})
const calLoading = ref(false)
const weekStart = ref(mondayOf(new Date()))

function pad(n) { return String(n).padStart(2, '0') }
function ymd(d) { return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}` }
function mondayOf(d) { const x = new Date(d); const dow = (x.getDay() + 6) % 7; x.setDate(x.getDate() - dow); x.setHours(0, 0, 0, 0); return x }

const weekDays = computed(() => {
  const arr = []
  const today = ymd(new Date())
  for (let i = 0; i < 7; i++) {
    const d = new Date(weekStart.value)
    d.setDate(d.getDate() + i)
    const date = ymd(d)
    arr.push({ date, label: `周${'日一二三四五六'[d.getDay()]} ${pad(d.getMonth() + 1)}-${pad(d.getDate())}`, isToday: date === today })
  }
  return arr
})
const rangeLabel = computed(() => `${weekDays.value[0].date} ~ ${weekDays.value[6].date}`)

async function loadCalendar() {
  calLoading.value = true
  try {
    const [t, s] = await Promise.all([
      listResource('technicians', { page: 1, per_page: 200 }),
      listResource('schedules', { page: 1, per_page: 500 })
    ])
    techs.value = t.list || []
    const map = {}
    for (const r of (s.list || [])) map[`${r.technician}#${r.date}`] = r
    schedMap.value = map
  } catch (e) {
    ElMessage.error('日历加载失败：' + ((e && e.message) || '未知原因'))
  } finally {
    calLoading.value = false
  }
}
function getSched(name, date) { return schedMap.value[`${name}#${date}`] || null }
function slotClass(s) { if (!s) return 'cal-slot--todo'; if (s.is_rest) return 'cal-slot--rest'; return 'cal-slot--work' }
function onViewMode(v) { if (v === 'calendar') loadCalendar() }
function shiftWeek(n) { const d = new Date(weekStart.value); d.setDate(d.getDate() + n * 7); weekStart.value = d }
function goThisWeek() { weekStart.value = mondayOf(new Date()) }
async function refreshCurrent() { if (viewMode.value === 'calendar') await loadCalendar(); else await reload() }
function cellClick(t, date) {
  const s = getSched(t.name, date)
  if (s) {
    editing.value = true
    Object.assign(form, { id: s.id, name: s.technician || t.name, date: s.date || date, shift: s.shift || '早班', startAt: s.start_at || '', endAt: s.end_at || '', isRest: !!s.is_rest, status: s.status || '已批准', reason: s.note || '' })
  } else {
    editing.value = false
    Object.assign(form, { ...emptyForm, name: t.name, date, shift: '早班', status: '已批准' })
  }
  formVisible.value = true
  formRef.value?.clearValidate()
}

onMounted(() => {
  reload()
})
</script>

<style scoped>
.page-subtitle {
  color: var(--text-tertiary);
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

/* 日历视图 */
.calendar-wrap {
  min-height: 200px;
}
.calendar-nav {
  display: flex;
  align-items: center;
  gap: var(--space-xs);
  margin-bottom: var(--space-sm);
}
.calendar-range {
  margin-left: var(--space-xs);
  color: var(--text-secondary);
  font-size: var(--font-size-body);
}
.cal-grid {
  overflow-x: auto;
  border: 1px solid var(--border-color, #ebeef5);
  border-radius: 6px;
}
.cal-row {
  display: grid;
  grid-template-columns: 96px repeat(7, minmax(96px, 1fr));
  border-bottom: 1px solid var(--border-color, #ebeef5);
}
.cal-row:last-child {
  border-bottom: none;
}
.cal-row--head {
  background: var(--bg-hover, #f5f7fa);
  font-weight: 600;
}
.cal-row--head .cal-day-cell.is-today {
  color: var(--primary);
}
.cal-tech-cell,
.cal-day-cell {
  padding: 6px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: var(--font-size-body);
  border-right: 1px solid var(--border-color, #ebeef5);
}
.cal-tech-cell {
  justify-content: flex-start;
}
.cal-tech-name {
  font-weight: 600;
}
.cal-row--head .cal-tech-cell,
.cal-row--head .cal-day-cell {
  min-height: 34px;
}
.cal-slot {
  width: 100%;
  min-height: 42px;
  border-radius: 4px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 4px;
  cursor: pointer;
  position: relative;
  transition: background 0.15s;
}
.cal-slot:hover {
  filter: brightness(0.96);
}
.cal-slot--work {
  background: rgba(64, 158, 255, 0.14);
  color: var(--primary, #409eff);
}
.cal-slot--rest {
  background: rgba(144, 147, 153, 0.16);
  color: var(--text-secondary, #909399);
}
.cal-slot--todo {
  background: var(--bg-hover, #fafafa);
  color: var(--text-tertiary, #c0c4cc);
}
.cal-shift {
  font-weight: 600;
}
.cal-todo {
  font-size: var(--font-size-body);
}
.cal-badge {
  position: absolute;
  top: 2px;
  right: 2px;
  font-size: 10px;
  line-height: 1;
  padding: 2px 3px;
  border-radius: 3px;
  background: var(--text-secondary, #909399);
  color: #fff;
}
.cal-badge--warn {
  background: var(--warning, #e6a23c);
}
.cal-empty {
  padding: var(--space-lg);
  text-align: center;
  color: var(--text-tertiary);
}
.cal-legend {
  display: flex;
  align-items: center;
  gap: var(--space-md);
  margin-top: var(--space-sm);
  font-size: var(--font-size-body);
  color: var(--text-secondary);
}
.cal-legend .lg {
  display: inline-block;
  width: 12px;
  height: 12px;
  border-radius: 3px;
  margin-right: 4px;
  vertical-align: middle;
}
.lg--work { background: rgba(64, 158, 255, 0.4); }
.lg--rest { background: rgba(144, 147, 153, 0.4); }
.lg--todo { background: #f0f0f0; }
.cal-tip {
  margin-left: auto;
  color: var(--text-tertiary);
}
</style>
