<script setup lang="ts">
withDefaults(
  defineProps<{
    rows?: number
    label?: string
  }>(),
  { rows: 5, label: '正在加载数据' },
)
</script>

<template>
  <div class="loading-state" role="status" aria-live="polite" :aria-label="label">
    <div class="loading-state__rows" aria-hidden="true">
      <span v-for="index in rows" :key="index" class="loading-state__row" :style="{ width: `${92 - index * 6}%` }" />
    </div>
    <span class="visually-hidden">{{ label }}</span>
  </div>
</template>
