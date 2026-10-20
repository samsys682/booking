<template>
  <el-container class="layout">
    <el-aside width="220px" class="layout__aside">
      <div class="layout__logo">
        <el-icon :size="20" color="var(--primary)"><Calendar /></el-icon>
        <span class="layout__logo-text">{{ site.displayName }}</span>
      </div>
      <!-- 一级导航固定（）：二级导航在内容区顶部以 tab 标签页呈现 -->
      <el-menu :default-active="activeMenu" class="layout__menu" router>
        <el-menu-item v-for="m in modules" :key="m.key" :index="m.path">
          <el-icon><component :is="m.icon" /></el-icon>
          <span>{{ m.label }}</span>
          <!-- 角标改自定义 span（el-badge 的 sup 即使改 static 也会挤压错位） -->
          <span v-if="m.badge && pendingCount" class="layout__menu-badge">{{ pendingCount }}</span>
        </el-menu-item>
      </el-menu>
    </el-aside>

    <el-container>
      <el-header class="layout__topbar" height="48px">
        <el-breadcrumb separator="/">
          <el-breadcrumb-item v-for="item in breadcrumb" :key="item">{{ item }}</el-breadcrumb-item>
        </el-breadcrumb>
        <div class="layout__topbar-right">
          <span class="version-badge">
            <el-tag size="small" :type="license.tagType" effect="plain">{{ license.edition }}</el-tag>
            <span>授权：{{ license.status }}</span>
          </span>
          <el-dropdown>
            <span class="layout__user">
              <el-icon><UserFilled /></el-icon>
              <span>管理员</span>
            </span>
            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item @click="logout">退出登录</el-dropdown-item>
              </el-dropdown-menu>
            </template>
          </el-dropdown>
        </div>
      </el-header>

      <!-- 二级导航：tab 标签页（）；预约订单 tab 带角标通知；授权版功能 tab 锁定，支付后联动解锁（） -->
      <el-tabs
        v-if="currentModule.subTabs.length"
        :model-value="activeTab"
        class="sub-tabs"
        @tab-click="onTabClick"
      >
        <el-tab-pane v-for="tab in currentModule.subTabs" :key="tab.path" :name="tab.path" :disabled="isTabLocked(tab)">
          <template #label>
            <span class="sub-tabs__label">
              <el-icon v-if="isTabLocked(tab)" :size="12"><Lock /></el-icon>
              {{ tab.label }}
              <span v-if="tab.badge && pendingCount" class="sub-tabs__badge">{{ pendingCount }}</span>
            </span>
          </template>
        </el-tab-pane>
      </el-tabs>

      <el-main class="layout__main">
        <router-view />
      </el-main>

      <el-footer class="layout__footer" height="28px">
        <span v-if="site.poweredBy">{{ site.poweredText }} · {{ site.editionName }}</span>
        <span v-else>{{ site.displayName }} · {{ site.editionName }}</span>
      </el-footer>
    </el-container>
  </el-container>
</template>

<script setup>
import { computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Lock } from '@element-plus/icons-vue'
import { modules, groupKeyOf, pendingBookingCount } from '../menu'
import { useLicenseStore } from '../store/license'
import { useSiteStore } from '../store/site'

const route = useRoute()
const router = useRouter()
const license = useLicenseStore()
const site = useSiteStore()

// 布局层挂载即同步真实授权：不再依赖登录/设置页刷新，
// 后台激活/续费后刷新页面即可解锁全部 proOnly tab（内需免重新登录）
onMounted(() => {
  license.refresh()
  site.load()
})

const pendingCount = computed(() => pendingBookingCount())
const currentModule = computed(() => modules.find((m) => m.key === groupKeyOf(route.path)) || modules[0])
const activeMenu = computed(() => currentModule.value.path)
const activeTab = computed(() => route.path)
const breadcrumb = computed(() => route.meta.breadcrumb || [currentModule.value.label])

function isTabLocked(tab) {
  return tab.proOnly && !license.isPro
}

function onTabClick({ paneName }) {
  if (paneName && paneName !== route.path) router.push(paneName)
}

function logout() {
  router.push('/login')
}
</script>

<style scoped>
.layout {
  height: 100vh;
}

.layout__aside {
  background: var(--bg-card);
  border-right: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.layout__logo {
  height: var(--topbar-h);
  display: flex;
  align-items: center;
  gap: var(--space-xs);
  padding: 0 var(--space-lg);
  border-bottom: 1px solid var(--border);
  flex-shrink: 0;
}

.layout__logo-text {
  font-size: var(--font-size-heading);
  font-weight: 600;
  color: var(--text-primary);
  white-space: nowrap;
}

.layout__menu {
  flex: 1;
  overflow-y: auto;
  border-right: none;
}

/* 菜单角标（自定义圆形角标，绝对定位于菜单项右侧垂直居中，
   不依赖 el-badge 的 sup 定位，避免 static/fixed 切换造成的错位与文字重叠 */
.layout__menu :deep(.el-menu-item) {
  position: relative;
}

.layout__menu-badge {
  position: absolute;
  right: 12px;
  top: 50%;
  transform: translateY(-50%);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 18px;
  height: 18px;
  padding: 0 5px;
  box-sizing: border-box;
  border-radius: 9px;
  background: var(--danger);
  color: #fff;
  font-size: 11px;
  line-height: 1;
  font-weight: 600;
}

.layout__topbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: var(--bg-card);
  border-bottom: 1px solid var(--border);
}

.layout__topbar-right {
  display: flex;
  align-items: center;
  gap: var(--space-md);
}

.layout__user {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  color: var(--text-secondary);
  cursor: pointer;
  font-size: var(--font-size-body);
}

/* 二级导航 tab 标签页 */
.sub-tabs {
  background: var(--bg-card);
  padding: 0 var(--space-lg);
  border-bottom: 1px solid var(--border);
}

.sub-tabs :deep(.el-tabs__header) {
  margin: 0;
}

.sub-tabs :deep(.el-tabs__item) {
  height: 40px;
  line-height: 40px;
  font-size: var(--font-size-body);
}

.sub-tabs__label {
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

/* tab 角标（自定义角标随标签文字同行内联、垂直居中，不重叠不换行） */
.sub-tabs__badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 16px;
  height: 16px;
  padding: 0 4px;
  box-sizing: border-box;
  border-radius: 8px;
  background: var(--danger);
  color: #fff;
  font-size: 11px;
  line-height: 1;
  font-weight: 600;
}

.layout__main {
  padding: 0;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.layout__footer {
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--bg-card);
  border-top: 1px solid var(--border);
  color: var(--text-tertiary);
  font-size: var(--font-size-body);
}
</style>
