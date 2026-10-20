<template>
  <view class="page" style="padding-bottom: 80px">
    <view v-for="a in rows" :key="a.id" class="card">
      <view class="row">
        <text style="font-weight: 600">{{ a.name }}</text>
        <text class="tiny muted" style="margin-left: 8px">{{ a.phone }}</text>
        <text v-if="a.isDefault" class="tag tag--green" style="margin-left: 8px">默认</text>
      </view>
      <view class="tiny muted" style="margin-top: 4px">{{ a.address }}</view>
      <view class="row" style="margin-top: 10px; gap: 10px">
        <button v-if="!a.isDefault" class="btn btn--mini" @tap="setDefault(a)">设为默认</button>
        <button class="btn btn--mini" @tap="openEdit(a)">编辑</button>
        <button class="btn btn--mini" style="color: #d9534f" @tap="del(a)">删除</button>
      </view>
    </view>

    <view v-if="!rows.length && !loading" class="empty">暂无地址</view>

    <view v-if="rows.length" class="action-bar">
      <button class="btn btn--primary btn--block" @tap="openCreate">+ 新增地址</button>
    </view>
    <button v-else class="btn btn--primary btn--block" style="margin-top: 8px" @tap="openCreate">+ 新增地址</button>

    <!-- 新增/编辑弹层 -->
    <view v-if="showForm">
      <view class="mask" @tap="showForm = false"></view>
      <view class="sheet">
        <view class="sheet__title">{{ editing ? '编辑地址' : '新增地址' }}</view>
        <view class="field">
          <text class="field__label">联系人</text>
          <input v-model="form.name" class="field__input" placeholder="请输入联系人" />
        </view>
        <view class="field">
          <text class="field__label">手机号</text>
          <input v-model="form.phone" class="field__input" type="number" maxlength="11" placeholder="请输入手机号" />
        </view>
        <view class="field">
          <text class="field__label">详细地址</text>
          <input v-model="form.address" class="field__input" placeholder="如：朝阳区望京西园一区 3 号楼 1201" />
        </view>
        <view class="row" style="margin-bottom: 12px" @tap="form.isDefault = !form.isDefault">
          <view class="checkbox" :class="{ 'checkbox--on': form.isDefault }">{{ form.isDefault ? '✓' : '' }}</view>
          <text class="tiny muted">设为默认地址</text>
        </view>
        <view class="row" style="gap: 10px">
          <button class="btn" style="flex: 1" @tap="showForm = false">取消</button>
          <button class="btn btn--primary" style="flex: 1" @tap="saveForm">保存</button>
        </view>
      </view>
    </view>
  </view>
</template>

<script setup>
// 服务地址管理 —— 顾客端小程序（对齐 user H5 AddressView：CRUD + 设默认）
import { onMounted, reactive, ref } from 'vue'
import { toast } from '../../api/http'
import { fetchAddresses, createAddress, updateAddress, deleteAddress } from '../../api/memberSelf'

// 单一数据轨道：my/addresses（member token 归属校验在后端）；失败报错不再降级演示数据

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
  editRid.value = a.id
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
    await updateAddress(a.id, { name: a.name || '', phone: a.phone || '', address: a.address || '', isDefault: true })
  } catch (e) {
    return toastErr(e, '设置失败')
  }
  await load()
  toast('已设为默认')
}

function del(a) {
  uni.showModal({
    title: '删除地址',
    content: '确定删除该地址吗？',
    success: async (r) => {
      if (!r.confirm) return
      try {
        await deleteAddress(a.id)
      } catch (e) {
        return toastErr(e, '删除失败')
      }
      await load()
      toast('已删除')
    }
  })
}

onMounted(load)
</script>

<style scoped>
.checkbox {
  width: 16px;
  height: 16px;
  border-radius: 4px;
  border: 1px solid #ccc;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  color: #fff;
  margin-right: 6px;
}
.checkbox--on { background: #2f6b4f; border-color: #2f6b4f; }
</style>
