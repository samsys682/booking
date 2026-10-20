<template>
  <div class="content">
    <div class="content-card content-card--list">
      <el-tabs v-model="tab" class="sub-tabs">
        <el-tab-pane label="消息模板" name="templates">
          <div class="action-bar">
            <h2 class="page-title">消息模板</h2>
            <div class="action-bar__left">
              <el-input v-model="tplQuery.keyword" placeholder="模板名称 / 触发场景" clearable style="width: 200px"
                @clear="tplPage = 1" @keyup.enter="tplPage = 1">
                <template #prefix><el-icon><Search /></el-icon></template>
              </el-input>
              <el-select v-model="tplQuery.channel" placeholder="发送通道" clearable style="width: 160px" @change="tplPage = 1">
                <el-option label="小程序订阅消息" value="小程序订阅消息" />
                <el-option label="公众号模板消息" value="公众号模板消息" />
              </el-select>
            </div>
            <div class="action-bar__right">
              <el-button type="primary" :icon="Plus" @click="openCreate">新建模板</el-button>
            </div>
          </div>
          <div class="table-wrap" v-loading="loading">
            <el-table :data="pagedTemplates" empty-text="暂无消息模板">
              <el-table-column prop="name" label="模板名称" min-width="160" />
              <el-table-column label="通道" width="140">
                <template #default="{ row }">
                  <el-tag size="small" :type="row.channel === '小程序订阅消息' ? 'success' : 'primary'">{{ row.channel }}</el-tag>
                </template>
              </el-table-column>
              <el-table-column prop="trigger" label="触发场景" min-width="140" show-overflow-tooltip />
              <el-table-column label="业务" width="80">
                <template #default="{ row }">
                  <el-tag size="small" type="info">{{ row.scene }}</el-tag>
                </template>
              </el-table-column>
              <el-table-column prop="sent" label="已发送" width="80" />
              <el-table-column prop="content" label="模板内容" min-width="220" show-overflow-tooltip />
              <el-table-column label="状态" width="90">
                <template #default="{ row }">
                  <el-switch v-model="row.status" @change="handleToggle(row)" />
                </template>
              </el-table-column>
              <el-table-column label="操作" width="140" fixed="right">
                <template #default="{ row }">
                  <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
                  <el-button link type="danger" size="small" @click="handleDelete(row)">删除</el-button>
                </template>
              </el-table-column>
            </el-table>
            <el-empty v-if="!loading && filteredTemplates.length === 0" description="暂无消息模板">
              <el-button type="primary" @click="openCreate">新建模板</el-button>
            </el-empty>
          </div>
          <div class="pagination-bar">
            <el-pagination v-model:current-page="tplPage" :page-size="pageSize"
              :total="filteredTemplates.length" layout="total, prev, pager, next" background size="small" />
          </div>
        </el-tab-pane>

        <el-tab-pane label="发送记录" name="logs">
          <div class="action-bar">
            <h2 class="page-title">发送记录</h2>
            <div class="action-bar__left">
              <el-input v-model="logQuery.keyword" placeholder="模板 / 接收人 / 订单号" clearable style="width: 200px"
                @clear="logPage = 1" @keyup.enter="logPage = 1">
                <template #prefix><el-icon><Search /></el-icon></template>
              </el-input>
              <el-select v-model="logQuery.status" placeholder="发送状态" clearable style="width: 120px" @change="logPage = 1">
                <el-option label="成功" value="成功" />
                <el-option label="跳过" value="跳过" />
                <el-option label="失败" value="失败" />
              </el-select>
            </div>
            <div class="action-bar__right">
              <el-button :icon="Search" @click="logPage = 1">查询</el-button>
            </div>
          </div>
          <div class="table-wrap" v-loading="loading">
            <el-table :data="pagedLogs" empty-text="暂无发送记录">
              <el-table-column prop="at" label="时间" min-width="150" />
              <el-table-column prop="template" label="模板" min-width="160" show-overflow-tooltip />
              <el-table-column label="通道" width="140">
                <template #default="{ row }">
                  <el-tag size="small" :type="row.channel === '小程序订阅消息' ? 'success' : 'primary'">{{ row.channel }}</el-tag>
                </template>
              </el-table-column>
              <el-table-column prop="target" label="接收人" min-width="90" />
              <el-table-column prop="order" label="关联订单" min-width="150">
                <template #default="{ row }">{{ row.order || '-' }}</template>
              </el-table-column>
              <el-table-column label="状态" width="90">
                <template #default="{ row }">
                  <el-tag size="small" :type="row.status === '成功' ? 'success' : (row.status === '跳过' ? 'warning' : 'danger')">{{ row.status }}</el-tag>
                </template>
              </el-table-column>
              <el-table-column prop="reason" label="跳过/失败原因" min-width="160" show-overflow-tooltip>
                <template #default="{ row }">{{ row.reason || '-' }}</template>
              </el-table-column>
              <el-table-column prop="ext" label="微信回执" min-width="140" show-overflow-tooltip>
                <template #default="{ row }">{{ row.ext || '-' }}</template>
              </el-table-column>
            </el-table>
            <el-empty v-if="!loading && filteredLogs.length === 0" description="暂无发送记录" />
          </div>
          <div class="pagination-bar">
            <el-pagination v-model:current-page="logPage" :page-size="pageSize"
              :total="filteredLogs.length" layout="total, prev, pager, next" background size="small" />
          </div>
        </el-tab-pane>

        <el-tab-pane label="微信接入" name="wechat">
          <div class="action-bar">
            <h2 class="page-title">微信消息接入</h2>
            <div class="action-bar__right">
              <el-button :icon="Search" :loading="notifyTesting" @click="handleNotifyTest">链路自检</el-button>
              <el-button type="primary" @click="handleNotifySave">保存配置</el-button>
            </div>
          </div>
          <div class="notify-card">
            <el-alert
              :title="notify.configured ? '微信推送已接入（真实发送）' : '未接入微信（当前为日志模式：消息只记录不外发）'"
              :type="notify.configured ? 'success' : 'info'" :closable="false" show-icon />
            <el-form label-position="top" style="margin-top: 16px">
              <el-row :gutter="16">
                <el-col :span="12">
                  <el-form-item label="AppID">
                    <el-input v-model="notify.appid" placeholder="小程序 / 公众号 AppID" maxlength="64" />
                  </el-form-item>
                </el-col>
                <el-col :span="12">
                  <el-form-item label="AppSecret">
                    <el-input v-model="notify.secret" type="password" show-password
                      :placeholder="notify.has_secret ? '已配置（留空则不修改）' : 'AppSecret（加密存储，不回显）'" maxlength="128" />
                  </el-form-item>
                </el-col>
                <el-col :span="12">
                  <el-form-item label="接口网关">
                    <el-input v-model="notify.gateway" placeholder="https://api.weixin.qq.com" maxlength="200" />
                  </el-form-item>
                </el-col>
                <el-col :span="12">
                  <el-form-item label="小程序跳转页面">
                    <el-input v-model="notify.mini_page" placeholder="pages/index/index" maxlength="120" />
                  </el-form-item>
                </el-col>
                <el-col :span="12">
                  <el-form-item label="公众号跳转链接">
                    <el-input v-model="notify.mp_url" placeholder="https://..." maxlength="200" />
                  </el-form-item>
                </el-col>
              </el-row>
              <span class="form-tip">配置完整后，消息发送将调用微信「小程序订阅消息 / 公众号模板消息」接口；会员需已绑定 openid（会员档案 → 微信 openid）。</span>
            </el-form>
          </div>
        </el-tab-pane>
      </el-tabs>
    </div>

    <el-dialog v-model="formVisible" :title="editing ? '编辑模板' : '新建模板'" width="640px" class="list-dialog">
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top">
        <div class="form-section">模板信息</div>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="模板名称" prop="name">
              <el-input v-model="form.name" placeholder="如：预约成功通知" maxlength="20" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="发送通道" prop="channel">
              <el-select v-model="form.channel" style="width: 100%">
                <el-option label="小程序订阅消息" value="小程序订阅消息" />
                <el-option label="公众号模板消息" value="公众号模板消息" />
              </el-select>
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="触发场景" prop="trigger">
              <el-input v-model="form.trigger" placeholder="如：订单创建成功" maxlength="20" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="业务分类" prop="scene">
              <el-radio-group v-model="form.scene">
                <el-radio-button label="订单">订单</el-radio-button>
                <el-radio-button label="营销">营销</el-radio-button>
              </el-radio-group>
            </el-form-item>
          </el-col>
        </el-row>
        <el-form-item label="模板内容" prop="content">
          <el-input v-model="form.content" type="textarea" :rows="3" placeholder="如：您的预约已确认：{{服务项目}}，{{服务时间}}。" maxlength="200" />
          <span class="form-tip">变量用 {{ varPattern }} 占位，发送时由订单数据填充；短信通道按产品决策全线不提供，仅支持微信两通道。</span>
        </el-form-item>
        <div class="form-section">微信推送（真实发送）</div>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="微信模板 ID">
              <el-input v-model="form.wx_template_id" placeholder="公众平台申请的模板 ID（空=仅记录不外发）" maxlength="64" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="字段映射（JSON）">
              <el-input v-model="form.wx_data_map" placeholder='{"thing1":"服务项目","time2":"服务时间"}' maxlength="500" />
            </el-form-item>
          </el-col>
        </el-row>
        <span class="form-tip">字段映射把「平台模板字段名 → 本系统变量名」对上，才能组装微信 data 报文；未配置微信参数时按日志模式记录（不报错）。</span>
        <div class="form-section">状态</div>
        <el-form-item label="启用状态">
          <el-switch v-model="form.status" />
          <span class="form-tip">停用后不再发送该模板消息；微信通知不限条数。</span>
        </el-form-item>
      </el-form>
      <template #footer>
        <div class="modal-footer">
          <el-button @click="formVisible = false">取消</el-button>
          <el-button type="primary" @click="handleSave">保存</el-button>
        </div>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { computed, reactive, ref, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Search, Plus } from '@element-plus/icons-vue'
