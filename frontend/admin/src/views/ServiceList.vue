<template>
  <div class="content">
    <div class="content-card content-card--list">
      <!-- 标题 + 搜索 + 新建一行（） -->
      <div class="action-bar">
        <h2 class="page-title">服务项目</h2>
        <div class="action-bar__left">
          <el-input
            v-model="query.keyword"
            placeholder="服务名称"
            clearable
            style="width: 200px"
            @clear="handleSearch"
            @keyup.enter="handleSearch"
          >
            <template #prefix><el-icon><Search /></el-icon></template>
          </el-input>
          <el-select v-model="query.category" placeholder="服务分类" clearable style="width: 130px" @change="handleSearch">
            <el-option v-for="c in categories" :key="c" :label="c" :value="c" />
          </el-select>
          <el-button :icon="Search" @click="handleSearch">查询</el-button>
        </div>
        <div class="action-bar__right">
          <ImportExportBar resource="services" label="服务" @imported="reload" />
          <el-button type="primary" :icon="Plus" @click="openCreate">新建服务</el-button>
        </div>
      </div>

      <div class="table-wrap" v-loading="loading">
        <el-table :data="pagedData" empty-text="暂无服务项目">
          <el-table-column label="图片" width="70">
            <template #default="{ row }">
              <el-image
                v-if="row.covers && row.covers.length"
                :src="row.covers[0]"
                class="svc-thumb"
                fit="cover"
                :preview-src-list="row.covers"
                preview-teleported
              />
              <div v-else class="svc-thumb svc-thumb--empty">
                <el-icon><Picture /></el-icon>
              </div>
            </template>
          </el-table-column>
          <el-table-column prop="name" label="服务名称" min-width="180" show-overflow-tooltip />
          <el-table-column prop="category" label="分类" width="110" />
          <el-table-column prop="price" label="价格" width="100" sortable>
            <template #default="{ row }">￥{{ row.price }}<span class="unit-text">/{{ row.unit }}</span></template>
          </el-table-column>
          <el-table-column prop="duration" label="参考时长" width="100">
            <template #default="{ row }">{{ row.duration }} 分钟</template>
          </el-table-column>
          <el-table-column prop="sales" label="累计销量" width="100" sortable />
          <el-table-column label="上架状态" width="100">
            <template #default="{ row }">
              <el-switch
                v-model="row.status"
                @change="handleToggle(row)"
              />
            </template>
          </el-table-column>
          <el-table-column label="操作" width="160" fixed="right">
            <template #default="{ row }">
              <el-button type="primary" link size="small" @click="openDetail(row)">详情</el-button>
              <el-button type="primary" link size="small" @click="openEdit(row)">编辑</el-button>
              <el-button type="danger" link size="small" @click="handleDelete(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>

        <el-empty v-if="!loading && filteredData.length === 0" description="暂无服务项目">
          <el-button type="primary" @click="openCreate">新建服务</el-button>
        </el-empty>
      </div>

      <div class="pagination-bar">
        <el-pagination
          v-model:current-page="page"
          :page-size="pageSize"
          :total="total"
          layout="total, prev, pager, next"
          background
          size="small"
        />
      </div>
    </div>

    <!-- 新建/编辑服务弹窗（重构：label 顶置 + 分区分隔；弹窗体内部滚动，长表单不超出视口） -->
    <el-dialog v-model="formVisible" :title="editing ? '编辑服务' : '新建服务'" width="720px" class="svc-dialog">
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top">
        <div class="form-section">基本信息</div>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="服务名称" prop="name">
              <el-input v-model="form.name" placeholder="如：日常保洁 2 小时" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="服务分类" prop="category">
              <el-select v-model="form.category" placeholder="选择分类" style="width: 100%">
                <el-option v-for="c in categories" :key="c" :label="c" :value="c" />
              </el-select>
            </el-form-item>
          </el-col>
        </el-row>

        <div class="form-section">价格与时长</div>
        <el-row :gutter="16">
          <el-col :span="8">
            <el-form-item label="价格（元）" prop="price">
              <el-input-number v-model="form.price" :min="0" :precision="2" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="计价单位" prop="unit">
              <!-- radio 必须写文字 slot，自闭合会渲染成空蓝块 -->
              <el-radio-group v-model="form.unit" class="unit-group">
                <el-radio-button value="次">次</el-radio-button>
                <el-radio-button value="台">台</el-radio-button>
                <el-radio-button value="小时">小时</el-radio-button>
                <el-radio-button value="平米">平米</el-radio-button>
              </el-radio-group>
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="参考时长（分钟）">
              <el-input-number v-model="form.duration" :min="0" :step="30" style="width: 100%" />
            </el-form-item>
          </el-col>
        </el-row>
        <div class="form-tip form-tip--unit">
          计价口径：次=按次固定价 · 台=按台数（家电清洗） · 小时=按时长 · 平米=按面积（开荒）；当前价格即 ¥{{ form.price || 0 }} / {{ form.unit }}
        </div>
        <!-- 图文内容（）：封面最多 9 张，详情用富文本 -->
        <el-form-item label="封面图">
          <el-upload
            :file-list="coverFileList"
            list-type="picture-card"
            :limit="9"
            multiple
            :auto-upload="false"
            accept="image/*"
            :on-change="handleCoverChange"
            :on-remove="handleCoverRemove"
            :on-exceed="handleCoverExceed"
          >
            <el-icon><Plus /></el-icon>
          </el-upload>
          <div class="form-tip">最多 9 张，建议 4:3 比例，第一张作为主图展示</div>
        </el-form-item>
        <el-form-item label="服务简介">
          <el-input
            v-model="form.description"
            type="textarea"
            :rows="2"
            maxlength="100"
            show-word-limit
            placeholder="一句话简介，展示在服务列表中"
          />
        </el-form-item>
        <el-form-item label="详情介绍">
          <RichEditor v-model="form.detail" placeholder="服务内容、适用场景、注意事项等，支持图文混排" />
          <div class="form-tip">富文本详情，展示在小程序端服务详情页</div>
        </el-form-item>
      </el-form>
      <template #footer>
        <div class="modal-footer">
          <el-button @click="formVisible = false">取消</el-button>
          <el-button type="primary" @click="handleSave">保存</el-button>
        </div>
      </template>
    </el-dialog>

    <!-- 服务详情弹窗：基本信息 + 可服务技师反向视图（服务→技师，供前台派单参考） -->
    <el-dialog v-model="detailVisible" :title="detailRow ? `服务详情 · ${detailRow.name}` : '服务详情'" width="560px">
      <div v-loading="detailLoading">
        <div v-if="detailRow">
          <div class="detail-line"><span class="detail-label">分类</span>{{ detailRow.category }}</div>
          <div class="detail-line"><span class="detail-label">价格</span>￥{{ detailRow.price }}/{{ detailRow.unit }} · {{ detailRow.duration }} 分钟</div>
          <div v-if="detailRow.description" class="detail-line"><span class="detail-label">简介</span>{{ detailRow.description }}</div>
        </div>
        <div class="form-section" style="margin-top: 12px">可服务技师</div>
        <el-empty v-if="!detailLoading && detailTechs.length === 0" description="暂无技师关联本服务（可在技师管理里为技师勾选可接项目）" :image-size="60" />
        <div v-else class="tech-list">
          <div v-for="t in detailTechs" :key="t.id" class="tech-item">
            <span class="tech-name">{{ t.name }}</span>
            <el-tag v-for="s in t.skills" :key="s" size="small" effect="plain" class="skill-tag">{{ s }}</el-tag>
            <span class="tech-meta">评分 {{ t.rating }} · {{ t.status }} · {{ t.phone }}</span>
          </div>
        </div>
      </div>
      <template #footer>
        <div class="modal-footer">
          <el-button @click="detailVisible = false">关闭</el-button>
        </div>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { computed, reactive, ref, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Search, Plus, Picture } from '@element-plus/icons-vue'
