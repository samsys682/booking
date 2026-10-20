<template>
  <div class="page">
    <div class="card">
      <div class="list-row__title">我的服务项目</div>
      <div class="tiny muted" style="margin-top: 4px">勾选你能承接的服务，客户按此范围向你预约/派单</div>
    </div>

    <div v-if="!rows.length" class="empty">暂无上架服务，请联系门店先添加服务项目</div>
    <div v-else class="card">
      <div
        v-for="s in rows"
        :key="s.id"
        class="list-row"
        style="cursor: pointer"
        @click="toggle(s.id)"
      >
        <div class="list-row__main">
          <div class="list-row__title">{{ s.name }}</div>
          <div class="list-row__sub">{{ s.category }} · {{ s.duration }} 分钟 · ¥{{ s.price }}</div>
        </div>
        <span :class="['checkbox', { on: picked.has(s.id) }]">{{ picked.has(s.id) ? '✓' : '' }}</span>
      </div>
    </div>

    <button class="btn btn--block" :disabled="saving || !rows.length" @click="save">
      {{ saving ? '保存中…' : '保存我的服务（已选 ' + picked.size + ' 项）' }}
    </button>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { useToast } from '../composables/useToast'
import { fetchMyServices, saveMyServices } from '../api/techSelf'

const { toast } = useToast()
const rows = ref([])
const picked = ref(new Set())
const saving = ref(false)

function toggle(id) {
  const s = new Set(picked.value)
  if (s.has(id)) {
    s.delete(id)
  } else {
    s.add(id)
  }
  picked.value = s
}

function toastErr(e, fallback) {
  toast(e && e.isHttpDown ? '后端服务未连接，请检查后端 API 服务是否启动' : (e && e.message ? e.message : fallback))
}

async function load() {
  try {
    rows.value = await fetchMyServices()
    picked.value = new Set(rows.value.filter((r) => r.selected).map((r) => r.id))
  } catch (e) {
    toastErr(e, '加载失败')
  }
}

async function save() {
  saving.value = true
  try {
    await saveMyServices([...picked.value])
    toast('已保存我的服务')
    await load()
  } catch (e) {
    toastErr(e, '保存失败')
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<style scoped>
.checkbox {
  width: 22px;
  height: 22px;
  border-radius: 6px;
  border: 1.5px solid var(--border, #d0d0d0);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
  line-height: 1;
  color: #fff;
  flex: none;
}
.checkbox.on {
  background: var(--primary);
  border-color: var(--primary);
}
</style>
