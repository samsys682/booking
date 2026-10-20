<template>
  <div class="content">
    <div class="content-card content-card--list">
      <div class="action-bar">
        <h2 class="page-title">角色权限</h2>
        <div class="action-bar__left">
          <el-input v-model="query.keyword" placeholder="角色名称 / 权限码" clearable style="width: 200px"
            @clear="handleSearch" @keyup.enter="handleSearch">
            <template #prefix><el-icon><Search /></el-icon></template>
          </el-input>
          <el-button :icon="Search" @click="handleSearch">查询</el-button>
        </div>
        <div class="action-bar__right">
          <el-button type="primary" :icon="Plus" @click="openCreate">新建角色</el-button>
        </div>
      </div>
      <div class="table-wrap" v-loading="loading">
        <el-table :data="pagedData" empty-text="暂无角色">
          <el-table-column prop="name" label="角色名称" min-width="130" />
          <el-table-column prop="code" label="权限码" min-width="120" />
          <el-table-column prop="members" label="账号数" width="90" />
          <el-table-column label="权限范围" min-width="240">
            <template #default="{ row }">
              <template v-if="row.perms.includes('*')">
                <el-tag size="small" type="danger">全部权限</el-tag>
              </template>
              <template v-else>
                <el-tag v-for="p in row.perms.slice(0, 3)" :key="p" size="small" class="perm-tag">{{ p }}</el-tag>
                <el-tag v-if="row.perms.length > 3" size="small" type="info">+{{ row.perms.length - 3 }}</el-tag>
              </template>
            </template>
          </el-table-column>
          <el-table-column prop="desc" label="备注" min-width="180" show-overflow-tooltip />
          <el-table-column label="状态" width="90">
            <template #default="{ row }">
              <el-switch v-model="row.status" @change="handleToggle(row)" />
            </template>
          </el-table-column>
          <el-table-column label="操作" width="140" fixed="right">
            <template #default="{ row }">
              <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
              <el-button link type="danger" size="small" :disabled="row.code === 'super'" @click="handleDelete(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
        <el-empty v-if="!loading && filteredData.length === 0" description="暂无角色">
          <el-button type="primary" @click="openCreate">新建角色</el-button>
        </el-empty>
      </div>
      <div class="pagination-bar">
        <el-pagination v-model:current-page="page" :page-size="pageSize"
          :total="filteredData.length" layout="total, prev, pager, next" background size="small" />
      </div>
    </div>

    <el-dialog v-model="formVisible" :title="editing ? '编辑角色' : '新建角色'" width="680px" class="list-dialog">
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top">
        <div class="form-section">角色信息</div>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="角色名称" prop="name">
              <el-input v-model="form.name" placeholder="如：调度员" maxlength="20" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="权限码" prop="code">
              <el-input v-model="form.code" placeholder="英文小写，如 dispatcher" :disabled="editing" />
            </el-form-item>
          </el-col>
        </el-row>
        <el-form-item label="备注">
          <el-input v-model="form.desc" type="textarea" :rows="2" placeholder="说明该角色的职责范围" maxlength="100" />
        </el-form-item>
        <div class="form-section">功能权限</div>
        <el-form-item prop="perms">
          <el-tree ref="treeRef" :data="permissionTree" node-key="key" show-checkbox default-expand-all
            :default-checked-keys="form.perms.includes('*') ? allLeafKeys : form.perms"
            class="perm-tree" />
          <span class="form-tip">勾选的权限码将同步给后端接口鉴权（菜单 / 按钮 / 数据三级授权）。</span>
        </el-form-item>
        <div class="form-section">状态</div>
        <el-form-item label="启用状态">
          <el-switch v-model="form.status" />
          <span class="form-tip">停用后该角色下的账号将无法登录。</span>
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
import { permissionTree } from '../mock/data'
import { useCrud } from '../composables/useCrud'

// 角色 / 角色权限接线（API 优先，内存 mock 兜底）
// perms 是权限码数组（展示/编辑用），持久化走 role-permissions 行（role_id + perm）
const crudR = useCrud('roles', {
  keywordField: ['name', 'code', 'desc'],
  mapRow: (r) => {
    const out = { ...r }
    out._rid = out.id
    if (!Array.isArray(out.perms)) out.perms = []
    if (out.members === undefined || out.members === null) out.members = 0
    return out
  }
})
const crudP = useCrud('role-permissions', { keywordField: ['perm'] })
const ridOf = (row) => row._rid ?? row.id
const editRid = ref(null)

