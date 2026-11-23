<script setup lang="ts">
import { computed } from 'vue'
import type { ApplicationStatus, EffectStatus } from '@/api/contracts'
import { APPLICATION_STATUS, EFFECT_STATUS } from '@/utils/status'

const props = withDefaults(
  defineProps<{
    kind: 'application' | 'effect'
    status: ApplicationStatus | EffectStatus | null | undefined
    size?: 'sm' | 'md'
    showLabelPrefix?: boolean
  }>(),
  { size: 'md', showLabelPrefix: false },
)

const presentation = computed(() => {
  if (props.kind === 'application') {
    return props.status ? APPLICATION_STATUS[props.status as ApplicationStatus] : null
  }
  return props.status ? EFFECT_STATUS[props.status as EffectStatus] : null
})

const prefix = computed(() => {
  if (!props.showLabelPrefix) return ''
  return props.kind === 'application' ? '申请状态' : '生效状态'
})
</script>

<template>
  <span
    v-if="presentation"
    class="status-badge"
    :class="[`status-badge--${presentation.tone}`, `status-badge--${size}`]"
    :data-status-kind="kind"
    :data-status-value="status"
  >
    <span class="status-badge__symbol" aria-hidden="true">{{ presentation.symbol }}</span>
    <span v-if="prefix" class="status-badge__prefix">{{ prefix }}</span>
    <span class="status-badge__label">{{ presentation.label }}</span>
  </span>
  <span
    v-else
    class="status-badge status-badge--muted status-badge--sm"
    :data-status-kind="kind"
    data-status-value="NONE"
  >
    <span class="status-badge__symbol" aria-hidden="true">—</span>
    <span v-if="prefix" class="status-badge__prefix">{{ prefix }}</span>
    <span class="status-badge__label">无生效要求</span>
  </span>
</template>
