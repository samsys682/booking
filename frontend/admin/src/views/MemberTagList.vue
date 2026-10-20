<template>
  <div class="content">
    <div class="content-card content-card--list">
      <div class="action-bar">
        <h2 class="page-title">会员标签</h2>
        <div class="action-bar__left">
          <el-input v-model="query.keyword" placeholder="标签名称" clearable style="width: 200px"
            @clear="handleSearch" @keyup.enter="handleSearch">
            <template #prefix><el-icon><Search /></el-icon></template>
          </el-input>
          <el-select v-model="query.kind" placeholder="标签类型" clearable style="width: 130px" @change="handleSearch">
            <el-option label="RFM 系统标签" value="RFM" />
            <el-option label="自定义标签" value="自定义" />
          </el-select>
          <el-button :icon="Search" @click="handleSearch">查询</el-button>
        </div>
        <div class="action-bar__right">
          <el-button type="primary" :icon="Plus" @click="openCreate">新建标签</el-button>
        </div>
      </div>
      <div class="table-wrap" v-loading="loading">
        <el-table :data="pagedData" empty-text="暂无标签">
          <el-table-column label="标签" min-width="150">
            <template #default="{ row }">
              <el-tag size="small" :type="row.color === 'primary' ? 'primary' : row.color">{{ row.name }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="类型" width="120">
            <template #default="{ row }">
              <el-tag size="small" :type="row.kind === 'RFM' ? 'warning' : 'info'" effect="plain">{{ row.kind }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column prop="rule" label="打标规则" min-width="220" show-overflow-tooltip />
          <el-table-column prop="members" label="会员数" width="100" />
          <el-table-column label="状态" width="90">
            <template #default="{ row }">
              <el-switch v-model="row.status" @change="handleToggle(row)" />
            </template>
          </el-table-column>
          <el-table-column label="操作" width="140" fixed="right">
            <template #default="{ row }">
              <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
              <el-button link type="danger" size="small" :disabled="row.kind === 'RFM'" @click="handleDelete(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
        <el-empty v-if="!loading && filteredData.length === 0" description="暂无标签">
          <el-button type="primary" @click="openCreate">新建标签</el-button>
        </el-empty>
      </div>
      <div class="pagination-bar">
        <el-pagination v-model:current-page="page" :page-size="pageSize"
          :total="total" layout="total, prev, pager, next" background size="small" />
      </div>
    </div>

    <el-dialog v-model="formVisible" :title="editing ? '编辑标签' : '新建标签'" width="620px" class="list-dialog">
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top">
        <div class="form-section">标签信息</div>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="标签名称" prop="name">
              <el-input v-model="form.name" placeholder="如：养宠家庭" maxlength="12" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="标签类型" prop="kind">
              <el-radio-group v-model="form.kind" :disabled="editing && form.kind === 'RFM'">
                <el-radio-button label="RFM">RFM</el-radio-button>
                <el-radio-button label="自定义">自定义</el-radio-button>
              </el-radio-group>
            </el-form-item>
          </el-col>
        </el-row>
        <el-form-item label="打标规则" prop="rule">
          <el-input v-model="form.rule" placeholder="RFM 标签填写条件表达式；自定义标签填「人工标注」" />
          <span class="form-tip">RFM 标签由系统按规则自动打标（如「R≥4 且 F≥4」），自定义标签由商家在会员档案里手动维护。</span>
        </el-form-item>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="标签颜色">
              <el-select v-model="form.color" style="width: 100%">
                <el-option label="主题蓝" value="primary" />
                <el-option label="成功绿" value="success" />
                <el-option label="警告橙" value="warning" />
                <el-option label="危险红" value="danger" />
                <el-option label="中性灰" value="info" />
              </el-select>
            </el-form-item>
          </el-col>
        </el-row>
        <div class="form-section">状态</div>
        <el-form-item label="启用状态">
          <el-switch v-model="form.status" />
          <span class="form-tip">停用后不再自动打标，已有会员的标签保留。</span>
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
const query = reactive({ keyword: '', kind: '' })
const page = ref(1)
const pageSize = 10
const crud = useCrud('member-tags', { fieldMap: {}, keywordField: ["name"] })
const total = computed(() => crud.state.total)
async function reload() {
  await crud.load({ ...query, page: page.value, per_page: pageSize })
}
const formVisible = ref(false)
const editing = ref(false)
const formRef = ref()

const emptyForm = { id: '', name: '', kind: '自定义', rule: '人工标注', members: 0, color: 'primary', status: true }
const form = reactive({ ...emptyForm })

const rules = {
  name: [{ required: true, message: '请输入标签名称', trigger: 'blur' }],
  rule: [{ required: true, message: '请输入打标规则', trigger: 'blur' }]
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
  ElMessageBox.confirm(`确认删除标签「${row.name}」吗？`, '删除标签', {
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
