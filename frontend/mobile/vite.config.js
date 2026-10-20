import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// 技师端工作台 H5：端口 5176（admin 5174 / user 5175）
export default defineConfig({
  plugins: [vue()],
  server: {
    host: true,
    port: 5176,
    // 后端联调：/api 同源转发 ThinkPHP8（127.0.0.1:8180），前端无跨域；
    // 后端不可达时页面统一报错提示
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8180',
        changeOrigin: true
      }
    }
  }
})
