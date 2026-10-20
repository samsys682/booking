<template>
  <div class="page">
    <!-- 评价概览（技师维度聚合：均分口径=收到的评价均分，与 admin 技师管理一致） -->
    <div class="card" style="background: linear-gradient(135deg, #2f6b4f, #3f8a66); color: #fff">
      <div class="tiny" style="opacity: 0.85">收到的评价</div>
      <div style="font-size: 30px; font-weight: 600; margin: 6px 0">
        {{ avgRating }}<span style="font-size: 14px; opacity: 0.85"> / 5</span>
      </div>
      <div class="tiny" style="opacity: 0.85">
        共 {{ rows.length }} 条 · 五星占比 {{ fiveRate }}
      </div>
    </div>

    <div class="sheet__title" style="margin: 4px 0 8px">评价列表</div>
    <div v-for="r in rows" :key="r.id" class="card">
      <div class="row row--between">
        <span class="rate-stars">{{ starsOf(r.rating) }}</span>
        <span class="tiny muted">{{ r.createdAt }}</span>
      </div>
      <div class="tiny muted" style="margin-top: 6px">{{ r.order }} · {{ r.service }} · {{ r.customer }}</div>
      <div class="tiny" style="margin-top: 8px">{{ r.content || '（未填写文字评价）' }}</div>
      <div v-if="r.images && r.images.length" class="rate-images">
        <img v-for="(img, i) in r.images" :key="i" :src="img" class="rate-thumb" alt="评价图片" />
      </div>
      <div v-if="r.reply" class="rate-reply tiny">商家回复：{{ r.reply }}</div>
    </div>
    <div v-if="!rows.length" class="empty">暂无收到的评价</div>
    <div class="tiny muted" style="margin: 8px 0 4px">评价由客户在订单完成后提交，商家回复后在此展示</div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useToast } from '../composables/useToast'
import { fetchReviews } from '../api/techSelf'

const { toast } = useToast()

const rows = ref([])

const avgRating = computed(() => {
  if (!rows.value.length) return '0.0'
  return (rows.value.reduce((sum, r) => sum + Number(r.rating || 0), 0) / rows.value.length).toFixed(1)
})

const fiveRate = computed(() => {
  if (!rows.value.length) return '0%'
  return Math.round((rows.value.filter((r) => Number(r.rating) === 5).length / rows.value.length) * 100) + '%'
})

function starsOf(n) {
  return '★★★★★'.slice(0, Number(n) || 0) + '☆☆☆☆☆'.slice(0, 5 - (Number(n) || 0))
}

async function load() {
  try {
    rows.value = await fetchReviews()
  } catch (e) {
    toast(e && e.isHttpDown ? '后端服务未连接，请检查后端 API 服务是否启动' : (e && e.message ? e.message : '加载失败'))
  }
}

onMounted(load)
</script>

<style scoped>
.rate-stars { color: #f7b500; letter-spacing: 1px; }
.rate-images { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
.rate-thumb { width: 56px; height: 56px; border-radius: 8px; object-fit: cover; display: block; }
.rate-reply {
  margin-top: 8px;
  padding: 8px 10px;
  background: var(--primary-light);
  border-radius: 8px;
  color: var(--text-secondary);
}
</style>
