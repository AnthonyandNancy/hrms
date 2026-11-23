<script setup lang="ts">
import { onErrorCaptured, ref } from 'vue'
import { RouterView } from 'vue-router'
import { ElMessage } from 'element-plus'
import { errorCopy } from '@/api/errors'

const boundaryError = ref<Error | null>(null)

onErrorCaptured((error) => {
  boundaryError.value = error instanceof Error ? error : new Error(String(error))
  ElMessage.error(errorCopy(boundaryError.value))
  return false
})

function reload(): void {
  window.location.reload()
}
</script>

<template>
  <div v-if="boundaryError" class="boundary" role="alert">
    <div class="boundary__panel">
      <p class="boundary__eyebrow">界面错误边界</p>
      <h1>页面暂时无法渲染</h1>
      <p>{{ errorCopy(boundaryError) }}</p>
      <p class="boundary__hint">你的数据没有被修改。刷新页面可重新加载；若问题持续，请联系系统管理员。</p>
      <el-button type="primary" @click="reload">刷新页面</el-button>
    </div>
  </div>
  <RouterView v-else />
</template>
