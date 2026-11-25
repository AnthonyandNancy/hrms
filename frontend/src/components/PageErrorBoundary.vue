<script setup lang="ts">
import { onErrorCaptured, ref } from 'vue'
import ErrorState from '@/components/ErrorState.vue'
import { isChunkLoadError, requestIdFromError, toError } from '@/utils/renderErrors'

const error = ref<Error | null>(null)
const requestId = ref<string | null>(null)
const chunkLoadFailed = ref(false)

onErrorCaptured((captured) => {
  error.value = toError(captured)
  requestId.value = requestIdFromError(captured)
  chunkLoadFailed.value = isChunkLoadError(captured)
  return false
})

function reload(): void {
  window.location.reload()
}

function reset(): void {
  error.value = null
  requestId.value = null
  chunkLoadFailed.value = false
}

function retry(): void {
  if (chunkLoadFailed.value) {
    reload()
    return
  }
  reset()
}

defineExpose({ reset })
</script>

<template>
  <section v-if="chunkLoadFailed" class="state-panel error-state page-boundary" role="alert">
    <div class="state-panel__mark" aria-hidden="true">!</div>
    <div class="state-panel__content">
      <h2 class="state-panel__title">页面版本已更新，请刷新</h2>
      <p class="state-panel__body">
        当前页面引用的资源版本已过期。刷新后会加载最新版本，不会影响已保存的数据。
      </p>
      <p v-if="requestId" class="state-panel__hint">
        请求编号：<span class="mono">{{ requestId }}</span>
      </p>
    </div>
    <el-button type="primary" plain @click="reload">刷新页面</el-button>
  </section>
  <ErrorState
    v-else-if="error"
    title="页面暂时无法显示"
    :description="error.message"
    :code="error.name"
    :request-id="requestId"
    action-label="重试"
    @retry="retry"
  />
  <slot v-else />
</template>
