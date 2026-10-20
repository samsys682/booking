<template>
  <div class="content">
    <div class="content-card content-card--list">
      <!-- 标题 + 搜索 + 操作一行（） -->
      <div class="action-bar">
        <h2 class="page-title">评价管理</h2>
        <div class="action-bar__left">
          <el-input
            v-model="query.keyword"
            placeholder="订单号 / 客户 / 内容"
            clearable
            style="width: 180px"
            @clear="handleSearch"
            @keyup.enter="handleSearch"
          >
            <template #prefix><el-icon><Search /></el-icon></template>
          </el-input>
          <el-select v-model="query.rating" placeholder="评分" clearable style="width: 100px" @change="handleSearch">
            <el-option v-for="r in [5, 4, 3, 2, 1]" :key="r" :label="`${r} 星`" :value="r" />
          </el-select>
          <el-select v-model="query.status" placeholder="状态" clearable style="width: 110px" @change="handleSearch">
            <el-option label="待回复" value="待回复" />
            <el-option label="已回复" value="已回复" />
            <el-option label="已隐藏" value="已隐藏" />
          </el-select>
          <el-button :icon="Search" @click="handleSearch">查询</el-button>
        </div>
        <div class="action-bar__right">
          <el-button :icon="Download">导出</el-button>
        </div>
      </div>

      <!-- 去技师化口径：评价对象=订单/服务，本页只做评价内容治理（回复/隐藏/删除），技师维度评价见「技师管理」详情 -->
      <div class="table-wrap" v-loading="loading">
        <el-table :data="pagedData" empty-text="暂无符合条件的评价">
          <el-table-column prop="order" label="订单号" width="150" fixed />
          <el-table-column prop="customer" label="客户" width="90" />
          <el-table-column prop="service" label="服务项目" min-width="150" show-overflow-tooltip />
          <el-table-column label="评分" width="130">
            <template #default="{ row }">
              <el-rate :model-value="row.rating" disabled size="small" />
            </template>
          </el-table-column>
          <el-table-column prop="content" label="评价内容" min-width="220" show-overflow-tooltip />
          <el-table-column label="图文" width="70">
            <template #default="{ row }">
              <span v-if="row.images && row.images.length">{{ row.images.length }} 图</span>
              <span v-else>—</span>
            </template>
          </el-table-column>
          <el-table-column label="回复" min-width="140" show-overflow-tooltip>
            <template #default="{ row }">
              <span v-if="row.reply">{{ row.reply }}</span>
              <span v-else>—</span>
            </template>
          </el-table-column>
          <el-table-column label="状态" width="90">
            <template #default="{ row }">
              <el-tag :type="reviewStatusMap[row.status]" size="small">{{ row.status }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column prop="createdAt" label="评价时间" width="130" sortable />
          <el-table-column label="操作" width="170" fixed="right">
            <template #default="{ row }">
              <el-button type="primary" link size="small" @click="openReply(row)">回复</el-button>
              <el-button
                :type="row.status === '已隐藏' ? 'primary' : 'warning'"
                link
                size="small"
                @click="toggleVisible(row)"
              >{{ row.status === '已隐藏' ? '显示' : '隐藏' }}</el-button>
              <el-button type="danger" link size="small" @click="handleDelete(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>

        <el-empty v-if="!loading && filteredData.length === 0" description="暂无符合条件的评价" />
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

    <!-- 回复弹窗 -->
    <el-dialog v-model="replyVisible" title="回复评价" width="520px">
      <el-descriptions v-if="current" :column="1" border size="small" class="reply-review">
        <el-descriptions-item label="评价">
          <el-rate :model-value="current.rating" disabled size="small" />
          <div class="reply-review__content">{{ current.content }}</div>
        </el-descriptions-item>
      </el-descriptions>
      <el-form label-width="60px">
        <el-form-item label="回复">
          <el-input v-model="replyText" type="textarea" :rows="4" maxlength="300" show-word-limit placeholder="回复内容将展示在用户端评价中" />
        </el-form-item>
      </el-form>
      <template #footer>
        <div class="modal-footer">
          <el-button @click="replyVisible = false">取消</el-button>
          <el-button type="primary" @click="submitReply">提交回复</el-button>
        </div>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { computed, reactive, ref, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Search, Download } from '@element-plus/icons-vue'
import { useCrud } from '../composables/useCrud'

const reviewStatusMap = { 待回复: 'warning', 已回复: 'success', 已隐藏: 'info' }

const loading = computed(() => crud.state.loading)
const query = reactive({ keyword: '', rating: '', status: '' })
const page = ref(1)
const pageSize = 10
const crud = useCrud('reviews', { fieldMap: {"order":"order_no","createdAt":"created_at"}, keywordField: ["order_no","customer","content"] })
// 全量拉取后前端过滤/分页：内容治理筛选需要完整数据集
async function reload() {
  await crud.load({ page: 1, per_page: 1000 })
}

const replyVisible = ref(false)
const current = ref(null)
const replyText = ref('')

const filteredData = computed(() => {
  let list = crud.state.rows
  if (query.keyword) {
    const kw = String(query.keyword)
    list = list.filter((r) => [r.order, r.customer, r.content].some((f) => String(f ?? '').includes(kw)))
  }
  if (query.rating) list = list.filter((r) => Number(r.rating) === Number(query.rating))
  if (query.status) list = list.filter((r) => r.status === query.status)
  return list
})

const total = computed(() => filteredData.value.length)

const pagedData = computed(() => {
  const start = (page.value - 1) * pageSize
  return filteredData.value.slice(start, start + pageSize)
})

function handleSearch() {
  page.value = 1
  reload()
}

function openReply(row) {
  current.value = row
  replyText.value = row.reply
  replyVisible.value = true
}

function submitReply() {
  if (!replyText.value.trim()) {
    ElMessage.warning('请输入回复内容')
    return
  }
  current.value.reply = replyText.value.trim()
  current.value.status = '已回复'
  crud.save(current.value.id, { reply: current.value.reply, status: current.value.status })
    .then(async () => {
      await reload()
      replyVisible.value = false
      ElMessage.success('回复成功')
    })
    .catch((e) => {
      ElMessage.error(e && e.isHttpDown ? '后端服务未连接，请检查后端 API 服务是否启动' : (e.message || '保存失败'))
    })
}

function toggleVisible(row) {
  row.status = row.status === '已隐藏' ? '已回复' : '已隐藏'
  crud.save(row.id, { status: row.status })
    .then(() => reload())
    .catch((e) => {
      ElMessage.error(e && e.isHttpDown ? '后端服务未连接，请检查后端 API 服务是否启动' : (e.message || '保存失败'))
    })
  ElMessage.success(row.status === '已隐藏' ? '评价已隐藏，用户端不再展示' : '评价已恢复展示')
}

function handleDelete(row) {
  ElMessageBox.confirm(`确认删除订单 ${row.order} 的评价吗？删除后不可恢复。`, '删除评价', {
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
.reply-review {
  margin-bottom: var(--space-md);
}

.reply-review__content {
  margin-top: 4px;
  color: var(--text-secondary);
}

/* 分页条样式统一在 tokens.css .pagination-bar（固定高度常驻底部） */
</style>
