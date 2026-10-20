<template>
  <div class="custom-page">
    <header class="custom-hero">
      <h2>系统定制</h2>
      <p>想增加功能、调整流程、换品牌？本系统支持按需定制</p>
    </header>

    <section class="custom-body">
      <img class="qr" src="/images/wechat-qr.svg" alt="微信二维码" />
      <div class="steps">
        <div class="step"><span class="no">1</span><div><b>扫码加顾问</b><p>备注「定制」优先通过</p></div></div>
        <div class="step"><span class="no">2</span><div><b>说清需求</b><p>想加的功能点 / 想改的流程</p></div></div>
        <div class="step"><span class="no">3</span><div><b>出方案报价</b><p>1 个工作日内回复</p></div></div>
        <div class="step"><span class="no">4</span><div><b>签约开发</b><p>成品打底，成本远低于新开发</p></div></div>
      </div>
      <p class="note">石家庄江金网络科技有限公司 · 交付源码 · 30 天质保 · 首年免费维护</p>
    </section>
  </div>
</template>

<style scoped>
.custom-page { min-height: 100vh; background: #f5f7fa; padding-bottom: 76px; }
.custom-hero { background: linear-gradient(160deg, #0f1e33, #1d3a55); color: #fff; padding: 28px 18px 22px; }
.custom-hero h2 { font-size: 20px; }
.custom-hero p { font-size: 13px; opacity: .8; margin-top: 6px; }
.custom-body { padding: 18px; max-width: 480px; margin: 0 auto; }
.qr { width: 200px; display: block; margin: 8px auto 18px; border-radius: 12px; box-shadow: 0 6px 20px rgba(15,30,51,.15); }
.steps { display: flex; flex-direction: column; gap: 10px; }
.step { display: flex; gap: 12px; align-items: flex-start; background: #fff; border-radius: 12px; padding: 14px; }
.step .no {
  width: 22px; height: 22px; border-radius: 50%; flex: none;
  background: linear-gradient(135deg, #d9a441, #f0c069); color: #fff;
  font-size: 12px; font-weight: 700; display: flex; align-items: center; justify-content: center;
}
.step b { font-size: 14px; }
.step p { font-size: 12px; color: #7b8a99; margin-top: 2px; }
.note { text-align: center; font-size: 12px; color: #7b8a99; margin-top: 18px; }
</style>
