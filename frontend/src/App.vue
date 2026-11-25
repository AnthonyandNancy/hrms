<script setup lang="ts">
import { onErrorCaptured, ref, watch } from 'vue'
import { RouterView, useRoute } from 'vue-router'
import { errorCopy } from '@/api/errors'
import { requestIdFromError, toError } from '@/utils/renderErrors'

/**
 * Last-resort boundary for a broken shell. Page and route errors are isolated
 * lower in AppShell so the navigation remains usable; only a failure that
 * escapes both nested boundaries reaches this panel.
 */
const route = useRoute()
const boundaryError = ref<Error | null>(null)
const boundaryRequestId = ref<string | null>(null)
const boundaryPath = ref('')
const lastBoundaryAt = ref(0)
const DUPLICATE_WINDOW_MS = 1000

onErrorCaptured((error) => {
  const normalized = toError(error)
  const currentPath = route.fullPath

  // A shell failure can trigger more than one captured render error. Keep one
  // panel per route (and within a one-second window) instead of stacking
  // duplicate messages.
  const now = Date.now()
  if (
    boundaryError.value &&
    boundaryPath.value === currentPath &&
    now - lastBoundaryAt.value < DUPLICATE_WINDOW_MS
  ) {
    return false
  }

  boundaryError.value = normalized
  boundaryRequestId.value = requestIdFromError(error)
  boundaryPath.value = currentPath
  lastBoundaryAt.value = now
  return false
})

watch(
  () => route.fullPath,
  () => {
    boundaryError.value = null
    boundaryRequestId.value = null
    boundaryPath.value = ''
    lastBoundaryAt.value = 0
  },
)

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
      <p v-if="boundaryRequestId" class="boundary__hint">
        请求编号：<span class="mono">{{ boundaryRequestId }}</span>
      </p>
      <p class="boundary__hint">你的数据没有被修改。刷新页面可重新加载；若问题持续，请联系系统管理员。</p>
      <el-button type="primary" @click="reload">刷新页面</el-button>
    </div>
  </div>
  <RouterView v-else />
</template>
