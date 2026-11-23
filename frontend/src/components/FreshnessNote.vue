<script setup lang="ts">
import { computed } from 'vue'
import { formatDateTimeInTimezone } from '@/utils/format'

const props = withDefaults(
  defineProps<{
    timestamp: string | null
    timezone?: string
    label?: string
  }>(),
  { timezone: 'Asia/Shanghai', label: '数据更新于' },
)

const formatted = computed(() => formatDateTimeInTimezone(props.timestamp, props.timezone))
</script>

<template>
  <p class="freshness" role="note">
    <span class="freshness__dot" aria-hidden="true" />
    {{ label }} <time :datetime="timestamp ?? undefined">{{ formatted }}</time>
    <span class="freshness__zone">（{{ timezone }}）</span>
  </p>
</template>
