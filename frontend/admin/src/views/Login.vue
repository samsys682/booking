<template>
  <div class="login">
    <div class="login__card">
      <div class="login__logo">
        <el-icon :size="24" color="var(--primary)"><Calendar /></el-icon>
        <span class="login__title">{{ site.displayName }}</span>
      </div>
      <div class="login__subtitle">管理后台</div>

      <el-form ref="formRef" :model="form" :rules="rules" label-position="top" @submit.prevent>
        <el-form-item label="账号" prop="username">
          <el-input v-model="form.username" placeholder="请输入账号" :disabled="loading" />
        </el-form-item>
        <el-form-item label="密码" prop="password">
          <el-input
            v-model="form.password"
            type="password"
            placeholder="请输入密码"
            show-password
            :disabled="loading"
            @keyup.enter="handleLogin"
          />
        </el-form-item>
        <el-button type="primary" class="login__submit" :loading="loading" @click="handleLogin">
          登录
        </el-button>
      </el-form>

      <div class="login__footer">
        <span v-if="site.poweredBy">{{ site.poweredText }} · {{ site.editionName }}</span>
        <span v-else>{{ site.displayName }}</span>
      </div>
    </div>
  </div>
</template>

<script setup>
import { reactive, ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { request, setToken } from '../api/http'
import { useLicenseStore } from '../store/license'
import { useSiteStore } from '../store/site'

const router = useRouter()
const license = useLicenseStore()
const site = useSiteStore()
const formRef = ref()
const loading = ref(false)

// 登录页免 token 读取站点名/Logo/版权（GET /site 在白名单内）
onMounted(() => {
  site.load()
})

const form = reactive({ username: '', password: '' })
const rules = {
  username: [{ required: true, message: '请输入账号', trigger: 'blur' }],
  password: [{ required: true, message: '请输入密码', trigger: 'blur' }]
}

async function handleLogin() {
  const valid = await formRef.value.validate().catch(() => false)
  if (!valid) return
  loading.value = true
  try {
    const data = await request('/auth/login', {
      method: 'POST',
      body: { account: form.username, password: form.password }
    })
    setToken(data.token || '')
    // 登录响应携带真实授权信息（AuthController login 返回 license），回填解锁菜单/限额
    if (data.license) license.applyApi(data.license)
    if (data.admin) {
      localStorage.setItem('bk_admin', JSON.stringify({
        name: data.admin.name || data.admin.username || form.username,
        role: data.admin.role || '',
        perms: data.admin.perms || []
      }))
    }
    ElMessage.success('登录成功')
    router.push('/')
  } catch (e) {
    if (e.isHttpDown) {
      ElMessage.error('后端服务未连接，请检查后端 API 服务是否启动')
    } else {
      ElMessage.error(e.message || '登录失败')
    }
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.login {
  height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--bg-page);
}

.login__card {
  width: 360px;
  background: var(--bg-card);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-md);
  padding: var(--space-xl) var(--space-xl) var(--space-lg);
}

.login__logo {
  display: flex;
  align-items: center;
  gap: var(--space-xs);
}

.login__title {
  font-size: var(--font-size-title);
  font-weight: 600;
  color: var(--text-primary);
}

.login__subtitle {
  margin: var(--space-xs) 0 var(--space-lg);
  color: var(--text-tertiary);
}

.login__submit {
  width: 100%;
  height: var(--ctrl-h);
}

.login__footer {
  margin-top: var(--space-lg);
  text-align: center;
  color: var(--text-tertiary);
}
</style>