import { useCrud } from '../composables/useCrud'
import { fetchNotifyConfig, saveNotifyConfig, testNotify } from '../api/notify'

// 消息模板 / 发送记录接线（API 优先，内存 mock 兜底）
// 发送记录只读；view 字段 order ↔ 列 order_no 走 fieldMap
const crudT = useCrud('message-templates', {
  keywordField: ['name', 'trigger', 'scene', 'content']
})
const crudL = useCrud('message-send-logs', {
  keywordField: ['template', 'target', 'order', 'reason'],
  fieldMap: { order: 'order_no' },
  mapRow: (r) => {
    const out = { ...r }
    out._rid = out.id
    return out
  }
})
const ridOf = (row) => row._rid ?? row.id
const editRid = ref(null)

const loading = ref(true)
const tab = ref('templates')
const pageSize = 10
const tplPage = ref(1)
const logPage = ref(1)
const formVisible = ref(false)
const editing = ref(false)
const formRef = ref()

const tplQuery = reactive({ keyword: '', channel: '' })
const logQuery = reactive({ keyword: '', status: '' })

const emptyForm = {
  id: '', name: '', channel: '小程序订阅消息', trigger: '', scene: '订单',
  sent: 0, status: true, content: '', wx_template_id: '', wx_data_map: ''
}
const form = reactive({ ...emptyForm })

