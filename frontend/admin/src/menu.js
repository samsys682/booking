// 导航配置（2026-10-06 按用户口径重排：订单管理含派单设置对话框、服务区域归服务项目、
// 客户运营解体（回访/投诉归会员管理）、储值与等级归会员管理、
// 次卡与会员卡归营销中心、多门店归系统、智能派单独立页取消（设置对话框在预约订单页）、
// 自动化营销与分销管理按用户指令删除）
// proOnly：授权版 / 源码版开放的功能（开源版锁定，支付成功后联动解锁）
// 角标数量：接入后端后改由通知接口推送（待办预约数）
export const pendingBookingCount = () => 0

export const modules = [
  { key: 'dashboard', label: '首页', icon: 'Odometer', path: '/', subTabs: [] },
  {
    key: 'booking',
    label: '订单管理',
    icon: 'Tickets',
    path: '/bookings',
    badge: true,
    subTabs: [
      { label: '预约订单', path: '/bookings', badge: true },
      { label: '候补排队', path: '/feature/queue', proOnly: true },
      { label: '定金尾款', path: '/feature/deposit', proOnly: true },
      { label: '评价管理', path: '/reviews' }
      // 派单设置：预约订单页顶部「派单设置」对话框（配置后按权重自动派单），不再设独立页
    ]
  },
  {
    key: 'services',
    label: '服务项目',
    icon: 'Goods',
    path: '/services',
    subTabs: [
      { label: '服务项目', path: '/services' },
      { label: '规格与加价', path: '/specs' },
      { label: '时段差价', path: '/price-rules', proOnly: true },
      { label: '服务协议', path: '/agreements', proOnly: true },
      { label: '服务区域', path: '/areas' }
    ]
  },
  {
    key: 'tech',
    label: '技师排班',
    icon: 'User',
    path: '/technicians',
    subTabs: [
      { label: '技师管理', path: '/technicians' },
      { label: '排班与休息', path: '/feature/schedule', proOnly: true },
      { label: '上班打卡', path: '/attendance', proOnly: true }
    ]
  },
  {
    key: 'member',
    label: '会员管理',
    icon: 'UserFilled',
    path: '/members',
    subTabs: [
      { label: '会员档案', path: '/members', proOnly: true },
      { label: '会员标签', path: '/member-tags', proOnly: true },
      { label: '储值与等级', path: '/feature/stored-value', proOnly: true },
      { label: '回访任务', path: '/follow-ups', proOnly: true },
      { label: '投诉工单', path: '/complaints', proOnly: true }
    ]
  },
  {
    key: 'marketing',
    label: '营销中心',
    icon: 'Present',
    path: '/feature/coupon',
    subTabs: [
      { label: '优惠券', path: '/feature/coupon', proOnly: true },
      { label: '积分商城', path: '/gifts', proOnly: true },
      { label: '积分兑换单', path: '/gift-exchanges', proOnly: true },
      { label: '积分体系', path: '/points', proOnly: true },
      { label: '次卡与疗程卡', path: '/timesheet-cards', proOnly: true },
      { label: '会员卡', path: '/feature/member-card', proOnly: true },
      { label: '营销活动', path: '/marketing', proOnly: true },
      { label: '推荐有礼', path: '/referral', proOnly: true },
      { label: '评价有礼', path: '/review-rewards', proOnly: true }
    ]
  },
  {
    // 小程序端控制内容（架构问答①：后台需补小程序管理模块）
    key: 'miniapp',
    label: '小程序管理',
    icon: 'Platform',
    path: '/feature/miniapp-home',
    subTabs: [
      { label: '首页装修', path: '/feature/miniapp-home', proOnly: true },
      { label: '页面管理', path: '/feature/miniapp-pages', proOnly: true },
      { label: '小程序码', path: '/feature/miniapp-qrcode', proOnly: true },
      { label: '发布管理', path: '/feature/miniapp-release', proOnly: true }
    ]
  },
  {
    key: 'finance',
    label: '财务管理',
    icon: 'DataAnalysis',
    path: '/feature/reports',
    subTabs: [
      { label: '经营报表', path: '/feature/reports', proOnly: true },
      { label: '提成结算', path: '/feature/commission', proOnly: true },
      { label: '财务对账', path: '/finance', proOnly: true },
      { label: '发票管理', path: '/invoices', proOnly: true },
      { label: '退款单', path: '/refunds', proOnly: true }
    ]
  },
  {
    key: 'custom',
    label: '定制服务',
    icon: 'ChatDotRound',
    path: '/custom',
    subTabs: [
      { label: '功能定制', path: '/custom' }
    ]
  },
  {
    key: 'system',
    label: '系统设置',
    icon: 'Setting',
    path: '/settings',
    subTabs: [
      { label: '系统设置', path: '/settings' },
      { label: '员工账号', path: '/accounts', proOnly: true },
      { label: '角色权限', path: '/roles', proOnly: true },
      { label: '门店管理', path: '/stores', proOnly: true },
      { label: '消息通知', path: '/feature/notify', proOnly: true },
      { label: '消息模板', path: '/message-templates', proOnly: true },
      { label: '安全风控', path: '/security', proOnly: true },
      { label: '数据备份', path: '/backups', proOnly: true },
      { label: '版本升级', path: '/upgrades', proOnly: true },
      { label: '操作日志', path: '/feature/audit-log', proOnly: true }
    ]
  }
]

