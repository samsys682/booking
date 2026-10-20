<template>
  <div class="content">
    <div class="content-card content-card--list">
      <div class="action-bar">
        <h2 class="page-title">积分商城</h2>
        <div class="action-bar__left">
          <el-input v-model="query.keyword" placeholder="礼品名称" clearable style="width: 200px"
            @clear="handleSearch" @keyup.enter="handleSearch">
            <template #prefix><el-icon><Search /></el-icon></template>
          </el-input>
          <el-button :icon="Search" @click="handleSearch">查询</el-button>
        </div>
        <div class="action-bar__right">
          <!-- 兑换动作走 my/gifts/{id}/redeem（会员端），本页为商家侧礼品资产维护 -->
          <el-button type="primary" :icon="Plus" @click="openCreate">上架礼品</el-button>
        </div>
      </div>
      <div class="table-wrap" v-loading="loading">
        <el-table :data="pagedData" empty-text="暂无礼品，点击右上角上架">
          <el-table-column prop="id" label="ID" width="70" />
          <el-table-column label="礼品" min-width="220">
            <template #default="{ row }">
              <div class="gift-cell">
                <el-image v-if="row.image" :src="row.image" fit="cover" class="gift-cell__img"
                  :preview-src-list="[row.image]" preview-teleported />
                <span>{{ row.name }}</span>
              </div>
            </template>
          </el-table-column>
          <el-table-column prop="points" label="所需积分" width="100" />
          <el-table-column prop="stock" label="库存" width="80" />
          <el-table-column prop="exchanged" label="已兑换" width="80" />
          <el-table-column prop="detail" label="礼品详情" min-width="180" show-overflow-tooltip>
            <template #default="{ row }">{{ row.detail || '-' }}</template>
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
        <el-empty v-if="!loading && filteredData.length === 0" description="暂无礼品">
          <el-button type="primary" @click="openCreate">上架礼品</el-button>
        </el-empty>
      </div>
      <div class="pagination-bar">
        <el-pagination v-model:current-page="page" :page-size="pageSize"
          :total="total" layout="total, prev, pager, next" background size="small" />
      </div>
    </div>

    <el-dialog v-model="formVisible" :title="editing ? '编辑礼品' : '上架礼品'" width="520px" class="list-dialog">
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top">
        <el-form-item label="礼品名称" prop="name">
          <el-input v-model="form.name" placeholder="如：保温水杯" maxlength="30" />
        </el-form-item>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="所需积分" prop="points">
              <el-input-number v-model="form.points" :min="1" :max="999999" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="库存" prop="stock">
              <el-input-number v-model="form.stock" :min="0" :max="999999" style="width: 100%" />
            </el-form-item>
          </el-col>
        </el-row>
        <el-form-item label="图片地址">
          <el-input v-model="form.image" placeholder="https://…（可留空）" maxlength="255" />
        </el-form-item>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="每人限兑">
              <el-input-number v-model="form.limitPerUser" :min="0" :max="99" style="width: 100%" />
              <span class="form-tip">0 = 不限次数</span>
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="兑换截止">
              <el-date-picker v-model="form.endAt" type="datetime" value-format="YYYY-MM-DD HH:mm:ss"
                placeholder="长期有效可留空" style="width: 100%" />
            </el-form-item>
          </el-col>
        </el-row>
        <el-form-item label="礼品详情">
          <el-input v-model="form.detail" type="textarea" :rows="3" placeholder="兑换说明、规格等" maxlength="200" />
        </el-form-item>
        <el-form-item label="上架状态">
          <el-switch v-model="form.status" />
          <span class="form-tip">下架后会员端不再展示；已兑换记录不受影响。</span>
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
const crud = useCrud('gifts', { keywordField: ['name'] })
const total = computed(() => crud.state.total)
async function reload() {
  await crud.load({ ...query, page: page.value, per_page: pageSize })
}
const formVisible = ref(false)
const editing = ref(false)
const formRef = ref()

const emptyForm = {
  id: '', name: '', points: 100, stock: 10, image: '', detail: '', exchanged: 0,
  limitPerUser: 0, endAt: '', status: true
}
const form = reactive({ ...emptyForm })

const rules = {
  name: [{ required: true, message: '请输入礼品名称', trigger: 'blur' }],
  points: [{ required: true, message: '请填写所需积分', trigger: 'change' }],
  stock: [{ required: true, message: '请填写库存', trigger: 'change' }]
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
  form.limitPerUser = Number(row.limit_per_user) || 0
  form.endAt = row.end_at || ''
  formVisible.value = true
  formRef.value?.clearValidate()
}

async function handleSave() {
  const valid = await formRef.value.validate().catch(() => false)
  if (!valid) return
  if (!form.name || form.points <= 0) {
    ElMessage.warning('请填写礼品名称与所需积分')
    return
  }
  await crud.save(editing.value ? form.id : null, {
    name: form.name,
    points: form.points,
    stock: form.stock,
    image: form.image,
    detail: form.detail,
    exchanged: form.exchanged || 0,
    limit_per_user: form.limitPerUser,
    end_at: form.endAt || '',
    status: form.status ? 1 : 0
  })
  ElMessage.success(editing.value ? '保存成功' : '上架成功')
  formVisible.value = false
  reload()
}

async function handleToggle(row) {
  await crud.patch(row.id, { status: row.status ? 1 : 0 })
  ElMessage.success(`「${row.name}」已${row.status ? '上架' : '下架'}`)
  reload()
}

function handleDelete(row) {
  ElMessageBox.confirm(`确认删除礼品「${row.name}」吗？已兑换记录不受影响。`, '删除礼品', {
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
.gift-cell {
  display: flex;
  align-items: center;
  gap: 10px;
}
.gift-cell__img {
  width: 36px;
  height: 36px;
  border-radius: 6px;
  flex-shrink: 0;
  background: var(--bg-secondary);
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
