<template>
  <view class="page">
    <!-- 会员信息（未登录引导登录） -->
    <view v-if="member.name" class="card profile">
      <view class="avatar">{{ member.name.slice(0, 1) }}</view>
      <view style="flex: 1">
        <view class="row">
          <text style="font-weight: 600">{{ member.name }}</text>
          <text class="tag tag--green">{{ member.level }}</text>
        </view>
        <view class="tiny" style="opacity: 0.85; margin-top: 4px">{{ member.phone || '未绑定手机号' }}</view>
      </view>
    </view>
    <view v-else class="card profile">
      <view class="avatar">客</view>
      <view style="flex: 1">
        <view class="row"><text style="font-weight: 600">未登录</text></view>
        <view class="tiny" style="opacity: 0.85; margin-top: 4px" @tap="goLogin">点击登录后可下单、查询订单 ›</view>
      </view>
    </view>

    <!-- 资产三格 -->
    <view class="card assets">
      <view class="asset" @tap="go('/pages/my/stored')">
        <view class="asset__num">{{ member.balance }}</view>
        <view class="tiny muted">储值余额（元）</view>
      </view>
      <view class="asset" @tap="go('/pages/my/points')">
        <view class="asset__num">{{ member.points }}</view>
        <view class="tiny muted">积分</view>
      </view>
      <view class="asset" @tap="go('/pages/my/coupons')">
        <view class="asset__num">{{ couponCount }}</view>
        <view class="tiny muted">可用优惠券</view>
      </view>
    </view>

    <!-- 邀请有礼（推荐奖励：双向积分；加载失败静默隐藏卡片） -->
    <view v-if="invite.inviteCode" class="card invite-card">
      <view class="invite-card__main">
        <view class="list-row__title">邀请有礼</view>
        <view class="tiny muted" style="margin-top: 2px">
          邀请好友双方各得 {{ invite.inviterReward }} / {{ invite.inviteeReward }} 积分{{ invite.condition ? '（' + invite.condition + '）' : '' }} · 已邀请 {{ invite.invited }} 人
        </view>
      </view>
      <button class="invite-code" @tap="copyInvite">我的邀请码 {{ invite.inviteCode }}</button>
    </view>

    <!-- 功能菜单 -->
    <view class="card">
      <view v-for="m in menus" :key="m.path" class="list-row" @tap="go(m.path)">
        <text class="menu-icon">{{ m.icon }}</text>
        <view class="list-row__main">
          <view class="list-row__title">{{ m.label }}</view>
        </view>
        <text class="muted">›</text>
      </view>
    </view>

    <button v-if="member.name" class="btn btn--block" style="margin-top: 4px" @tap="logout">退出登录</button>
  </view>
</template>

<script setup>
// 我的 —— 顾客端小程序（对齐 user H5 MyView：资料卡 + 资产三格 + 邀请有礼 + 菜单）
import { computed, ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { toast } from '../../api/http'
import { setToken } from '../../api/http'
import { loadMember, clearMember } from '../../api/auth'
import { fetchStored, fetchMyCoupons, fetchPoints, fetchInvite } from '../../api/memberSelf'

// 真实登录资料优先（登录时缓存 storage）；资产字段给默认值防缓存行缺字段
const member = ref({ balance: 0, points: 0, level: '普通会员', ...(loadMember() || {}) })

// 登录态资产真实数据（/my/* 自助端点）
const myCouponRows = ref([])
const couponCount = computed(() => myCouponRows.value.filter((c) => c.status === '未使用').length)

// 邀请有礼（/my/invite；失败静默隐藏卡片，不阻塞主流程）
const invite = ref({ inviteCode: '', invited: 0, inviterReward: 0, inviteeReward: 0, condition: '' })

const menus = [
  { icon: '券', label: '我的优惠券', path: '/pages/my/coupons' },
  { icon: '储', label: '储值余额', path: '/pages/my/stored' },
  { icon: '积', label: '我的积分', path: '/pages/my/points' },
  { icon: '卡', label: '我的会员卡', path: '/pages/my/cards' },
  { icon: '址', label: '服务地址管理', path: '/pages/my/addresses' },
  { icon: '单', label: '我的订单', path: '/pages/orders/list', isTab: true }
]

function go(path) {
  if (path === '/pages/orders/list') return uni.switchTab({ url: path })
  uni.navigateTo({ url: path })
}

function goLogin() {
  uni.navigateTo({ url: '/pages/login/login' })
}

function copyInvite() {
  const text = `推荐你使用「上门服务」小程序，下单方便还有优惠。填我的邀请码 ${invite.value.inviteCode} 注册，我们都能得积分！`
  uni.setClipboardData({
    data: text,
    success: () => toast('邀请文案已复制，快去分享吧'),
    fail: () => toast('邀请码：' + invite.value.inviteCode)
  })
}

function logout() {
  uni.showModal({
    title: '退出登录',
    content: '确定退出登录吗？',
    success: (r) => {
      if (!r.confirm) return
      setToken('')
      clearMember()
      member.value = { balance: 0, points: 0, level: '普通会员' }
      myCouponRows.value = []
      toast('已退出')
    }
  })
}

onShow(async () => {
  // 恢复登录态（子页登录后返回刷新）
  const cached = loadMember()
  if (cached) member.value = { balance: 0, points: 0, level: '普通会员', ...cached }
  if (!uni.getStorageSync('bk_user_token')) return
  // 会员自助端点（/my/*）；失败报错不落 mock
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
    if (!e.isHttpDown) toast(e.message || '加载失败')
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
.invite-card { display: flex; align-items: center; gap: 12px; }
.invite-card__main { flex: 1; }
.invite-code {
  border: none;
  border-radius: 14px;
  padding: 6px 12px;
  background: #e4efe8;
  color: #2f6b4f;
  font-size: 12px;
  white-space: nowrap;
  line-height: 1.6;
}
.assets { display: flex; }
.asset { flex: 1; text-align: center; }
.asset__num { font-size: 19px; font-weight: 600; color: #2f6b4f; }
.menu-icon {
  width: 30px;
  height: 30px;
  border-radius: 8px;
  background: #e4efe8;
  color: #2f6b4f;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 13px;
  flex: none;
}
</style>