const loading = ref(true)
const query = reactive({ keyword: '' })
const page = ref(1)
const pageSize = 10
const formVisible = ref(false)
const editing = ref(false)
const formRef = ref()
const treeRef = ref()

const emptyForm = { id: '', name: '', code: '', desc: '', perms: [], status: true }
const form = reactive({ ...emptyForm })

const allLeafKeys = permissionTree.reduce((acc, g) => acc.concat(g.children.map((c) => c.key)), [])

const rules = {
  name: [{ required: true, message: '请输入角色名称', trigger: 'blur' }],
  code: [
    { required: true, message: '请输入权限码', trigger: 'blur' },
    { pattern: /^[a-z][a-z_]{2,20}$/, message: '小写字母或下划线，3-21 位', trigger: 'blur' }
  ]
}

const filteredData = computed(() => {
  const kw = query.keyword.trim().toLowerCase()
  return crudR.state.rows.filter((r) => !kw || r.name.toLowerCase().includes(kw) || r.code.toLowerCase().includes(kw))
})

const pagedData = computed(() => {
  const start = (page.value - 1) * pageSize
  return filteredData.value.slice(start, start + pageSize)
})

function handleSearch() {
  page.value = 1
}

function openCreate() {
  editing.value = false
  Object.assign(form, emptyForm)
  formVisible.value = true
  formRef.value?.clearValidate()
}

function openEdit(row) {
  editing.value = true
  editRid.value = ridOf(row)
  Object.assign(form, { ...row, perms: [...row.perms] })
  formVisible.value = true
  formRef.value?.clearValidate()
}

async function handleSave() {
  const valid = await formRef.value.validate().catch(() => false)
  if (!valid) return
  const checked = treeRef.value.getCheckedKeys(false)
  if (!checked.length) {
    ElMessage.warning('请至少勾选一项功能权限')
    return
  }
  // 显式挑字段（openEdit 会把 _rid/__mockId 带进 form）；perms 供展示，持久化走 role-permissions
  const payload = { name: form.name, code: form.code, desc: form.desc, status: form.status, perms: checked }
  const saved = await crudR.save(editing.value ? editRid.value : null, payload)
  const rid = editing.value ? editRid.value : ridOf(saved || {})
  await syncPerms(rid, checked)
  await loadAll()
  ElMessage.success(editing.value ? '保存成功' : '新建成功')
  formVisible.value = false
}

async function handleToggle(row) {
  await crudR.patch(ridOf(row), { status: row.status })
  ElMessage.success(`「${row.name}」已${row.status ? '启用' : '停用'}`)
}

function handleDelete(row) {
  ElMessageBox.confirm(`确认删除角色「${row.name}」吗？其下账号需重新分配角色。`, '删除角色', {
    type: 'warning', confirmButtonText: '确认删除', cancelButtonText: '取消'
  }).then(async () => {
    const rid = ridOf(row)
    await crudR.remove(rid)
    // 一并清理该角色的权限行
    const perms = crudP.state.rows.filter((p) => String(p.roleId ?? p.role_id ?? '') === String(rid))
    for (const p of perms) await crudP.remove(ridOf(p))
    await loadAll()
    ElMessage.success('删除成功')
  }).catch(() => {})
}

async function loadAll() {
  await Promise.all([crudR.load({ page: 1, per_page: 200 }), crudP.load({ page: 1, per_page: 500 })])
  // 后端角色行不带 perms：从 role-permissions 合并回显
  for (const r of crudR.state.rows) {
    if (!Array.isArray(r.perms) || r.perms.length === 0) {
      const perms = crudP.state.rows.filter((p) => String(p.roleId ?? p.role_id ?? '') === String(ridOf(r))).map((p) => p.perm)
      if (perms.length) r.perms = perms
    }
  }
}

async function syncPerms(rid, checked) {
  if (rid === undefined || rid === null) return // mock 新建拿不到 id：perms 已随角色 payload 落库展示
  const existing = crudP.state.rows.filter((p) => String(p.roleId ?? p.role_id ?? '') === String(rid))
  for (const p of existing) {
    if (!checked.includes(p.perm)) await crudP.remove(ridOf(p))
  }
  for (const perm of checked) {
    if (!existing.some((p) => p.perm === perm)) await crudP.save(null, { roleId: rid, perm })
  }
}

onMounted(async () => {
  try {
    await loadAll()
  } catch (e) {
    console.warn('[roles]', e && e.message)
  } finally {
    loading.value = false
  }
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
.perm-tag {
  margin-right: 4px;
}
.perm-tree {
  width: 100%;
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  padding: var(--space-sm);
  max-height: 260px;
  overflow-y: auto;
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
