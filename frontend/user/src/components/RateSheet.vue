<template>
  <div v-if="show">
    <div class="mask" @click="$emit('cancel')"></div>
    <div class="sheet">
      <div class="sheet__title">评价本单</div>
      <div v-if="orderNo" class="tiny muted" style="margin-bottom: 10px">
        订单 {{ orderNo }}<template v-if="service"> · {{ service }}</template>
      </div>

      <!-- 星级 -->
      <div class="rate-row">
        <button
          v-for="n in 5"
          :key="n"
          class="rate-star"
          :class="{ 'rate-star--on': n <= rating }"
          @click="rating = n"
        >★</button>
        <span class="tiny muted">{{ ratingText }}</span>
      </div>

      <!-- 文字 -->
      <textarea
        v-model="content"
        class="field__input"
        rows="3"
        maxlength="300"
        placeholder="说说本次服务的体验吧（选填）"
      ></textarea>

      <!-- 晒图 -->
      <div class="rate-images">
        <div v-for="(img, i) in images" :key="i" class="rate-img">
          <img :src="img" alt="评价图片" />
          <button class="rate-img__del" @click="removeImage(i)">×</button>
        </div>
        <label v-if="images.length < 9" class="rate-img rate-img--add">
          ＋<br /><span class="tiny">晒图</span>
          <input type="file" accept="image/*" multiple hidden @change="addImages" />
        </label>
      </div>
      <div class="tiny muted">最多 9 张（上传接口待接入，当前仅本地预览）</div>

      <!-- 评价有礼（奖励规则由父级下发，保持口径单源） -->
      <div v-if="rewardText" class="rate-reward tiny">{{ rewardText }}</div>

      <button class="btn btn--primary btn--block" style="margin-top: 12px" @click="submit">提交评价</button>
      <button class="btn btn--block" style="margin-top: 8px" @click="$emit('cancel')">取消</button>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useToast } from '../composables/useToast'

defineProps({
  show: { type: Boolean, default: false },
  orderNo: { type: String, default: '' },
  service: { type: String, default: '' },
  rewardText: { type: String, default: '' }
})
const emit = defineEmits(['cancel', 'submit'])

const { toast } = useToast()

const rating = ref(5)
const content = ref('')
const images = ref([])

const ratingText = computed(() => ['', '很不满意', '不满意', '一般', '满意', '超预期'][rating.value])

// 晒图（mock 阶段本地预览；接入后端后改为上传接口返回 URL）
function addImages(e) {
  const files = Array.from(e.target.files || [])
  for (const f of files) {
    if (images.value.length >= 9) {
      toast('晒图最多 9 张')
      break
    }
    images.value.push(URL.createObjectURL(f))
  }
  e.target.value = ''
}

function removeImage(i) {
  images.value.splice(i, 1)
}

function submit() {
  emit('submit', { rating: rating.value, content: content.value.trim(), images: [...images.value] })
}
</script>

<style scoped>
.rate-row {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 10px;
}

.rate-star {
  background: none;
  border: none;
  padding: 0;
  font-size: 26px;
  line-height: 1;
  color: var(--border);
  cursor: pointer;
}

.rate-star--on {
  color: #f7b500;
}

.rate-images {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin: 10px 0 4px;
}

.rate-img {
  position: relative;
  width: 64px;
  height: 64px;
  border-radius: 8px;
  overflow: hidden;
  background: var(--primary-light);
}

.rate-img img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.rate-img__del {
  position: absolute;
  top: 0;
  right: 0;
  width: 18px;
  height: 18px;
  padding: 0;
  border: none;
  border-radius: 0 0 0 8px;
  background: rgba(0, 0, 0, 0.55);
  color: #fff;
  font-size: 12px;
  line-height: 18px;
  cursor: pointer;
}

.rate-img--add {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  border: 1px dashed var(--border);
  background: transparent;
  color: var(--text-secondary);
  cursor: pointer;
}

.rate-reward {
  margin-top: 10px;
  padding: 8px 10px;
  background: var(--primary-light);
  border-radius: 8px;
  color: var(--primary);
}
</style>
