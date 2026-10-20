<template>
  <div class="rich-editor" :class="{ 'rich-editor--disabled': disabled }">
    <Toolbar class="rich-editor__toolbar" :editor="editorRef" :default-config="toolbarConfig" mode="default" />
    <Editor
      class="rich-editor__body"
      v-model="valueHtml"
      :default-config="editorConfig"
      mode="default"
      @on-created="handleCreated"
    />
  </div>
</template>

<script setup>
// 富文本编辑器（服务图文详情改富文本，替代详情图方式）
// 工具栏一行展示；删掉 emoji/撤销/前进/全屏等低频功能
// 图片/视频走真实上传接口（POST /upload），落盘后插入返回的 URL（不再本地 base64）
import '@wangeditor/editor/dist/css/style.css'
import { ref, shallowRef, watch, onBeforeUnmount } from 'vue'
import { Editor, Toolbar } from '@wangeditor/editor-for-vue'
import { uploadFile } from '../api/upload'

const props = defineProps({
  modelValue: { type: String, default: '' },
  placeholder: { type: String, default: '输入图文详情…' },
  disabled: { type: Boolean, default: false }
})
const emit = defineEmits(['update:modelValue'])

const editorRef = shallowRef()
const valueHtml = ref(props.modelValue)

// 工具栏精简（）：保留 标题/加粗/斜体/下划线/删除线/颜色/对齐/列表/链接/图片/视频/分割线
// 删除：emotion（emoji）/undo/redo（撤销前进）/fullScreen/fontSize/fontFamily/bgColor/lineHeight/indent/delIndent/insertTable/codeBlock
const toolbarConfig = {
  excludeKeys: [
    'emotion',
    'undo',
    'redo',
    'fullScreen',
    'fontSize',
    'fontFamily',
    'bgColor',
    'lineHeight',
    'indent',
    'delIndent',
    'insertTable',
    'codeBlock'
  ]
}

// 图片/视频真实上传：前端先做体积预检（与后端上限一致），再 POST /upload，插入返回 URL
function realUpload(file, insertFn, maxMB, kind) {
  if (file.size > maxMB * 1024 * 1024) {
    import('element-plus').then(({ ElMessage }) => ElMessage.warning(`${kind}大小不能超过 ${maxMB}MB`))
    return
  }
  uploadFile(file)
    .then((res) => insertFn(res.url))
    .catch((e) =>
      import('element-plus').then(({ ElMessage }) => ElMessage.error(e.message || `${kind}上传失败，请重试`))
    )
}

const editorConfig = {
  placeholder: props.placeholder,
  MENU_CONF: {
    uploadImage: {
      customUpload(file, insertFn) {
        // insertFn(url, alt, href)
        realUpload(file, (url) => insertFn(url, file.name, url), 5, '图片')
      }
    },
    uploadVideo: {
      customUpload(file, insertFn) {
        // insertFn(url, poster)
        realUpload(file, (url) => insertFn(url, ''), 20, '视频')
      }
    }
  }
}

watch(
  () => props.modelValue,
  (v) => {
    if (v !== valueHtml.value) valueHtml.value = v
  }
)

watch(valueHtml, (v) => emit('update:modelValue', v))

watch(
  () => props.disabled,
  (v) => {
    const editor = editorRef.value
    if (!editor) return
    v ? editor.disable() : editor.enable()
  }
)

function handleCreated(editor) {
  editorRef.value = editor
  if (props.disabled) editor.disable()
}

onBeforeUnmount(() => {
  const editor = editorRef.value
  if (editor) editor.destroy()
})
</script>

<style scoped>
.rich-editor {
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  overflow: hidden;
}

.rich-editor__toolbar {
  border-bottom: 1px solid var(--border);
  /* 弹窗内宽度足够，工具栏换行展示，不再出横向滚动条 */
  flex-wrap: wrap;
}

.rich-editor__toolbar :deep(.w-e-toolbar) {
  flex-wrap: wrap;
}

.rich-editor__body {
  min-height: 220px;
  overflow-y: hidden;
}

.rich-editor--disabled {
  background: #FAFAFA;
}
</style>
