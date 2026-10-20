<template>
  <div class="content">
    <!-- 版本与授权状态（附一 P0：后台可见；v1.14 OA 真实验卡制） -->
    <div class="content-card">
      <div class="panel-header">版本与授权</div>
      <div class="license-row">
        <span class="version-badge">
          <el-tag size="small" :type="license.tagType" effect="plain">{{ license.edition }}</el-tag>
          <span>授权状态：{{ license.status }}</span>
          <span v-if="license.isPro" class="license-meta">末次验卡：{{ license.lastVerifiedAt || '—' }}</span>
        </span>
        <span class="license-actions">
          <el-button v-if="license.isPro" size="small" :loading="reverifying" @click="doReverify">续验</el-button>
          <el-button size="small" :loading="smokeLoading" @click="doSmoke">发卡自检</el-button>
          <el-button size="small" plain @click="activateVisible = !activateVisible">{{ license.isPro ? '换卡激活' : '卡密激活' }}</el-button>
          <el-button v-if="license.editionKey !== 'src'" size="small" type="primary" plain @click="showUpgrade">{{ license.isPro ? '升级源码版' : '升级授权版' }}</el-button>
        </span>
      </div>
      <!-- 卡密激活表单（v1.14）：card_key 仅本次请求，不落存储；后端对接 OA verify 绑定设备 -->
      <el-form v-if="activateVisible" inline class="activate-form" @submit.prevent="doActivate">
        <el-input v-model="activateForm.cardNo" placeholder="卡号（平台发货后提供）" style="width: 220px" clearable />
        <el-input v-model="activateForm.cardKey" type="password" show-password placeholder="卡密" style="width: 200px" clearable />
        <el-button type="primary" :loading="activating" @click="doActivate">激活</el-button>
        <el-button @click="activateVisible = false">收起</el-button>
      </el-form>
      <div class="limit-grid">
        <div v-for="item in limitItems" :key="item.label" class="limit-item">
          <div class="limit-item__label">{{ item.label }}</div>
          <el-progress
            v-if="item.max !== null"
            :percentage="Math.round((item.used / item.max) * 100)"
            :status="item.used / item.max >= 0.8 ? 'warning' : 'success'"
            :stroke-width="8"
          />
          <div v-else class="limit-item__unlimited">不限量</div>
          <div class="limit-item__text">{{ item.used }} / {{ item.max === null ? '不限' : item.max }}</div>
        </div>
      </div>
    </div>

    <!-- 门店信息 + 预约规则（两个面板一行显示） -->
    <div class="settings-row">
      <div class="content-card">
        <div class="panel-header">门店信息</div>
        <el-form :model="storeForm" label-width="90px">
          <el-form-item label="门店名称">
            <el-input v-model="storeForm.name" />
          </el-form-item>
          <el-form-item label="联系电话">
            <el-input v-model="storeForm.phone" />
          </el-form-item>
          <el-form-item label="门店地址">
            <el-input v-model="storeForm.address" type="textarea" :rows="2" />
          </el-form-item>
          <el-form-item label="营业时间">
            <el-time-picker v-model="storeForm.hours" is-range range-separator="至" start-placeholder="开始" end-placeholder="结束" style="width: 100%" />
          </el-form-item>
        </el-form>
      </div>

      <div class="content-card">
        <div class="panel-header">预约规则</div>
        <el-form :model="bookingForm" label-width="110px">
          <div class="rule-group">提前时长</div>
          <el-form-item label="最少提前">
            <el-input-number v-model="bookingForm.advanceHours" :min="0" :max="72" />
            <span class="form-unit">小时（下单到上门的最短间隔，镜像后端 assertBookingWindow）</span>
          </el-form-item>
          <el-form-item label="最远预约">
            <el-input-number v-model="bookingForm.maxAdvanceDays" :min="1" :max="90" />
            <span class="form-unit">天（超出不可下单/改约）</span>
          </el-form-item>
          <div class="rule-group">取消政策</div>
          <el-form-item label="免费取消截止">
            <el-input-number v-model="bookingForm.cancelLimitHours" :min="0" :max="72" />
            <span class="form-unit">小时前（距上门不足此时长取消，按违约金比例收取）</span>
          </el-form-item>
          <el-form-item label="违约金比例">
            <el-input-number v-model="bookingForm.cancelFeeRate" :min="0" :max="100" />
            <span class="form-unit">%（订单实付金额比例，镜像 OrderService::calcCancelFee）</span>
          </el-form-item>
          <div class="rule-group">爽约限制</div>
          <el-form-item label="爽约次数上限">
            <el-input-number v-model="bookingForm.noShowLimit" :min="1" :max="10" />
            <span class="form-unit">次（客户无故未到场累计达到后限制下单）</span>
          </el-form-item>
          <el-form-item label="限制下单">
            <el-input-number v-model="bookingForm.noShowFreezeDays" :min="1" :max="90" />
            <span class="form-unit">天（达到爽约上限后的冻结天数）</span>
          </el-form-item>
          <el-form-item label="启用候补排队">
            <el-switch v-model="bookingForm.waitlist" />
            <span class="form-unit">满约时允许客户排队候补</span>
          </el-form-item>
          <div class="rule-group">定金尾款</div>
          <el-form-item label="启用定金尾款">
            <el-switch v-model="bookingForm.depositEnabled" />
            <span class="form-unit">开启后下单先付定金，服务前后结清尾款（授权版功能）</span>
          </el-form-item>
          <el-form-item label="定金比例">
            <el-input-number v-model="bookingForm.depositRate" :min="1" :max="100" />
            <span class="form-unit">%（实付金额的百分比作为定金，余款为尾款；100=全额预付）</span>
          </el-form-item>
        </el-form>
      </div>
    </div>

    <!-- 消息通知（微信通道，2026-10-03 定案） -->
    <div class="content-card" style="margin-top: var(--space-md)">
      <div class="panel-header">消息通知</div>
      <el-form :model="notifyForm" label-width="110px" style="max-width: 520px">
        <el-form-item label="通知通道">
          <el-tag size="small" type="success" effect="plain">微信消息</el-tag>
          <span class="form-unit">公众号模板消息 / 小程序订阅消息，本系统不提供短信通道</span>
        </el-form-item>
        <el-form-item label="公众号通道">
          <el-switch v-model="notifyForm.mpEnabled" />
          <span class="form-unit">公众号模板消息通道开关（setting wechat_mp_enabled）</span>
        </el-form-item>
        <el-form-item label="小程序通道">
          <el-switch v-model="notifyForm.miniEnabled" />
          <span class="form-unit">小程序订阅消息通道开关（setting wechat_mini_enabled）</span>
        </el-form-item>
        <el-form-item label="日志保留">
          <el-input-number v-model="notifyForm.logKeepDays" :min="1" :max="365" />
          <span class="form-unit">天（操作日志/消息发送记录保留天数，setting log_keep_days）</span>
        </el-form-item>
        <el-form-item label="本月通知用量">
          <el-progress
            v-if="license.limits.wechatMessages.max !== null"
            :percentage="Math.round((license.limits.wechatMessages.used / license.limits.wechatMessages.max) * 100)"
            :status="license.limits.wechatMessages.used / license.limits.wechatMessages.max >= 0.8 ? 'warning' : 'success'"
            :stroke-width="8"
            style="width: 240px"
          />
          <span v-else class="form-unit">不限量</span>
          <span class="form-unit">{{ license.limits.wechatMessages.used }} / {{ license.limits.wechatMessages.max === null ? '不限' : license.limits.wechatMessages.max }} 条</span>
        </el-form-item>
      </el-form>
    </div>

    <!-- 账号安全（《功能矩阵》附四#7：密码策略 / 登录失败锁定 / 异地提醒；全版本可用） -->
    <div class="content-card" style="margin-top: var(--space-md)">
      <div class="panel-header">账号安全</div>
      <el-form :model="securityForm" label-width="130px" style="max-width: 560px">
        <el-divider content-position="left">密码策略</el-divider>
        <el-form-item label="密码最短长度">
          <el-input-number v-model="securityForm.pwdMinLength" :min="6" :max="32" />
          <span class="form-unit">位（新建账号 / 修改密码时校验，setting pwd_min_length）</span>
        </el-form-item>
        <el-form-item label="字符类别要求">
          <el-select v-model="securityForm.pwdCharTypes" style="width: 220px">
            <el-option :value="1" label="仅限制长度" />
            <el-option :value="2" label="至少 2 类（大小写/数字/符号）" />
            <el-option :value="3" label="至少 3 类" />
            <el-option :value="4" label="4 类全含" />
          </el-select>
          <span class="form-unit">密码需包含的字符种类数</span>
        </el-form-item>
        <el-divider content-position="left">登录失败保护</el-divider>
        <el-form-item label="失败锁定阈值">
          <el-input-number v-model="securityForm.loginLockThreshold" :min="0" :max="20" />
          <span class="form-unit">次连续失败后锁定账号（0=不启用，setting login_lock_threshold）</span>
        </el-form-item>
        <el-form-item label="锁定时长">
          <el-input-number v-model="securityForm.loginLockMinutes" :min="1" :max="1440" />
          <span class="form-unit">分钟</span>
        </el-form-item>
        <el-form-item label="异地登录提醒">
          <el-switch v-model="securityForm.loginRemoteAlert" />
          <span class="form-unit">IP 网段变化时在操作日志留痕提醒（不阻断登录）</span>
        </el-form-item>
      </el-form>
    </div>

    <!-- 站点与品牌（四杠杆 · 品牌权益）：站点信息三版本可改，品牌名/去版权为授权版功能 -->
    <div class="content-card" style="margin-top: var(--space-md)">
      <div class="panel-header">
        站点与品牌
        <el-tag v-if="site.canCustomBrand" size="small" type="success" effect="plain">授权版已解锁</el-tag>
        <el-tag v-else size="small" type="info" effect="plain">授权版功能</el-tag>
      </div>
      <el-form :model="brandForm" label-width="110px" style="max-width: 560px">
        <el-form-item label="站点名称">
          <el-input v-model="brandForm.siteName" placeholder="系统站点名称（后台 Logo 文案 / 登录页标题）" />
        </el-form-item>
        <el-form-item label="公司主体">
          <el-input v-model="brandForm.companyName" placeholder="发票抬头默认值" />
        </el-form-item>
        <el-form-item label="客服电话">
          <el-input v-model="brandForm.servicePhone" placeholder="消息通知模板中的「客服电话」变量" />
        </el-form-item>
        <el-form-item label="Logo 地址">
          <el-input v-model="brandForm.logo" placeholder="Logo 图片 URL（留空使用内置图标）" />
        </el-form-item>
        <el-divider content-position="left">品牌权益</el-divider>
        <el-form-item label="自定义品牌名">
          <el-input
            v-model="brandForm.brandName"
            :disabled="!site.canCustomBrand"
            :placeholder="site.canCustomBrand ? '留空则使用站点名称' : '授权版可自定义品牌名'"
          />
        </el-form-item>
        <el-form-item label="版权标识">
          <el-switch v-model="brandForm.poweredBy" :disabled="!site.canCustomBrand" />
          <span class="form-unit">{{ brandForm.poweredBy ? '保留 Powered by 版权链接（开源版强制）' : '已去除 Powered by 版权标识' }}</span>
        </el-form-item>
      </el-form>
      <div v-if="!site.canCustomBrand" class="locked-card" style="max-width: 520px">
        <div class="locked-card__title">
          <el-icon><Lock /></el-icon>
          自定义品牌（去除 Powered by 版权标识）
          <el-tag size="small" type="info" effect="plain">授权版功能</el-tag>
        </div>
        <div class="locked-card__desc">授权版可自定义后台品牌名称、Logo 与页脚版权，替换系统默认标识；开源版保留默认标识。</div>
        <el-button size="small" @click="showUpgrade">升级解锁</el-button>
      </div>
    </div>

    <!-- 在线支付（支付宝+微信，付费版功能，开源版不提供） -->
    <div class="content-card" style="margin-top: var(--space-md)">
      <div class="panel-header pay-header">
        在线支付
        <el-tag v-if="license.isPro" size="small" type="success" effect="plain">付费版已开通</el-tag>
        <el-tag v-else size="small" type="info" effect="plain">付费版功能</el-tag>
      </div>
      <template v-if="license.isPro">
        <el-form :model="payForm" label-width="130px" style="max-width: 680px">
          <el-divider content-position="left">支付宝</el-divider>
          <el-form-item label="启用支付宝">
            <el-switch v-model="payForm.alipay.enabled" />
            <span class="form-unit">电脑网站支付，客户跳转支付宝完成付款</span>
          </el-form-item>
          <template v-if="payForm.alipay.enabled">
            <el-form-item label="应用 AppID">
              <el-input v-model="payForm.alipay.appId" placeholder="开放平台应用 AppID" />
            </el-form-item>
            <el-form-item label="商户 PID">
              <el-input v-model="payForm.alipay.pid" placeholder="支付宝商户 PID" />
            </el-form-item>
            <el-form-item label="应用私钥">
              <el-input v-model="payForm.alipay.privateKey" type="password" show-password
                :placeholder="paymentSettings.has.alipay.privateKey ? '已配置（留空保持不变）' : 'RSA2 应用私钥'" />
            </el-form-item>
            <el-form-item label="支付宝公钥">
              <el-input v-model="payForm.alipay.publicKey" type="password" show-password
                :placeholder="paymentSettings.has.alipay.publicKey ? '已配置（留空保持不变）' : '回调验签用支付宝公钥'" />
            </el-form-item>
            <el-form-item label="回调地址">
              <el-input v-model="payForm.alipay.notifyUrl" placeholder="https://你的域名/api/v1/pay/notify" />
            </el-form-item>
            <el-form-item label="网关">
              <el-input v-model="payForm.alipay.gateway" placeholder="默认 https://openapi.alipay.com/gateway.do" />
            </el-form-item>
          </template>

          <el-divider content-position="left">微信支付</el-divider>
          <el-form-item label="启用微信支付">
            <el-switch v-model="payForm.wechat.enabled" />
            <span class="form-unit">Native 扫码支付（APIv3）</span>
          </el-form-item>
          <template v-if="payForm.wechat.enabled">
            <el-form-item label="商户号">
              <el-input v-model="payForm.wechat.mchId" placeholder="微信支付商户号 mchid" />
            </el-form-item>
            <el-form-item label="AppID">
              <el-input v-model="payForm.wechat.appId" placeholder="公众号 / 小程序 AppID" />
            </el-form-item>
            <el-form-item label="证书序列号">
              <el-input v-model="payForm.wechat.serialNo" placeholder="商户 API 证书序列号 serial_no" />
            </el-form-item>
            <el-form-item label="APIv3 密钥">
              <el-input v-model="payForm.wechat.apiKey" type="password" show-password
                :placeholder="paymentSettings.has.wechat.apiKey ? '已配置（留空保持不变）' : '32 位 APIv3 密钥'" />
            </el-form-item>
            <el-form-item label="商户私钥">
              <el-input v-model="payForm.wechat.privateKey" type="password" show-password
                :placeholder="paymentSettings.has.wechat.privateKey ? '已配置（留空保持不变）' : '商户 API 证书私钥（apiclient_key.pem）'" />
            </el-form-item>
            <el-form-item label="平台证书">
              <el-input v-model="payForm.wechat.platformCert" type="password" show-password
                :placeholder="paymentSettings.has.wechat.platformCert ? '已配置（留空保持不变）' : '微信支付平台证书 / 公钥（回调验签）'" />
            </el-form-item>
            <el-form-item label="回调地址">
              <el-input v-model="payForm.wechat.notifyUrl" placeholder="https://你的域名/api/v1/pay/notify" />
            </el-form-item>
          </template>

          <el-divider content-position="left">线下收款</el-divider>
          <el-form-item label="启用线下收款">
            <el-switch v-model="payForm.offline.enabled" />
            <span class="form-unit">现金 / POS 刷卡，由后台「确认收款」入账</span>
          </el-form-item>
          <el-form-item label="说明">
            <span class="form-unit">商户密钥服务端加密存储、永不回显；未配置凭证时订单自动走沙箱模拟收款</span>
          </el-form-item>
        </el-form>
      </template>
      <!-- 开源版：锁定提示 -->
      <div v-else class="locked-card" style="max-width: 520px">
        <div class="locked-card__title">
          <el-icon><Lock /></el-icon>
          在线支付（支付宝 + 微信支付）
          <el-tag size="small" type="info" effect="plain">付费版功能</el-tag>
        </div>
        <div class="locked-card__desc">付费版可在系统设置中自行开通支付宝与微信支付并配置商户参数，预约订单支持在线收款；开源版不提供该功能，可使用线下收款记录。</div>
        <el-button size="small" type="primary" plain @click="showUpgrade">升级解锁</el-button>
      </div>
    </div>

    <div class="save-bar">
      <el-button @click="handleReset">重置</el-button>
      <el-button type="primary" @click="handleSave">保存设置</el-button>
    </div>

    <!-- 购买授权在线支付（对接 OA 支付宝支付） -->
    <PurchaseDialog v-model="purchaseVisible" />
  </div>
