// =====================================================================
// useCrud v3 —— 统一数据源（仅真实 API；后端不可达时报错，不用假数据）— 用户端 H5
// 用法：
//   const crud = useCrud('services', {
//     fieldMap: { name: 'name', category: 'category' },   // 视图字段 ↔ 后端列
//     moneyFields: ['price'],                              // 后端「分」视图「元」
//   })
//   crud.load({ keyword, status, page }) → rows/total/page 响应式
//   crud.save(editing ? row.id : null, form) / crud.remove(id) / crud.patch(id, {status})
// 约定：
//   - 后端列名为 snake_case、金额一律「分」（schema COMMENT 明示）；
//     视图层统一 camelCase + 「元」，读写由本层自动换算。
//   - fieldMap{视图字段: 后端列} 双向映射；mapPayload 写前语义转换（如 名称→id）。
//   - 后端不可达（HttpDownError）时：全局 toast 报错并上抛（v3 起不再回落内存假数据，
//     避免误导买家）；业务错误（BizError）直接上抛，由页面提示——业务规则以服务端为准。
// =====================================================================

import { reactive } from 'vue'
import { list as apiList, create as apiCreate, update as apiUpdate, remove as apiRemove } from '../api/resource'
import { useToast } from './useToast'

// 全局节流：后端未连接的提示 60s 内只弹一次（避免多页面/多请求刷屏）
let lastDownTipAt = 0
const { toast } = useToast()
function tipHttpDown() {
  const now = Date.now()
  if (now - lastDownTipAt < 60000) return
  lastDownTipAt = now
  toast('后端服务未连接，请检查后端 API 服务是否启动')
}

/** snake_case → camelCase */
function camelKey(k) {
  return String(k).replace(/_([a-z])/g, (_, c) => c.toUpperCase())
}

/** camelCase → snake_case */
function snakeKey(k) {
  return String(k).replace(/[A-Z]/g, (c) => '_' + c.toLowerCase())
}

/**
 * @param {string} resource 后端资源名（services / bookings …）
 * @param {object} opts
 *   keywordField：keyword 模糊匹配的视图字段（string 或数组，默认 name）
 *   fieldMap：{ 视图字段: 后端 snake 列 } 双向映射（读补别名 / 写转列名）
 *   filterMap：{ 查询键: 视图字段 } 过滤映射（缺省=查询键即视图字段）
 *   moneyFields：金额视图字段（后端存「分」，视图用「元」，读写自动换算）
 *   mapPayload：写前语义转换（如 {role:'店长'} → {role_id:2}）
 *   mapRow：额外的行转换（在 camelCase/别名/金额换算之后执行）
 */
export function useCrud(resource, opts = {}) {
  const state = reactive({
    rows: [],
    total: 0,
    page: 1,
    pageSize: 10,
    loading: false
  })

  const keywordFields = Array.isArray(opts.keywordField) ? opts.keywordField : [opts.keywordField || 'name']
  const fieldMap = opts.fieldMap || {}
  const filterMap = opts.filterMap || null
  const moneyFields = opts.moneyFields || []
  const mapPayload = opts.mapPayload || ((d) => d)
  const extraMap = opts.mapRow || ((row) => row)

  // 视图字段 → 后端列（fieldMap 优先，缺省 camel→snake）
  const toCol = (viewField) => fieldMap[viewField] || snakeKey(viewField)

  // 后端行 → 视图行：snake→camel + fieldMap 别名 + 金额分→元 + 页面自定义映射
  const mapRow = (row) => {
    const out = {}
    for (const [k, v] of Object.entries(row)) out[camelKey(k)] = v
    for (const [view, col] of Object.entries(fieldMap)) {
      const val = out[camelKey(col)]
      if (val !== undefined && out[view] === undefined) out[view] = val
    }
    // 兼容页面既有字段别名（后端是 actual，页面用 amount）
    if (out.actual !== undefined && out.amount === undefined) out.amount = out.actual
    for (const f of moneyFields) {
      if (out[f] !== undefined && out[f] !== null && out[f] !== '') out[f] = Math.round(Number(out[f])) / 100
    }
    const merged = extraMap(out)
    // 页面 mapRow 的字典回退可能返回 undefined，回退保留原值
    for (const [k, v] of Object.entries(merged)) {
      if (v === undefined && out[k] !== undefined) merged[k] = out[k]
    }
    return merged
  }

  // 视图数据 → API payload：mapPayload → 键映射为后端列 → 金额元→分 → bool→1/0
  function toApi(data) {
    const shaped = mapPayload({ ...data })
    const out = {}
    for (const [k, v] of Object.entries(shaped)) {
      if (k === 'id' || String(k).startsWith('__')) continue
      if (v === undefined) continue
      const col = toCol(k)
      let val = v
      if (moneyFields.includes(k) && val !== '' && val !== null) {
        val = Math.round(Number(val) * 100)
      } else if (typeof val === 'boolean') {
        val = val ? 1 : 0
      }
      out[col] = val
    }
    return out
  }

  // 查询参数 → 后端查询键（key 经 fieldMap 映射；page/per_page/keyword/sort 原样）
  function toQuery(params) {
    const out = {}
    for (const [k, v] of Object.entries(params)) {
      if (v === undefined || v === '' || v === null) continue
      if (k === 'page' || k === 'per_page' || k === 'keyword' || k === 'sort') {
        out[k] = v
        continue
      }
      out[toCol(k)] = typeof v === 'boolean' ? (v ? 1 : 0) : v
    }
    return out
  }

  /**
   * 加载列表
   * @param {object} params { keyword, page, per_page, ...精确过滤（视图键） }
   */
  async function load(params = {}) {
    state.loading = true
    const query = { page: state.page, per_page: state.pageSize, ...params }
    try {
      const data = await apiList(resource, toQuery(query))
      state.rows = (data.list || []).map((r) => mapRow(r))
      state.total = Number(data.total || 0)
      state.page = Number(data.page || query.page || 1)
    } catch (e) {
      // 列表加载失败不阻断页面（空列表 + 全局报错提示），也避免 onMounted 未捕获异常
      if (e && e.isHttpDown) {
        tipHttpDown()
      }
    } finally {
      state.loading = false
    }
  }

  /**
   * 保存（id 为空=新增，否则修改）；data 为视图形状（camel + 元 + 布尔）
   * @param {number|string|null} id
   * @param {object} data
   */
  async function save(id, data) {
    const payload = toApi(data)
    try {
      const row = id ? await apiUpdate(resource, id, payload) : await apiCreate(resource, payload)
      return mapRow(row || {})
    } catch (e) {
      if (e && e.isHttpDown) {
        tipHttpDown()
      }
      throw e
    }
  }

  /**
   * 局部修改（状态开关等）
   */
  async function patch(id, data) {
    return save(id, data)
  }

  /**
   * 删除
   */
  async function remove(id) {
    try {
      await apiRemove(resource, id)
    } catch (e) {
      if (e && e.isHttpDown) {
        tipHttpDown()
      }
      throw e
    }
  }

  return { state, raw: state, load, save, patch, remove }
}