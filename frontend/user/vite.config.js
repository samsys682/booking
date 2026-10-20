import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// booking 用户端 H5：与 admin 同约定 —— API 优先（/api/v1），后端不可达时页面层自动落内存 mock 兜底
export default defineConfig({
  plugins: [vue()],
  server: {
    host: true,
    port: 5175,
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
