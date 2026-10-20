<template>
  <div>
    <div class="navbar">
      <button class="navbar__back" @click="$router.back()">‹</button>
      <span class="navbar__title">服务地址管理</span>
    </div>

    <div class="page page--nobar" style="padding-bottom: 80px">
      <div v-for="a in rows" :key="a.id" class="card">
        <div class="row row--between">
          <div style="flex: 1">
            <div class="row">
              <b>{{ a.name }}</b>
              <span class="tiny muted" style="margin-left: 8px">{{ a.phone }}</span>
              <span v-if="a.isDefault" class="tag tag--green" style="margin-left: 8px">默认</span>
            </div>
            <div class="tiny muted" style="margin-top: 4px">{{ a.address }}</div>
          </div>
        </div>
        <div class="row" style="margin-top: 10px; gap: 10px">
          <button v-if="!a.isDefault" class="btn btn--mini" @click="setDefault(a)">设为默认</button>
          <button class="btn btn--mini" @click="openEdit(a)">编辑</button>
          <button class="btn btn--mini" style="color: var(--danger)" @click="del(a)">删除</button>
        </div>
      </div>

      <div v-if="!rows.length && !loading" class="empty">暂无地址</div>
    </div>

    <div class="action-bar">
      <button class="btn btn--primary btn--block" @click="openCreate">+ 新增地址</button>
    </div>

    <!-- 新增/编辑 -->
    <div v-if="showForm">
      <div class="mask" @click="showForm = false"></div>
      <div class="sheet">
        <div class="sheet__title">{{ editing ? '编辑地址' : '新增地址' }}</div>
        <div class="field">
          <label class="field__label">联系人</label>
          <input v-model="form.name" class="field__input" placeholder="请输入联系人" />
        </div>
        <div class="field">
          <label class="field__label">手机号</label>
          <input v-model="form.phone" class="field__input" maxlength="11" placeholder="请输入手机号" />
        </div>
        <div class="field">
          <label class="field__label">详细地址</label>
          <input v-model="form.address" class="field__input" placeholder="如：朝阳区望京西园一区 3 号楼 1201" />
        </div>
        <label class="row" style="margin-bottom: 12px; cursor: pointer">
          <input v-model="form.isDefault" type="checkbox" />
          <span class="tiny muted">设为默认地址</span>
        </label>
        <div class="row" style="gap: 10px">
          <button class="btn" style="flex: 1" @click="showForm = false">取消</button>
          <button class="btn btn--primary" style="flex: 1" @click="saveForm">保存</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useToast } from '../composables/useToast'
import { fetchAddresses, createAddress, updateAddress, deleteAddress } from '../api/memberSelf'

const { toast } = useToast()

// 单一数据轨道：my/addresses（member token 归属校验在后端）；失败报错不再降级演示数据

const ridOf = (row) => row.id

const rows = ref([])
const loading = ref(false)

const showForm = ref(false)
const editing = ref(false)
const editRid = ref('')
const form = reactive({ name: '', phone: '', address: '', isDefault: false })

// 错误统一提示：后端不可达报连接问题，其余报业务错误
function toastErr(e, fallback) {
  toast(e && e.isHttpDown ? '后端服务未连接，请检查后端 API 服务是否启动' : (e && e.message ? e.message : fallback))
}

async function load() {
  try {
    loading.value = true
    const data = await fetchAddresses()
    rows.value = data.list || []
  } catch (e) {
    toastErr(e, '加载失败')
  } finally {
    loading.value = false
  }
}

function openCreate() {
  editing.value = false
  editRid.value = ''
  Object.assign(form, { name: '', phone: '', address: '', isDefault: false })
  showForm.value = true
}

function openEdit(a) {
  editing.value = true
  editRid.value = ridOf(a)
  Object.assign(form, {
    name: a.name || '',
    phone: a.phone || '',
    address: a.address || '',
    isDefault: !!a.isDefault
  })
  showForm.value = true
}

async function saveForm() {
  if (!String(form.name).trim()) return toast('请填写联系人')
  if (!/^1\d{10}$/.test(String(form.phone).trim())) return toast('请填写 11 位手机号')
  if (!form.address) return toast('请填写详细地址')
  try {
    if (editing.value) await updateAddress(editRid.value, { ...form })
    else await createAddress({ ...form })
  } catch (e) {
    return toastErr(e, '保存失败')
  }
  await load()
  showForm.value = false
  toast('已保存')
}

async function setDefault(a) {
  try {
    // 后端置默认时自动唯一化（取消其它默认），一次请求即可
    await updateAddress(ridOf(a), { ...formOf(a), isDefault: true })
  } catch (e) {
    return toastErr(e, '设置失败')
  }
  await load()
  toast('已设为默认')
}

function formOf(a) {
  return { name: a.name || '', phone: a.phone || '', address: a.address || '' }
}

async function del(a) {
  if (!window.confirm('确定删除该地址吗？')) return
  try {
    await deleteAddress(ridOf(a))
  } catch (e) {
    return toastErr(e, '删除失败')
  }
  await load()
  toast('已删除')
}

onMounted(load)
</script>
