<template>
  <div class="content">
    <div class="content-card content-card--list">
      <div class="action-bar">
        <h2 class="page-title">页面管理</h2>
        <div class="action-bar__left">
          <span class="page-subtitle">小程序自定义页面的展示开关与排序</span>
        </div>
        <div class="action-bar__right">
          <el-button type="primary" :icon="Plus" @click="openCreate">新建页面</el-button>
        </div>
      </div>

      <div class="table-wrap">
        <el-table :data="pages" empty-text="暂无页面">
          <el-table-column prop="name" label="页面名称" min-width="140" />
          <el-table-column prop="type" label="类型" width="110">
            <template #default="{ row }"><el-tag size="small" effect="plain">{{ row.type }}</el-tag></template>
          </el-table-column>
          <el-table-column prop="path" label="路径" min-width="160" />
          <el-table-column prop="sort" label="排序" width="80" />
          <el-table-column label="展示" width="80">
            <template #default="{ row }"><el-switch v-model="row.enabled" /></template>
          </el-table-column>
          <el-table-column label="操作" width="130" fixed="right">
            <template #default="{ row }">
              <el-button type="primary" link size="small" @click="move(row, -1)" :disabled="row === pages[0]">上移</el-button>
              <el-button type="primary" link size="small" @click="move(row, 1)" :disabled="row === pages[pages.length - 1]">下移</el-button>
            </template>
          </el-table-column>
        </el-table>
      </div>
    </div>

    <el-dialog v-model="createVisible" title="新建页面" width="480px">
      <el-form label-width="90px">
        <el-form-item label="页面名称"><el-input v-model="form.name" placeholder="如：关于我们" /></el-form-item>
        <el-form-item label="类型">
          <el-radio-group v-model="form.type">
            <el-radio-button value="自定义页">自定义页</el-radio-button>
            <el-radio-button value="图文页">图文页</el-radio-button>
          </el-radio-group>
        </el-form-item>
      </el-form>
      <template #footer>
        <div class="modal-footer">
          <el-button @click="createVisible = false">取消</el-button>
          <el-button type="primary" @click="handleCreate">创建</el-button>
        </div>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
// 核查：页面可建、可排序、可启停
import { ref, reactive } from 'vue'
import { ElMessage } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'

const createVisible = ref(false)
const form = reactive({ name: '', type: '自定义页' })

const pages = reactive([
  { name: '门店介绍', type: '图文页', path: '/pages/about', sort: 1, enabled: true },
  { name: '服务协议', type: '图文页', path: '/pages/agreement', sort: 2, enabled: true },
  { name: '常见问题', type: '自定义页', path: '/pages/faq', sort: 3, enabled: false }
])

function openCreate() {
  form.name = ''
  form.type = '自定义页'
  createVisible.value = true
}

function handleCreate() {
  if (!form.name) {
    ElMessage.warning('请填写页面名称')
    return
  }
  pages.push({ name: form.name, type: form.type, path: '/pages/custom-' + (pages.length + 1), sort: pages.length + 1, enabled: true })
  createVisible.value = false
  ElMessage.success('页面已创建')
}

function move(row, dir) {
  const i = pages.indexOf(row)
  const j = i + dir
  if (j < 0 || j >= pages.length) return
  ;[pages[i], pages[j]] = [pages[j], pages[i]]
  pages.forEach((p, idx) => (p.sort = idx + 1))
  ElMessage.success('排序已更新')
}
</script>

<style scoped>
.page-subtitle {
  color: var(--text-tertiary);
}
</style>
