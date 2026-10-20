<template>
  <div class="content">
    <div class="content-card content-card--list">
      <div class="action-bar">
        <h2 class="page-title">门店管理</h2>
        <div class="action-bar__left">
          <el-input v-model="query.keyword" placeholder="门店 / 编码 / 店长" clearable style="width: 200px"
            @clear="handleSearch" @keyup.enter="handleSearch">
            <template #prefix><el-icon><Search /></el-icon></template>
          </el-input>
          <el-select v-model="query.crossStore" placeholder="跨店通用" clearable style="width: 130px" @change="handleSearch">
            <el-option label="支持通兑" value="yes" />
            <el-option label="不支持" value="no" />
          </el-select>
          <el-button :icon="Search" @click="handleSearch">查询</el-button>
        </div>
        <div class="action-bar__right">
          <el-button type="primary" :icon="Plus" @click="openCreate">新建门店</el-button>
        </div>
      </div>
      <div class="scope-bar">
        <el-tag size="small" :type="scope.scoped ? 'warning' : 'success'" effect="plain">
          {{ scope.scoped ? '门店视角' : '总部视角' }}
        </el-tag>
        <span class="scope-text">
          {{ scope.scoped
            ? `当前账号仅可见「${scope.store_name}」的订单 / 会员 / 技师数据（多门店数据隔离）`
            : '当前账号可见全部门店数据' }}
        </span>
        <el-tag v-if="scope.scoped" size="small" :type="scope.cross_store ? 'success' : 'info'">
          {{ scope.cross_store ? '本店支持跨店通兑' : '本店不支持跨店通兑' }}
        </el-tag>
      </div>
      <div class="table-wrap" v-loading="loading">
        <el-table :data="pagedData" empty-text="暂无门店">
          <el-table-column prop="name" label="门店名称" min-width="120" />
          <el-table-column prop="code" label="编码" width="80" />
          <el-table-column prop="manager" label="店长" width="90" />
          <el-table-column prop="phone" label="联系电话" min-width="120" />
          <el-table-column prop="address" label="地址" min-width="200" show-overflow-tooltip />
          <el-table-column prop="technicians" label="技师" width="70" />
          <el-table-column prop="orders" label="累计订单" width="90" />
          <el-table-column label="跨店通兑" width="100">
            <template #default="{ row }">
              <el-tag size="small" :type="row.crossStore ? 'success' : 'info'">{{ row.crossStore ? '支持' : '不支持' }}</el-tag>
            </template>
          </el-table-column>
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
        <el-empty v-if="!loading && filteredData.length === 0" description="暂无门店">
          <el-button type="primary" @click="openCreate">新建门店</el-button>
        </el-empty>
      </div>
      <div class="pagination-bar">
        <el-pagination v-model:current-page="page" :page-size="pageSize"
          :total="total" layout="total, prev, pager, next" background size="small" />
      </div>
    </div>

    <el-dialog v-model="formVisible" :title="editing ? '编辑门店' : '新建门店'" width="640px" class="list-dialog">
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top">
        <div class="form-section">门店信息</div>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="门店名称" prop="name">
              <el-input v-model="form.name" maxlength="20" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="门店编码" prop="code">
              <el-input v-model="form.code" placeholder="如：HQ / CN" maxlength="10" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="店长" prop="manager">
              <el-input v-model="form.manager" maxlength="20" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="联系电话" prop="phone">
              <el-input v-model="form.phone" maxlength="11" />
            </el-form-item>
          </el-col>
        </el-row>
        <el-form-item label="门店地址" prop="address">
          <el-input v-model="form.address" maxlength="80" />
        </el-form-item>
        <div class="form-section">经营设置</div>
        <el-form-item label="跨店通用">
          <el-switch v-model="form.crossStore" />
          <span class="form-tip">开启后储值、积分、优惠券、次卡可在本店与其他门店通用；关闭则仅限本店核销。多门店商家请统一口径后再开启。</span>
        </el-form-item>
        <el-form-item label="启用状态">
          <el-switch v-model="form.status" />
          <span class="form-tip">停用后门店不再接收新订单，历史订单与技师归属保留。</span>
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
import { fetchStoreScope } from '../api/store'

// 门店作用域（多门店数据隔离）：门店账号仅见本店数据，总部见全部
const scope = reactive({ store_id: 0, store_name: '', cross_store: true, scoped: false })

const loading = computed(() => crud.state.loading)
const query = reactive({ keyword: '', crossStore: '' })
const page = ref(1)
const pageSize = 10
const crud = useCrud('stores', { fieldMap: {}, keywordField: ["name","code","manager","phone"] })
const total = computed(() => crud.state.total)
async function reload() {
  await crud.load({ ...query, page: page.value, per_page: pageSize })
}
const formVisible = ref(false)
const editing = ref(false)
const formRef = ref()

const emptyForm = {
  id: '', name: '', code: '', manager: '', phone: '', address: '',
  technicians: 0, orders: 0, crossStore: false, status: true
}
const form = reactive({ ...emptyForm })

const rules = {
  name: [{ required: true, message: '请输入门店名称', trigger: 'blur' }],
  code: [{ required: true, message: '请输入门店编码', trigger: 'blur' }],
  manager: [{ required: true, message: '请输入店长', trigger: 'blur' }],
  phone: [{ required: true, pattern: /^1\d{10}$/, message: '请输入 11 位手机号', trigger: 'blur' }],
  address: [{ required: true, message: '请输入门店地址', trigger: 'blur' }]
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
  ElMessageBox.confirm(`确认删除门店「${row.name}」吗？请先转移该店技师与未完成订单。`, '删除门店', {
    type: 'warning', confirmButtonText: '确认删除', cancelButtonText: '取消'
  }).then(async () => {
    await crud.remove(row.id)
    ElMessage.success('删除成功')
    reload()
  }).catch(() => {})
}

onMounted(() => {
  reload()
  fetchStoreScope().then((r) => Object.assign(scope, r)).catch(() => {})
})
</script>

<style scoped>
.scope-bar {
  display: flex;
  align-items: center;
  gap: var(--space-sm);
  padding: var(--space-sm) var(--space-md);
  margin-bottom: var(--space-sm);
  background: var(--bg-page);
  border-radius: var(--radius-sm);
}
.scope-text {
  color: var(--text-secondary);
  font-size: var(--font-size-body);
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
