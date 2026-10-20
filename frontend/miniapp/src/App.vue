<script>
// 应用入口：小程序 onLaunch 不强制登录；需要登录态的接口 401 时由 http.js 统一跳登录页
export default {
  onLaunch() {
    console.log('booking 顾客端小程序启动')
  }
}
</script>

<style>
/* 全局样式在 styles/main.css（编译为 app.wxss） */
</style>
