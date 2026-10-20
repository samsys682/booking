<template>
  <div v-if="show">
    <div class="mask" @click="$emit('cancel')"></div>
    <div class="sheet">
      <div class="sheet__title">选择支付方式</div>
      <div class="pay-amount">
        <span class="tiny muted">应付金额</span>
        <div class="price" style="font-size: 24px"><span class="yen">¥</span>{{ amount }}</div>
      </div>
      <div
        v-for="c in channels"
        :key="c.name"
        class="list-row"
        @click="$emit('pay', c.name)"
      >
        <span class="pay-icon">{{ c.icon }}</span>
        <div class="list-row__main">
          <div class="list-row__title">{{ c.name }}</div>
          <div class="list-row__sub">{{ c.desc }}</div>
        </div>
        <span class="muted">›</span>
      </div>
      <button class="btn btn--block" style="margin-top: 12px" @click="$emit('cancel')">取消</button>
    </div>
  </div>
</template>

<script setup>
defineProps({
  show: { type: Boolean, default: false },
  amount: { type: [Number, String], default: 0 }
})
defineEmits(['pay', 'cancel'])

const channels = [
  { name: '微信支付', icon: '微', desc: '推荐微信用户使用' },
  { name: '支付宝', icon: '支', desc: '支付宝账号支付' },
  { name: '储值余额', icon: '储', desc: '使用会员储值余额抵扣' }
]
</script>

<style scoped>
.pay-amount { text-align: center; padding: 8px 0 14px; }
.pay-icon {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  background: var(--primary-light);
  color: var(--primary);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 15px;
  flex: none;
}
</style>
