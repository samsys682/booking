import { createRouter, createWebHashHistory } from 'vue-router'

// 技师端 H5 路由（hash 模式，兼容静态部署）
const routes = [
  { path: '/', redirect: '/home' },
  { path: '/home', component: () => import('../views/HomeView.vue') },
  { path: '/schedule', component: () => import('../views/ScheduleView.vue') },
  { path: '/income', component: () => import('../views/IncomeView.vue') },
  { path: '/my', component: () => import('../views/MyView.vue') },
  { path: '/login', component: () => import('../views/LoginView.vue') },
  { path: '/my/attendance', component: () => import('../views/AttendanceView.vue') },
  { path: '/my/reviews', component: () => import('../views/ReviewsView.vue') },
  { path: '/my/services', component: () => import('../views/MyServicesView.vue') },
  { path: '/order/:id', component: () => import('../views/OrderDetail.vue') }
]

const router = createRouter({
  history: createWebHashHistory(),
  routes
})

export default router