const rules = {
  name: [{ required: true, message: '请输入模板名称', trigger: 'blur' }],
  channel: [{ required: true, message: '请选择发送通道', trigger: 'change' }],
  trigger: [{ required: true, message: '请输入触发场景', trigger: 'blur' }],
  content: [{ required: true, message: '请输入模板内容', trigger: 'blur' }]
}

const varPattern = '{{变量名}}'

const filteredTemplates = computed(() => {
  const kw = tplQuery.keyword.trim().toLowerCase()
  return crudT.state.rows.filter((r) => {
    const matchKw = !kw || r.name.toLowerCase().includes(kw) || r.trigger.toLowerCase().includes(kw)
    const matchChannel = !tplQuery.channel || r.channel === tplQuery.channel
    return matchKw && matchChannel
  })
})
const pagedTemplates = computed(() => {
  const start = (tplPage.value - 1) * pageSize
  return filteredTemplates.value.slice(start, start + pageSize)
})

const filteredLogs = computed(() => {
  const kw = logQuery.keyword.trim().toLowerCase()
  return crudL.state.rows.filter((l) => {
    const matchKw = !kw || l.template.toLowerCase().includes(kw) || l.target.toLowerCase().includes(kw) || (l.order || '').toLowerCase().includes(kw)
    const matchStatus = !logQuery.status || l.status === logQuery.status
    return matchKw && matchStatus
  })
})
const pagedLogs = computed(() => {
  const start = (logPage.value - 1) * pageSize
  return filteredLogs.value.slice(start, start + pageSize)
})