import { useCrud } from '../composables/useCrud'
import { uploadFile } from '../api/upload'
import { request } from '../api/http'
import RichEditor from '../components/RichEditor.vue'
import ImportExportBar from '../components/ImportExportBar.vue'

const loading = computed(() => crud.state.loading)
const query = reactive({ keyword: '', category: '' })
const page = ref(1)
const pageSize = 10
const crud = useCrud('services', { fieldMap: {}, moneyFields: ["price"], keywordField: ["name","category"] })
const total = computed(() => crud.state.total)
async function reload() {
  await crud.load({ ...query, page: page.value, per_page: pageSize })
}

const formVisible = ref(false)
const editing = ref(false)
const editingId = ref(null)
const formRef = ref()

const categories = ['保洁', '家电清洗', '专项清洁', '收纳']

const form = reactive({ name: '', category: '', price: 0, unit: '次', duration: 60, covers: [], detail: '', description: '' })
const coverFileList = ref([])
const rules = {
  name: [{ required: true, message: '请输入服务名称', trigger: 'blur' }],
  category: [{ required: true, message: '请选择服务分类', trigger: 'change' }],
  price: [{ required: true, message: '请输入价格', trigger: 'blur' }],
  unit: [{ required: true, message: '请选择计价单位', trigger: 'change' }]
}

const filteredData = computed(() => crud.state.rows)

const pagedData = computed(() => crud.state.rows)

function handleSearch() {
  page.value = 1
  reload()
}

function openCreate() {
  editing.value = false
  editingId.value = null
  Object.assign(form, { name: '', category: '', price: 0, unit: '次', duration: 60, covers: [], detail: '', description: '' })
  coverFileList.value = []
  formVisible.value = true
}

function openEdit(row) {
  editing.value = true
  editingId.value = row.id
  Object.assign(form, {
    name: row.name,
    category: row.category,
    price: row.price,
    unit: row.unit,
    duration: row.duration,
    covers: [...(row.covers || [])],
    detail: row.detail || '',
    description: row.description
  })
  coverFileList.value = form.covers.map((url, i) => ({ name: `cover-${i}`, url }))
  formVisible.value = true
}

