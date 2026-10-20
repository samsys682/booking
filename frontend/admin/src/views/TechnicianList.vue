<template>
  <div class="content">
    <!-- 技师数达到开源版上限时黄条提醒（附一 P0） -->
    <el-alert v-if="techLimitReached" type="warning" :closable="false" class="limit-alert">
      <template #title>
        技师数已达开源版上限 {{ license.limits.technicians.max }} 人，无法继续新增。
        升级授权版可扩充技师数量
        <el-button type="warning" link size="small" @click="showUpgrade">立即升级</el-button>
      </template>
    </el-alert>

    <div class="content-card content-card--list">
      <!-- 标题 + 搜索 + 新建一行（） -->
      <div class="action-bar">
        <h2 class="page-title">技师管理</h2>
        <div class="action-bar__left">
          <el-input
            v-model="query.keyword"
            placeholder="姓名 / 电话"
            clearable
            style="width: 200px"
            @clear="handleSearch"
            @keyup.enter="handleSearch"
          >
            <template #prefix><el-icon><Search /></el-icon></template>
          </el-input>
          <el-select v-model="query.status" placeholder="在岗状态" clearable style="width: 120px" @change="handleSearch">
            <el-option label="在岗" value="在岗" />
            <el-option label="服务中" value="服务中" />
            <el-option label="休假" value="休假" />
          </el-select>
          <el-button :icon="Search" @click="handleSearch">查询</el-button>
        </div>
        <div class="action-bar__right">
          <ImportExportBar resource="technicians" label="技师" @imported="loadAll" />
          <el-tooltip :disabled="!techLimitReached" content="开源版最多 5 名技师，升级授权版可扩充">
            <span>
              <el-button
                type="primary"
                :icon="Plus"
                :disabled="techLimitReached"
                @click="openCreate"
              >新增技师</el-button>
            </span>
          </el-tooltip>
        </div>
      </div>

      <div class="table-wrap" v-loading="loading">
        <el-table :data="pagedData" empty-text="暂无技师">
          <el-table-column label="头像" width="60">
            <template #default="{ row }">
              <el-avatar v-if="row.avatar" :src="row.avatar" :size="32" />
              <el-avatar v-else :size="32" class="tech-avatar--empty">{{ row.name.charAt(0) }}</el-avatar>
            </template>
          </el-table-column>
          <el-table-column prop="name" label="姓名" width="100" />
          <el-table-column prop="phone" label="电话" width="120" />
          <el-table-column label="技能" min-width="160">
            <template #default="{ row }">
              <el-tag v-for="skill in row.skills" :key="skill" size="small" effect="plain" class="skill-tag">
                {{ skill }}
              </el-tag>
            </template>
          </el-table-column>
          <el-table-column label="服务定价" width="110">
            <template #default="{ row }">
              <el-link v-if="minPriceText(row)" type="primary" :underline="false" @click="openEdit(row)">{{ minPriceText(row) }}</el-link>
              <el-link v-else type="info" :underline="false" @click="openEdit(row)">未定价</el-link>
            </template>
          </el-table-column>
          <el-table-column prop="rating" label="评分" width="90" sortable :sort-method="(a, b) => ratingOf(a) - ratingOf(b)">
            <template #default="{ row }">{{ ratingOf(row).toFixed(1) }}</template>
          </el-table-column>
          <el-table-column prop="intro" label="简介" min-width="160" show-overflow-tooltip />
          <el-table-column prop="monthOrders" label="本月单数" width="100" sortable />
          <el-table-column prop="status" label="状态" width="90">
            <template #default="{ row }">
              <el-tag :type="statusType(row.status)" size="small">{{ row.status }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="210" fixed="right">
            <template #default="{ row }">
              <el-button type="primary" link size="small" @click="openDetail(row)">详情</el-button>
              <el-button type="primary" link size="small" @click="openEdit(row)">编辑</el-button>
              <el-button link size="small" @click="openSchedule(row)">排班</el-button>
              <el-button type="danger" link size="small" @click="handleDelete(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>

        <el-empty v-if="!loading && filteredData.length === 0" description="暂无技师">
          <el-button type="primary" :disabled="techLimitReached" @click="openCreate">新增技师</el-button>
        </el-empty>
      </div>

      <div class="pagination-bar">
        <el-pagination
          v-model:current-page="page"
          :page-size="pageSize"
          :total="filteredData.length"
          layout="total, prev, pager, next"
          background
          size="small"
        />
      </div>
    </div>

    <!-- 新增/编辑技师弹窗（图文：头像 + 作品图 + 简介，） -->
    <el-dialog v-model="formVisible" :title="editing ? '编辑技师' : '新增技师'" width="560px">
      <el-form ref="formRef" :model="form" :rules="rules" label-width="80px">
        <el-form-item label="头像">
          <el-upload
            class="tech-upload"
            :file-list="avatarFileList"
            list-type="picture-card"
            :limit="1"
            :auto-upload="false"
            accept="image/*"
            :on-change="handleAvatarChange"
            :on-remove="handleAvatarRemove"
            :on-exceed="handleAvatarExceed"
          >
            <el-icon><Plus /></el-icon>
          </el-upload>
          <div class="form-tip">建议正方形人像照，展示在小程序技师列表</div>
        </el-form-item>
        <el-form-item label="姓名" prop="name">
          <el-input v-model="form.name" placeholder="技师姓名" />
        </el-form-item>
        <el-form-item label="电话" prop="phone">
          <el-input v-model="form.phone" placeholder="联系电话" />
        </el-form-item>
        <el-form-item label="技能" prop="skills">
          <!-- 技能字典（setting: skill_dict）可选项 + allow-create 自由扩展，保存时自动回写字典 -->
          <el-select v-model="form.skills" multiple filterable allow-create default-first-option
            placeholder="选择或输入新技能" style="width: 100%">
            <el-option v-for="c in skillOptions" :key="c" :label="c" :value="c" />
          </el-select>
        </el-form-item>
        <!-- 技师级服务定价：不同技师同一服务可不同价，未设置的按服务项目标准价 -->
        <el-form-item label="服务定价">
          <div class="price-rows">
            <div v-for="(p, i) in form.servicePrices" :key="i" class="price-row">
              <el-select v-model="p.serviceId" placeholder="服务项目" style="flex: 1">
                <el-option v-for="s in serviceOptions" :key="s.id" :label="s.name" :value="s.id" />
              </el-select>
              <el-input-number v-model="p.price" :min="0" :step="10" :precision="0" controls-position="right" style="width: 110px" />
              <span class="price-unit">元</span>
              <el-button type="danger" link :icon="Delete" @click="removePrice(i)">删除</el-button>
            </div>
          </div>
          <el-button link type="primary" :icon="Plus" @click="addPrice">添加服务定价</el-button>
          <div class="form-tip">技师接单价格；未设置的服务按「服务项目」标准价执行</div>
        </el-form-item>
        <el-form-item label="作品图">
          <el-upload
            class="tech-upload"
            :file-list="galleryFileList"
            list-type="picture-card"
            :auto-upload="false"
            multiple
            accept="image/*"
            :on-change="handleGalleryChange"
            :on-remove="handleGalleryRemove"
          >
            <el-icon><Plus /></el-icon>
          </el-upload>
          <div class="form-tip">服务案例、资质证书等图片，最多 9 张</div>
        </el-form-item>
        <el-form-item label="简介">
          <el-input v-model="form.intro" type="textarea" :rows="3" maxlength="200" show-word-limit placeholder="从业经验、擅长领域、服务理念等" />
        </el-form-item>
      </el-form>
      <template #footer>
        <div class="modal-footer">
          <el-button @click="formVisible = false">取消</el-button>
          <el-button type="primary" @click="handleSave">保存</el-button>
        </div>
      </template>
    </el-dialog>

    <!-- 技师详情（图文展示） -->
    <el-dialog v-model="detailVisible" title="技师详情" width="560px">
      <div v-if="detailRow" class="tech-detail">
        <div class="tech-detail__head">
          <el-avatar v-if="detailRow.avatar" :src="detailRow.avatar" :size="64" />
          <el-avatar v-else :size="64" class="tech-avatar--empty">{{ detailRow.name.charAt(0) }}</el-avatar>
          <div>
            <div class="tech-detail__name">{{ detailRow.name }}</div>
            <div class="tech-detail__meta">
              {{ detailRow.phone }} · 评分 {{ ratingOf(detailRow).toFixed(1) }} · 本月 {{ detailRow.monthOrders }} 单
              <el-tag :type="statusType(detailRow.status)" size="small">{{ detailRow.status }}</el-tag>
            </div>
            <div class="tech-detail__skills">
              <el-tag v-for="skill in detailRow.skills" :key="skill" size="small" effect="plain" class="skill-tag">{{ skill }}</el-tag>
            </div>
          </div>
        </div>
        <div class="tech-detail__section">
          <div class="panel-header">个人简介</div>
          <div class="tech-detail__intro">{{ detailRow.intro || '暂无简介' }}</div>
        </div>
        <div class="tech-detail__section">
          <div class="panel-header">服务定价</div>
          <div v-if="(detailRow.servicePrices || []).length" class="tech-detail__prices">
            <div v-for="p in detailRow.servicePrices" :key="p.serviceId" class="tech-detail__price-row">
              <span>{{ serviceName(p.serviceId) }}</span>
              <span class="tech-detail__price">¥{{ p.price }}</span>
            </div>
          </div>
          <div v-else class="tech-detail__intro">未单独定价，按「服务项目」标准价执行</div>
        </div>
        <div class="tech-detail__section">
          <div class="panel-header">作品图</div>
          <div v-if="(detailRow.gallery || []).length" class="tech-detail__gallery">
            <el-image
              v-for="(img, i) in detailRow.gallery"
              :key="i"
              :src="img"
              class="tech-detail__img"
              fit="cover"
              :preview-src-list="detailRow.gallery"
              :initial-index="i"
              preview-teleported
            />
          </div>
          <el-empty v-else description="暂无作品图" :image-size="60" />
        </div>
        <!-- 技师维度评价归本页聚合（评价管理页去技师化，不展示技师） -->
        <div class="tech-detail__section">
          <div class="panel-header">收到的评价（{{ detailReviews.length }}）</div>
          <div v-if="detailReviews.length" class="tech-reviews">
            <div v-for="r in detailReviews" :key="r.id" class="tech-review">
              <div class="tech-review__head">
                <el-rate :model-value="r.rating" disabled size="small" />
                <span class="tech-review__meta">{{ r.order }} · {{ r.customer }}</span>
                <el-tag v-if="r.status === '已隐藏'" type="info" size="small">已隐藏</el-tag>
              </div>
              <div class="tech-review__content">{{ r.content }}</div>
              <div v-if="r.reply" class="tech-review__reply">回复：{{ r.reply }}</div>
              <div class="tech-review__time">{{ r.createdAt }}</div>
            </div>
          </div>
          <el-empty v-else description="暂无收到的评价" :image-size="60" />
        </div>
      </div>
    </el-dialog>

    <!-- 购买授权在线支付（对接 OA 支付宝支付） -->
    <PurchaseDialog v-model="purchaseVisible" />
  </div>
</template>

<script setup>
import { computed, reactive, ref, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Search, Plus, Delete } from '@element-plus/icons-vue'
import { list as listResource, create as createResource, remove as removeResource, update as updateSetting } from '../api/resource'
import { useRouter } from 'vue-router'
import { useCrud } from '../composables/useCrud'
import { useLicenseStore } from '../store/license'
import PurchaseDialog from '../components/PurchaseDialog.vue'
import ImportExportBar from '../components/ImportExportBar.vue'

const purchaseVisible = ref(false)
const license = useLicenseStore()
const router = useRouter()

// 技师服务定价（technician_service）：technician_id → [{ serviceId, price(元) }]
// 技师表无该列（此前提交的 servicePrices 被后端丢弃），改由独立资源按 (技师, 服务) 落库
const techServiceMap = ref({})
async function loadTechServices() {
  try {
    const res = await listResource('technician-services', { page: 1, per_page: 100 })
    const map = {}
    for (const r of res.list || []) {
      const tid = Number(r.technician_id || 0)
      const sid = Number(r.service_id || 0)
      if (!tid || !sid) continue
      ;(map[tid] = map[tid] || []).push({ serviceId: sid, price: Math.round(Number(r.price) || 0) / 100 })
    }
    techServiceMap.value = map
  } catch (e) {
    console.warn('[tech-services]', e && e.message)
  }
}

// 技师 CRUD 接线（API 优先，内存 mock 兜底）
// rating 是评分、monthOrders 是单数，均非金额，禁入 moneyFields
const crudT = useCrud('technicians', {
  keywordField: ['name', 'phone', 'intro'],
  mapRow: (r) => {
    const out = { ...r }
    out._rid = out.id
    if (!Array.isArray(out.skills)) out.skills = []
    if (!Array.isArray(out.gallery)) out.gallery = []
    // 服务定价来自 technician_service（技师表无该列），按 technician_id 挂载
    out.servicePrices = (techServiceMap.value[out.id] || []).map((x) => ({ serviceId: x.serviceId, price: x.price }))
    if (out.rating === undefined || out.rating === null) out.rating = 0
    if (out.monthOrders === undefined || out.monthOrders === null) out.monthOrders = 0
    return out
  }
})
const ridOf = (row) => row._rid ?? row.id
const editRid = ref(null)

const loading = ref(true)
const query = reactive({ keyword: '', status: '' })
const page = ref(1)
const pageSize = 10

const formVisible = ref(false)
const editing = ref(false)
const formRef = ref()
const form = reactive({ name: '', phone: '', skills: [], servicePrices: [], avatar: '', gallery: [], intro: '' })
const avatarFileList = ref([])
const galleryFileList = ref([])
const detailVisible = ref(false)
const detailRow = ref(null)
const rules = {
  name: [{ required: true, message: '请输入姓名', trigger: 'blur' }],
  phone: [{ required: true, message: '请输入电话', trigger: 'blur' }],
  skills: [{ required: true, type: 'array', message: '请选择技能', trigger: 'change' }]
}

// 技能字典：setting skill_dict（原写死 4 项已字典化）；allow-create 输入新技能保存时回写
const skillDictId = ref(null)
const skillOptions = ref(['保洁', '家电清洗', '专项清洁', '收纳'])
async function loadSkillDict() {
  try {
    const res = await listResource('settings', { page: 1, per_page: 200, key: 'skill_dict' })
    const row = (res.list || []).find((x) => x.key === 'skill_dict')
    if (row) {
      skillDictId.value = row.id
      const dict = JSON.parse(row.value || '[]')
      if (Array.isArray(dict) && dict.length) skillOptions.value = dict
    }
  } catch (e) {
    console.warn('[skill-dict]', e && e.message)
  }
}
// 新技能回写字典（去重，放尾部）
async function syncSkillDict() {
  const extra = (form.skills || []).filter((s) => s && !skillOptions.value.includes(s))
  if (!extra.length) return
  const value = JSON.stringify([...skillOptions.value, ...extra])
  try {
    if (skillDictId.value) {
      await updateSetting('settings', skillDictId.value, { value })
    } else {
      const res = await createResource('settings', { group: 'tech', key: 'skill_dict', value, type: 'json', remark: '技师技能字典：技能多选项库，可自由扩展' })
      skillDictId.value = res?.data?.id ?? null
    }
    skillOptions.value = [...skillOptions.value, ...extra]
  } catch (e) {
    console.warn('[skill-dict-sync]', e && e.message)
  }
}

// 服务定价可选项目=服务项目表（技师按项目定价，未设按标准价）；来源：services 资源真实数据
// 存 {id,name} 供下拉选择与「id→名称」回显（technician_service 按 service_id 关联）
const serviceOptions = ref([])

// service_id → 服务名称（详情/表单回显）
function serviceName(id) {
  const s = serviceOptions.value.find((x) => Number(x.id) === Number(id))
  return s ? s.name : (id ? '#' + id : '')
}

// 评价聚合数据源（技师评分均分/详情评价列表）；无评价回退技师基础分
const reviewRows = ref([])

async function loadOptions() {
  try {
    const [svc, rev] = await Promise.all([
      // 注意：后端 per_page 上限 100（>100 会被夹回 20），此处取 100 拿满
      listResource('services', { page: 1, per_page: 100 }),
      listResource('reviews', { page: 1, per_page: 100 })
    ])
    serviceOptions.value = (svc.list || []).map((s) => ({ id: s.id, name: s.name }))
    reviewRows.value = rev.list
  } catch (e) {
    // 下拉/评价为辅助信息，加载失败不影响列表主流程
    console.warn('[technician-options]', e && e.message)
  }
}

function addPrice() {
  form.servicePrices.push({ serviceId: null, price: 0 })
}

function removePrice(i) {
  form.servicePrices.splice(i, 1)
}

// 技师评分=收到的评价均分（评价对象=订单/服务，技师维度仅在本页/技师主页聚合；无评价回退基础分）
function ratingOf(row) {
  const list = reviewRows.value.filter((r) => r.technician === row.name && r.status !== '已隐藏')
  if (!list.length) return Number(row.rating) || 0
  return list.reduce((s, r) => s + (Number(r.rating) || 0), 0) / list.length
}

// 详情「收到的评价」：按评价时间倒序（含已隐藏，标注状态供治理参考）
const detailReviews = computed(() => {
  if (!detailRow.value) return []
  return reviewRows.value
    .filter((r) => r.technician === detailRow.value.name)
    .sort((a, b) => String(b.createdAt).localeCompare(String(a.createdAt)))
})

// 列表定价摘要：有定价显示最低价起，否则提示未定价
function minPriceText(row) {
  const prices = (row.servicePrices || []).map((p) => Number(p.price) || 0)
  return prices.length ? `¥${Math.min(...prices)} 起` : ''
}

// 支付开通后 max 为 null（不限），不再触发上限（）
const techLimitReached = computed(
  () => license.limits.technicians.max !== null && license.limits.technicians.used >= license.limits.technicians.max
)

const filteredData = computed(() => {
  let list = crudT.state.rows
  if (query.keyword) {
    list = list.filter((t) => t.name.includes(query.keyword) || t.phone.includes(query.keyword))
  }
  if (query.status) list = list.filter((t) => t.status === query.status)
  return list
})

const pagedData = computed(() => {
  const start = (page.value - 1) * pageSize
  return filteredData.value.slice(start, start + pageSize)
})

function statusType(status) {
  return { 在岗: 'success', 服务中: 'primary', 休假: 'info' }[status] || 'info'
}

function handleSearch() {
  page.value = 1
}

function openCreate() {
  editing.value = false
  Object.assign(form, { name: '', phone: '', skills: [], servicePrices: [], avatar: '', gallery: [], intro: '' })
  avatarFileList.value = []
  galleryFileList.value = []
  formVisible.value = true
}

function openEdit(row) {
  editing.value = true
  editRid.value = ridOf(row)
  Object.assign(form, {
    name: row.name,
    phone: row.phone,
    skills: [...row.skills],
    servicePrices: (row.servicePrices || []).map((p) => ({ serviceId: p.serviceId, price: Number(p.price) || 0 })),
    avatar: row.avatar,
    gallery: [...(row.gallery || [])],
    intro: row.intro
  })
  avatarFileList.value = form.avatar ? [{ name: 'avatar', url: form.avatar }] : []
  galleryFileList.value = form.gallery.map((url, i) => ({ name: `img-${i}`, url }))
  formVisible.value = true
}

function openDetail(row) {
  detailRow.value = row
  detailVisible.value = true
}

// 图文上传（；mock 阶段本地预览，接入后端后改为上传接口）
function handleAvatarChange(file) {
  form.avatar = URL.createObjectURL(file.raw)
  avatarFileList.value = [{ name: file.name, url: form.avatar }]
}

function handleAvatarRemove() {
  form.avatar = ''
  avatarFileList.value = []
}

function handleAvatarExceed() {
  ElMessage.warning('头像只能上传 1 张，请先删除再重新上传')
}

function handleGalleryChange(file) {
  if (form.gallery.length >= 9) {
    ElMessage.warning('作品图最多 9 张')
    return
  }
  const url = URL.createObjectURL(file.raw)
  form.gallery.push(url)
  galleryFileList.value = form.gallery.map((u, i) => ({ name: `img-${i}`, url: u }))
}

function handleGalleryRemove(file) {
  form.gallery = form.gallery.filter((u) => u !== file.url)
  galleryFileList.value = form.gallery.map((u, i) => ({ name: `img-${i}`, url: u }))
}

function openSchedule(row) {
  // 排班已上线：跳转排班页并按技师名过滤
  router.push({ path: '/feature/schedule', query: { keyword: row.name } })
}

// 技师服务定价落库：先清该技师旧行，再按表单写新行（每技师行数少，简单可靠；价格元→分）
async function syncServicePrices(techId, rows) {
  const old = await listResource('technician-services', { page: 1, per_page: 100, technician_id: techId })
  for (const r of old.list || []) {
    await removeResource('technician-services', r.id)
  }
  for (const p of rows) {
    await createResource('technician-services', {
      technician_id: techId,
      service_id: Number(p.serviceId),
      price: Math.round(Number(p.price || 0) * 100)
    })
  }
}

async function handleSave() {
  const valid = await formRef.value.validate().catch(() => false)
  if (!valid) return
  await syncSkillDict()
  // 服务定价校验：每行必须选服务项目，且同一服务不可重复定价
  const priceRows = form.servicePrices.filter((p) => p.serviceId || p.price)
  if (priceRows.some((p) => !p.serviceId)) {
    ElMessage.warning('请选择服务定价的服务项目')
    return
  }
  const ids = priceRows.map((p) => Number(p.serviceId))
  if (new Set(ids).size !== ids.length) {
    ElMessage.warning('同一服务项目只能设置一个价格')
    return
  }
  // 显式挑字段；skills/gallery 由后端 JSON 列保存；服务定价另走 technician-services 资源
  const payload = {
    name: form.name,
    phone: form.phone,
    skills: [...form.skills],
    avatar: form.avatar || '',
    gallery: [...(form.gallery || [])],
    intro: form.intro || ''
  }
  if (!editing.value) {
    payload.status = '在岗'
    payload.rating = 0
    payload.monthOrders = 0
  }
  const saved = await crudT.save(editing.value ? editRid.value : null, payload)
  const techId = saved?.id || editRid.value
  try {
    if (techId) await syncServicePrices(techId, priceRows)
  } catch (e) {
    ElMessage.warning('服务定价保存失败：' + (e && e.message))
  }
  await loadAll()
  ElMessage.success(editing.value ? '保存成功' : '新增成功')
  formVisible.value = false
}

function handleDelete(row) {
  ElMessageBox.confirm(`确认删除技师「${row.name}」吗？删除后不可恢复，历史订单不受影响。`, '删除技师', {
    type: 'warning',
    confirmButtonText: '确认删除',
    cancelButtonText: '取消'
  })
    .then(async () => {
      await crudT.remove(ridOf(row))
      await loadAll()
      ElMessage.success('删除成功')
    })
    .catch(() => {})
}

function showUpgrade() {
  purchaseVisible.value = true
}

async function loadAll() {
  // 先拉技师服务定价，供 mapRow 挂载到各技师行
  await loadTechServices()
  await crudT.load({ page: 1, per_page: 200 })
  // 同步开源版技师数上限计数
  if (license.limits && license.limits.technicians) license.limits.technicians.used = crudT.state.rows.length
}

onMounted(async () => {
  loadSkillDict()
  loadOptions()
  try {
    await loadAll()
  } catch (e) {
    console.warn('[technicians]', e && e.message)
  } finally {
    loading.value = false
  }
})
</script>

<style scoped>
.limit-alert {
  margin-bottom: var(--space-md);
}

/* 分页条样式统一在 tokens.css .pagination-bar（固定高度常驻底部） */

.skill-tag {
  margin-right: 4px;
}

.form-tip {
  color: var(--text-tertiary);
  line-height: 1.6;
}

/* 上传占位缩小为 64px（头像/作品图），避免 picture-card 默认 148px 占位过大 */
.tech-upload :deep(.el-upload--picture-card),
.tech-upload :deep(.el-upload-list__item) {
  width: 64px;
  height: 64px;
}

.tech-upload :deep(.el-upload--picture-card .el-icon),
.tech-upload :deep(.el-upload-list__item .el-icon) {
  font-size: 18px;
}

/* 服务定价行编辑 */
.price-rows {
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: var(--space-xs);
}

.price-row {
  display: flex;
  align-items: center;
  gap: var(--space-xs);
}

.price-unit {
  color: var(--text-secondary);
}

.tech-detail__prices {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.tech-detail__price-row {
  display: flex;
  justify-content: space-between;
  padding: 4px 0;
  border-bottom: 1px dashed var(--border);
  color: var(--text-secondary);
}

.tech-detail__price {
  color: var(--danger, #f56c6c);
  font-weight: 600;
}

.tech-avatar--empty {
  background: var(--primary-light);
  color: var(--primary);
}

.tech-detail__head {
  display: flex;
  gap: var(--space-md);
  align-items: flex-start;
  margin-bottom: var(--space-md);
}

.tech-detail__name {
  font-size: var(--font-size-heading);
  font-weight: 600;
  margin-bottom: 4px;
}

.tech-detail__meta {
  display: flex;
  align-items: center;
  gap: var(--space-xs);
  color: var(--text-secondary);
  margin-bottom: 6px;
}

.tech-detail__intro {
  color: var(--text-secondary);
  line-height: 1.8;
}

.tech-detail__gallery {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-xs);
}

.tech-detail__img {
  width: 96px;
  height: 72px;
  border-radius: var(--radius-sm);
}

.tech-detail__section {
  margin-top: var(--space-sm);
}

/* 收到的评价列表（技师维度聚合） */
.tech-reviews {
  display: flex;
  flex-direction: column;
  gap: var(--space-xs);
}

.tech-review {
  padding: var(--space-xs) var(--space-sm);
  background: #FAFAFA;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
}

.tech-review__head {
  display: flex;
  align-items: center;
  gap: var(--space-xs);
}

.tech-review__meta {
  font-size: 12px;
  color: var(--text-secondary);
}

.tech-review__content {
  margin-top: 4px;
  line-height: 1.7;
}

.tech-review__reply {
  margin-top: 4px;
  font-size: 12px;
  color: var(--text-secondary);
}

.tech-review__time {
  margin-top: 2px;
  font-size: 12px;
  color: var(--text-tertiary);
}
</style>
