<template>
  <router-view />
  <nav v-if="showTabbar" class="tabbar">
    <router-link
      v-for="t in tabs"
      :key="t.path"
      :to="t.path"
      class="tabbar__item"
      :class="{ active: $route.path === t.path }"
    >
      <span class="tabbar__icon">{{ t.icon }}</span>
      <span>{{ t.label }}</span>
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

const tabs = [
  { path: '/home', icon: '⌂', label: '首页' },
  { path: '/orders', icon: '≣', label: '订单' },
  { path: '/custom', icon: '✦', label: '定制' },
  { path: '/my', icon: '☺', label: '我的' }
]

const showTabbar = computed(() => ['/home', '/orders', '/custom', '/my'].includes(route.path))
</script>
