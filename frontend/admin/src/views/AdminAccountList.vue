<template>
  <div class="content">
    <div class="content-card content-card--list">
      <div class="action-bar">
        <h2 class="page-title">员工账号</h2>
        <div class="action-bar__left">
          <el-input v-model="query.keyword" placeholder="姓名 / 登录账号" clearable style="width: 200px"
            @clear="handleSearch" @keyup.enter="handleSearch">
            <template #prefix><el-icon><Search /></el-icon></template>
          </el-input>
          <el-select v-model="query.role" placeholder="所属角色" clearable style="width: 150px" @change="handleSearch">
            <el-option v-for="r in roles" :key="r.id" :label="r.name" :value="r.name" />
          </el-select>
          <el-button :icon="Search" @click="handleSearch">查询</el-button>
        </div>
        <div class="action-bar__right">
          <el-button type="primary" :icon="Plus" @click="openCreate">新建账号</el-button>
        </div>
      </div>
      <div class="table-wrap" v-loading="loading">
        <el-table :data="pagedData" empty-text="暂无员工账号">
          <el-table-column prop="name" label="姓名" min-width="110" />
          <el-table-column prop="account" label="登录账号" min-width="120" />
          <el-table-column prop="role" label="所属角色" min-width="120">
            <template #default="{ row }">
              <el-tag size="small" :type="row.role === '超级管理员' ? 'danger' : 'primary'">{{ row.role }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column prop="phone" label="手机号" min-width="120" />
          <el-table-column prop="store" label="所属门店" min-width="100" />
          <el-table-column prop="lastLogin" label="最近登录" min-width="140" />
          <el-table-column label="状态" width="90">
            <template #default="{ row }">
              <el-switch v-model="row.status" @change="handleToggle(row)" />
            </template>
          </el-table-column>
          <el-table-column label="操作" width="160" fixed="right">
            <template #default="{ row }">
              <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
              <el-button link type="primary" size="small" @click="handleReset(row)">重置密码</el-button>
              <el-button link type="danger" size="small" :disabled="row.role === '超级管理员'" @click="handleDelete(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
        <el-empty v-if="!loading && filteredData.length === 0" description="暂无员工账号">
          <el-button type="primary" @click="openCreate">新建账号</el-button>
        </el-empty>
      </div>
      <div class="pagination-bar">
        <el-pagination v-model:current-page="page" :page-size="pageSize"
          :total="total" layout="total, prev, pager, next" background size="small" />
      </div>
    </div>

    <el-dialog v-model="formVisible" :title="editing ? '编辑账号' : '新建账号'" width="640px" class="list-dialog">
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top">
        <div class="form-section">基本信息</div>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="姓名" prop="name">
              <el-input v-model="form.name" placeholder="请输入真实姓名" maxlength="20" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="登录账号" prop="account">
              <el-input v-model="form.account" placeholder="4-20 位字母或数字" :disabled="editing" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="手机号" prop="phone">
              <el-input v-model="form.phone" placeholder="用于登录与找回密码" maxlength="11" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="所属角色" prop="role">
              <el-select v-model="form.role" placeholder="请选择角色" style="width: 100%">
                <el-option v-for="r in roles" :key="r.id" :label="r.name" :value="r.name" />
              </el-select>
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="所属门店" prop="store">
              <el-select v-model="form.store" placeholder="请选择门店" style="width: 100%">
                <el-option v-for="s in stores" :key="s.id" :label="s.name" :value="s.name" />
              </el-select>
            </el-form-item>
          </el-col>
          <el-col v-if="!editing" :span="12">
            <el-form-item label="初始密码" prop="password">
              <el-input v-model="form.password" type="password" show-password placeholder="至少 8 位，含字母与数字" />
            </el-form-item>
          </el-col>
        </el-row>
        <div class="form-section">状态</div>
        <el-form-item label="启用状态">
          <el-switch v-model="form.status" />
          <span class="form-tip">停用后该账号无法登录后台，历史操作日志保留。</span>
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

// 角色/门店下拉选项：真实 API 加载（原 mock 导入被误删后重建，失败不影响主流程）
const roles = ref([])
const stores = ref([])
async function loadOptions() {
  try {
    const [r, s] = await Promise.all([
      listResource('roles', { page: 1, per_page: 200 }),
      listResource('stores', { page: 1, per_page: 200 })
    ])
    roles.value = r.list || []
    stores.value = s.list || []
  } catch (e) {
    console.warn('[admin-account-options]', e && e.message)
  }
}

const loading = computed(() => crud.state.loading)
const query = reactive({ keyword: '', role: '' })
const page = ref(1)
const pageSize = 10
const crud = useCrud('admins', { fieldMap: {"role":"role_id","store":"store_id","lastLogin":"last_login_at"}, keywordField: ["name","account","phone"], mapPayload: (d) => {
      const r = roles.value.find((x) => x.name === d.role || String(x.id) === String(d.role))
      const s = stores.value.find((x) => x.name === d.store || String(x.id) === String(d.store))
      return { ...d, role_id: r ? r.id : d.role, store_id: s ? s.id : d.store }
    }, mapRow: (r) => {
      const role = roles.value.find((x) => String(x.id) === String(r.role_id))
      const store = stores.value.find((x) => String(x.id) === String(r.store_id))
      return { ...r, role: role ? role.name : r.role_id, store: store ? store.name : r.store_id }
    } })
const total = computed(() => crud.state.total)
async function reload() {
  await crud.load({ ...query, page: page.value, per_page: pageSize })
}
const formVisible = ref(false)
const editing = ref(false)
const formRef = ref()

const emptyForm = { id: '', name: '', account: '', phone: '', role: '', store: '总店', password: '', status: true }
const form = reactive({ ...emptyForm })

const rules = {
  name: [{ required: true, message: '请输入姓名', trigger: 'blur' }],
  account: [
    { required: true, message: '请输入登录账号', trigger: 'blur' },
    { pattern: /^[A-Za-z0-9_]{4,20}$/, message: '4-20 位字母、数字或下划线', trigger: 'blur' }
  ],
  phone: [{ pattern: /^1\d{10}$/, message: '请输入 11 位手机号', trigger: 'blur' }],
  role: [{ required: true, message: '请选择所属角色', trigger: 'change' }],
  password: [
    { required: true, message: '请设置初始密码', trigger: 'blur' },
    { min: 8, message: '密码至少 8 位', trigger: 'blur' }
  ]
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

function handleReset(row) {
  ElMessageBox.confirm(`确认重置「${row.name}」的登录密码吗？重置后需通知其修改。`, '重置密码', {
    type: 'warning', confirmButtonText: '确认重置', cancelButtonText: '取消'
  }).then(() => ElMessage.success('密码已重置为初始密码')).catch(() => {})
}

function handleDelete(row) {
  ElMessageBox.confirm(`确认删除账号「${row.name}」吗？删除后不可恢复。`, '删除账号', {
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
