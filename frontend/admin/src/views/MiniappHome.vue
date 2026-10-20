<template>
  <div class="content">
    <div class="content-card content-card--list">
      <div class="action-bar">
        <h2 class="page-title">首页装修</h2>
        <div class="action-bar__left">
          <span class="page-subtitle">轮播图与金刚区配置，小程序端首页实时生效</span>
        </div>
        <div class="action-bar__right">
          <el-button type="primary" :icon="Plus" @click="addBanner">添加轮播图</el-button>
        </div>
      </div>

      <div class="panel-header">轮播图</div>
      <div class="table-wrap">
        <el-table :data="banners" empty-text="暂无轮播图">
          <el-table-column prop="title" label="标题" min-width="140" />
          <el-table-column prop="link" label="跳转链接" min-width="160" />
          <el-table-column prop="sort" label="排序" width="80" />
          <el-table-column label="启用" width="80">
            <template #default="{ row }"><el-switch v-model="row.enabled" /></template>
          </el-table-column>
          <el-table-column label="操作" width="90" fixed="right">
            <template #default="{ row }">
              <el-button type="danger" link size="small" @click="removeRow(banners, row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
      </div>
    </div>

    <div class="content-card content-card--list" style="margin-top: var(--space-md)">
      <div class="action-bar">
        <h2 class="page-title">金刚区</h2>
        <div class="action-bar__left">
          <span class="page-subtitle">首页宫格入口</span>
        </div>
        <div class="action-bar__right">
          <el-button type="primary" :icon="Plus" @click="addEntry">添加入口</el-button>
        </div>
      </div>
      <div class="table-wrap">
        <el-table :data="entries" empty-text="暂无入口">
          <el-table-column prop="name" label="名称" min-width="120" />
          <el-table-column prop="link" label="跳转" min-width="160" />
          <el-table-column prop="sort" label="排序" width="80" />
          <el-table-column label="启用" width="80">
            <template #default="{ row }"><el-switch v-model="row.enabled" /></template>
          </el-table-column>
          <el-table-column label="操作" width="90" fixed="right">
            <template #default="{ row }">
              <el-button type="danger" link size="small" @click="removeRow(entries, row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
      </div>
    </div>
  </div>
</template>

<script setup>
// 核查：装修项可增删启停
import { reactive } from 'vue'
import { ElMessage } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'

const banners = reactive([
  { title: '秋季大促 · 满 200 减 50', link: '/pages/activity?id=1', sort: 1, enabled: true },
  { title: '新人首单立减 30', link: '/pages/service?new=1', sort: 2, enabled: true }
])

const entries = reactive([
  { name: '日常保洁', link: '/pages/service?cat=1', sort: 1, enabled: true },
  { name: '家电清洗', link: '/pages/service?cat=2', sort: 2, enabled: true },
  { name: '会员中心', link: '/pages/member', sort: 3, enabled: true }
])

function addBanner() {
  banners.push({ title: '新轮播图', link: '/pages/index', sort: banners.length + 1, enabled: true })
  ElMessage.success('已添加，请编辑标题与链接')
}

function addEntry() {
  entries.push({ name: '新入口', link: '/pages/index', sort: entries.length + 1, enabled: true })
  ElMessage.success('已添加，请编辑名称与链接')
}

function removeRow(list, row) {
  const i = list.indexOf(row)
  if (i >= 0) list.splice(i, 1)
  ElMessage.success('已删除')
}
</script>

<style scoped>
.page-subtitle {
  color: var(--text-tertiary);
}
</style>
