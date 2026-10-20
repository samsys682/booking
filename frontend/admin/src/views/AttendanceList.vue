<template>
  <div class="content">
    <div class="content-card content-card--list">
      <div class="action-bar">
        <h2 class="page-title">考勤打卡</h2>
        <div class="action-bar__left">
          <el-input v-model="query.keyword" placeholder="技师姓名" clearable style="width: 180px"
            @clear="handleSearch" @keyup.enter="handleSearch">
            <template #prefix><el-icon><Search /></el-icon></template>
          </el-input>
          <el-select v-model="query.status" placeholder="考勤状态" clearable style="width: 130px" @change="handleSearch">
            <el-option v-for="s in statusList" :key="s" :label="s" :value="s" />
          </el-select>
          <el-date-picker v-model="query.date" type="date" placeholder="打卡日期" value-format="YYYY-MM-DD"
            style="width: 150px" @change="handleSearch" />
          <el-button :icon="Search" @click="handleSearch">查询</el-button>
        </div>
        <div class="action-bar__right">
          <el-button type="primary" :icon="Plus" @click="openCreate">补录考勤</el-button>
        </div>
      </div>
      <div class="table-wrap" v-loading="loading">
        <el-table :data="pagedData" empty-text="暂无考勤记录">
          <el-table-column prop="date" label="日期" width="110" />
          <el-table-column prop="technician" label="技师" min-width="100" />
          <el-table-column label="上班打卡" width="100">
            <template #default="{ row }">{{ row.clockIn || '-' }}</template>
          </el-table-column>
          <el-table-column label="下班打卡" width="100">
            <template #default="{ row }">{{ row.clockOut || '-' }}</template>
          </el-table-column>
          <el-table-column label="工时(h)" width="90">
            <template #default="{ row }">{{ row.hours }}</template>
          </el-table-column>
          <el-table-column prop="orders" label="服务单数" width="90" />
          <el-table-column label="状态" width="100">
            <template #default="{ row }">
              <el-tag size="small" :type="attendanceType(row.status)">{{ row.status }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column prop="note" label="备注" min-width="150" show-overflow-tooltip />
          <el-table-column label="操作" width="140" fixed="right">
            <template #default="{ row }">
              <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
              <el-button link type="danger" size="small" @click="handleDelete(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
        <el-empty v-if="!loading && filteredData.length === 0" description="暂无考勤记录">
          <el-button type="primary" @click="openCreate">补录考勤</el-button>
        </el-empty>
      </div>
      <div class="pagination-bar">
        <el-pagination v-model:current-page="page" :page-size="pageSize"
          :total="total" layout="total, prev, pager, next" background size="small" />
      </div>
    </div>

    <el-dialog v-model="formVisible" :title="editing ? '编辑考勤' : '补录考勤'" width="620px" class="list-dialog">
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top">
        <div class="form-section">打卡信息</div>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="技师" prop="technician">
              <el-select v-model="form.technician" style="width: 100%">
                <el-option v-for="t in technicianNames" :key="t" :label="t" :value="t" />
              </el-select>
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="日期" prop="date">
              <el-date-picker v-model="form.date" type="date" value-format="YYYY-MM-DD" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="上班时间">
              <el-time-picker v-model="form.clockIn" format="HH:mm" value-format="HH:mm" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="下班时间">
              <el-time-picker v-model="form.clockOut" format="HH:mm" value-format="HH:mm" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="考勤状态" prop="status">
              <el-select v-model="form.status" style="width: 100%">
                <el-option v-for="s in statusList" :key="s" :label="s" :value="s" />
              </el-select>
            </el-form-item>
          </el-col>
        </el-row>
        <el-form-item label="备注">
          <el-input v-model="form.note" type="textarea" :rows="2" placeholder="如：迟到原因、补录说明" maxlength="100" />
          <span class="form-tip">补录记录会在操作日志中留痕；工时按上下班时间自动计算，可人工修正。</span>
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
import { list as listResource } from '../api/resource'

const loading = computed(() => crud.state.loading)
const query = reactive({ keyword: '', status: '', date: '' })
const page = ref(1)
const pageSize = 10
const crud = useCrud('attendances', { fieldMap: {"clockIn":"clock_in","clockOut":"clock_out"}, keywordField: ["technician","date"] })
const total = computed(() => crud.state.total)
async function reload() {
  await crud.load({ ...query, page: page.value, per_page: pageSize })
}
const formVisible = ref(false)
const editing = ref(false)
const formRef = ref()

const statusList = ['正常', '迟到', '早退', '缺卡']
// 技师下拉选项：真实 API 加载（原 mock 导入被误删后重建）
const technicians = ref([])
const technicianNames = computed(() => technicians.value.map((t) => t.name))
async function loadOptions() {
  try {
    const res = await listResource('technicians', { page: 1, per_page: 500 })
    technicians.value = res.list || []
  } catch (e) {
    console.warn('[attendance-options]', e && e.message)
  }
}

const emptyForm = { id: '', technician: '', date: '', clockIn: '', clockOut: '', hours: 0, orders: 0, status: '正常', note: '' }
const form = reactive({ ...emptyForm })

const rules = {
  technician: [{ required: true, message: '请选择技师', trigger: 'change' }],
  date: [{ required: true, message: '请选择日期', trigger: 'change' }],
  status: [{ required: true, message: '请选择考勤状态', trigger: 'change' }]
}

function attendanceType(status) {
  return { 正常: 'success', 迟到: 'warning', 早退: 'warning', 缺卡: 'danger' }[status] || 'info'
}

function calcHours(inStr, outStr) {
  if (!inStr || !outStr) return 0
  const [ih, im] = inStr.split(':').map(Number)
  const [oh, om] = outStr.split(':').map(Number)
  return Math.max(0, Math.round(((oh * 60 + om) - (ih * 60 + im)) / 6) / 10)
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
  const payload = { ...form, hours: calcHours(form.clockIn, form.clockOut) }
  await crud.save(editing.value ? form.id : null, payload)
  ElMessage.success(editing.value ? '保存成功' : '新建成功')
  formVisible.value = false
  reload()
}

function handleDelete(row) {
  ElMessageBox.confirm(`确认删除 ${row.technician} 在 ${row.date} 的考勤记录吗？`, '删除考勤', {
    type: 'warning', confirmButtonText: '确认删除', cancelButtonText: '取消'
  }).then(async () => {
    await crud.remove(row.id)
    ElMessage.success('删除成功')
    reload()
  }).catch(() => {})
}

onMounted(() => {
  reload()
  loadOptions()
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
