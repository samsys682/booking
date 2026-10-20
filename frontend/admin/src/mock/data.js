// 假数据已清除：全部列表导出为空数组，页面逻辑保持兼容（表格显示空列表）。
// 后端不可达时不再展示任何编造数据，统一由 useCrud/请求层报错提示。
import { reactive } from 'vue'
import { STATUS_META } from '../statusFlow'

// ===== 保留的静态常量（纯前端结构，不含业务假数据） =====

// 订单状态 → el-tag 类型（十二态口径，从 statusFlow.js::STATUS_META 派生，勿手写分叉）
export const bookingStatusMap = Object.fromEntries(
  Object.entries(STATUS_META).map(([s, m]) => [s, m.type])
)

// 派单规则默认值（实际以 setting 表下发为准）
export const dispatchRule = reactive({
  mode: '指派',
  weights: { skill: 40, schedule: 25, load: 20, rating: 15 },
  timeoutHours: 2
})

// 预约规则默认值（事实源：后端 setting 表 + OrderService 校验口径）
export const bookingRules = reactive({
  advanceHours: 2,
  maxAdvanceDays: 30,
  cancelLimitHours: 4,
  cancelFeeRate: 10,
  noShowLimit: 3,
  noShowFreezeDays: 7,
  waitlist: false
})

// 权限树（RBAC 配置页勾选结构，静态定义）
export const permissionTree = [
  { key: 'booking', label: '预约与订单', children: [
    { key: 'booking.view', label: '查看订单' }, { key: 'booking.create', label: '新建订单' },
    { key: 'booking.update', label: '改单/取消' }, { key: 'booking.delete', label: '删除订单' },
    { key: 'booking.dispatch', label: '派单' }
  ] },
  { key: 'tech', label: '技师与排班', children: [
    { key: 'tech.view', label: '查看技师' }, { key: 'tech.manage', label: '增删改技师' },
    { key: 'schedule.manage', label: '排班管理' }, { key: 'attendance.view', label: '考勤查看' }
  ] },
  { key: 'member', label: '会员与营销', children: [
    { key: 'member.view', label: '查看会员' }, { key: 'member.manage', label: '会员档案维护' },
    { key: 'coupon.manage', label: '优惠券管理' }, { key: 'point.manage', label: '积分管理' }
  ] },
  { key: 'finance', label: '经营与财务', children: [
    { key: 'finance.view', label: '财务查看' }, { key: 'finance.refund', label: '退款审核' },
    { key: 'commission.settle', label: '提成结算' }, { key: 'report.view', label: '报表查看' }
  ] },
  { key: 'system', label: '系统', children: [
    { key: 'system.settings', label: '系统设置' }, { key: 'system.backup', label: '备份恢复' },
    { key: 'system.audit', label: '操作日志' }, { key: 'system.upgrade', label: '版本升级' }
  ] }
]

// ===== 业务数据一律走后端 API，以下导出均为空数组（兼容历史页面 import） =====
// 注意：license 演示授权已删除，授权状态以 /auth/profile 下发为准（store/license.js）

export const dashboardStats = [
  { label: '今日预约', value: 0, unit: '单', icon: 'Calendar', tone: 'primary' },
  { label: '待服务', value: 0, unit: '单', icon: 'Clock', tone: 'warning' },
  { label: '今日营收', value: 0, unit: '元', icon: 'Money', tone: 'success' },
  { label: '本月订单', value: 0, unit: '单', icon: 'Tickets', tone: 'info' }
]

export const recentBookings = reactive([])
export const services = reactive([])
export const technicians = reactive([])
export const reviews = reactive([])
export const memberCards = reactive([])
export const cardLogs = reactive([])
export const storedAccounts = reactive([])
export const rechargePlans = reactive([])
export const storedLogs = reactive([])
export const coupons = reactive([])
export const couponInstances = reactive([])
export const couponLogs = reactive([])
export const adminAccounts = reactive([])
export const roles = reactive([])
export const serviceSpecs = reactive([])
export const serviceAreas = reactive([])
export const priceRules = reactive([])
export const attendances = reactive([])
export const dispatchRules = reactive([])
export const memberProfiles = reactive([])
export const memberTags = reactive([])
export const marketingActivities = reactive([])
export const referralRules = reactive([])
export const pointRules = reactive([])
export const pointLogs = reactive([])
export const reviewRewards = reactive([])
export const distributors = reactive([])
export const followUpTasks = reactive([])
export const complaints = reactive([])
export const autoMarketingRules = reactive([])
export const financeOrders = reactive([])
export const invoices = reactive([])
export const refundOrders = reactive([])
export const stores = reactive([])
export const backups = reactive([])
export const upgradeRecords = reactive([])
export const messageTemplates = reactive([])
export const messageSendLogs = reactive([])
export const timesheetCards = reactive([])
export const securityEvents = reactive([])