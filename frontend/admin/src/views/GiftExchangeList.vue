<template>
  <div class="content">
    <div class="content-card content-card--list">
      <div class="action-bar">
        <h2 class="page-title">积分兑换单</h2>
        <div class="action-bar__left">
          <el-input v-model="query.keyword" placeholder="兑换单号 / 会员 / 礼品 / 收货人" clearable style="width: 240px"
            @clear="handleSearch" @keyup.enter="handleSearch">
            <template #prefix><el-icon><Search /></el-icon></template>
          </el-input>
          <el-select v-model="query.status" placeholder="履约状态" clearable style="width: 120px" @change="handleSearch">
            <el-option label="待发货" value="待发货" />
            <el-option label="已发货" value="已发货" />
            <el-option label="已完成" value="已完成" />
            <el-option label="已取消" value="已取消" />
          </el-select>
          <el-button :icon="Search" @click="handleSearch">查询</el-button>
        </div>
        <div class="action-bar__right">
          <span class="pending-tip">待发货 {{ pendingCount }} 单</span>
        </div>
      </div>
      <div class="table-wrap" v-loading="loading">
        <el-table :data="rows" empty-text="暂无兑换单，会员在积分商城兑换后生成">
          <el-table-column prop="exchangeNo" label="兑换单号" min-width="150" />
          <el-table-column prop="member" label="会员" min-width="90" />
          <el-table-column prop="giftName" label="礼品" min-width="140" show-overflow-tooltip />
          <el-table-column label="积分" width="90">
            <template #default="{ row }">{{ row.points }} 分</template>
          </el-table-column>
          <el-table-column prop="qty" label="数量" width="70" />
          <el-table-column label="收货信息" min-width="210" show-overflow-tooltip>
            <template #default="{ row }">
              {{ row.contact }} {{ row.contactPhone }}<span v-if="row.address"> · {{ row.address }}</span>
            </template>
          </el-table-column>
          <el-table-column label="状态" width="100">
            <template #default="{ row }">
              <el-tag size="small" :type="statusType(row.status)">{{ row.status }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="物流 / 备注" min-width="140" show-overflow-tooltip>
            <template #default="{ row }">{{ row.expressNo || row.remark || '-' }}</template>
          </el-table-column>
          <el-table-column prop="at" label="兑换时间" min-width="150" />
          <el-table-column label="操作" width="180" fixed="right">
            <template #default="{ row }">
              <el-button v-if="row.status === '待发货'" link type="primary" size="small" @click="handleShip(row)">发货</el-button>
              <el-button v-if="row.status === '已发货'" link type="success" size="small" @click="handleComplete(row)">完成</el-button>
              <el-button v-if="row.status === '待发货'" link type="danger" size="small" @click="handleCancel(row)">取消</el-button>
              <span v-if="row.status === '已完成' || row.status === '已取消'" class="op-none">—</span>
            </template>
          </el-table-column>
        </el-table>
      </div>
      <div class="pagination-bar">
        <el-pagination v-model:current-page="page" :page-size="pageSize"
          :total="total" layout="total, prev, pager, next" background size="small" />
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, reactive, ref, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Search } from '@element-plus/icons-vue'
import { useCrud } from '../composables/useCrud'
import { shipGiftExchange, completeGiftExchange, cancelGiftExchange } from '../api/finance'

const loading = computed(() => crud.state.loading)
const query = reactive({ keyword: '', status: '' })
const page = ref(1)
const pageSize = 10
const crud = useCrud('gift-exchanges', { keywordField: ['exchange_no', 'member', 'gift_name', 'contact'] })
const total = computed(() => crud.state.total)
const rows = computed(() => crud.state.rows)
const pendingCount = computed(() => rows.value.filter((r) => r.status === '待发货').length)

async function reload() {
  await crud.load({ ...query, page: page.value, per_page: pageSize })
}

function statusType(s) {
  return { 待发货: 'warning', 已发货: 'primary', 已完成: 'success', 已取消: 'info' }[s] || 'info'
}

function handleSearch() {
  page.value = 1
  reload()
}

/** 发货（物流单号可留空 = 到店自提） */
async function handleShip(row) {
  try {
    const { value } = await ElMessageBox.prompt('请填写物流单号（到店自提可留空）', '发货', {
      confirmButtonText: '确认发货', cancelButtonText: '取消', inputPlaceholder: '如：SF1234567890'
    })
    await shipGiftExchange(row.id, { express_no: (value || '').trim() })
    ElMessage.success('已发货')
    reload()
  } catch (e) {
    if (e !== 'cancel' && e !== 'close') ElMessage.error(e?.message || '发货失败')
  }
}

/** 完成（会员已收货） */
async function handleComplete(row) {
  try {
    await ElMessageBox.confirm(`确认兑换单「${row.exchangeNo}」已完成（会员已收货）？`, '完成兑换单', {
      type: 'info', confirmButtonText: '确认完成', cancelButtonText: '取消'
    })
  } catch (e) {
    return
  }
  try {
    await completeGiftExchange(row.id)
    ElMessage.success('已完成')
    reload()
  } catch (e) {
    ElMessage.error(e?.message || '操作失败')
  }
}

/** 取消（退回积分 + 恢复库存） */
async function handleCancel(row) {
  try {
    const { value } = await ElMessageBox.prompt(
      `取消后将退回 ${row.points} 积分并恢复库存，请填写取消原因`, '取消兑换单', {
        confirmButtonText: '确认取消', cancelButtonText: '返回', inputPlaceholder: '如：库存不足 / 会员协商取消',
        inputValidator: (v) => (v && v.trim() ? true : '取消原因不能为空')
      })
    await cancelGiftExchange(row.id, value.trim())
    ElMessage.success('已取消并退回积分')
    reload()
  } catch (e) {
    if (e !== 'cancel' && e !== 'close') ElMessage.error(e?.message || '取消失败')
  }
}

onMounted(() => {
  reload()
})
</script>

<style scoped>
.pending-tip {
  color: var(--warning, #e6a23c);
  font-size: var(--font-size-body);
  font-weight: 600;
}
.op-none {
  color: var(--text-tertiary);
}
</style>
