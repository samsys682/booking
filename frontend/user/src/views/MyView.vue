<template>
  <div class="page">
    <!-- 会员信息 -->
    <div class="card profile">
      <div class="avatar">{{ member.name.slice(0, 1) }}</div>
      <div style="flex: 1">
        <div class="row">
          <b>{{ member.name }}</b>
          <span class="tag tag--green">{{ member.level }}</span>
        </div>
        <div class="tiny" style="opacity: 0.85; margin-top: 4px">{{ member.phone }}</div>
      </div>
    </div>

    <!-- 资产 -->
    <div class="card assets">
      <div class="asset" @click="$router.push('/my/stored-value')">
        <div class="asset__num">{{ member.balance }}</div>
        <div class="tiny muted">储值余额（元）</div>
      </div>
      <div class="asset" @click="$router.push('/my/points')">
        <div class="asset__num">{{ member.points }}</div>
        <div class="tiny muted">积分</div>
      </div>
      <div class="asset" @click="$router.push('/my/coupons')">
        <div class="asset__num">{{ couponCount }}</div>
        <div class="tiny muted">可用优惠券</div>
      </div>
    </div>

    <!-- 邀请有礼（推荐奖励：双向积分，邀请码仅注册时可绑定归属） -->
    <div class="card invite-card" v-if="invite.inviteCode">
      <div class="invite-card__main">
        <div class="list-row__title">邀请有礼</div>
        <div class="tiny muted" style="margin-top: 2px">
          邀请好友双方各得 {{ invite.inviterReward }} / {{ invite.inviteeReward }} 积分{{ invite.condition ? '（' + invite.condition + '）' : '' }} · 已邀请 {{ invite.invited }} 人
        </div>
      </div>
      <button class="invite-code" @click="copyInvite">我的邀请码 {{ invite.inviteCode }}</button>
    </div>

    <!-- 功能菜单 -->
    <div class="card">
      <div v-for="m in menus" :key="m.path" class="list-row" @click="go(m)">
        <span class="menu-icon">{{ m.icon }}</span>
        <div class="list-row__main">
          <div class="list-row__title">{{ m.label }}</div>
        </div>
        <span class="muted">›</span>
      </div>
    </div>

    <button class="btn btn--block" style="margin-top: 4px" @click="logout">退出登录</button>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useToast } from '../composables/useToast'
import { setToken } from '../api/http'
import { loadMember, clearMember } from '../api/auth'
import { fetchStored, fetchMyCoupons, fetchPoints, fetchInvite } from '../api/memberSelf'

const router = useRouter()
const { toast } = useToast()

// 登录态真实数据（会员自助端点）；后端不可达时报错不再落 mock
const myCouponRows = ref([])

// 真实登录资料优先（memberLogin 缓存）；资产字段给默认值防缓存行缺字段
const member = ref({ balance: 0, points: 0, level: '普通会员', ...(loadMember() || {}) })

// 邀请有礼（/my/invite；加载失败静默隐藏卡片，不阻塞主流程）
const invite = ref({ inviteCode: '', invited: 0, inviterReward: 0, inviteeReward: 0, condition: '' })

function copyInvite() {
  const text = `推荐你使用「预约上门服务」，下单方便还有优惠。填我的邀请码 ${invite.value.inviteCode} 注册，我们都能得积分！`
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(text).then(() => toast('邀请文案已复制，快去分享吧'))
      .catch(() => toast('邀请码：' + invite.value.inviteCode))
  } else {
    toast('邀请码：' + invite.value.inviteCode)
  }
}

const couponCount = computed(() => myCouponRows.value.filter((c) => c.status === '未使用').length)

const menus = [
  { icon: '券', label: '我的优惠券', path: '/my/coupons' },
  { icon: '储', label: '储值余额', path: '/my/stored-value' },
  { icon: '积', label: '我的积分', path: '/my/points' },
  { icon: '卡', label: '我的会员卡', path: '/my/cards' },
  { icon: '址', label: '服务地址管理', path: '/my/addresses' },
  { icon: '单', label: '我的订单', path: '/orders' }
]

function go(m) {
  router.push(m.path)
}

function logout() {
  if (!window.confirm('确定退出登录吗？')) return
  setToken('')
  clearMember()
  toast('已退出')
  router.replace('/login')
}

onMounted(async () => {
  // 会员自助端点（/my/*）；失败一律报错，不再内存 mock 兜底
  try {
    const [stored, coupons, points] = await Promise.all([fetchStored(), fetchMyCoupons(), fetchPoints()])
    const acc = stored.account
    if (acc) {
      member.value.balance = acc.balance
      member.value.level = acc.level || member.value.level
    }
    member.value.points = points.balance ?? member.value.points
    myCouponRows.value = coupons.list || []
  } catch (e) {
    toast(e.isHttpDown ? '后端服务未连接，请检查后端 API 服务是否启动' : (e.message || '加载失败'))
  }
  // 邀请信息独立加载，失败不提示（卡片隐藏即可）
  try {
    invite.value = { ...invite.value, ...(await fetchInvite()) }
  } catch (e) { /* 静默 */ }
})
</script>

<style scoped>
.profile {
  display: flex;
  align-items: center;
  gap: 12px;
  background: linear-gradient(135deg, #2f6b4f, #4a8f6c);
  color: #fff;
}
.avatar {
  width: 48px;
  height: 48px;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.22);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  font-weight: 600;
}
.invite-card {
  display: flex;
  align-items: center;
  gap: 12px;
}
.invite-card__main {
  flex: 1;
}
.invite-code {
  border: none;
  border-radius: 14px;
  padding: 6px 12px;
  background: var(--primary-light);
  color: var(--primary);
  font-size: 12px;
  white-space: nowrap;
}
.assets { display: flex; }
.asset { flex: 1; text-align: center; }
.asset__num { font-size: 19px; font-weight: 600; color: var(--primary); }
.menu-icon {
  width: 30px;
  height: 30px;
  border-radius: 8px;
  background: var(--primary-light);
  color: var(--primary);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 13px;
  flex: none;
}
</style>
