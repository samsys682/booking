<template>
  <div>
    <div class="navbar">
      <button class="navbar__back" @click="$router.back()">‹</button>
      <span class="navbar__title">服务详情</span>
    </div>

    <div class="page page--nobar" style="padding-bottom: 80px">
      <div v-if="service" class="card">
        <div class="row row--between">
          <div class="list-row__title" style="font-size: 17px">{{ service.name }}</div>
          <span class="tag tag--green">{{ service.category }}</span>
        </div>
        <div class="price" style="font-size: 22px; margin: 10px 0">
          <span class="yen">¥</span>{{ service.price }}<span class="tiny muted"> / {{ service.unit }}</span>
        </div>
        <div class="muted tiny">服务时长约 {{ service.duration }} 分钟 · 已售 {{ service.sales }}</div>
      </div>

      <div v-if="service" class="card">
        <div class="sheet__title">服务介绍</div>
        <!-- 富文本为商家后台录入的自家内容 -->
        <div class="rich" v-html="service.detail"></div>
      </div>

      <div v-if="!service && !loading" class="empty">
        未找到该服务
        <div><button class="btn empty__btn" @click="$router.push('/home')">返回首页</button></div>
      </div>
    </div>

    <div v-if="service" class="action-bar">
      <button class="btn" @click="$router.push('/home')">返回首页</button>
      <button class="btn btn--primary" :disabled="!service.status" @click="$router.push('/order/create?serviceId=' + service.id)">
        {{ service.status ? '立即预约' : '暂未上架' }}
      </button>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useCrud } from '../composables/useCrud'

const route = useRoute()
const crud = useCrud('services', { moneyFields: ['price'] })
const service = ref(null)
const loading = ref(true)

onMounted(async () => {
  await crud.load({ page: 1, per_page: 200 })
  service.value = crud.state.rows.find((s) => String(s.id) === String(route.params.id)) || null
  loading.value = false
})
</script>

<style scoped>
.rich :deep(p) { margin: 6px 0; color: var(--text-secondary); }
.rich :deep(ul) { padding-left: 18px; color: var(--text-secondary); }
.rich :deep(h3) { font-size: 15px; margin-bottom: 6px; }
</style>
