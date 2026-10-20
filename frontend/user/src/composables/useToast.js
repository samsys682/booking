// 轻提示（全局单例，App.vue 挂载渲染）
import { reactive } from 'vue'

const state = reactive({ msg: '' })
let timer = null

export function useToast() {
  function toast(msg) {
    state.msg = msg
    clearTimeout(timer)
    timer = setTimeout(() => {
      state.msg = ''
    }, 1800)
  }
  return { toastState: state, toast }
}
