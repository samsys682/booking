<template>
  <view class="page login-page">
    <view class="logo">上门服务</view>
    <view class="muted tiny" style="text-align: center; margin-bottom: 24px">保洁 · 家电清洗 · 收纳整理</view>

    <!-- 微信一键授权（主通道） -->
    <view class="card" v-if="!showSms">
      <button class="btn btn--primary btn--block" :disabled="loading" @tap="wxLogin">微信一键登录</button>
      <!-- 手机号快捷授权：需企业认证小程序；按钮点击与 wx.login 一次请求提交，
           命中老手机号会员自动合并（防一人两号） -->
      <button
        class="btn btn--block"
        style="margin-top: 10px"
        :disabled="loading"
        open-type="getPhoneNumber"
        @getphonenumber="onPhoneAuth"
      >微信手机号快捷登录</button>
      <view class="tiny muted" style="margin-top: 10px; text-align: center">
        未注册的微信将自动建档；也可
        <text class="link" @tap="showSms = true">使用短信验证码登录</text>
      </view>
    </view>

    <!-- 短信验证码登录（回退通道：微信凭证未配置/授权受限时） -->
    <view class="card" v-else>
      <view class="field">
        <text class="field__label">手机号</text>
        <input v-model="form.phone" class="field__input" maxlength="11" placeholder="请输入手机号" />
      </view>
      <view class="field">
        <text class="field__label">验证码</text>
        <view class="row">
          <input v-model="form.code" class="field__input" maxlength="6" placeholder="请输入验证码" />
          <button class="btn" style="flex: none" @tap="sendCode">{{ countdown > 0 ? countdown + 's' : '获取验证码' }}</button>
        </view>
      </view>
      <view class="field">
        <text class="field__label">邀请码（选填）</text>
        <input v-model="form.inviteCode" class="field__input" maxlength="10" placeholder="如 M01001，新用户填写后双方得积分" />
      </view>
      <button class="btn btn--primary btn--block" :disabled="loading" @tap="smsLogin">登录 / 注册</button>
      <view class="tiny muted" style="margin-top: 10px; text-align: center">
        <text class="link" @tap="showSms = false">返回微信登录</text>
      </view>
    </view>
  </view>
</template>

<script setup>
// 登录页 —— 顾客端小程序（批次五）
// 主通道 wx.login → member-wx-login（openid 建档/合并）；短信登录为回退通道。
// 后端「未配置」提示时自动切到短信通道（微信登录参数由商家后台配置）。
import { reactive, ref } from 'vue'
import { toast, BizError } from '../../api/http'
import { memberWxLogin, memberSendCode, memberLogin } from '../../api/auth'
import { errText } from '../../utils'

const showSms = ref(false)
const loading = ref(false)
const form = reactive({ phone: '', code: '', inviteCode: '' })
const countdown = ref(0)
let timer = null

function done() {
  toast('登录成功')
  uni.switchTab({ url: '/pages/index/index' })
}

async function wxLogin() {
  if (loading.value) return
  loading.value = true
  try {
    await memberWxLogin({ inviteCode: form.inviteCode })
    done()
  } catch (e) {
    if (e instanceof BizError && e.code === 400 && String(e.message).includes('未配置')) {
      // 微信登录参数未配置 → 引导回退短信通道（商家后台「消息通知」可配置）
      showSms.value = true
      toast('商家尚未配置微信登录，请用短信验证码登录')
    } else if (e instanceof BizError && e.code === 429) {
      toast(e.message || '登录过于频繁，请稍后再试')
    } else {
      toast(errText(e, '微信登录失败，请重试'))
    }
  } finally {
    loading.value = false
  }
}

// 手机号快捷授权：getPhoneNumber 事件 code 与 wx.login code 同请求提交
async function onPhoneAuth(e) {
  if (loading.value) return
  const detail = e.detail || {}
  if (detail.errMsg && !detail.errMsg.includes('ok')) {
    // 用户拒绝授权/无权限 → 回退纯 openid 登录
    return wxLogin()
  }
  if (!detail.code) return
  loading.value = true
  try {
    await memberWxLogin({ phoneCode: detail.code, inviteCode: form.inviteCode })
    done()
  } catch (err) {
    if (err instanceof BizError && err.code === 400 && String(err.message).includes('未配置')) {
      showSms.value = true
      toast('商家尚未配置微信登录，请用短信验证码登录')
    } else {
      toast(errText(err, '登录失败，请重试'))
    }
  } finally {
    loading.value = false
  }
}

async function sendCode() {
  if (!/^1\d{10}$/.test(form.phone)) return toast('请输入正确的手机号')
  if (countdown.value > 0) return
  countdown.value = 60
  timer = setInterval(() => {
    countdown.value -= 1
    if (countdown.value <= 0) clearInterval(timer)
  }, 1000)
  try {
    const data = await memberSendCode(form.phone)
    toast(data && data.demo_code ? `验证码已发送：${data.demo_code}` : '验证码已发送')
  } catch (e) {
    toast(errText(e, '发送失败'))
    if (countdown.value > 0) {
      countdown.value = 0
      clearInterval(timer)
    }
  }
}

async function smsLogin() {
  if (!/^1\d{10}$/.test(form.phone)) return toast('请输入正确的手机号')
  if (!/^\d{6}$/.test(form.code)) return toast('请输入 6 位验证码')
  loading.value = true
  try {
    await memberLogin(form.phone, form.code, form.inviteCode)
    done()
  } catch (e) {
    toast(errText(e, '登录失败'))
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.login-page { padding-top: 64px; }
.logo {
  width: 64px;
  height: 64px;
  margin: 0 auto 12px;
  border-radius: 16px;
  background: var(--primary);
  color: #fff;
  font-size: 15px;
  display: flex;
  align-items: center;
  justify-content: center;
}
.link { color: var(--primary); }
</style>
