<template>
  <div class="content">
    <div class="content-card content-card--list">
      <div class="action-bar">
        <h2 class="page-title">会员档案</h2>
        <div class="action-bar__left">
          <el-input v-model="query.keyword" placeholder="姓名 / 手机号" clearable style="width: 200px"
            @clear="handleSearch" @keyup.enter="handleSearch">
            <template #prefix><el-icon><Search /></el-icon></template>
          </el-input>
          <el-select v-model="query.rfmTag" placeholder="RFM 标签" clearable style="width: 150px" @change="handleSearch">
            <el-option v-for="t in rfmTags" :key="t" :label="t" :value="t" />
          </el-select>
          <el-button :icon="Search" @click="handleSearch">查询</el-button>
        </div>
        <div class="action-bar__right">
          <ImportExportBar resource="members" label="会员" @imported="reload" />
          <el-button type="primary" :icon="Plus" @click="openCreate">新建档案</el-button>
        </div>
      </div>
      <div class="table-wrap" v-loading="loading">
        <el-table :data="pagedData" empty-text="暂无会员">
          <el-table-column prop="name" label="姓名" min-width="100" />
          <el-table-column prop="phone" label="手机号" min-width="120" />
          <el-table-column prop="level" label="等级" width="110">
            <template #default="{ row }">
              <el-tag size="small" :type="levelType(row.level)">{{ row.level }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="RFM" min-width="160">
            <template #default="{ row }">
              <el-tooltip :content="`R=${row.rScore} F=${row.fScore} M=${row.mScore}`" placement="top">
                <el-tag size="small" effect="plain">{{ row.rfmTag }}</el-tag>
              </el-tooltip>
            </template>
          </el-table-column>
          <el-table-column label="累计消费" width="110">
            <template #default="{ row }">¥{{ row.totalAmount }}</template>
          </el-table-column>
          <el-table-column prop="totalOrders" label="订单数" width="90" />
          <el-table-column prop="lastOrder" label="最近消费" width="110" />
          <el-table-column label="标签" min-width="140">
            <template #default="{ row }">
              <el-tag v-for="t in memberTagsOf(row)" :key="t" size="small" effect="plain" class="member-tag">{{ t }}</el-tag>
              <span v-if="!memberTagsOf(row).length" class="tiny muted">-</span>
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
        <el-empty v-if="!loading && filteredData.length === 0" description="暂无会员">
          <el-button type="primary" @click="openCreate">新建档案</el-button>
        </el-empty>
      </div>
      <div class="pagination-bar">
        <el-pagination v-model:current-page="page" :page-size="pageSize"
          :total="total" layout="total, prev, pager, next" background size="small" />
      </div>
    </div>

    <el-dialog v-model="formVisible" :title="editing ? '编辑档案' : '新建档案'" width="660px" class="list-dialog">
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top">
        <div class="form-section">基本信息</div>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="姓名" prop="name">
              <el-input v-model="form.name" maxlength="20" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="手机号" prop="phone">
              <el-input v-model="form.phone" maxlength="11" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="会员等级">
              <el-select v-model="form.level" style="width: 100%">
                <el-option v-for="lv in levelOptions" :key="lv.name" :label="lv.name" :value="lv.name" />
              </el-select>
              <span v-if="levelRules.length" class="form-tip">等级规则（累计消费门槛）：{{ levelRulesHint }}</span>
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="所属门店">
              <el-select v-model="form.store" style="width: 100%">
                <el-option v-for="s in stores" :key="s.id" :label="s.name" :value="s.name" />
              </el-select>
            </el-form-item>
          </el-col>
        </el-row>
        <div class="form-section">RFM 打分</div>
        <el-row :gutter="16">
          <el-col :span="8">
            <el-form-item label="R 最近消费">
              <el-input-number v-model="form.rScore" :min="1" :max="5" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="F 消费频次">
              <el-input-number v-model="form.fScore" :min="1" :max="5" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="M 消费金额">
              <el-input-number v-model="form.mScore" :min="1" :max="5" style="width: 100%" />
            </el-form-item>
          </el-col>
        </el-row>
        <div class="form-section">状态</div>
        <el-form-item label="会员标签">
          <el-select v-model="form.tagNames" multiple placeholder="选择标签（可与会员标签模块互通）" style="width: 100%">
            <el-option v-for="t in tags" :key="t.id" :label="t.name" :value="t.name" />
          </el-select>
          <span class="form-tip">来源「会员标签」模块的启用标签；保存后自动同步打标关联。</span>
        </el-form-item>
        <el-form-item label="RFM 标签">
          <el-select v-model="form.rfmTag" style="width: 100%">
            <el-option v-for="t in rfmTags" :key="t" :label="t" :value="t" />
          </el-select>
          <span class="form-tip">按 R/F/M 三维打分自动归类，可人工修正；RFM 归类与自定义标签相互独立。</span>
        </el-form-item>
        <el-form-item label="启用状态">
          <el-switch v-model="form.status" />
          <span class="form-tip">停用后该会员无法登录会员端，储值余额与积分保留。</span>
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
import { list as listResource, create as listCreate, remove as listRemove } from '../api/resource'
import ImportExportBar from '../components/ImportExportBar.vue'

// 门店下拉/会员标签/打标关联/等级规则：真实 API 加载
const stores = ref([])
const tags = ref([]) // member-tags 启用标签
const tagRelMap = ref({}) // member_id -> [tag_id]
const relRows = ref([]) // member-tag-rels 原始行（syncTagRels 删除用）
const tagNameById = ref({})
const levelRules = ref([]) // setting level_rules（会员等级规则）
async function loadOptions() {
  try {
    const [res, resT, resR, resS] = await Promise.all([
      listResource('stores', { page: 1, per_page: 200 }),
      listResource('member-tags', { page: 1, per_page: 200, status: 1 }),
      listResource('member-tag-rels', { page: 1, per_page: 2000 }),
      listResource('settings', { page: 1, per_page: 200, key: 'level_rules' }).catch(() => ({ list: [] }))
    ])
    stores.value = res.list || []
    tags.value = (resT.list || []).filter((t) => t.status)
    tagNameById.value = Object.fromEntries(tags.value.map((t) => [String(t.id), t.name]))
    const relMap = {}
    const rows = resR.list || []
    relRows.value = rows
    for (const rel of rows) {
      const k = String(rel.memberId ?? rel.member_id)
      ;(relMap[k] = relMap[k] || []).push(String(rel.tagId ?? rel.tag_id))
    }
    tagRelMap.value = relMap
    const ruleRow = (resS.list || []).find((x) => x.key === 'level_rules')
    if (ruleRow) {
      try { levelRules.value = JSON.parse(ruleRow.value) } catch { /* 规则 JSON 损坏时回退硬编码 */ }
    }
  } catch (e) {
    console.warn('[member-profile-options]', e && e.message)
  }
}

const levelOptions = computed(() =>
  levelRules.value.length ? levelRules.value.map((lv) => ({ name: lv.name })) : [{ name: '普通会员' }, { name: '白银会员' }, { name: '黄金会员' }]
)
const levelRulesHint = computed(() =>
  levelRules.value.map((lv) => `${lv.name} ≥¥${lv.threshold}`).join('；')
)
// 会员标签列：rels member_id -> tag_id -> tag 名
function memberTagsOf(row) {
  return (tagRelMap.value[String(row.id)] || [])
    .map((id) => tagNameById.value[String(id)])
    .filter(Boolean)
}

const loading = computed(() => crud.state.loading)
const query = reactive({ keyword: '', rfmTag: '' })
const page = ref(1)
const pageSize = 10
const crud = useCrud('members', { fieldMap: {"store":"store_id","lastOrder":"last_order_at"}, keywordField: ["name","phone"], mapPayload: (d) => {
      const s = stores.value.find((x) => x.name === d.store || String(x.id) === String(d.store))
      return { ...d, store_id: s ? s.id : d.store }
    }, mapRow: (r) => {
      const store = stores.value.find((x) => String(x.id) === String(r.store_id))
      return { ...r, store: store ? store.name : r.store_id }
    } })
const total = computed(() => crud.state.total)
async function reload() {
  await crud.load({ ...query, page: page.value, per_page: pageSize })
}
const formVisible = ref(false)
const editing = ref(false)
const formRef = ref()

const rfmTags = ['重要价值客户', '重要发展客户', '重要保持客户', '一般价值客户', '一般保持客户', '一般发展客户', '流失客户']

const emptyForm = {
  id: '', name: '', phone: '', level: '普通会员', rScore: 3, fScore: 3, mScore: 3,
  rfmTag: '一般价值客户', totalAmount: 0, totalOrders: 0, lastOrder: '-', joinAt: '', store: '总店', status: true,
  tagNames: []
}
const form = reactive({ ...emptyForm })

const rules = {
  name: [{ required: true, message: '请输入姓名', trigger: 'blur' }],
  phone: [{ required: true, pattern: /^1\d{10}$/, message: '请输入 11 位手机号', trigger: 'blur' }]
}

function levelType(level) {
  return level === '黄金会员' ? 'warning' : level === '白银会员' ? 'info' : 'primary'
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
  Object.assign(form, row, { tagNames: memberTagsOf(row) })
  formVisible.value = true
  formRef.value?.clearValidate()
}

// 同步打标关联（member-tag-rels）：按保存时的 tagNames 与现有 rels 做 diff
async function syncTagRels(memberId, tagNames) {
  const nameToId = Object.fromEntries(tags.value.map((t) => [t.name, String(t.id)]))
  const wantIds = tagNames.map((n) => nameToId[n]).filter(Boolean)
  const haveIds = tagRelMap.value[String(memberId)] || []
  const toAdd = wantIds.filter((id) => !haveIds.includes(id))
  const toDel = haveIds.filter((id) => !wantIds.includes(id))
  for (const tagId of toAdd) {
    await listCreate('member-tag-rels', { member_id: memberId, tag_id: tagId })
  }
  for (const rel of relRows.value) {
    if (toDel.includes(String(rel.tagId ?? rel.tag_id)) && String(rel.memberId ?? rel.member_id) === String(memberId)) {
      await listRemove('member-tag-rels', rel.id)
    }
  }
  // 更新本地缓存，避免整页重拉
  tagRelMap.value = { ...tagRelMap.value, [String(memberId)]: wantIds }
}

async function handleSave() {
  const valid = await formRef.value.validate().catch(() => false)
  if (!valid) return
  const payload = { ...form }
  delete payload.tagNames
  await crud.save(editing.value ? form.id : null, payload)
  if (editing.value && form.id) {
    await syncTagRels(form.id, form.tagNames || [])
  }
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
  ElMessageBox.confirm(`确认删除会员「${row.name}」的档案吗？其订单与流水将保留为匿名记录。`, '删除档案', {
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
.member-tag {
  margin: 2px 4px 2px 0;
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
