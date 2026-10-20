<template>
  <div class="page page--nobar login-page">
    <div class="logo">上门服务</div>
    <div class="muted tiny" style="text-align: center; margin-bottom: 24px">保洁 · 家电清洗 · 收纳整理</div>

    <div class="card">
      <div class="field">
        <label class="field__label">手机号</label>
        <input v-model="form.phone" class="field__input" maxlength="11" placeholder="请输入手机号" />
      </div>
      <div class="field">
        <label class="field__label">验证码</label>
        <div class="row">
          <input v-model="form.code" class="field__input" maxlength="6" placeholder="请输入验证码" />
          <button class="btn" style="flex: none" @click="sendCode">{{ countdown > 0 ? countdown + 's' : '获取验证码' }}</button>
        </div>
      </div>
      <div class="field">
        <label class="field__label">邀请码（选填）</label>
        <input v-model="form.inviteCode" class="field__input" maxlength="10" placeholder="如 M01001，新用户填写后双方得积分" />
      </div>
      <button class="btn btn--primary btn--block" :disabled="loading" @click="login">登录 / 注册</button>
    </div>
  </div>
</template>

<script setup>
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useToast } from '../composables/useToast'
import { memberSendCode, memberLogin } from '../api/auth'

const router = useRouter()
const { toast } = useToast()

const form = reactive({ phone: '', code: '', inviteCode: '' })
const loading = ref(false)
const countdown = ref(0)

async function sendCode() {
  if (!/^1\d{10}$/.test(form.phone)) return toast('请输入正确的手机号')
  if (countdown.value > 0) return
  countdown.value = 60
  const timer = setInterval(() => {
    countdown.value -= 1
    if (countdown.value <= 0) clearInterval(timer)
  }, 1000)
  try {
    const data = await memberSendCode(form.phone)
    toast(data && data.demo_code ? `验证码已发送：${data.demo_code}` : '验证码已发送')
  } catch (e) {
    if (e.isHttpDown) {
      toast('后端服务未连接，请检查后端 API 服务是否启动')
    } else {
      toast(e.message || '发送失败')
    }
    if (countdown.value > 0) {
      countdown.value = 0
      clearInterval(timer)
    }
  }
}

async function login() {
  if (!/^1\d{10}$/.test(form.phone)) return toast('请输入正确的手机号')
  if (!/^\d{6}$/.test(form.code)) return toast('请输入 6 位验证码')
  loading.value = true
  try {
    await memberLogin(form.phone, form.code, form.inviteCode)
    toast('登录成功')
    router.replace('/home')
  } catch (e) {
    if (e.isHttpDown) {
      toast('后端服务未连接，请检查后端 API 服务是否启动')
    } else {
      toast(e.message || '登录失败')
    }
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
</style>
