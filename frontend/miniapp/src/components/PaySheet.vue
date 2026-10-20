<template>
  <view v-if="show">
    <view class="mask" @tap="emit('cancel')"></view>
    <view class="sheet">
      <view class="sheet__title">选择支付方式</view>
      <view class="pay-amount">
        <view class="tiny muted">应付金额</view>
        <view class="price" style="font-size: 24px"><text class="yen">¥</text>{{ amount }}</view>
      </view>
      <view v-for="c in channels" :key="c.name" class="list-row" @tap="emit('pay', c.name)">
        <view class="pay-icon">{{ c.icon }}</view>
        <view class="list-row__main">
          <view class="list-row__title">{{ c.name }}</view>
          <view class="list-row__sub">{{ c.desc }}</view>
        </view>
        <text class="muted">›</text>
      </view>
      <button class="btn btn--block" style="margin-top: 12px" @tap="emit('cancel')">取消</button>
    </view>
  </view>
</template>

<script setup>
// 支付方式弹层 —— 顾客端小程序（批次五）
// 仅提供线下确认通道（到店付/储值余额，服务端 payConfirm 落账）：
// 微信支付 wx.requestPayment 不做（无商户权限，无法开发也无法测试）
defineProps({
  show: { type: Boolean, default: false },
  amount: { type: [Number, String], default: 0 }
})
const emit = defineEmits(['pay', 'cancel'])

const channels = [
  { name: '到店付', icon: '店', desc: '到店/上门后线下付款，商家确认' },
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
