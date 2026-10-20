<template>
  <div class="content">
    <div class="content-card content-card--list">
      <div class="action-bar">
        <h2 class="page-title">消息通知</h2>
        <div class="action-bar__left">
          <span class="page-subtitle">微信消息通道（不提供短信通道）；模板开关 / 内容 / 触发点可配置</span>
        </div>
        <div class="action-bar__right">
          <el-button type="primary" @click="openResend">手工补发</el-button>
          <el-button @click="reloadAll">刷新</el-button>
        </div>
      </div>

      <!-- 通道状态（setting notify 组，改在「系统设置 → 消息通知」） -->
      <div class="channel-bar">
        <el-tag v-for="ch in CHANNELS" :key="ch" :type="channelOn(ch) ? 'success' : 'info'" size="small" effect="plain">
          {{ ch }}：{{ channelOn(ch) ? '开启' : '关闭' }}
        </el-tag>
        <span class="page-subtitle">通道开关在「系统设置 → 消息通知」调整；关闭时发送记录会记为「跳过」</span>
      </div>

      <div class="table-wrap">
        <el-table v-loading="tplCrud.state.loading" :data="tplCrud.state.rows" empty-text="暂无模板">
          <el-table-column prop="name" label="通知模板" min-width="150" />
          <el-table-column prop="channel" label="通道" width="140" />
          <el-table-column prop="trigger" label="触发点" width="130" />
          <el-table-column prop="scene" label="场景" width="80" />
          <el-table-column prop="sent" label="已发送" width="80" />
          <el-table-column label="启用" width="80">
            <template #default="{ row }">
              <el-switch :model-value="row.status === true" @change="(v) => toggle(row, v)" />
            </template>
          </el-table-column>
          <el-table-column label="操作" width="140" fixed="right">
            <template #default="{ row }">
              <el-button type="primary" link size="small" @click="openEdit(row)">编辑</el-button>
              <el-button type="primary" link size="small" @click="openPreview(row)">预览</el-button>
            </template>
          </el-table-column>
        </el-table>
      </div>
    </div>

    <div class="content-card content-card--list" style="margin-top: var(--space-md)">
      <div class="action-bar">
        <h2 class="page-title">发送记录</h2>
        <div class="action-bar__left">
          <el-input v-model="logKeyword" placeholder="搜索模板 / 接收人 / 订单号" clearable style="width: 260px" @keyup.enter="reloadLogs" @clear="reloadLogs" />
        </div>
        <div class="action-bar__right">
          <el-select v-model="logStatus" placeholder="全部结果" clearable style="width: 130px" @change="reloadLogs">
            <el-option label="成功" value="成功" />
            <el-option label="跳过" value="跳过" />
            <el-option label="失败" value="失败" />
          </el-select>
        </div>
      </div>
      <div class="table-wrap">
        <el-table v-loading="logCrud.state.loading" :data="logCrud.state.rows" empty-text="暂无记录">
          <el-table-column prop="at" label="时间" width="150" />
          <el-table-column prop="template" label="模板" width="150" />
          <el-table-column prop="channel" label="通道" width="140" />
          <el-table-column prop="target" label="接收人" width="120" />
          <el-table-column prop="orderNo" label="订单号" width="150" />
          <el-table-column label="结果" width="80">
            <template #default="{ row }">
              <el-tag :type="logTagType(row.status)" size="small">{{ row.status }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column prop="content" label="发送内容" min-width="220" show-overflow-tooltip />
          <el-table-column prop="reason" label="说明" min-width="160" show-overflow-tooltip />
        </el-table>
      </div>
      <div class="pager">
        <el-pagination
          v-model:current-page="logCrud.state.page"
          :page-size="logCrud.state.pageSize"
          :total="logCrud.state.total"
          layout="total, prev, pager, next"
          @current-change="reloadLogs"
        />
      </div>
    </div>

    <!-- 编辑模板 -->
    <el-dialog v-model="editVisible" title="编辑通知模板" width="560px">
      <el-form label-width="90px">
        <el-form-item label="模板名称"><el-input v-model="editForm.name" disabled /></el-form-item>
        <el-form-item label="通道"><el-input v-model="editForm.channel" disabled /></el-form-item>
        <el-form-item label="触发点"><el-input v-model="editForm.trigger" placeholder="须与后端触发点一致，如 订单创建成功" /></el-form-item>
        <el-form-item label="场景">
          <el-select v-model="editForm.scene" style="width: 100%">
            <el-option label="订单" value="订单" />
            <el-option label="营销" value="营销" />
          </el-select>
        </el-form-item>
        <el-form-item label="消息内容">
          <el-input v-model="editForm.content" type="textarea" :rows="3" placeholder="支持占位符：{{订单号}} {{服务项目}} {{服务时间}} {{技师姓名}} {{客户姓名}} {{订单金额}} {{门店名称}} {{客服电话}}" />
        </el-form-item>
        <el-form-item label="可用占位符">
          <div class="placeholder-tips">
            <el-tag v-for="p in PLACEHOLDERS" :key="p" size="small" effect="plain" class="placeholder-tag">{{ p }}</el-tag>
          </div>
        </el-form-item>
      </el-form>
      <template #footer>
        <div class="modal-footer">
          <el-button @click="editVisible = false">取消</el-button>
          <el-button type="primary" @click="saveEdit">保存</el-button>
        </div>
      </template>
    </el-dialog>

    <!-- 渲染预览 -->
    <el-dialog v-model="previewVisible" title="模板渲染预览" width="560px">
      <el-form label-width="90px">
        <el-form-item label="模板">{{ previewData.name }}</el-form-item>
        <el-form-item label="原始内容"><div class="preview-box">{{ previewData.raw }}</div></el-form-item>
        <el-form-item label="订单号">
          <el-input v-model="previewOrderNo" placeholder="可选：填订单号以带入真实变量" @keyup.enter="doPreview" />
        </el-form-item>
        <el-form-item label="渲染结果">
          <div class="preview-box preview-box--hl">{{ previewData.rendered || '（请填写订单号后回车）' }}</div>
        </el-form-item>
        <el-form-item label="提示">
          <span class="page-subtitle">未命中的占位符会原样保留，便于发现漏配</span>
        </el-form-item>
      </el-form>
      <template #footer>
        <div class="modal-footer">
          <el-button type="primary" @click="doPreview">按订单号渲染</el-button>
          <el-button @click="previewVisible = false">关闭</el-button>
        </div>
      </template>
    </el-dialog>

    <!-- 手工补发 -->
    <el-dialog v-model="resendVisible" title="手工补发消息" width="520px">
      <el-form label-width="90px">
        <el-form-item label="触发点">
          <el-select v-model="resendForm.trigger" style="width: 100%" placeholder="选择要补发的触发点">
            <el-option
              v-for="t in triggers"
              :key="t.id"
              :label="`${t.name}（${t.trigger}）`"
              :value="t.trigger"
              :disabled="!t.available"
            >
              <span>{{ t.name }}（{{ t.trigger }}）</span>
              <span class="opt-suffix">{{ t.available ? t.channel : '通道关闭/已停用' }}</span>
            </el-option>
          </el-select>
        </el-form-item>
        <el-form-item label="订单号">
          <el-input v-model="resendForm.orderNo" placeholder="可选：填订单号以带入真实变量" />
        </el-form-item>
      </el-form>
      <template #footer>
        <div class="modal-footer">
          <el-button @click="resendVisible = false">取消</el-button>
          <el-button type="primary" :loading="resending" @click="doResend">发送</el-button>
        </div>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
// 消息通知：模板（message-templates 资源）+ 发送记录（message-send-logs 资源）
// + 触发点/通道状态（/messages/triggers）+ 渲染预览与手工补发（/messages/preview|send）
// 自动发送由后端 MessageService 在「下单 / 技师接单 / 确认完成」触发
import { ref, reactive, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { useCrud } from '../composables/useCrud'
import { request } from '../api/http'

const CHANNELS = ['小程序订阅消息', '公众号模板消息']
const PLACEHOLDERS = ['{{订单号}}', '{{服务项目}}', '{{服务时间}}', '{{技师姓名}}', '{{客户姓名}}', '{{订单金额}}', '{{门店名称}}', '{{客服电话}}']

const tplCrud = useCrud('message-templates', { keywordField: ['name', 'trigger', 'scene'] })
const logCrud = useCrud('message-send-logs', { keywordField: ['template', 'target', 'orderNo'] })

const logKeyword = ref('')
const logStatus = ref('')
const triggers = ref([])

// ---------- 加载 ----------
async function loadTriggers() {
  try {
    const res = await request('/messages/triggers')
    triggers.value = res.list || []
  } catch (e) {
    console.warn('[messages/triggers]', e && e.message)
  }
}

function channelOn(channel) {
  const t = triggers.value.find((x) => x.channel === channel)
  return t ? !!t.channelEnabled : true
}

function reloadTemplates() {
  tplCrud.state.pageSize = 100
  return tplCrud.load({ page: 1, per_page: 100 })
}

function reloadLogs() {
  return logCrud.load({
    page: logCrud.state.page,
    per_page: logCrud.state.pageSize,
    keyword: logKeyword.value,
    status: logStatus.value
  })
}

async function reloadAll() {
  await Promise.all([loadTriggers(), reloadTemplates(), reloadLogs()])
}

onMounted(reloadAll)

// ---------- 模板开关 / 编辑 ----------
async function toggle(row, val) {
  try {
    await tplCrud.patch(row.id, { status: val })
    ElMessage.success(`「${row.name}」已${val ? '启用' : '停用'}`)
    reloadTemplates()
  } catch (e) {
    ElMessage.error('切换失败：' + ((e && e.message) || '未知原因'))
    reloadTemplates()
  }
}

const editVisible = ref(false)
const editForm = reactive({ id: null, name: '', channel: '', trigger: '', scene: '订单', content: '' })

function openEdit(row) {
  Object.assign(editForm, {
    id: row.id,
    name: row.name,
    channel: row.channel,
    trigger: row.trigger,
    scene: row.scene,
    content: row.content
  })
  editVisible.value = true
}

async function saveEdit() {
  if (!editForm.trigger) {
    ElMessage.warning('触发点不能为空')
    return
  }
  try {
    await tplCrud.save(editForm.id, {
      trigger: editForm.trigger,
      scene: editForm.scene,
      content: editForm.content
    })
    editVisible.value = false
    ElMessage.success('模板已保存')
    reloadTemplates()
  } catch (e) {
    ElMessage.error('保存失败：' + ((e && e.message) || '未知原因'))
  }
}

// ---------- 预览 ----------
const previewVisible = ref(false)
const previewOrderNo = ref('')
const previewData = reactive({ name: '', raw: '', rendered: '', trigger: '' })

async function openPreview(row) {
  previewData.name = row.name
  previewData.trigger = row.trigger
  previewData.raw = row.content
  previewData.rendered = ''
  previewOrderNo.value = ''
  previewVisible.value = true
  await doPreview()
}

async function doPreview() {
  if (!previewData.trigger) return
  try {
    const res = await request('/messages/preview', {
      query: { trigger: previewData.trigger, order_no: previewOrderNo.value }
    })
    previewData.raw = res.raw
    previewData.rendered = res.rendered
  } catch (e) {
    ElMessage.error('预览失败：' + ((e && e.message) || '未知原因'))
  }
}

// ---------- 手工补发 ----------
const resendVisible = ref(false)
const resending = ref(false)
const resendForm = reactive({ trigger: '', orderNo: '' })

function openResend() {
  resendForm.trigger = ''
  resendForm.orderNo = ''
  resendVisible.value = true
}

async function doResend() {
  if (!resendForm.trigger) {
    ElMessage.warning('请选择触发点')
    return
  }
  resending.value = true
  try {
    const res = await request('/messages/send', {
      method: 'POST',
      body: { trigger: resendForm.trigger, order_no: resendForm.orderNo }
    })
    if (res && res.sent) {
      ElMessage.success('已发送')
      resendVisible.value = false
    } else {
      // 通道关闭 / 未配模板 / 超配额属可预期业务结果，给出明确原因
      ElMessage.warning('未发送：' + ((res && res.reason) || '未知原因'))
    }
    reloadLogs()
  } catch (e) {
    ElMessage.error('发送失败：' + ((e && e.message) || '未知原因'))
  } finally {
    resending.value = false
  }
}

function logTagType(status) {
  if (status === '成功') return 'success'
  if (status === '跳过') return 'warning'
  return 'danger'
}
</script>

<style scoped>
.page-subtitle {
  color: var(--text-tertiary);
}
.channel-bar {
  display: flex;
  align-items: center;
  gap: var(--space-sm);
  padding: 0 var(--space-md) var(--space-sm);
  flex-wrap: wrap;
}
.pager {
  display: flex;
  justify-content: flex-end;
  padding: var(--space-sm) var(--space-md);
}
.placeholder-tips {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.placeholder-tag {
  font-family: monospace;
}
.preview-box {
  width: 100%;
  padding: 8px 10px;
  border: 1px solid var(--border-color, #dcdfe6);
  border-radius: 4px;
  background: var(--bg-page, #f7f8fa);
  white-space: pre-wrap;
  word-break: break-all;
  line-height: 1.6;
}
.preview-box--hl {
  color: var(--color-primary, #409eff);
}
.opt-suffix {
  float: right;
  color: var(--text-tertiary);
  font-size: 12px;
}
</style>
