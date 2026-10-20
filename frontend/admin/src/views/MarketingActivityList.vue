<template>
  <div class="content">
    <div class="content-card content-card--list">
      <div class="action-bar">
        <h2 class="page-title">营销活动</h2>
        <div class="action-bar__left">
          <el-input v-model="query.keyword" placeholder="活动名称 / 适用服务" clearable style="width: 200px"
            @clear="handleSearch" @keyup.enter="handleSearch">
            <template #prefix><el-icon><Search /></el-icon></template>
          </el-input>
          <el-select v-model="query.kind" placeholder="活动类型" clearable style="width: 130px" @change="handleSearch">
            <el-option v-for="k in kinds" :key="k" :label="k" :value="k" />
          </el-select>
          <el-button :icon="Search" @click="handleSearch">查询</el-button>
        </div>
        <div class="action-bar__right">
          <el-button type="primary" :icon="Plus" @click="openCreate">新建活动</el-button>
        </div>
      </div>
      <div class="table-wrap" v-loading="loading">
        <el-table :data="pagedData" empty-text="暂无营销活动">
          <el-table-column prop="name" label="活动名称" min-width="150" />
          <el-table-column label="类型" width="100">
            <template #default="{ row }">
              <el-tag size="small" :type="kindType(row.kind)">{{ row.kind }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column prop="target" label="适用服务" min-width="130" show-overflow-tooltip />
          <el-table-column label="活动价 / 原价" min-width="140">
            <template #default="{ row }">
              <template v-if="row.price">
                <span class="price">¥{{ row.price }}</span>
                <span class="origin-price">¥{{ row.originPrice }}</span>
              </template>
              <template v-else>按折扣结算</template>
            </template>
          </el-table-column>
          <el-table-column label="成团 / 库存" width="110">
            <template #default="{ row }">{{ row.groupSize ? `${row.groupSize} 人团` : `${row.sold}/${row.stock}` }}</template>
          </el-table-column>
          <el-table-column prop="startAt" label="开始时间" min-width="140" />
          <el-table-column prop="endAt" label="结束时间" min-width="140" />
          <el-table-column label="状态" width="100">
            <template #default="{ row }">
              <el-tag size="small" :type="activityType(row.status)">{{ row.status }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="140" fixed="right">
            <template #default="{ row }">
              <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
              <el-button link type="danger" size="small" @click="handleDelete(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
        <el-empty v-if="!loading && filteredData.length === 0" description="暂无营销活动">
          <el-button type="primary" @click="openCreate">新建活动</el-button>
        </el-empty>
      </div>
      <div class="pagination-bar">
        <el-pagination v-model:current-page="page" :page-size="pageSize"
          :total="total" layout="total, prev, pager, next" background size="small" />
      </div>
    </div>

    <el-dialog v-model="formVisible" :title="editing ? '编辑活动' : '新建活动'" width="680px" class="list-dialog">
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top">
        <div class="form-section">活动信息</div>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="活动名称" prop="name">
              <el-input v-model="form.name" maxlength="30" placeholder="如：双人保洁拼团" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="活动类型" prop="kind">
              <el-radio-group v-model="form.kind">
                <el-radio-button v-for="k in kinds" :key="k" :label="k">{{ k }}</el-radio-button>
              </el-radio-group>
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="适用服务" prop="target">
              <el-select v-model="form.target" style="width: 100%">
                <el-option label="全部服务" value="全部服务" />
                <el-option v-for="s in serviceNames" :key="s" :label="s" :value="s" />
              </el-select>
            </el-form-item>
          </el-col>
          <el-col v-if="form.kind === '拼团'" :span="12">
            <el-form-item label="成团人数">
              <el-input-number v-model="form.groupSize" :min="2" :max="20" style="width: 100%" />
            </el-form-item>
          </el-col>
        </el-row>
        <div class="form-section">价格与库存</div>
        <el-row :gutter="16">
          <el-col :span="8">
            <el-form-item label="活动价（元）">
              <el-input-number v-model="form.price" :min="0" :precision="2" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="原价（元）">
              <el-input-number v-model="form.originPrice" :min="0" :precision="2" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="活动库存">
              <el-input-number v-model="form.stock" :min="0" :max="99999" style="width: 100%" />
            </el-form-item>
          </el-col>
        </el-row>
        <div class="form-section">生效时间</div>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="开始时间" prop="startAt">
              <el-date-picker v-model="form.startAt" type="datetime" value-format="YYYY-MM-DD HH:mm" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="结束时间" prop="endAt">
              <el-date-picker v-model="form.endAt" type="datetime" value-format="YYYY-MM-DD HH:mm" style="width: 100%" />
            </el-form-item>
          </el-col>
        </el-row>
        <div class="form-section">状态</div>
        <el-form-item label="活动状态" prop="status">
          <el-radio-group v-model="form.status">
            <el-radio-button label="未开始">未开始</el-radio-button>
            <el-radio-button label="进行中">进行中</el-radio-button>
            <el-radio-button label="已结束">已结束</el-radio-button>
          </el-radio-group>
          <span class="form-tip">到期自动结束；结束后会员端下架入口，已成团订单继续履约。</span>
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
const query = reactive({ keyword: '', kind: '' })
const page = ref(1)
const pageSize = 10
const crud = useCrud('marketing-activities', { fieldMap: {}, moneyFields: ["price","originPrice"], keywordField: ["name"] })
const total = computed(() => crud.state.total)
async function reload() {
  await crud.load({ ...query, page: page.value, per_page: pageSize })
}
const formVisible = ref(false)
const editing = ref(false)
const formRef = ref()

const kinds = ['拼团', '秒杀', '限时折扣']
// 服务下拉选项：真实 API 加载（原 mock 导入被误删后重建）
const services = ref([])
const serviceNames = computed(() => services.value.map((s) => s.name))
async function loadOptions() {
  try {
    const res = await listResource('services', { page: 1, per_page: 500 })
    services.value = res.list || []
  } catch (e) {
    console.warn('[marketing-options]', e && e.message)
  }
}

const emptyForm = {
  id: '', name: '', kind: '拼团', target: '全部服务', price: 0, originPrice: 0,
  groupSize: 2, sold: 0, stock: 100, startAt: '', endAt: '', status: '未开始'
}
const form = reactive({ ...emptyForm })

const rules = {
  name: [{ required: true, message: '请输入活动名称', trigger: 'blur' }],
  target: [{ required: true, message: '请选择适用服务', trigger: 'change' }],
  startAt: [{ required: true, message: '请选择开始时间', trigger: 'change' }],
  endAt: [{ required: true, message: '请选择结束时间', trigger: 'change' }]
}

function kindType(kind) {
  return { 拼团: 'danger', 秒杀: 'warning', 限时折扣: 'primary' }[kind] || 'info'
}
function activityType(status) {
  return { 进行中: 'success', 未开始: 'warning', 已结束: 'info' }[status] || 'info'
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
  if (form.endAt && form.startAt && form.endAt <= form.startAt) {
    ElMessage.warning('结束时间必须晚于开始时间')
    return
  }
  await crud.save(editing.value ? form.id : null, { ...form })
  ElMessage.success(editing.value ? '保存成功' : '新建成功')
  formVisible.value = false
  reload()
}

function handleDelete(row) {
  ElMessageBox.confirm(`确认删除活动「${row.name}」吗？已成团订单不受影响。`, '删除活动', {
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
.price {
  color: var(--danger, #f56c6c);
  font-weight: 600;
}
.origin-price {
  color: var(--text-tertiary);
  text-decoration: line-through;
  margin-left: 6px;
  font-size: var(--font-size-body);
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
