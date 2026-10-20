<template>
  <router-view />
  <!-- 版本门禁升级引导（全局单例；写操作被拦时由 http.js 触发） -->
  <UpgradeGuide />
</template>

<script setup>
import UpgradeGuide from './components/UpgradeGuide.vue'
</script>
