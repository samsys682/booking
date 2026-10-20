// =====================================================================
// wire-crud.cjs —— d2 codemod：批量把 admin 视图的 mock 变异改为 useCrud(API+mock兜底)
// 转换配方（对标准页骨架，参照 MemberTagList.vue）：
//   1. mock/data import 后插 useCrud import
//   2. pageSize 行后插 crud/total/reload 定义（含每页 fieldMap/money 配置）
//   3. loading ref → computed(crud.state.loading)
//   4. filteredData/pagedData computed 体 → crud.state.rows（模板变量名不变）
//   5. 模板 :total="filteredData.length" → :total="total"
//   6. handleSearch 体 → page=1 + reload()
//   7. handleSave 变异块 → crud.save(...) + 成功提示（去「（mock）」）+ reload()
//   8. handleDelete 整函数重写（保留原确认文案）→ crud.remove + reload()
//   9. handleToggle 整函数重写（保留原文案）→ crud.patch + reload()
//  10. onMounted → reload()
// 特殊页（Login/Dashboard/BookingList/Settings/多tab页等）不在此列，手工改造。
// 用法：node scripts/wire-crud.cjs [--dry]
// =====================================================================

const fs = require('fs')
const path = require('path')

const VIEWS = path.join(__dirname, '..', 'src', 'views')
const DRY = process.argv.includes('--dry')