// 受限功能说明（FeatureView 展示；购买解锁后可查看使用）
export const features = {
  queue: { title: '候补排队', desc: '约满时段自动进入候补队列，名额释放后按顺序自动通知递补。', points: ['候补队列管理', '名额释放自动通知', '候补转正式预约'] },
  deposit: { title: '定金尾款', desc: '支持定金预约锁定档期，服务完成后线上收取尾款。', points: ['定金线上收取', '尾款自动核算', '定金退款规则'] },
  schedule: { title: '排班与休息', desc: '技师按周排班、请假休息、节假日特殊安排。', points: ['周排班模板', '请假与调休', '节假日安排'] },
  dispatch: { title: '智能派单', desc: '按技能、档期、负载、评分加权自动派单；设置入口在预约订单页。', points: ['派单权重配置', '支付后自动派单', '拒单改派'] },
  'member-card': { title: '会员卡', desc: '次卡、时长卡开卡消费，自动核销。', points: ['次卡/时长卡', '开卡与核销', '转卡与退卡'] },
  'stored-value': { title: '储值与等级', desc: '储值赠送、会员等级规则（累计消费门槛）与等级折扣。', points: ['储值赠送', '等级规则设置', '等级折扣'] },
  coupon: { title: '优惠券', desc: '满减券、折扣券、新人券发放与核销。', points: ['券模板', '发放与领取', '核销统计'] },
  gifts: { title: '积分商城', desc: '礼品管理与积分兑换，会员端积分页可直接兑换。', points: ['礼品管理', '积分兑换', '兑换流水'] },
  reports: { title: '经营报表', desc: '营收趋势、服务项目排行、技师绩效等多维分析。', points: ['营收趋势', '项目/技师排行', '导出报表'] },
  commission: { title: '提成结算', desc: '按单提成、阶梯提成规则自动核算，一键生成工资单。', points: ['提成规则配置', '自动核算', '工资单导出'] },
  notify: { title: '消息通知', desc: '微信消息模板通知（需微信资质，当前未对接仅记录），预约提醒、服务进度。', points: ['触发规则配置', '发送记录', '微信对接预留'] },
  'audit-log': { title: '操作日志', desc: '关键操作留痕审计，支持按人员、时间检索。', points: ['操作留痕', '检索与导出', '风险操作告警'] },
  'miniapp-home': { title: '小程序首页装修', desc: '轮播图、金刚区、推荐服务可视化装修，实时预览。', points: ['轮播图管理', '金刚区配置', '推荐位管理'] },
  'miniapp-pages': { title: '小程序页面管理', desc: '自定义页面、门店介绍、服务说明等展示内容管理。', points: ['页面列表', '展示开关', '排序管理'] },
  'miniapp-qrcode': { title: '小程序码', desc: '门店小程序码与渠道码生成，扫码来源带参统计。', points: ['门店码生成', '渠道参数', '扫码统计'] },
  'miniapp-release': { title: '发布管理', desc: '小程序版本提交审核、正式发布与回滚记录。', points: ['提交审核', '版本发布', '发布记录'] }
}

export function findModuleByPath(path) {
  return modules.find((m) => m.key === (pathToGroup[path] || '')) || modules[0]
}

const pathToGroup = {
  '/': 'dashboard',
  '/bookings': 'booking',
  '/reviews': 'booking',
  '/services': 'services',
  '/specs': 'services',
  '/areas': 'services',
  '/price-rules': 'services',
  '/agreements': 'services',
  '/technicians': 'tech',
  '/attendance': 'tech',
  '/members': 'member',
  '/member-tags': 'member',
  '/follow-ups': 'member',
  '/complaints': 'member',
  '/gifts': 'marketing',
  '/points': 'marketing',
  '/marketing': 'marketing',
  '/referral': 'marketing',
  '/review-rewards': 'marketing',
  '/timesheet-cards': 'marketing',
  '/finance': 'finance',
  '/invoices': 'finance',
  '/refunds': 'finance',
  '/custom': 'custom',
  '/stores': 'system',
  '/accounts': 'system',
  '/roles': 'system',
  '/message-templates': 'system',
  '/security': 'system',
  '/backups': 'system',
  '/upgrades': 'system',
  '/settings': 'system'
}

export function groupKeyOf(path) {
  if (path.startsWith('/feature/')) {
    for (const m of modules) {
      if (m.subTabs.some((t) => t.path === path)) return m.key
    }
  }
  return pathToGroup[path] || 'dashboard'
}
