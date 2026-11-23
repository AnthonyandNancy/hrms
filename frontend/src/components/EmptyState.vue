<script setup lang="ts">
withDefaults(
  defineProps<{
    title: string
    description?: string
    actionLabel?: string
  }>(),
  { description: '当前条件下没有记录。可以调整筛选条件后再试。' },
)

const emit = defineEmits<{
  action: []
}>()
</script>

<template>
  <section class="state-panel empty-state" aria-live="polite">
    <div class="state-panel__mark" aria-hidden="true">—</div>
    <div class="state-panel__content">
      <h2 class="state-panel__title">{{ title }}</h2>
      <p class="state-panel__body">{{ description }}</p>
    </div>
    <el-button v-if="actionLabel" plain @click="emit('action')">{{ actionLabel }}</el-button>
  </section>
</template>