// =====================================================================
// 每页配置表：resource=后端资源；seedVar 缺省自动抓（首个 xx.findIndex）；
// fieldMap={视图字段:后端snake列}；money=金额视图字段（后端分→视图元）；
// keyword=模糊搜索视图字段；mapPayload/mapRow=页面级语义转换代码（函数体字符串）
// =====================================================================
const PAGES = {
  AdminAccountList: {
    resource: 'admins',
    fieldMap: { role: 'role_id', store: 'store_id', lastLogin: 'last_login_at' },
    keyword: ['name', 'account', 'phone'],
    mapPayload: `(d) => {
      const r = roles.find((x) => x.name === d.role || String(x.id) === String(d.role))
      const s = stores.find((x) => x.name === d.store || String(x.id) === String(d.store))
      return { ...d, role_id: r ? r.id : d.role, store_id: s ? s.id : d.store }
    }`,
    mapRow: `(r) => {
      const role = roles.find((x) => String(x.id) === String(r.role_id))
      const store = stores.find((x) => String(x.id) === String(r.store_id))
      return { ...r, role: role ? role.name : r.role_id, store: store ? store.name : r.store_id }
    }`
  },
  AttendanceList: {
    resource: 'attendances',
    fieldMap: { clockIn: 'clock_in', clockOut: 'clock_out' },
    keyword: ['technician', 'date']
  },
  AuditLogList: {
    resource: 'audit-logs',
    readonly: true,
    fieldMap: { who: 'admin_name', detail: 'content', at: 'created_at' },
    keyword: ['admin_name', 'module', 'action', 'target']
  },
  AutoMarketingList: {
    resource: 'auto-marketing-rules',
    fieldMap: {},
    keyword: ['name']
  },
  BackupList: {
    resource: 'backups',
    fieldMap: {},
    keyword: ['name', 'operator']
  },
  CommissionList: {
    resource: 'commission-settlements',
    readonly: true,
    fieldMap: { name: 'technician', revenue: 'gross' },
    money: ['gross', 'revenue', 'commission', 'bonus', 'penalty', 'actual'],
    keyword: ['technician', 'month']
  },
  ComplaintList: {
    resource: 'complaints',
    fieldMap: { order: 'order_no', createdAt: 'created_at' },
    keyword: ['order_no', 'customer']
  },
  DepositList: {
    resource: 'deposits',
    readonly: true,
    fieldMap: { order: 'order_no' },
    money: ['amount'],
    keyword: ['order_no']
  },
  DispatchList: {
    resource: 'bookings',
    readonly: true,
    fieldMap: { order: 'order_no', slot: 'start_at' },
    keyword: ['order_no', 'customer', 'address']
  },
  DistributionList: {
    resource: 'distributors',
    fieldMap: {},
    keyword: ['name', 'phone']
  },
  FinanceReconcileList: {
    resource: 'finance-reconciles',
    fieldMap: { order: 'order_no' },
    money: ['gross', 'discount', 'actual', 'fee', 'net'],
    keyword: ['order_no', 'channel']
  },
  FollowUpList: {
    resource: 'follow-up-tasks',
    fieldMap: { order: 'order_no' },
    keyword: ['order_no', 'customer']
  },
  InvoiceList: {
    resource: 'invoices',
    fieldMap: { order: 'order_no', reason: 'reject_reason' },
    money: ['amount'],
    keyword: ['order_no', 'member', 'title']
  },
  MarketingActivityList: {
    resource: 'marketing-activities',
    fieldMap: {},
    money: ['price', 'originPrice'],
    keyword: ['name']
  },
  MemberProfileList: {
    resource: 'members',
    fieldMap: { store: 'store_id', lastOrder: 'last_order_at' },
    keyword: ['name', 'phone'],
    mapPayload: `(d) => {
      const s = stores.find((x) => x.name === d.store || String(x.id) === String(d.store))
      return { ...d, store_id: s ? s.id : d.store }
    }`,
    mapRow: `(r) => {
      const store = stores.find((x) => String(x.id) === String(r.store_id))
      return { ...r, store: store ? store.name : r.store_id }
    }`
  },
  MemberTagList: {
    resource: 'member-tags',
    fieldMap: {},
    keyword: ['name']
  },
  PriceRuleList: {
    resource: 'price-rules',
    fieldMap: {},
    keyword: ['name', 'scope']
  },
  QueueList: {
    resource: 'booking-queues',
    readonly: true,
    fieldMap: { member: 'customer', slot: 'time_slot', count: 'seq' },
    keyword: ['customer', 'phone', 'service']
  },
  ReferralList: {
    resource: 'referral-rules',
    fieldMap: {},
    keyword: ['name']
  },
  RefundList: {
    resource: 'refunds',
    fieldMap: { order: 'order_no', createdAt: 'refunded_at' },
    money: ['amount'],
    keyword: ['refund_no', 'order_no', 'member']
  },
  ReviewList: {
    resource: 'reviews',
    fieldMap: { order: 'order_no', createdAt: 'created_at' },
    keyword: ['order_no', 'customer', 'technician']
  },
  ReviewRewardList: {
    resource: 'review-rewards',
    fieldMap: {},
    keyword: ['name']
  },
  ScheduleList: {
    resource: 'schedules',
    readonly: true,
    fieldMap: { name: 'technician', reason: 'note', at: 'created_at' },
    keyword: ['technician', 'date', 'shift']
  },
  SecurityCenter: {
    resource: 'security-events',
    fieldMap: { order: 'order_no' },
    keyword: ['kind', 'order_no', 'customer']
  },
  ServiceAreaList: {
    resource: 'service-areas',
    fieldMap: {},
    keyword: ['name', 'cities']
  },
  ServiceList: {
    resource: 'services',
    seedVar: 'mockServices',
    fieldMap: {},
    money: ['price'],
    keyword: ['name', 'category']
  },
  ServiceSpecList: {
    resource: 'service-specs',
    fieldMap: {},
    money: ['price'],
    keyword: ['name', 'service']
  },
  StoreList: {
    resource: 'stores',
    fieldMap: {},
    keyword: ['name', 'code', 'manager', 'phone']
  },
  TimesheetCardList: {
    resource: 'timesheet-cards',
    fieldMap: {},
    money: ['price', 'originPrice'],
    keyword: ['name', 'service']
  },
  UpgradeList: {
    resource: 'upgrade-records',
    fieldMap: { from: 'from_version', to: 'to_version' },
    keyword: ['operator', 'note']
  }
}

// =====================================================================
// 转换实现
// =====================================================================

function optsLiteral(p) {
  const parts = []
  parts.push(`fieldMap: ${JSON.stringify(p.fieldMap || {})}`)
  if (p.money && p.money.length) parts.push(`moneyFields: ${JSON.stringify(p.money)}`)
  if (p.keyword) parts.push(`keywordField: ${JSON.stringify(p.keyword)}`)
  if (p.mapPayload) parts.push(`mapPayload: ${p.mapPayload}`)
  if (p.mapRow) parts.push(`mapRow: ${p.mapRow}`)
  return `{ ${parts.join(', ')} }`
}

