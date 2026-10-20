import { createRouter, createWebHistory } from 'vue-router'

const routes = [
  {
    path: '/login',
    name: 'login',
    component: () => import('../views/Login.vue'),
    meta: { title: '登录' }
  },
  {
    path: '/',
    component: () => import('../layouts/AdminLayout.vue'),
    children: [
      {
        path: '',
        name: 'dashboard',
        component: () => import('../views/Dashboard.vue'),
        meta: { title: '首页看板', breadcrumb: ['首页看板'] }
      },
      {
        path: 'bookings',
        name: 'bookings',
        component: () => import('../views/BookingList.vue'),
        meta: { title: '预约订单', breadcrumb: ['预约与订单', '预约订单'] }
      },
      {
        path: 'reviews',
        name: 'reviews',
        component: () => import('../views/ReviewList.vue'),
        meta: { title: '评价管理', breadcrumb: ['预约与订单', '评价管理'] }
      },
      {
        path: 'services',
        name: 'services',
        component: () => import('../views/ServiceList.vue'),
        meta: { title: '服务项目', breadcrumb: ['服务项目', '服务项目管理'] }
      },
      {
        path: 'technicians',
        name: 'technicians',
        component: () => import('../views/TechnicianList.vue'),
        meta: { title: '技师管理', breadcrumb: ['技师与排班', '技师管理'] }
      },
      {
        path: 'custom',
        name: 'custom',
        component: () => import('../views/CustomView.vue'),
        meta: { title: '定制服务', breadcrumb: ['定制服务'] }
      },
      {
        path: 'settings',
        name: 'settings',
        component: () => import('../views/Settings.vue'),
        meta: { title: '系统设置', breadcrumb: ['系统', '系统设置'] }
      },
      {
        // 会员与营销（占位页改为可操作页面，开卡/充值/发券 + 流水）
        path: 'feature/member-card',
        name: 'memberCard',
        component: () => import('../views/MemberCardList.vue'),
        meta: { title: '会员卡', breadcrumb: ['会员与营销', '会员卡'] }
      },
      {
        path: 'feature/stored-value',
        name: 'storedValue',
        component: () => import('../views/StoredValueList.vue'),
        meta: { title: '储值卡与等级', breadcrumb: ['会员与营销', '储值卡与等级'] }
      },
      {
        path: 'feature/coupon',
        name: 'coupon',
        component: () => import('../views/CouponList.vue'),
        meta: { title: '优惠券', breadcrumb: ['会员与营销', '优惠券'] }
      },
      {
        // 核查：占位页全部补齐为可操作页面（优先于通配路由）
        path: 'feature/queue',
        name: 'queue',
        component: () => import('../views/QueueList.vue'),
        meta: { title: '候补排队', breadcrumb: ['预约与订单', '候补排队'] }
      },
      {
        path: 'feature/deposit',
        name: 'deposit',
        component: () => import('../views/DepositList.vue'),
        meta: { title: '定金尾款', breadcrumb: ['预约与订单', '定金尾款'] }
      },
      {
        path: 'feature/schedule',
        name: 'schedule',
        component: () => import('../views/ScheduleList.vue'),
        meta: { title: '排班与休息', breadcrumb: ['技师与排班', '排班与休息'] }
      },
      {
        path: 'feature/dispatch',
        name: 'dispatch',
        component: () => import('../views/DispatchList.vue'),
        meta: { title: '智能派单', breadcrumb: ['技师与排班', '智能派单'] }
      },
      {
        path: 'feature/reports',
        name: 'reports',
        component: () => import('../views/ReportView.vue'),
        meta: { title: '经营报表', breadcrumb: ['经营与财务', '经营报表'] }
      },
      {
        path: 'feature/commission',
        name: 'commission',
        component: () => import('../views/CommissionList.vue'),
        meta: { title: '提成结算', breadcrumb: ['经营与财务', '提成结算'] }
      },
      {
        path: 'feature/notify',
        name: 'notify',
        component: () => import('../views/NotifyList.vue'),
        meta: { title: '消息通知', breadcrumb: ['系统', '消息通知'] }
      },
      {
        path: 'feature/audit-log',
        name: 'auditLog',
        component: () => import('../views/AuditLogList.vue'),
        meta: { title: '操作日志', breadcrumb: ['系统', '操作日志'] }
      },
      {
        path: 'feature/miniapp-home',
        name: 'miniappHome',
        component: () => import('../views/MiniappHome.vue'),
        meta: { title: '首页装修', breadcrumb: ['小程序管理', '首页装修'] }
      },
      {
        path: 'feature/miniapp-pages',
        name: 'miniappPages',
        component: () => import('../views/MiniappPages.vue'),
        meta: { title: '页面管理', breadcrumb: ['小程序管理', '页面管理'] }
      },
      {
        path: 'feature/miniapp-qrcode',
        name: 'miniappQrcode',
        component: () => import('../views/MiniappQrcode.vue'),
        meta: { title: '小程序码', breadcrumb: ['小程序管理', '小程序码'] }
      },
      {
        path: 'feature/miniapp-release',
        name: 'miniappRelease',
        component: () => import('../views/MiniappRelease.vue'),
        meta: { title: '发布管理', breadcrumb: ['小程序管理', '发布管理'] }
      },
      {
        // ===== 缺口盘点 P1：账号与组织 =====
        path: 'accounts',
        name: 'accounts',
        component: () => import('../views/AdminAccountList.vue'),
        meta: { title: '员工账号', breadcrumb: ['系统', '员工账号'] }
      },
      {
        path: 'roles',
        name: 'roles',
        component: () => import('../views/RoleList.vue'),
        meta: { title: '角色权限', breadcrumb: ['系统', '角色权限'] }
      },
      {
        // ===== 缺口盘点 P1：服务项目补充 =====
        path: 'specs',
        name: 'specs',
        component: () => import('../views/ServiceSpecList.vue'),
        meta: { title: '规格与加价项', breadcrumb: ['服务项目', '规格与加价项'] }
      },
      {
        path: 'areas',
        name: 'areas',
        component: () => import('../views/ServiceAreaList.vue'),
        meta: { title: '服务区域', breadcrumb: ['服务项目', '服务区域'] }
      },
      {
        path: 'price-rules',
        name: 'priceRules',
        component: () => import('../views/PriceRuleList.vue'),
        meta: { title: '时段差价', breadcrumb: ['服务项目', '时段差价'] }
      },
      {
        // 批次一 C：电子服务协议（附四 #6；开源版门禁 EditionGate=pro + 菜单 proOnly）
        path: 'agreements',
        name: 'agreements',
        component: () => import('../views/AgreementList.vue'),
        meta: { title: '服务协议', breadcrumb: ['服务项目', '服务协议'] }
      },
      {
        // ===== 缺口盘点 P1：技师与排班补充 =====
        path: 'attendance',
        name: 'attendance',
        component: () => import('../views/AttendanceList.vue'),
        meta: { title: '考勤打卡', breadcrumb: ['技师与排班', '考勤打卡'] }
      },
      {
        // ===== 缺口盘点 P1：会员中心 =====
        path: 'members',
        name: 'members',
        component: () => import('../views/MemberProfileList.vue'),
        meta: { title: '会员档案', breadcrumb: ['会员中心', '会员档案'] }
      },
      {
        path: 'member-tags',
        name: 'memberTags',
        component: () => import('../views/MemberTagList.vue'),
        meta: { title: '会员标签', breadcrumb: ['会员中心', '会员标签'] }
      },
      {
        // 竞品补强 §4.3-3：次卡/疗程卡 + 跨店通用 + 扫码核销
        path: 'timesheet-cards',
        name: 'timesheetCards',
        component: () => import('../views/TimesheetCardList.vue'),
        meta: { title: '次卡与疗程卡', breadcrumb: ['会员中心', '次卡与疗程卡'] }
      },
      {
        // ===== 缺口盘点 P1：营销中心 =====
        path: 'marketing',
        name: 'marketing',
        component: () => import('../views/MarketingActivityList.vue'),
        meta: { title: '营销活动', breadcrumb: ['营销中心', '营销活动'] }
      },
      {
        path: 'points',
        name: 'points',
        component: () => import('../views/PointList.vue'),
        meta: { title: '积分体系', breadcrumb: ['营销中心', '积分体系'] }
      },
      {
        // 积分商城（菜单重排拍板：礼品资产维护；会员端兑换走 my/gifts/{id}/redeem）
        path: 'gifts',
        name: 'gifts',
        component: () => import('../views/GiftList.vue'),
        meta: { title: '积分商城', breadcrumb: ['营销中心', '积分商城'] }
      },
      {
        // 积分兑换单（P2-c：会员兑换后的履约载体，发货/完成/取消）
        path: 'gift-exchanges',
        name: 'giftExchanges',
        component: () => import('../views/GiftExchangeList.vue'),
        meta: { title: '积分兑换单', breadcrumb: ['营销中心', '积分兑换单'] }
      },
      {
        path: 'referral',
        name: 'referral',
        component: () => import('../views/ReferralList.vue'),
        meta: { title: '推荐有礼', breadcrumb: ['营销中心', '推荐有礼'] }
      },
      {
        path: 'review-rewards',
        name: 'reviewRewards',
        component: () => import('../views/ReviewRewardList.vue'),
        meta: { title: '评价有礼', breadcrumb: ['营销中心', '评价有礼'] }
      },
      {
        // ===== 缺口盘点 P1：客户运营 =====
        path: 'follow-ups',
        name: 'followUps',
        component: () => import('../views/FollowUpList.vue'),
        meta: { title: '回访任务', breadcrumb: ['客户运营', '回访任务'] }
      },
      {
        path: 'complaints',
        name: 'complaints',
        component: () => import('../views/ComplaintList.vue'),
        meta: { title: '投诉工单', breadcrumb: ['客户运营', '投诉工单'] }
      },
      {
        // ===== 缺口盘点 P1：经营与财务补充 =====
        path: 'finance',
        name: 'finance',
        component: () => import('../views/FinanceReconcileList.vue'),
        meta: { title: '财务对账', breadcrumb: ['经营与财务', '财务对账'] }
      },
      {
        path: 'invoices',
        name: 'invoices',
        component: () => import('../views/InvoiceList.vue'),
        meta: { title: '发票管理', breadcrumb: ['经营与财务', '发票管理'] }
      },
      {
        path: 'refunds',
        name: 'refunds',
        component: () => import('../views/RefundList.vue'),
        meta: { title: '退款单', breadcrumb: ['经营与财务', '退款单'] }
      },
      {
        path: 'stores',
        name: 'stores',
        component: () => import('../views/StoreList.vue'),
        meta: { title: '多门店', breadcrumb: ['经营与财务', '多门店'] }
      },
      {
        // ===== 缺口盘点 P1：系统补充 =====
        path: 'message-templates',
        name: 'messageTemplates',
        component: () => import('../views/MessageTemplateList.vue'),
        meta: { title: '消息模板', breadcrumb: ['系统', '消息模板'] }
      },
      {
        path: 'backups',
        name: 'backups',
        component: () => import('../views/BackupList.vue'),
        meta: { title: '数据备份', breadcrumb: ['系统', '数据备份'] }
      },
      {
        path: 'upgrades',
        name: 'upgrades',
        component: () => import('../views/UpgradeList.vue'),
        meta: { title: '版本升级', breadcrumb: ['系统', '版本升级'] }
      },
      {
        // 竞品补强 §4.3-7：安全风控（报警/行程/隐私号/超时预警）
        path: 'security',
        name: 'security',
        component: () => import('../views/SecurityCenter.vue'),
        meta: { title: '安全风控', breadcrumb: ['系统', '安全风控'] }
      },
      {
        // 授权版功能页（开源版锁定，购买解锁后可查看使用）
        path: 'feature/:key',
        name: 'feature',
        component: () => import('../views/FeatureView.vue'),
        meta: { title: '功能', breadcrumb: ['授权版功能'] }
      }
    ]
  }
]

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes
})

router.afterEach((to) => {
  document.title = (to.meta.title ? to.meta.title + ' · ' : '') + '预约上门服务系统 · 管理后台'
})

export default router
