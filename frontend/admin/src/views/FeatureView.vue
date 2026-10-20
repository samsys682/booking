<template>
  <div class="content">
    <div class="content-card feature-card">
      <div class="feature-card__head">
        <h2 class="page-title feature-card__title">
          {{ feature.title }}
          <el-tag v-if="license.isPro" size="small" type="success" effect="plain">已开通</el-tag>
          <el-tag v-else size="small" type="info" effect="plain">授权版功能</el-tag>
        </h2>
        <el-button v-if="license.isPro" size="small" @click="$router.back()">返回</el-button>
      </div>

      <p class="feature-card__desc">{{ feature.desc }}</p>

      <ul class="feature-card__points">
        <li v-for="p in feature.points" :key="p">
          <el-icon><CircleCheck /></el-icon>{{ p }}
        </li>
      </ul>

      <template v-if="!license.isPro">
        <el-alert type="info" :closable="false" class="feature-card__alert">
          <template #title>以下功能当前受限，购买开放版本后可查看使用。</template>
        </el-alert>
        <el-button type="primary" @click="purchaseVisible = true">购买解锁</el-button>
      </template>
    </div>

    <PurchaseDialog v-model="purchaseVisible" />
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useRoute } from 'vue-router'
import { CircleCheck } from '@element-plus/icons-vue'
import { features } from '../menu'
import { useLicenseStore } from '../store/license'
import PurchaseDialog from '../components/PurchaseDialog.vue'

const route = useRoute()
const license = useLicenseStore()
const purchaseVisible = ref(false)

const feature = computed(() => features[route.params.key] || { title: '功能开发中', desc: '该功能正在开发，敬请期待。', points: [] })
</script>

<style scoped>
/* 占位卡片铺满内容区（去掉 640px 宽度限制，
   flex:1 撑满剩余高度），否则页面背景（灰底）露出一圈"背景太小" */
.feature-card {
  max-width: none;
  flex: 1;
  min-height: fit-content;
}

.feature-card__head {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.feature-card__title {
  display: flex;
  align-items: center;
  gap: var(--space-xs);
  margin-bottom: var(--space-sm);
}

.feature-card__desc {
  color: var(--text-secondary);
  margin: 0 0 var(--space-md);
}

.feature-card__points {
  list-style: none;
  margin: 0 0 var(--space-md);
  padding: 0;
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: var(--space-xs);
}

.feature-card__points li {
  display: flex;
  align-items: center;
  gap: 6px;
  color: var(--text-secondary);
  background: #FAFAFA;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  padding: var(--space-xs) var(--space-sm);
}

.feature-card__points .el-icon {
  color: var(--success);
}

.feature-card__alert {
  margin-bottom: var(--space-md);
}
</style>