function wire(name, p) {
  const file = path.join(VIEWS, name + '.vue')
  let src = fs.readFileSync(file, 'utf8')
  const orig = src
  const notes = []

  // ---- 0. seedVar：自动抓取（findIndex 优先 → import 别名 → import 名） ----
  let seedVar = p.seedVar || ''
  if (!seedVar && !p.readonly) {
    const m = /\b(\w+)\.findIndex\(/.exec(src)
    if (m) {
      seedVar = m[1]
    } else {
      const imp = /import \{\s*\w+\s+as\s+(\w+)\s*\} from '\.\.\/mock\/data'/.exec(src) || /import \{\s*(\w+)\s*\} from '\.\.\/mock\/data'/.exec(src)
      if (imp) seedVar = imp[1]
    }
    if (!seedVar) notes.push('seedVar 未找到（按空种子处理）')
  }

  // ---- 1. useCrud import（插在 mock/data import 行后；不匹配则最后一个 import 行后） ----
  if (!src.includes("composables/useCrud")) {
    const re = /(import \{[^}]*\} from '\.\.\/mock\/data'\r?\n)/
    if (re.test(src)) {
      src = src.replace(re, `$1import { useCrud } from '../composables/useCrud'\n`)
    } else {
      const lines = src.split(/\r?\n/)
      let lastImp = -1
      lines.forEach((l, i) => { if (/^import /.test(l)) lastImp = i })
      if (lastImp > -1) {
        lines.splice(lastImp + 1, 0, "import { useCrud } from '../composables/useCrud'")
        src = lines.join('\n')
        notes.push('useCrud import 按 fallback 插入')
      } else {
        notes.push('import 插入失败')
      }
    }
  }

  // ---- 2. crud/total/reload 定义（pageSize 行后；无则 page 行后；无翻页页不依赖 page） ----
  if (!src.includes('useCrud(')) {
    const hasPage = /const page = ref\(/.test(src)
    const inject = hasPage
      ? `const crud = useCrud('${p.resource}', ${seedVar || '[]'}, ${optsLiteral(p)})
const total = computed(() => crud.state.total)
async function reload() {
  await crud.load({ ...query, page: page.value, per_page: pageSize })
}
`
      : `const crud = useCrud('${p.resource}', ${seedVar || '[]'}, ${optsLiteral(p)})
const total = computed(() => crud.state.total)
async function reload() {
  await crud.load({ ...query, per_page: 100 })
}
`
    let done = false
    for (const anchor of [/(const pageSize = \d+\r?\n)/, /(const page = ref\(\d+\)\r?\n)/]) {
      if (anchor.test(src)) {
        src = src.replace(anchor, `$1${inject}`)
        done = true
        break
      }
    }
    if (!done) notes.push('crud 定义插入失败（无 pageSize/page 锚点）')
  }

  // ---- 3. loading ref → computed ----
  src = src.replace(/const loading = ref\(true\)/, 'const loading = computed(() => crud.state.loading)')

  // ---- 4. filteredData / pagedData computed 体 → crud.state.rows ----
  src = src.replace(/const filteredData = computed\(\(\) => \{[\s\S]*?\n\}\)/, 'const filteredData = computed(() => crud.state.rows)')
  src = src.replace(/const pagedData = computed\(\(\) => \{[\s\S]*?\n\}\)/, 'const pagedData = computed(() => crud.state.rows)')

  // ---- 5. 分页 :total → 服务端 total ----
  src = src.replace(/:total="filteredData\.length"/g, ':total="total"')

  // ---- 6. handleSearch 体（indexOf 定位行首 }，避免转义换行问题） ----
  const NL = String.fromCharCode(10)
  const hsTag = 'function handleSearch() {'
  const hsStart = src.indexOf(hsTag)
  if (hsStart > -1) {
    const hsEnd = src.indexOf(NL + '}', hsStart)
    if (hsEnd > -1) {
      src = src.slice(0, hsStart) + hsTag + NL + '  page.value = 1' + NL + '  reload()' + NL + '}' + src.slice(hsEnd + 2)
    }
  }

  // ---- 7. handleSave 变异块 → crud.save ----
  // 变异块 = if (editing.value) { ...mock... } else { ...mock... } + formVisible 收尾
  const saveRe = /  if \(editing\.value\) \{[\s\S]*?\r?\n  \}\r?\n  formVisible\.value = false/
  if (saveRe.test(src)) {
    // 探测自定义 payload 变量（const payload/record = …）
    const fnBody = /async function handleSave\(\) \{[\s\S]*?\n\}/.exec(src)
    let payloadExpr = '{ ...form }'
    if (fnBody) {
      const pv = /const (payload|record) =/.exec(fnBody[0])
      if (pv) payloadExpr = pv[1]
    }
    src = src.replace(
      saveRe,
      `  await crud.save(editing.value ? form.id : null, ${payloadExpr})\n  ElMessage.success(editing.value ? '保存成功' : '新建成功')\n  formVisible.value = false\n  reload()`
    )
    if (!p.readonly) notes.push('handleSave 已接线 payload=' + payloadExpr)
  } else if (!p.readonly) {
    notes.push('handleSave 变异块未匹配')
  }

  // ---- 8. handleDelete 整函数重写（保留原确认文案/标题） ----
  const delRe = /function handleDelete\(row\) \{[\s\S]*?\n\}/
  if (delRe.test(src)) {
    src = src.replace(delRe, (m) => {
      const confirmArg = (/ElMessageBox\.confirm\(([\s\S]*?), ['"]/.exec(m) || [])[1] || '`确认删除该记录吗？`'
      const title = (/ElMessageBox\.confirm\([\s\S]*?, ['"]([^'"]+)['"], \{/.exec(m) || [])[1] || '删除确认'
      return `function handleDelete(row) {
  ElMessageBox.confirm(${confirmArg}, '${title}', {
    type: 'warning', confirmButtonText: '确认删除', cancelButtonText: '取消'
  }).then(async () => {
    await crud.remove(row.id)
    ElMessage.success('删除成功')
    reload()
  }).catch(() => {})
}`
    })
    notes.push('handleDelete 已接线')
  }

  // ---- 9. handleToggle 整函数重写（保留原文案，去「（mock）」） ----
  const togRe = /(?:async )?function handleToggle\(row\) \{[\s\S]*?\n\}/
  if (togRe.test(src)) {
    src = src.replace(togRe, (m) => {
      let msg = (/ElMessage\.success\(([\s\S]*?)\)/.exec(m) || [])[1] || '状态已更新'
      msg = msg.replace(/（mock）/g, '')
      return `async function handleToggle(row) {
  await crud.patch(row.id, { status: row.status })
  ElMessage.success(${msg})
  reload()
}`
    })
    notes.push('handleToggle 已接线')
  }

  // ---- 10. onMounted → reload ----
  src = src.replace(/onMounted\(\(\) => \{[\s\S]*?\n\}\)/, 'onMounted(() => {\n  reload()\n})')

  // ---- 残留清理：其他「（mock）」文案 ----
  src = src.replace(/（mock）/g, '')

  if (src !== orig) {
    if (!DRY) fs.writeFileSync(file, src)
    console.log(`[OK] ${name} → ${p.resource} | ${notes.join('; ')}`)
  } else {
    console.log(`[--] ${name} 无变化 | ${notes.join('; ')}`)
  }
}

// =====================================================================
// 执行
// =====================================================================
for (const [name, p] of Object.entries(PAGES)) {
  const file = path.join(VIEWS, name + '.vue')
  if (!fs.existsSync(file)) {
    console.log(`[!!] ${name}.vue 不存在，跳过`)
    continue
  }
  try {
    wire(name, p)
  } catch (e) {
    console.log(`[!!] ${name} 转换失败: ${e.message}`)
  }
}
console.log(DRY ? 'dry-run 完成（未写盘）' : 'codemod 完成')
