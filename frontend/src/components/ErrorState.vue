<script setup lang="ts">
withDefaults(
  defineProps<{
    title?: string
    description: string
    code?: string
    requestId?: string | null
    actionLabel?: string
  }>(),
  { title: '暂时无法加载', code: '', requestId: null, actionLabel: '重试' },
)

const emit = defineEmits<{
  retry: []
}>()
</script>

<template>
  <section class="state-panel error-state" role="alert" aria-live="assertive">
    <div class="state-panel__mark" aria-hidden="true">!</div>
    <div class="state-panel__content">
      <h2 class="state-panel__title">{{ title }}</h2>
      <p class="state-panel__body">{{ description }}</p>
      <p v-if="requestId" class="state-panel__request" data-testid="request-id">
        请求编号：<span class="mono">{{ requestId }}</span>
      </p>
      <p class="state-panel__hint">
        如多次失败，请记录错误类别与请求编号并联系系统管理员。
        <template v-if="code">错误类别：{{ code }}</template>
      </p>
    </div>
    <el-button type="primary" plain @click="emit('retry')">{{ actionLabel }}</el-button>
  </section>
</template>
