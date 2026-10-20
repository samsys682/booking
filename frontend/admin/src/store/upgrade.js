// =====================================================================
// 版本门禁升级引导（全局）
// 后端 EditionGate 拦截写操作时返回 403 + data.upgrade{edition,need,needName,feature,tip}；
// http.js 统一捕获后调用 promptUpgrade()，由 App.vue 挂载的 <UpgradeGuide /> 弹出引导。
// 这样「开源版能看见入口、点了才提示升级」的付费转化路径才闭环（《开发计划》§2.6）。
// =====================================================================
import { reactive } from 'vue'

export const upgradeState = reactive({
  visible: false,
  tip: '',
  needName: '',
  feature: '',
  edition: ''
})

/**
 * 触发升级引导弹窗
 * @param {object} info 后端 data.upgrade（{edition,need,needName,feature,tip}）
 */
export function promptUpgrade(info) {
  if (!info) return
  upgradeState.tip = info.tip || '该功能为付费版本功能，升级后即可使用'
  upgradeState.needName = info.needName || '授权版'
  upgradeState.feature = info.feature || ''
  upgradeState.edition = info.edition || ''
  upgradeState.visible = true
}

export function closeUpgrade() {
  upgradeState.visible = false
}