function openCreate() {
  editing.value = false
  Object.assign(form, emptyForm)
  formVisible.value = true
  formRef.value?.clearValidate()
}

function openEdit(row) {
  editing.value = true
  editRid.value = ridOf(row)
  Object.assign(form, row)
  formVisible.value = true
  formRef.value?.clearValidate()
}

async function handleSave() {
  const valid = await formRef.value.validate().catch(() => false)
  if (!valid) return
  // 显式挑字段（openEdit 会把 _rid/__mockId 带进 form）
  const payload = {
    name: form.name, channel: form.channel, trigger: form.trigger, scene: form.scene,
    content: form.content, sent: form.sent ?? 0, status: form.status,
    wx_template_id: form.wx_template_id || '', wx_data_map: form.wx_data_map || ''
  }
  await crudT.save(editing.value ? editRid.value : null, payload)
  await crudT.load({ page: 1, per_page: 200 })
  ElMessage.success(editing.value ? '保存成功' : '新建成功')
  formVisible.value = false
}

async function handleToggle(row) {
  await crudT.patch(ridOf(row), { status: row.status })
  ElMessage.success(`「${row.name}」已${row.status ? '启用' : '停用'}`)
}

function handleDelete(row) {
  ElMessageBox.confirm(`确认删除模板「${row.name}」吗？发送记录会保留。`, '删除模板', {
    type: 'warning', confirmButtonText: '确认删除', cancelButtonText: '取消'
  }).then(async () => {
    await crudT.remove(ridOf(row))
    await crudT.load({ page: 1, per_page: 200 })
    ElMessage.success('删除成功')
  }).catch(() => {})
}

// ---- 微信接入配置 ----
const notify = reactive({
  appid: '', secret: '', gateway: '', mini_page: '', mp_url: '',
  has_secret: false, configured: false
})
const notifyTesting = ref(false)

async function loadNotify() {
  try {
    const r = await fetchNotifyConfig()
    Object.assign(notify, {
      appid: r.appid || '',
      secret: '',
      gateway: r.gateway || '',
      mini_page: r.mini_page || '',
      mp_url: r.mp_url || '',
      has_secret: !!r.has_secret,
      configured: !!r.configured
    })
  } catch (e) { /* 后端不可达静默 */ }
}

async function handleNotifySave() {
  // secret 留空 = 不修改（后端约定：缺省保持原值）
  const payload = {
    appid: notify.appid,
    gateway: notify.gateway,
    mini_page: notify.mini_page,
    mp_url: notify.mp_url
  }
  if (notify.secret !== '') payload.secret = notify.secret
  try {
    const r = await saveNotifyConfig(payload)
    Object.assign(notify, { has_secret: !!r.has_secret, configured: !!r.configured, secret: '' })
    ElMessage.success('配置已保存')
  } catch (e) {
    ElMessage.error(e.message || '保存失败')
  }
}

function handleNotifyTest() {
  notifyTesting.value = true
  testNotify().then((r) => {
    if (r.ok) ElMessage.success(r.detail || '链路通畅')
    else ElMessage.warning(r.detail || '未接入微信')
  }).catch((e) => {
    ElMessage.error(e.message || '自检失败')
  }).finally(() => { notifyTesting.value = false })
}

onMounted(async () => {
  try {
    await Promise.all([crudT.load({ page: 1, per_page: 200 }), crudL.load({ page: 1, per_page: 200 })])
  } catch (e) {
    console.warn('[message-templates]', e && e.message)
  } finally {
    loading.value = false
  }
  loadNotify()
})
</script>

<style scoped>
.form-section {
  font-size: var(--font-size-heading);
  font-weight: 600;
  color: var(--text-primary);
  margin: 0 0 var(--space-sm);
  padding-left: 8px;
  border-left: 3px solid var(--primary);
  line-height: 1.2;
}
.form-tip {
  width: 100%;
  flex-basis: 100%;
  color: var(--text-tertiary);
  font-size: var(--font-size-body);
  line-height: 1.6;
  margin-top: 4px;
}
.notify-card {
  padding: var(--space-md);
  background: var(--bg-page);
  border-radius: var(--radius-sm);
}
:deep(.list-dialog) {
  margin-top: 4vh;
}
:deep(.list-dialog .el-dialog__body) {
  max-height: calc(88vh - 110px);
  overflow-y: auto;
  overflow-x: hidden;
  padding-top: var(--space-sm);
}
</style>
