<template>
  <div class="content">
    <div class="content-card content-card--list">
      <div class="action-bar">
        <h2 class="page-title">候补排队</h2>
        <div class="action-bar__left">
          <el-input v-model="query.keyword" placeholder="会员 / 电话 / 服务" clearable style="width: 200px"
            @clear="handleSearch" @keyup.enter="handleSearch">
            <template #prefix><el-icon><Search /></el-icon></template>
          </el-input>
          <el-select v-model="query.status" placeholder="候补状态" clearable style="width: 120px" @change="handleSearch">
            <el-option label="排队中" value="排队中" />
            <el-option label="已补位" value="已补位" />
            <el-option label="已过号" value="已过号" />
            <el-option label="已取消" value="已取消" />
          </el-select>
          <el-button :icon="Search" @click="handleSearch">查询</el-button>
        </div>
        <div class="action-bar__right">
          <el-button type="primary" :icon="Plus" @click="openCreate">新建候补</el-button>
        </div>
      </div>

      <div class="table-wrap" v-loading="loading">
        <el-table :data="pagedData" empty-text="暂无候补记录">
          <el-table-column prop="id" label="候补号" width="90" />
          <el-table-column prop="member" label="会员" width="90" />
          <el-table-column prop="phone" label="电话" width="120" />
          <el-table-column prop="service" label="服务项目" min-width="140" />
          <el-table-column prop="date" label="期望日期" width="110" />
          <el-table-column prop="slot" label="期望时段" width="110" />
          <el-table-column label="队列位置" width="90">
            <template #default="{ row }">{{ row.status === '排队中' ? '第 ' + row.pos + ' 位' : '—' }}</template>
          </el-table-column>
          <el-table-column label="状态" width="90">
            <template #default="{ row }">
              <el-tag :type="queueTag(row.status)" size="small">{{ row.status }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column prop="doneAt" label="处理记录" min-width="130" show-overflow-tooltip />
          <el-table-column label="操作" width="230" fixed="right">
            <template #default="{ row }">
              <el-button v-if="row.status === '排队中'" type="primary" link size="small" @click="promote(row)">转正式预约</el-button>
              <el-button v-if="row.status === '排队中'" type="danger" link size="small" @click="cancel(row)">取消候补</el-button>
              <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
              <el-button link type="danger" size="small" @click="handleDelete(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
        <el-empty v-if="!loading && filteredData.length === 0" description="暂无候补记录">
          <el-button type="primary" @click="openCreate">新建候补</el-button>
        </el-empty>
      </div>
      <div class="pagination-bar">
        <el-pagination v-model:current-page="page" :page-size="pageSize"
          :total="total" layout="total, prev, pager, next" background size="small" />
      </div>
    </div>

    <el-dialog v-model="formVisible" :title="editing ? '编辑候补' : '新建候补'" width="560px" class="list-dialog">
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top">
        <div class="form-section">候补信息</div>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="会员" prop="member">
              <el-input v-model="form.member" placeholder="客户姓名" maxlength="20" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="电话">
              <el-input v-model="form.phone" placeholder="手机号" maxlength="20" />
            </el-form-item>
          </el-col>
        </el-row>
        <el-form-item label="意向服务">
          <el-input v-model="form.service" placeholder="服务名称" maxlength="50" />
        </el-form-item>
        <el-row :gutter="16">
          <el-col :span="8">
            <el-form-item label="期望日期" prop="date">
              <el-input v-model="form.date" placeholder="如：2026-10-05" maxlength="10" />
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="期望时段">
              <el-input v-model="form.slot" placeholder="如：09:00-12:00" maxlength="20" />
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="队列位置">
              <el-input-number v-model="form.pos" :min="1" style="width: 100%" />
            </el-form-item>
          </el-col>
        </el-row>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="候补状态">
              <el-select v-model="form.status" style="width: 100%">
                <el-option label="排队中" value="排队中" />
                <el-option label="已补位" value="已补位" />
                <el-option label="已过号" value="已过号" />
                <el-option label="已取消" value="已取消" />
              </el-select>
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="处理记录">
              <el-input v-model="form.doneAt" placeholder="选填" maxlength="50" />
            </el-form-item>
          </el-col>
        </el-row>
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
// 候补排队：转正式预约/取消候补推进状态；候补记录支持完整增删改查
import { computed, reactive, ref, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Search, Plus } from '@element-plus/icons-vue'
import { useCrud } from '../composables/useCrud'

const loading = computed(() => crud.state.loading)
const query = reactive({ keyword: '', status: '' })
const page = ref(1)
const pageSize = 10

const seed = [
  { id: 1, member: '刘女士', phone: '138****2210', service: '深度保洁 3 小时', date: '2026-10-05', slot: '09:00-12:00', pos: 1, status: '排队中', doneAt: '' },
  { id: 2, member: '黄先生', phone: '135****8837', service: '家电清洗 · 油烟机', date: '2026-10-05', slot: '14:00-17:00', pos: 2, status: '排队中', doneAt: '' },
  { id: 3, member: '吴女士', phone: '136****5529', service: '开荒保洁', date: '2026-10-06', slot: '全天', pos: 3, status: '排队中', doneAt: '' },
  { id: 4, member: '郑先生', phone: '137****9045', service: '日常保洁 2 小时', date: '2026-10-04', slot: '09:00-12:00', pos: 1, status: '已补位', doneAt: '10-04 09:12 补位' }
]

const crud = useCrud('booking-queues', { fieldMap: { member: 'customer', slot: 'time_slot', pos: 'seq', doneAt: 'note' }, keywordField: ["customer","phone","service"] })
const total = computed(() => crud.state.total)
async function reload() {
  await crud.load({ ...query, page: page.value, per_page: pageSize })
}

const formVisible = ref(false)
const editing = ref(false)
const formRef = ref()

const emptyForm = { id: '', member: '', phone: '', service: '', date: '', slot: '', pos: 1, status: '排队中', doneAt: '' }
const form = reactive({ ...emptyForm })

const rules = {
  member: [{ required: true, message: '请输入会员姓名', trigger: 'blur' }],
  date: [{ required: true, message: '请输入期望日期', trigger: 'blur' }]
}

const filteredData = computed(() => crud.state.rows)

const pagedData = computed(() => crud.state.rows)

function queueTag(s) {
  return { 排队中: 'warning', 已补位: 'success', 已过号: 'danger', 已取消: 'info' }[s] || 'info'
}

function nowStr() {
  return new Date().toLocaleString('zh-CN', { hour12: false }).replace(/\//g, '-')
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

async function promote(row) {
  await crud.patch(row.id, { status: '已补位', doneAt: nowStr().slice(5, 16) + ' 补位' })
  ElMessage.success(`已为「${row.member}」创建正式预约，队列已顺延`)
  reload()
}

function cancel(row) {
  ElMessageBox.confirm(`确认取消「${row.member}」的候补吗？`, '取消候补', {
    type: 'warning', confirmButtonText: '确认取消', cancelButtonText: '返回'
  }).then(async () => {
    await crud.patch(row.id, { status: '已取消', doneAt: nowStr().slice(5, 16) + ' 取消' })
    ElMessage.success('候补已取消')
    reload()
  }).catch(() => {})
}

function handleDelete(row) {
  ElMessageBox.confirm(`确认删除候补记录「${row.member}」吗？`, '删除候补', {
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
</style>
