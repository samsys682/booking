import { defineConfig } from 'vite'
import uni from '@dcloudio/vite-plugin-uni'

// 顾客端微信小程序构建配置（uni-app Vue3）
export default defineConfig({
  plugins: [uni()]
})