// 封面上传（最多 9 张；接入后端 /upload 真实上传，落盘后回写 URL）
async function handleCoverChange(file) {
  if (!file || !file.raw) return
  try {
    const res = await uploadFile(file.raw)
    form.covers.push(res.url)
    coverFileList.value = form.covers.map((u, i) => ({ name: `cover-${i}`, url: u }))
  } catch (e) {
    ElMessage.error(e.message || '封面图上传失败')
    // 失败时移除 el-upload 内部的临时项，避免界面残留未落盘项
    coverFileList.value = form.covers.map((u, i) => ({ name: `cover-${i}`, url: u }))
  }
}

function handleCoverRemove(file) {
  form.covers = form.covers.filter((u) => u !== file.url)
  coverFileList.value = form.covers.map((u, i) => ({ name: `cover-${i}`, url: u }))
}

function handleCoverExceed() {
  ElMessage.warning('封面图最多 9 张，请先删除再重新上传')
}

async function handleSave() {
  const valid = await formRef.value.validate().catch(() => false)
  if (!valid) return
  await crud.save(editingId.value, { ...form })
  ElMessage.success(editing.value ? '保存成功' : '新建成功')
  formVisible.value = false
  reload()
}

// 详情弹窗：拉取服务详情（含可服务技师反向视图）
const detailVisible = ref(false)
const detailLoading = ref(false)
const detailRow = ref(null)
const detailTechs = ref([])

async function openDetail(row) {
  detailVisible.value = true
  detailLoading.value = true
  detailRow.value = null
  detailTechs.value = []
  try {
    const data = await request(`/services/${row.id}`)
    detailRow.value = data
    detailTechs.value = data.serviceable_technicians || []
  } catch (e) {
    ElMessage.error(e.message || '获取服务详情失败')
  } finally {
    detailLoading.value = false
  }
}

async function handleToggle(row) {
  await crud.patch(row.id, { status: row.status })
  ElMessage.success(`${row.name} 已${row.status ? '上架' : '下架'}`)
  reload()
}

function handleDelete(row) {
  ElMessageBox.confirm(`确认删除服务「${row.name}」吗？历史订单不受影响。`, '删除服务', {
    type: 'warning', confirmButtonText: '确认删除', cancelButtonText: '取消'
  }).then(async () => {
    await crud.remove(row.id)
    ElMessage.success('删除成功')
    reload()
  }).catch(() => {})
}

onMounted(() => {
  reload()
})
</script>

<style scoped>
/* 分页条样式统一在 tokens.css .pagination-bar（固定高度常驻底部） */

/* 表单分区标题（label 顶置后的视觉分组） */
.form-section {
  font-size: var(--font-size-heading);
  font-weight: 600;
  color: var(--text-primary);
  margin: 0 0 var(--space-sm);
  padding-left: 8px;
  border-left: 3px solid var(--primary);
  line-height: 1.2;
}

.form-section + .el-row,
.form-tip--unit + .el-form-item {
  margin-bottom: var(--space-md);
}

.unit-text {
  color: var(--text-tertiary);
}

.form-tip {
  /* 提示文字统一小号浅色，在表单项内必须整行铺满（否则会和上传框并排垂直居中） */
  width: 100%;
  flex-basis: 100%;
  color: var(--text-tertiary);
  font-size: var(--font-size-body);
  line-height: 1.6;
  margin-top: 4px;
}

/* 计价单位说明（；label 已顶置，去掉旧左右布局的 90px 缩进） */
.form-tip--unit {
  margin: 0 0 var(--space-md);
}

.svc-thumb {
  width: 48px;
  height: 36px;
  border-radius: var(--radius-sm);
}

.svc-thumb--empty {
  border: 1px dashed var(--border);
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--text-tertiary);
}

/* 弹窗高度自适应视口，表单体内部滚动（顶部/底部按钮始终可见） */
:deep(.svc-dialog) {
  margin-top: 4vh;
}

:deep(.svc-dialog .el-dialog__body) {
  max-height: calc(88vh - 110px);
  overflow-y: auto;
  overflow-x: hidden;
  padding-top: var(--space-sm);
}

/* 服务详情弹窗 */
.detail-line {
  display: flex;
  gap: 8px;
  margin-bottom: 6px;
  font-size: var(--font-size-body);
  color: var(--text-primary);
}

.detail-label {
  flex: none;
  color: var(--text-tertiary);
}

.tech-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.tech-item {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
  padding: 8px 10px;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
}

.tech-name {
  font-weight: 600;
}

.tech-meta {
  margin-left: auto;
  color: var(--text-tertiary);
  font-size: var(--font-size-body);
}

.skill-tag {
  margin: 0;
}

/* 封面图上传框缩小（弹窗内不需要默认 148px 大框） */
:deep(.svc-dialog .el-upload--picture-card),
:deep(.svc-dialog .el-upload-list--picture-card .el-upload-list__item) {
  width: 80px;
  height: 80px;
}
</style>