</template>

<script setup>
import { computed, reactive, ref, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { Lock } from '@element-plus/icons-vue'
import { useCrud } from '../composables/useCrud'
import { request } from '../api/http'
import { list as listResource, update as updateResource } from '../api/resource'
import { useLicenseStore } from '../store/license'
import { usePaymentSettingsStore } from '../store/paymentSettings'
import { useSiteStore } from '../store/site'
import PurchaseDialog from '../components/PurchaseDialog.vue'

const purchaseVisible = ref(false)
const license = useLicenseStore()
const paymentSettings = usePaymentSettingsStore()
const site = useSiteStore()

// 站点与品牌（四杠杆 · 品牌权益）：站点信息三版本可改；品牌名/去版权按版本门禁
const brandForm = reactive({ siteName: '', companyName: '', servicePhone: '', logo: '', brandName: '', poweredBy: true })

async function loadBrand() {
  await site.load()
  brandForm.siteName = site.siteName
  brandForm.companyName = site.companyName
  brandForm.servicePhone = site.servicePhone
  brandForm.logo = site.logo
  brandForm.brandName = site.brandName
  brandForm.poweredBy = site.poweredBy
}

// 卡密激活（v1.14）：对接 /license/activate（OA 真实验卡 + 绑定设备 + 覆盖配额）
const activateVisible = ref(false)
const activateForm = reactive({ cardNo: '', cardKey: '' })
const activating = ref(false)
const reverifying = ref(false)
const smokeLoading = ref(false)

async function doActivate() {
  if (!activateForm.cardNo.trim() || !activateForm.cardKey) {
    ElMessage.warning('请输入卡号与卡密')
    return
  }
  activating.value = true
  try {
    await license.activateWithCard(activateForm.cardNo.trim(), activateForm.cardKey)
    ElMessage.success('激活成功，版本与限额已更新')
    activateForm.cardNo = ''
    activateForm.cardKey = ''
    activateVisible.value = false
  } catch (e) {
    ElMessage.error(e.message || '激活失败，请核对卡号与卡密')
  } finally {
    activating.value = false
  }
}

// 每日续验：后端按宽限规则降级，前端只展示结果
async function doReverify() {
  reverifying.value = true
  try {
    const res = await license.reverify()
    if (res && res.verified) ElMessage.success(res.message || '续验通过')
    else ElMessage.warning((res && res.message) || '续验未通过')
  } catch (e) {
    ElMessage.error(e.message || '续验失败，请稍后重试')
  } finally {
    reverifying.value = false
  }
}

// 发卡链路自检（issue→verify→refund 全链路对测试卡核验；§4.1a 熔断依据）
async function doSmoke() {
  smokeLoading.value = true
  try {
    const res = await request('/license/smoke', { method: 'POST', body: {} })
    if (res && res.ok) ElMessage.success('发卡链路自检通过：' + (res.detail || ''))
    else ElMessage.error('自检失败：' + ((res && res.detail) || '未知原因'))
  } catch (e) {
    ElMessage.error(e.message || '自检请求失败，请检查后端与 OA 服务')
  } finally {
    smokeLoading.value = false
  }
}

// 进入设置页时拉取真实授权（登录后可能已激活/过期，保持限额与版本标识同步）
license.refresh()

// 系统设置：key-value 行资源（group/key/value/type/remark），表单 ↔ 行双向映射
const crud = useCrud('settings', { keywordField: ['key', 'remark'] })
const settingRid = {} // key → 行 id（upsert 用）
const ridOf = (r) => r.id

// 在线支付设置（）：付费版可配置，开源版锁定
// 字段与后端 PaymentSettingsService::SCHEMA 一一对应（敏感字段留空=保持原值，不回显）
const payForm = reactive({
  alipay: { enabled: false, pid: '', appId: '', privateKey: '', publicKey: '', notifyUrl: '', returnUrl: '', gateway: '' },
  wechat: { enabled: false, mchId: '', appId: '', serialNo: '', apiKey: '', privateKey: '', platformCert: '', notifyUrl: '', gateway: '' },
  offline: { enabled: true }
})

// 读：在线支付渠道配置（脱敏）→ 表单；敏感字段一律留空（仅回 has_ 标记）
async function loadPayment() {
  try {
    await paymentSettings.load()
    const a = paymentSettings.alipay
    const w = paymentSettings.wechat
    Object.assign(payForm.alipay, { enabled: a.enabled, pid: a.pid, appId: a.appId, notifyUrl: a.notifyUrl, returnUrl: a.returnUrl, gateway: a.gateway, privateKey: '', publicKey: '' })
    Object.assign(payForm.wechat, { enabled: w.enabled, mchId: w.mchId, appId: w.appId, serialNo: w.serialNo, notifyUrl: w.notifyUrl, gateway: w.gateway, apiKey: '', privateKey: '', platformCert: '' })
    payForm.offline.enabled = paymentSettings.offline.enabled
  } catch (e) {
    console.warn('[payment]', e && e.message)
  }
}

// 组装保存载荷：敏感字段仅在本次填写时提交（留空=保持原值，不误清空）
function payPayload() {
  const body = {
    alipay: {
      enabled: payForm.alipay.enabled,
      app_id: payForm.alipay.appId,
      pid: payForm.alipay.pid,
      notify_url: payForm.alipay.notifyUrl,
      return_url: payForm.alipay.returnUrl,
      gateway: payForm.alipay.gateway
    },
    wechat: {
      enabled: payForm.wechat.enabled,
      mch_id: payForm.wechat.mchId,
      app_id: payForm.wechat.appId,
      serial_no: payForm.wechat.serialNo,
      notify_url: payForm.wechat.notifyUrl,
      gateway: payForm.wechat.gateway
    },
    offline: { enabled: payForm.offline.enabled }
  }
  if (payForm.alipay.privateKey) body.alipay.private_key = payForm.alipay.privateKey
  if (payForm.alipay.publicKey) body.alipay.public_key = payForm.alipay.publicKey
  if (payForm.wechat.apiKey) body.wechat.api_v3_key = payForm.wechat.apiKey
  if (payForm.wechat.privateKey) body.wechat.private_key = payForm.wechat.privateKey
  if (payForm.wechat.platformCert) body.wechat.platform_cert = payForm.wechat.platformCert
  return body
}

// 限额随版本联动：支付开通后 max 为 null（不限）
const limitItems = computed(() => [
  { label: '门店数', used: license.limits.stores.used, max: license.limits.stores.max },
  { label: '技师数', used: license.limits.technicians.used, max: license.limits.technicians.max },
  { label: '本月订单', used: license.limits.monthlyOrders.used, max: license.limits.monthlyOrders.max },
  { label: '本月微信通知', used: license.limits.wechatMessages.used, max: license.limits.wechatMessages.max }
])

// 门店信息：编辑主门店行（stores 资源）。门店是 store 表实体（被订单/技师/管理员的 store_id
// 引用，门店数受 quota.stores_max 限制）；此前写 store.name 等 setting 幻影键，保存无效。
const storeRid = ref(null)
const storeForm = reactive({
  name: '',
  phone: '',
  address: '',
  hours: null
})

// 预约规则：绑定后端实际读取的 setting 键（OrderService::assertBookingWindow / calcCancelFee /
// markNoshow / assertMemberCanBook），见 docs/预约系统修改.md §阶段4。
// ⚠️ 此前键名为 booking.advanceHours 等驼峰形式 → 后端从不读取，保存「成功」但规则不生效。
const bookingForm = reactive({
  advanceHours: 2,
  maxAdvanceDays: 30,
  cancelLimitHours: 4,
  cancelFeeRate: 10,
  noShowLimit: 3,
  noShowFreezeDays: 7,
  waitlist: false,
  depositEnabled: false,
  depositRate: 30
})

const notifyForm = reactive({
  mpEnabled: true,
  miniEnabled: true,
  logKeepDays: 7
})

// 账号安全（附四#7）：密码策略 + 登录失败锁定 + 异地提醒；后端 SecurityService 读取同名字段
const securityForm = reactive({
  pwdMinLength: 8,
  pwdCharTypes: 1,
  loginLockThreshold: 5,
  loginLockMinutes: 15,
  loginRemoteAlert: true
})

// 设置项定义：key ↔ 表单字段（保存时统一序列化为字符串）
// key 必须与后端 setting 实际键名一致（见 data/sql/06_seed.sql），否则配置不生效
const SETTING_DEFS = [
  { key: 'advance_hours', group: 'booking', type: 'number', get: () => bookingForm.advanceHours, set: (v) => (bookingForm.advanceHours = Number(v) || 0) },
  { key: 'max_advance_days', group: 'booking', type: 'number', get: () => bookingForm.maxAdvanceDays, set: (v) => (bookingForm.maxAdvanceDays = Number(v) || 0) },
  { key: 'cancel_limit_hours', group: 'booking', type: 'number', get: () => bookingForm.cancelLimitHours, set: (v) => (bookingForm.cancelLimitHours = Number(v) || 0) },
  { key: 'cancel_fee_rate', group: 'booking', type: 'number', get: () => bookingForm.cancelFeeRate, set: (v) => (bookingForm.cancelFeeRate = Number(v) || 0) },
  { key: 'noshow_limit', group: 'booking', type: 'number', get: () => bookingForm.noShowLimit, set: (v) => (bookingForm.noShowLimit = Number(v) || 0) },
  { key: 'noshow_freeze_days', group: 'booking', type: 'number', get: () => bookingForm.noShowFreezeDays, set: (v) => (bookingForm.noShowFreezeDays = Number(v) || 0) },
  { key: 'queue_enabled', group: 'booking', type: 'bool', get: () => (bookingForm.waitlist ? '1' : '0'), set: (v) => (bookingForm.waitlist = v === '1' || v === 1 || v === true) },
  { key: 'deposit_enabled', group: 'booking', type: 'bool', get: () => (bookingForm.depositEnabled ? '1' : '0'), set: (v) => (bookingForm.depositEnabled = v === '1' || v === 1 || v === true) },
  { key: 'deposit_rate', group: 'booking', type: 'number', get: () => bookingForm.depositRate, set: (v) => (bookingForm.depositRate = Number(v) || 0) },
  { key: 'wechat_mp_enabled', group: 'notify', type: 'bool', get: () => (notifyForm.mpEnabled ? '1' : '0'), set: (v) => (notifyForm.mpEnabled = v === '1' || v === 1 || v === true) },
  { key: 'wechat_mini_enabled', group: 'notify', type: 'bool', get: () => (notifyForm.miniEnabled ? '1' : '0'), set: (v) => (notifyForm.miniEnabled = v === '1' || v === 1 || v === true) },
  { key: 'log_keep_days', group: 'notify', type: 'number', get: () => notifyForm.logKeepDays, set: (v) => (notifyForm.logKeepDays = Number(v) || 0) },
  { key: 'pwd_min_length', group: 'security', type: 'number', get: () => securityForm.pwdMinLength, set: (v) => (securityForm.pwdMinLength = Number(v) || 8) },
  { key: 'pwd_char_types', group: 'security', type: 'number', get: () => securityForm.pwdCharTypes, set: (v) => (securityForm.pwdCharTypes = Number(v) || 1) },
  { key: 'login_lock_threshold', group: 'security', type: 'number', get: () => securityForm.loginLockThreshold, set: (v) => (securityForm.loginLockThreshold = Number(v) || 0) },
  { key: 'login_lock_minutes', group: 'security', type: 'number', get: () => securityForm.loginLockMinutes, set: (v) => (securityForm.loginLockMinutes = Number(v) || 15) },
  { key: 'login_remote_alert', group: 'security', type: 'bool', get: () => (securityForm.loginRemoteAlert ? '1' : '0'), set: (v) => (securityForm.loginRemoteAlert = v === '1' || v === 1 || v === true) }
]

// 营业时间：[Date,Date] ↔ "HH:mm-HH:mm"
function formatHours(hours) {
  if (!Array.isArray(hours) || hours.length !== 2) return ''
  const f = (d) => `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`
  return `${f(hours[0])}-${f(hours[1])}`
}
function parseHours(v) {
  const m = /^(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})$/.exec(String(v || ''))
  if (!m) return null
  return [new Date(`1970-01-01T${m[1]}:00`), new Date(`1970-01-01T${m[2]}:00`)]
}

