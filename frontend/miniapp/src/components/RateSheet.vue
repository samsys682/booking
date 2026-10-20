<template>
  <view v-if="show">
    <view class="mask" @tap="emit('cancel')"></view>
    <view class="sheet">
      <view class="sheet__title">评价本单</view>
      <view v-if="orderNo" class="tiny muted" style="margin-bottom: 10px">
        订单 {{ orderNo }}<text v-if="service"> · {{ service }}</text>
      </view>

      <!-- 星级 -->
      <view class="rate-row">
        <text
          v-for="n in 5"
          :key="n"
          class="rate-star"
          :class="{ 'rate-star--on': n <= rating }"
          @tap="rating = n"
        >★</text>
        <text class="tiny muted">{{ ratingText }}</text>
      </view>

      <!-- 文字 -->
      <textarea
        v-model="content"
        class="field__input field__input--textarea"
        maxlength="300"
        placeholder="说说本次服务的体验吧"
      ></textarea>

      <!-- 评价有礼（奖励规则由父级下发，保持口径单源） -->
      <view v-if="rewardText" class="rate-reward tiny">{{ rewardText }}</view>

      <button class="btn btn--primary btn--block" style="margin-top: 12px" @tap="submit">提交评价</button>
      <button class="btn btn--block" style="margin-top: 8px" @tap="emit('cancel')">取消</button>
    </view>
  </view>
</template>

<script setup>
// 评价弹层 —— 顾客端小程序（星级/文字；晒图上传接口未接入，暂不提供）
import { computed, ref, watch } from 'vue'
import { toast } from '../api/http'

const props = defineProps({
  show: { type: Boolean, default: false },
  orderNo: { type: String, default: '' },
  service: { type: String, default: '' },
  rewardText: { type: String, default: '' }
})
const emit = defineEmits(['cancel', 'submit'])

const rating = ref(5)
const content = ref('')

// 每次打开重置（避免上一单内容残留）
watch(() => props.show, (v) => {
  if (v) {
    rating.value = 5
    content.value = ''
  }
})

const ratingText = computed(() => ['', '很不满意', '不满意', '一般', '满意', '超预期'][rating.value])

function submit() {
  if (!content.value.trim()) return toast('请填写评价内容')
  emit('submit', { rating: rating.value, content: content.value.trim() })
}
</script>

<style scoped>
.rate-row { display: flex; align-items: center; gap: 6px; margin-bottom: 10px; }
.rate-star {
  font-size: 26px;
  line-height: 1;
  color: var(--border);
}
.rate-star--on { color: #f7b500; }
.rate-reward {
  margin-top: 10px;
  padding: 8px 10px;
  background: var(--primary-light);
  border-radius: 8px;
  color: var(--primary);
}
</style>
