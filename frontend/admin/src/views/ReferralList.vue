<template>
  <div class="content">
    <div class="content-card content-card--list">
      <div class="action-bar">
        <h2 class="page-title">推荐有礼</h2>
        <div class="action-bar__left">
          <el-input v-model="query.keyword" placeholder="活动名称" clearable style="width: 200px"
            @clear="handleSearch" @keyup.enter="handleSearch">
            <template #prefix><el-icon><Search /></el-icon></template>
          </el-input>
          <el-button :icon="Search" @click="handleSearch">查询</el-button>
        </div>
        <div class="action-bar__right">
          <el-button type="primary" :icon="Plus" @click="openCreate">新建活动</el-button>
        </div>
      </div>
      <div class="table-wrap" v-loading="loading">
        <el-table :data="pagedData" empty-text="暂无推荐活动">
          <el-table-column prop="name" label="活动名称" min-width="150" />
          <el-table-column label="邀请人奖励" min-width="140">
            <template #default="{ row }">{{ row.inviterReward }}</template>
          </el-table-column>
          <el-table-column label="被邀请人奖励" min-width="140">
            <template #default="{ row }">{{ row.inviteeReward }}</template>
          </el-table-column>
          <el-table-column prop="condition" label="奖励条件" min-width="180" show-overflow-tooltip />
          <el-table-column prop="issued" label="已发放" width="90" />
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
        <el-empty v-if="!loading && filteredData.length === 0" description="暂无推荐活动">
          <el-button type="primary" @click="openCreate">新建活动</el-button>
        </el-empty>
      </div>
      <div class="pagination-bar">
        <el-pagination v-model:current-page="page" :page-size="pageSize"
          :total="total" layout="total, prev, pager, next" background size="small" />
      </div>
    </div>

    <el-dialog v-model="formVisible" :title="editing ? '编辑活动' : '新建活动'" width="640px" class="list-dialog">
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top">
        <div class="form-section">活动信息</div>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="活动名称" prop="name">
              <el-input v-model="form.name" placeholder="如：老客推荐新客" maxlength="20" />
            </el-form-item>
          </el-col>
        </el-row>
        <div class="form-section">奖励设置</div>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="邀请人奖励（积分）" prop="inviterReward">
              <el-input-number v-model="form.inviterReward" :min="0" :max="999999" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="被邀请人奖励（积分）" prop="inviteeReward">
              <el-input-number v-model="form.inviteeReward" :min="0" :max="999999" style="width: 100%" />
            </el-form-item>
          </el-col>
        </el-row>
        <el-form-item label="奖励条件" prop="condition">
          <el-input v-model="form.condition" placeholder="如：新客完成首单后发放" maxlength="50" />
          <span class="form-tip">奖励以积分发放（服务端按「被邀请人首单完成」自动入账）；发放记录可在操作日志中追溯。</span>
        </el-form-item>
        <div class="form-section">状态</div>
        <el-form-item label="启用状态">
          <el-switch v-model="form.status" />
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
const crud = useCrud('referral-rules', { fieldMap: {}, keywordField: ["name"] })
const total = computed(() => crud.state.total)
async function reload() {
  await crud.load({ ...query, page: page.value, per_page: pageSize })
}
const formVisible = ref(false)
const editing = ref(false)
const formRef = ref()

const emptyForm = { id: '', name: '', inviterReward: 0, inviteeReward: 0, condition: '', issued: 0, status: true }
const form = reactive({ ...emptyForm })

const rules = {
  name: [{ required: true, message: '请输入活动名称', trigger: 'blur' }],
  inviterReward: [{ required: true, message: '请输入邀请人奖励', trigger: 'blur' }],
  inviteeReward: [{ required: true, message: '请输入被邀请人奖励', trigger: 'blur' }],
  condition: [{ required: true, message: '请输入奖励条件', trigger: 'blur' }]
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
  ElMessageBox.confirm(`确认删除活动「${row.name}」吗？已发放奖励不回收。`, '删除活动', {
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