// 读：setting 行 → 表单（后端不可达时表单保持默认值）
async function loadSettings() {
  await crud.load({ page: 1, per_page: 200 })
  for (const r of crud.state.rows) {
    if (!r.key) continue
    settingRid[r.key] = ridOf(r)
    const def = SETTING_DEFS.find((d) => d.key === r.key)
    if (def && r.value !== undefined && r.value !== null) def.set(String(r.value))
  }
  await loadStore()
}

// 主门店行（取 id 最小的一行作为「本店」；门店管理页可维护多门店）
async function loadStore() {
  try {
    const res = await listResource('stores', { page: 1, per_page: 50 })
    const row = (res.list || [])[0]
    if (!row) return
    storeRid.value = row.id
    storeForm.name = row.name || ''
    storeForm.phone = row.phone || ''
    storeForm.address = row.address || ''
    storeForm.hours = parseHours(row.hours)
  } catch (e) {
    console.warn('[store]', e && e.message)
  }
}

async function saveStore() {
  if (!storeRid.value) return
  await updateResource('stores', storeRid.value, {
    name: storeForm.name,
    phone: storeForm.phone,
    address: storeForm.address,
    hours: formatHours(storeForm.hours)
  })
}

async function handleSave() {
  // 系统设置逐项 upsert（已有行改、无行新增）
  for (const d of SETTING_DEFS) {
    const saved = await crud.save(settingRid[d.key] || null, {
      group: d.group,
      key: d.key,
      value: String(d.get()),
      type: d.type,
      remark: ''
    })
    // 新行回写 id（后端返回行时）
    if (!settingRid[d.key] && saved) {
      const nid = ridOf(saved)
      if (nid) settingRid[d.key] = nid
    }
  }
  // 门店信息走 stores 资源（store 表实体），不写 setting
  try {
    await saveStore()
  } catch (e) {
    ElMessage.error('门店信息保存失败：' + ((e && e.message) || '未知原因'))
    return
  }
  // 在线支付配置保存到后端（敏感字段服务端加密；开源版保存被 403 拦截并弹升级）
  try {
    await paymentSettings.save(payPayload())
    // 保存后清空内存中的敏感字段（不回显、不驻留）
    payForm.alipay.privateKey = ''
    payForm.alipay.publicKey = ''
    payForm.wechat.apiKey = ''
    payForm.wechat.privateKey = ''
    payForm.wechat.platformCert = ''
  } catch (e) {
    ElMessage.error('在线支付配置保存失败：' + ((e && e.message) || '未知原因'))
    return
  }
  // 站点与品牌保存（站点信息三版本可写；品牌名/去版权为授权版功能，开源版被 403 拦截并弹升级）
  try {
    await site.save({
      site_name: brandForm.siteName,
      company_name: brandForm.companyName,
      service_phone: brandForm.servicePhone,
      logo: brandForm.logo,
      brand_name: brandForm.brandName,
      powered_by: brandForm.poweredBy
    })
  } catch (e) {
    ElMessage.error('站点/品牌保存失败：' + ((e && e.message) || '未知原因'))
    return
  }
  ElMessage.success('设置已保存')
  loadSettings()
}

