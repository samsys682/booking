<template>
  <div class="content">
    <div class="content-card content-card--list">
      <div class="action-bar">
        <h2 class="page-title">时段差价</h2>
        <div class="action-bar__left">
          <el-input v-model="query.keyword" placeholder="规则名称" clearable style="width: 200px"
            @clear="handleSearch" @keyup.enter="handleSearch">
            <template #prefix><el-icon><Search /></el-icon></template>
          </el-input>
          <el-select v-model="query.dateType" placeholder="日期类型" clearable style="width: 130px" @change="handleSearch">
            <el-option v-for="t in dateTypes" :key="t" :label="t" :value="t" />
          </el-select>
          <el-button :icon="Search" @click="handleSearch">查询</el-button>
        </div>
        <div class="action-bar__right">
          <el-button type="primary" :icon="Plus" @click="openCreate">新建规则</el-button>
        </div>
      </div>
      <div class="table-wrap" v-loading="loading">
        <el-table :data="pagedData" empty-text="暂无差价规则">
          <el-table-column prop="name" label="规则名称" min-width="130" />
          <el-table-column prop="scope" label="适用范围" min-width="110" />
          <el-table-column prop="dateType" label="日期类型" width="110">
            <template #default="{ row }">
              <el-tag size="small" type="info">{{ row.dateType }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column prop="timeRange" label="生效时段" min-width="160" />
          <el-table-column label="加价方式" min-width="130">
            <template #default="{ row }">
              {{ row.mode === '倍率' ? `基础价 × ${row.value}` : `固定 +¥${row.value}` }}
            </template>
          </el-table-column>
          <el-table-column prop="priority" label="优先级" width="90" />
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
        <el-empty v-if="!loading && filteredData.length === 0" description="暂无差价规则">
          <el-button type="primary" @click="openCreate">新建规则</el-button>
        </el-empty>
      </div>
      <div class="pagination-bar">
        <el-pagination v-model:current-page="page" :page-size="pageSize"
          :total="total" layout="total, prev, pager, next" background size="small" />
      </div>
    </div>

    <el-dialog v-model="formVisible" :title="editing ? '编辑规则' : '新建规则'" width="660px" class="list-dialog">
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top">
        <div class="form-section">生效条件</div>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="规则名称" prop="name">
              <el-input v-model="form.name" placeholder="如：周末加价" maxlength="20" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="适用范围" prop="scope">
              <el-select v-model="form.scope" style="width: 100%">
                <el-option label="全服务" value="全服务" />
                <el-option v-for="s in serviceNames" :key="s" :label="s" :value="s" />
              </el-select>
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="日期类型" prop="dateType">
              <el-select v-model="form.dateType" style="width: 100%">
                <el-option v-for="t in dateTypes" :key="t" :label="t" :value="t" />
              </el-select>
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="生效时段" prop="timeRange">
              <el-input v-model="form.timeRange" placeholder="如：21:00 - 08:00" />
            </el-form-item>
          </el-col>
        </el-row>
        <div class="form-section">加价方式</div>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="加价模式" prop="mode">
              <el-radio-group v-model="form.mode">
                <el-radio-button label="倍率">倍率</el-radio-button>
                <el-radio-button label="固定金额">固定金额</el-radio-button>
              </el-radio-group>
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item :label="form.mode === '倍率' ? '倍率（如 1.1）' : '加价金额（元）'" prop="value">
              <el-input-number v-model="form.value" :min="form.mode === '倍率' ? 1 : 0"
                :step="form.mode === '倍率' ? 0.1 : 10" :precision="form.mode === '倍率' ? 1 : 2"
                style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="优先级">
              <el-input-number v-model="form.priority" :min="1" :max="99" style="width: 100%" />
              <span class="form-tip">数字小的优先命中；同一时段多条规则时只应用优先级最高的一条。</span>
            </el-form-item>
          </el-col>
        </el-row>
        <div class="form-section">状态</div>
        <el-form-item label="启用状态">
          <el-switch v-model="form.status" />
          <span class="form-tip">停用后下单页按基础价计算，已生成订单价格不变。</span>
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
const query = reactive({ keyword: '', dateType: '' })
const page = ref(1)
const pageSize = 10
// value 口径：倍率存百分比（1.1 倍 → 110）、固定加价存「分」（30 元 → 3000）——两者都是 ×100，
// 故统一走 moneyFields 做「展示值 ↔ 存储值」换算；否则 1.1 入 INT 列会被静默截断为 1（规则失效）
const crud = useCrud('price-rules', { fieldMap: {}, moneyFields: ['value'], keywordField: ["name","scope"] })
const total = computed(() => crud.state.total)
async function reload() {
  await crud.load({ ...query, page: page.value, per_page: pageSize })
}
const formVisible = ref(false)
const editing = ref(false)
const formRef = ref()

const dateTypes = ['每日', '周末', '节假日', '指定日期']
// 服务下拉选项：真实 API 加载（原 mock 导入被误删后重建）
const services = ref([])
const serviceNames = computed(() => services.value.map((s) => s.name))
async function loadOptions() {
  try {
    const res = await listResource('services', { page: 1, per_page: 500 })
    services.value = res.list || []
  } catch (e) {
    console.warn('[price-rule-options]', e && e.message)
  }
}

const emptyForm = { id: '', name: '', scope: '全服务', dateType: '每日', timeRange: '00:00 - 24:00', mode: '倍率', value: 1.1, priority: 1, status: true }
const form = reactive({ ...emptyForm })

const rules = {
  name: [{ required: true, message: '请输入规则名称', trigger: 'blur' }],
  dateType: [{ required: true, message: '请选择日期类型', trigger: 'change' }],
  timeRange: [{ required: true, message: '请输入生效时段', trigger: 'blur' }],
  value: [{ required: true, message: '请输入加价数值', trigger: 'blur' }]
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
  reload()
}

async function handleToggle(row) {
  await crud.patch(row.id, { status: row.status })
  ElMessage.success(`「${row.name}」已${row.status ? '启用' : '停用'}`)
  reload()
}

function handleDelete(row) {
  ElMessageBox.confirm(`确认删除规则「${row.name}」吗？`, '删除规则', {
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
