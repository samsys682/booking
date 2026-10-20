<template>
  <div class="custom-view">
    <el-card>
      <template #header><b>功能定制</b></template>
      <div class="grid">
        <div class="left">
          <p class="tip">本系统支持按需定制：增加功能模块、调整业务流程、对接第三方系统、更换品牌等。</p>
          <el-steps direction="vertical" :active="4">
            <el-step title="联系顾问" description="微信扫码，备注「系统定制」" />
            <el-step title="出方案报价" description="1 个工作日内给出功能清单与报价" />
            <el-step title="签约开发" description="成品引擎二次开发，成本远低于全新开发" />
            <el-step title="验收交付" description="交付源码 + 30 天质保 + 首年免费维护" />
          </el-steps>
          <p class="company">石家庄江金网络科技有限公司</p>
        </div>
        <div class="right">
          <img src="/images/wechat-qr.svg" alt="微信二维码" class="qr" />
          <p class="muted">微信扫码咨询定制</p>
        </div>
      </div>
    </el-card>
  </div>
</template>

<script setup>
</script>

<style scoped>
.custom-view { max-width: 760px; }
.grid { display: flex; gap: 30px; align-items: flex-start; }
.left { flex: 1; }
.tip { font-size: 14px; color: #44586d; margin-bottom: 18px; }
.company { margin-top: 18px; font-size: 13px; color: #7b8a99; }
.right { text-align: center; flex: none; }
.qr { width: 180px; border-radius: 10px; }
.muted { font-size: 12px; color: #7b8a99; margin-top: 6px; }
</style>
