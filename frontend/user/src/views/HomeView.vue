<template>
  <div class="page">
    <!-- 顶部横幅 -->
    <div class="card hero">
      <div class="hero__title">上门服务预约</div>
      <div class="hero__sub">保洁 · 家电清洗 · 收纳整理，专业技师上门</div>
    </div>

    <!-- 搜索 -->
    <div class="card">
      <input v-model="keyword" class="field__input" placeholder="搜索服务，如：保洁" />
    </div>

    <!-- 分类筛选 -->
    <div class="chips">
      <span
        v-for="c in categories"
        :key="c"
        class="chip"
        :class="{ active: category === c }"
        @click="category = c"
      >{{ c }}</span>
    </div>

    <!-- 进行中活动（管理端「营销活动」发布的限时拼团/秒杀/折扣；无活动时不占位） -->
    <div v-for="a in activities" :key="a.id" class="card activity" @click="goActivity(a)">
      <div class="row row--between">
        <div class="list-row__title">{{ a.kind }} · {{ a.name }}</div>
        <span class="tag tag--red">进行中</span>
      </div>
      <div class="row row--between">
        <div class="price">
          <span class="yen">¥</span>{{ a.price }}
          <span v-if="a.originPrice > a.price" class="tiny muted" style="text-decoration: line-through; margin-left: 4px">¥{{ a.originPrice }}</span>
        </div>
        <span class="tiny muted" v-if="a.stock > 0">仅剩 {{ a.stock - a.sold }} 份</span>
      </div>
      <div class="tiny muted">{{ a.endAt }} 截止 · 点击查看可预约服务</div>
    </div>

    <!-- 服务列表 -->
    <div
      v-for="s in filtered"
      :key="s.id"
      class="card"
      @click="$router.push('/service/' + s.id)"
    >
      <div class="row row--between">
        <div class="list-row__title">{{ s.name }}</div>
        <span class="tag tag--green">{{ s.category }}</span>
      </div>
      <div class="muted tiny desc">{{ s.description }}</div>
      <div class="row row--between">
        <div class="price"><span class="yen">¥</span>{{ s.price }}<span class="tiny muted"> / {{ s.unit }}</span></div>
        <button class="btn btn--primary btn--mini" @click.stop="$router.push('/order/create?serviceId=' + s.id)">
          立即预约
        </button>
      </div>
      <div class="tiny muted">已售 {{ s.sales }} · 约 {{ s.duration }} 分钟</div>
    </div>

    <div v-if="!filtered.length && !crud.state.loading" class="empty">暂无相关服务，可联系商家或稍后再试</div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useCrud } from '../composables/useCrud'
import { loadMember } from '../api/auth'

const router = useRouter()

const crud = useCrud('services', {
  keywordField: ['name', 'description', 'category'],
  moneyFields: ['price'] // 后端存「分」，视图展示「元」
})

const keyword = ref('')
const category = ref('全部')
const categories = ['全部', '保洁', '家电清洗', '专项清洁', '收纳']

const filtered = computed(() =>
  crud.state.rows.filter((s) => {
    if (!s.status) return false
    if (category.value !== '全部' && s.category !== category.value) return false
    if (keyword.value && !String(s.name + s.description).includes(keyword.value)) return false
    return true
  })
)

onMounted(async () => {
  try {
    await crud.load({ page: 1, per_page: 200 })
  } catch (e) {
    // 后端异常（如 500）不阻断首页；模板已有空态提示
  }
  try {
    await loadActivities()
  } catch (e) {
    // 活动加载失败不阻断首页
  }
})

// 进行中活动：营销活动真实数据（status='进行中' 且时间窗内），价格分转元
const activityCrud = useCrud('marketing-activities', { moneyFields: ['price', 'origin_price'] })
const activities = ref([])
async function loadActivities() {
  // 未登录不拉活动（marketing-activities 需登录态，避免 401 噪音）
  if (!loadMember()) return
  await activityCrud.load({ page: 1, per_page: 50, status: '进行中' })
  const now = Date.now()
  activities.value = activityCrud.state.rows.filter((a) => {
    const end = a.endAt ? new Date(String(a.endAt).replace(' ', 'T')).getTime() : 0
    return a.status === '进行中' && a.price > 0 && (!end || end > now)
  })
}
function goActivity(a) {
  if (a.serviceId && a.serviceId > 0) {
    router.push('/order/create?serviceId=' + a.serviceId)
  } else {
    category.value = '全部'
    keyword.value = ''
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }
}
</script>

<style scoped>
.hero {
  background: linear-gradient(135deg, #2f6b4f, #4a8f6c);
  color: #fff;
}
.hero__title { font-size: 19px; font-weight: 600; }
.hero__sub { margin-top: 6px; font-size: 12px; opacity: 0.85; }
.activity {
  border: 1px solid #ffd6d6;
  background: #fffafa;
}
.tag--red { color: #d03050; }
.desc {
  margin: 6px 0 10px;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
</style>