function handleReset() {
  loadSettings()
  loadPayment()
  loadBrand()
  ElMessage.info('已重置为上次保存的设置')
}

function showUpgrade() {
  purchaseVisible.value = true
}

onMounted(() => {
  loadSettings()
  loadPayment()
  loadBrand()
})
</script>

<style scoped>
.license-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: var(--space-md);
}

.license-actions {
  display: inline-flex;
  gap: var(--space-xs);
}

.license-meta {
  margin-left: var(--space-sm);
  color: var(--text-tertiary);
}

/* 卡密激活行内表单 */
.activate-form {
  margin-bottom: var(--space-md);
}

.limit-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: var(--space-md);
}

.limit-item__label {
  color: var(--text-secondary);
  margin-bottom: 4px;
}

.limit-item__text {
  color: var(--text-tertiary);
  margin-top: 2px;
}

.limit-item__unlimited {
  color: var(--success);
  line-height: 8px;
}

.form-unit {
  margin-left: var(--space-xs);
  color: var(--text-tertiary);
}

/* 门店信息 + 预约规则一行双列（） */
.settings-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: var(--space-md);
  margin-top: var(--space-md);
  align-items: start;
}

/* 预约规则分组小标题（提前时长/取消政策/爽约限制） */
.rule-group {
  font-size: var(--font-size-body);
  font-weight: 600;
  color: var(--text-secondary);
  margin: var(--space-sm) 0 var(--space-xs);
  padding-left: 8px;
  border-left: 3px solid var(--primary);
}

/* 在线支付设置卡标题（） */
.pay-header {
  display: flex;
  align-items: center;
  gap: var(--space-xs);
}

@media (max-width: 1024px) {
  .limit-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}

/* 窄屏（<900px）才降为堆叠，常规桌面视口保持一行双列（） */
@media (max-width: 900px) {
  .settings-row {
    grid-template-columns: 1fr;
  }
}
</style>
