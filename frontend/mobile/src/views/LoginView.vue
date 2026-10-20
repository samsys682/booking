<template>
  <div class="page page--nobar" style="padding-top: 60px">
    <div style="text-align: center; margin-bottom: 28px">
      <div style="font-size: 22px; font-weight: 600">技师工作台</div>
      <div class="tiny muted" style="margin-top: 6px">手机号 + 密码登录，开始接单服务</div>
    </div>

    <div class="card">
      <div class="field">
        <label class="field__label">手机号</label>
        <input v-model="phone" class="field__input" maxlength="11" placeholder="请输入手机号" />
      </div>
      <div class="field">
        <label class="field__label">密码</label>
        <input v-model="password" class="field__input" type="password" placeholder="请输入密码" @keyup.enter="login" />
      </div>
      <button class="btn btn--primary btn--block" @click="login">登录</button>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useToast } from '../composables/useToast'
import { techLogin } from '../api/auth'

const router = useRouter()
const { toast } = useToast()

const phone = ref('')
const password = ref('')

async function login() {
  if (!/^1\d{10}$/.test(phone.value)) return toast('请输入正确的手机号')
  if (!password.value) return toast('请输入密码')
  try {
    await techLogin(phone.value, password.value)
    toast('登录成功')
    router.replace('/home')
  } catch (e) {
    if (e.isHttpDown) {
      toast('后端服务未连接，请检查后端 API 服务是否启动')
    } else {
      toast(e.message || '登录失败')
    }
  }
}
</script>
