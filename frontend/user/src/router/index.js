import { createRouter, createWebHashHistory } from 'vue-router'

const routes = [
  { path: '/', redirect: '/home' },
  { path: '/home', component: () => import('../views/HomeView.vue') },
  { path: '/orders', component: () => import('../views/OrdersView.vue') },
  { path: '/my', component: () => import('../views/MyView.vue') },
  { path: '/login', component: () => import('../views/LoginView.vue') },
  { path: '/custom', component: () => import('../views/CustomView.vue') },
  { path: '/service/:id', component: () => import('../views/ServiceDetail.vue') },
  { path: '/order/create', component: () => import('../views/OrderCreate.vue') },
  { path: '/order/:id', component: () => import('../views/OrderDetail.vue') },
  { path: '/my/coupons', component: () => import('../views/CouponsView.vue') },
  { path: '/my/stored-value', component: () => import('../views/StoredValueView.vue') },
  { path: '/my/points', component: () => import('../views/PointsView.vue') },
  { path: '/my/cards', component: () => import('../views/MemberCardView.vue') },
  { path: '/my/addresses', component: () => import('../views/AddressView.vue') }
]

const router = createRouter({
  history: createWebHashHistory(),
  routes
})

export default router
