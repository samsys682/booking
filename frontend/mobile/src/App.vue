<template>
  <!-- :key 强制路径变化时重建组件：详情页 /order/A → /order/B 参数复用时重新拉数据 -->
  <router-view :key="$route.fullPath" />

  <nav v-if="showTabbar" class="tabbar">
    <router-link class="tabbar__item" :class="{ active: route.path === '/home' }" to="/home">
      <span class="tabbar__icon">⌂</span>
      <span>工作台</span>
    </router-link>
    <router-link class="tabbar__item" :class="{ active: route.path === '/schedule' }" to="/schedule">
      <span class="tabbar__icon">▤</span>
      <span>日程</span>
    </router-link>
    <router-link class="tabbar__item" :class="{ active: route.path === '/income' }" to="/income">
      <span class="tabbar__icon">¥</span>
      <span>收入</span>
    </router-link>
    <router-link class="tabbar__item" :class="{ active: route.path === '/my' }" to="/my">
      <span class="tabbar__icon">☺</span>
      <span>我的</span>
    </router-link>
  </nav>

  <div v-if="toastState.msg" class="toast">{{ toastState.msg }}</div>
</template>

<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useToast } from './composables/useToast'

const route = useRoute()
const { toastState } = useToast()

const showTabbar = computed(() => ['/home', '/schedule', '/income', '/my'].includes(route.path))
</script>
