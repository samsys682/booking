<template>
  <div class="content">
    <div class="content-card content-card--list">
      <div class="action-bar">
        <h2 class="page-title">服务区域</h2>
        <div class="action-bar__left">
          <el-input v-model="query.keyword" placeholder="区域名称 / 街道" clearable style="width: 200px"
            @clear="handleSearch" @keyup.enter="handleSearch">
            <template #prefix><el-icon><Search /></el-icon></template>
          </el-input>
          <el-button :icon="Search" @click="handleSearch">查询</el-button>
        </div>
        <div class="action-bar__right">
          <el-button type="primary" :icon="Plus" @click="openCreate">新建区域</el-button>
        </div>
      </div>
      <div class="table-wrap" v-loading="loading">
        <el-table :data="pagedData" empty-text="暂无服务区域">
          <el-table-column prop="name" label="区域名称" min-width="110" />
          <el-table-column prop="cities" label="城市 / 区县" min-width="150" />
          <el-table-column prop="districts" label="覆盖街道" min-width="220" show-overflow-tooltip />
          <el-table-column label="起步价" width="100">
            <template #default="{ row }">¥{{ row.startPrice }}</template>
          </el-table-column>
          <el-table-column label="偏远加价" width="100">
            <template #default="{ row }">{{ row.remoteFee ? `¥${row.remoteFee}` : '无' }}</template>
          </el-table-column>
          <el-table-column label="服务半径" width="100">
            <template #default="{ row }">{{ row.radius }} km</template>
          </el-table-column>
          <el-table-column prop="sort" label="排序" width="80" />
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
        <el-empty v-if="!loading && filteredData.length === 0" description="暂无服务区域">
          <el-button type="primary" @click="openCreate">新建区域</el-button>
        </el-empty>
      </div>
      <div class="pagination-bar">
        <el-pagination v-model:current-page="page" :page-size="pageSize"
          :total="total" layout="total, prev, pager, next" background size="small" />
      </div>
    </div>

    <el-dialog v-model="formVisible" :title="editing ? '编辑区域' : '新建区域'" width="680px" class="list-dialog">
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top">
        <div class="form-section">区域范围</div>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="区域名称" prop="name">
              <el-input v-model="form.name" placeholder="如：城东片区" maxlength="20" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="城市 / 区县" prop="cities">
              <el-input v-model="form.cities" placeholder="如：杭州市 / 上城区" maxlength="40" />
            </el-form-item>
          </el-col>
        </el-row>
        <el-form-item label="覆盖街道" prop="districts">
          <el-input v-model="form.districts" type="textarea" :rows="2" placeholder="多个街道用顿号分隔，如：清波街道、湖滨街道" />
          <span class="form-tip">用于用户端地址识别与 LBS 就近派单，地址不在任何区域内时提示「暂未开通」。</span>
        </el-form-item>
        <div class="form-section">计价与范围</div>
        <el-row :gutter="16">
          <el-col :span="8">
            <el-form-item label="起步价（元）" prop="startPrice">
              <el-input-number v-model="form.startPrice" :min="0" :precision="2" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="偏远加价（元）">
              <el-input-number v-model="form.remoteFee" :min="0" :precision="2" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="服务半径（km）">
              <el-input-number v-model="form.radius" :min="1" :max="100" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="排序">
              <el-input-number v-model="form.sort" :min="1" :max="999" style="width: 100%" />
            </el-form-item>
          </el-col>
        </el-row>
        <div class="form-section">路途费 / 打车费（授权版+，按客户地址到区域中心的实际距离加收）</div>
        <el-row :gutter="16">
          <el-col :span="8">
            <el-form-item label="中心经度">
              <el-input-number v-model="form.centerLng" :precision="6" :step="0.0001" :controls="false" style="width: 100%" placeholder="如 121.473700" />
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="中心纬度">
              <el-input-number v-model="form.centerLat" :precision="6" :step="0.0001" :controls="false" style="width: 100%" placeholder="如 31.230400" />
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="免费半径（km）">
              <el-input-number v-model="form.freeKm" :min="0" :max="100" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="每公里加收（元）">
              <el-input-number v-model="form.feePerKm" :min="0" :precision="2" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="路费封顶（元）">
              <el-input-number v-model="form.feeCap" :min="0" :precision="2" style="width: 100%" />
            </el-form-item>
          </el-col>
        </el-row>
        <div class="form-section">状态</div>
        <el-form-item label="启用状态">
          <el-switch v-model="form.status" />
          <span class="form-tip">停用后该区域不可下单，用户端地址校验时提示暂未开通。</span>
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

const loading = computed(() => crud.state.loading)
const query = reactive({ keyword: '' })
const page = ref(1)
const pageSize = 10
const crud = useCrud('service-areas', { fieldMap: { feePerKm: 'per_km_fen', feeCap: 'travel_cap_fen' }, moneyFields: ['feePerKm', 'feeCap'], keywordField: ["name","cities"] })
const total = computed(() => crud.state.total)
async function reload() {
  await crud.load({ ...query, page: page.value, per_page: pageSize })
}
const formVisible = ref(false)
const editing = ref(false)
const formRef = ref()

const emptyForm = { id: '', name: '', cities: '', districts: '', startPrice: 128, remoteFee: 0, radius: 10, sort: 1, centerLng: 0, centerLat: 0, freeKm: 0, feePerKm: 0, feeCap: 0, status: true }
const form = reactive({ ...emptyForm })

const rules = {
  name: [{ required: true, message: '请输入区域名称', trigger: 'blur' }],
  cities: [{ required: true, message: '请输入城市 / 区县', trigger: 'blur' }],
  districts: [{ required: true, message: '请输入覆盖街道', trigger: 'blur' }],
  startPrice: [{ required: true, message: '请输入起步价', trigger: 'blur' }]
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
  ElMessageBox.confirm(`确认删除区域「${row.name}」吗？删除后该范围地址将无法下单。`, '删除区域', {
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
