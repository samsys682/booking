<template>
  <!-- 版本门禁升级引导：写操作被 EditionGate 拦截时由 http.js 统一触发 -->
  <el-dialog v-model="state.visible" title="该功能需要升级版本" width="440px" align-center>
    <div class="upgrade-guide">
      <div class="upgrade-guide__icon"><el-icon><Lock /></el-icon></div>
      <p class="upgrade-guide__tip">{{ state.tip }}</p>
      <p class="upgrade-guide__sub">
        当前版本：{{ state.edition || '开源版' }}；升级到「{{ state.needName || '授权版' }}」即可解锁。
        已购买的用户可在「系统设置 → 授权激活」中填入卡密即时开通。
      </p>
    </div>
    <template #footer>
      <el-button @click="state.visible = false">稍后再说</el-button>
      <el-button type="primary" @click="openPlans">查看升级方案</el-button>
    </template>
  </el-dialog>

  <!-- 复用购买弹窗（选版本 → 支付 → 发卡） -->
  <PurchaseDialog v-model="plansVisible" :default-plan="planKey" />
</template>

<script setup>
import { ref, computed } from 'vue'
import { Lock } from '@element-plus/icons-vue'
import { upgradeState as state } from '../store/upgrade'
import PurchaseDialog from './PurchaseDialog.vue'

const plansVisible = ref(false)
// 需要源码版时默认选中源码版套餐，否则默认授权版
const planKey = computed(() => (state.needName === '源码版' ? 'source' : 'pro'))

function openPlans() {
  state.visible = false
  plansVisible.value = true
}
</script>

<style scoped>
.upgrade-guide {
  text-align: center;
  padding: 4px 0 2px;
}
.upgrade-guide__icon {
  width: 52px;
  height: 52px;
  margin: 0 auto 12px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 24px;
  color: #b88230;
  background: #fdf3e3;
}
.upgrade-guide__tip {
  font-size: 15px;
  color: var(--el-text-color-primary);
  margin: 0 0 8px;
  line-height: 1.6;
}
.upgrade-guide__sub {
  font-size: 13px;
  color: var(--el-text-color-secondary);
  margin: 0;
  line-height: 1.6;
}
</style>
