<template>
  <div class="content">
    <div class="content-card content-card--list">
      <div class="action-bar">
        <h2 class="page-title">服务协议</h2>
        <div class="action-bar__left">
          <el-input v-model="query.keyword" placeholder="协议名称" clearable style="width: 200px"
            @clear="handleSearch" @keyup.enter="handleSearch">
            <template #prefix><el-icon><Search /></el-icon></template>
          </el-input>
          <el-button :icon="Search" @click="handleSearch">查询</el-button>
        </div>
        <div class="action-bar__right">
          <el-button :icon="Document" @click="openSigns">签署记录</el-button>
          <el-button type="primary" :icon="Plus" @click="openCreate">新建协议</el-button>
        </div>
      </div>
      <div class="table-wrap" v-loading="loading">
        <el-table :data="rows" empty-text="暂无协议">
          <el-table-column prop="name" label="协议名称" min-width="180" show-overflow-tooltip />
          <el-table-column label="适用范围" width="110">
            <template #default="{ row }">
              <el-tag size="small" :type="row.scope === '全部服务' ? 'success' : 'warning'">{{ row.scope }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="适用服务" min-width="180" show-overflow-tooltip>
            <template #default="{ row }">{{ scopeText(row) }}</template>
          </el-table-column>
          <el-table-column prop="version" label="版本号" width="80" />
          <el-table-column label="状态" width="90">
            <template #default="{ row }">
              <el-switch v-model="row.status" @change="handleToggle(row)" />
            </template>
          </el-table-column>
          <el-table-column prop="updatedAt" label="更新时间" width="160" />
          <el-table-column label="操作" width="140" fixed="right">
            <template #default="{ row }">
              <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
              <el-button link type="danger" size="small" @click="handleDelete(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
      </div>
      <div class="pagination-bar">
        <el-pagination v-model:current-page="page" :page-size="pageSize"
          :total="total" layout="total, prev, pager, next" background size="small" />
      </div>
    </div>

    <el-dialog v-model="formVisible" :title="editing ? '编辑协议' : '新建协议'" width="760px" class="list-dialog">
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top">
        <div class="form-section">基本信息</div>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="协议名称" prop="name">
              <el-input v-model="form.name" placeholder="如：上门服务知情同意书" maxlength="50" />
            </el-form-item>
          </el-col>
          <el-col :span="6">
            <el-form-item label="版本号" prop="version">
              <el-input-number v-model="form.version" :min="1" :max="9999" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="6">
            <el-form-item label="启用状态">
              <el-switch v-model="form.status" />
            </el-form-item>
          </el-col>
        </el-row>
        <el-form-item label="适用范围">
          <el-radio-group v-model="form.scope">
            <el-radio-button label="全部服务">全部服务</el-radio-button>
            <el-radio-button label="指定服务">指定服务</el-radio-button>
          </el-radio-group>
          <span class="form-tip">指定服务时，仅顾客预约所选服务时要求签署；修改协议内容后请务必递增版本号，会员需重新签署。</span>
        </el-form-item>
        <el-form-item v-if="form.scope === '指定服务'" label="适用服务" prop="serviceIds">
          <el-select v-model="form.serviceIds" multiple filterable placeholder="选择需要签署协议的服务" style="width: 100%">
            <el-option v-for="s in services" :key="s.id" :label="s.name" :value="s.id" />
          </el-select>
        </el-form-item>
        <div class="form-section">协议内容</div>
        <el-form-item prop="content">
          <RichEditor v-model="form.content" placeholder="协议正文（知情同意、安全须知、免责条款等，支持图文混排）" />
        </el-form-item>
      </el-form>
      <template #footer>
        <div class="modal-footer">
          <el-button @click="formVisible = false">取消</el-button>
          <el-button type="primary" @click="handleSave">保存</el-button>
        </div>
      </template>
    </el-dialog>

    <el-dialog v-model="signsVisible" title="协议签署记录" width="900px" class="list-dialog">
      <div class="signs-bar">
        <el-input v-model="signsQuery.keyword" placeholder="流水号 / 协议名 / 会员 / 手机号 / 订单号" clearable
          style="width: 300px" @clear="loadSigns" @keyup.enter="loadSigns">
          <template #prefix><el-icon><Search /></el-icon></template>
        </el-input>
        <el-button :icon="Search" @click="loadSigns">查询</el-button>
      </div>
      <el-table :data="signs" v-loading="signsLoading" empty-text="暂无签署记录" max-height="440">
        <el-table-column prop="sign_no" label="流水号" width="180" show-overflow-tooltip />
        <el-table-column prop="agreement_name" label="协议" min-width="150" show-overflow-tooltip />
        <el-table-column prop="member" label="会员" width="100" />
        <el-table-column prop="phone" label="手机号" width="120" />
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag size="small" :type="Number(row.used) === 1 ? 'success' : 'info'">
              {{ Number(row.used) === 1 ? '已消费' : '未使用' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="关联订单" min-width="140" show-overflow-tooltip>
          <template #default="{ row }">{{ row.order_no || '-' }}</template>
        </el-table-column>
        <el-table-column prop="at" label="签署时间" width="160" />
      </el-table>
      <div class="pagination-bar">
        <el-pagination v-model:current-page="signsPage" :page-size="signsPageSize" :total="signsTotal"
          layout="total, prev, pager, next" background size="small" @current-change="loadSigns" />
      </div>
    </el-dialog>
  </div>
</template>

<script setup>
import { computed, reactive, ref, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Search, Plus, Document } from '@element-plus/icons-vue'
import { useCrud } from '../composables/useCrud'
import { list as listResource } from '../api/resource'
import RichEditor from '../components/RichEditor.vue'

const loading = computed(() => crud.state.loading)
const query = reactive({ keyword: '' })
const page = ref(1)
const pageSize = 10
const crud = useCrud('service-agreements', {
  keywordField: 'name',
  // serviceIds：后端 service_ids 为 JSON 字符串，读时解数组（el-select 多选）、写时回 JSON
  mapRow: (row) => {
    if (typeof row.serviceIds === 'string' && row.serviceIds !== '') {
      try { row.serviceIds = JSON.parse(row.serviceIds) } catch (e) { row.serviceIds = [] }
    }
    if (!Array.isArray(row.serviceIds)) row.serviceIds = []
    return row
  },
  mapPayload: (data) => {
    if (Array.isArray(data.serviceIds)) data.serviceIds = JSON.stringify(data.serviceIds)
    return data
  }
})
const rows = computed(() => crud.state.rows)
const total = computed(() => crud.state.total)
async function reload() {
  await crud.load({ ...query, page: page.value, per_page: pageSize })
}
const formVisible = ref(false)
const editing = ref(false)
const formRef = ref()

// 服务下拉选项（指定服务多选 + id→名称映射）
const services = ref([])
async function loadOptions() {
  try {
    const res = await listResource('services', { page: 1, per_page: 500 })
    services.value = res.list || []
  } catch (e) {
    console.warn('[agreement-service-options]', e && e.message)
  }
}
function scopeText(row) {
  if (row.scope === '全部服务') return '全部服务'
  const ids = Array.isArray(row.serviceIds) ? row.serviceIds : []
  return ids.map((id) => services.value.find((s) => Number(s.id) === Number(id))?.name || ('#' + id)).join('、') || '未选择'
}

const emptyForm = { id: '', name: '', scope: '全部服务', serviceIds: [], version: 1, content: '', status: true }
const form = reactive({ ...emptyForm })

const rules = {
  name: [{ required: true, message: '请输入协议名称', trigger: 'blur' }],
  content: [{ required: true, message: '请填写协议内容', trigger: 'blur' }],
  serviceIds: [{
    validator: (_r, value, cb) => {
      if (form.scope === '指定服务' && (!value || value.length === 0)) cb(new Error('请选择适用服务'))
      else cb()
    }, trigger: 'change'
  }]
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
  Object.assign(form, { ...emptyForm, ...row })
  formVisible.value = true
  formRef.value?.clearValidate()
}

async function handleSave() {
  const valid = await formRef.value.validate().catch(() => false)
  if (!valid) return
  await crud.save(editing.value ? form.id : null, { ...form, version: Number(form.version) || 1 })
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
  ElMessageBox.confirm(`确认删除协议「${row.name}」吗？已产生的签署留痕不受影响。`, '删除协议', {
    type: 'warning', confirmButtonText: '确认删除', cancelButtonText: '取消'
  }).then(async () => {
    await crud.remove(row.id)
    ElMessage.success('删除成功')
    reload()
  }).catch(() => {})
}

// ---- 签署记录（agreement-signs 只读资源；keyword 白名单：sign_no/agreement_name/member/phone/order_no） ----
const signsVisible = ref(false)
const signsLoading = ref(false)
const signs = ref([])
const signsTotal = ref(0)
const signsPage = ref(1)
const signsPageSize = 10
const signsQuery = reactive({ keyword: '' })

async function loadSigns() {
  signsLoading.value = true
  try {
    const res = await listResource('agreement-signs', {
      keyword: signsQuery.keyword, page: signsPage.value, per_page: signsPageSize
    })
    signs.value = res.list || []
    signsTotal.value = Number(res.total || 0)
  } catch (e) {
    console.warn('[agreement-signs]', e && e.message)
  } finally {
    signsLoading.value = false
  }
}

function openSigns() {
  signsPage.value = 1
  signsVisible.value = true
  loadSigns()
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
.signs-bar {
  display: flex;
  gap: var(--space-sm);
  margin-bottom: var(--space-sm);
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
