<script setup lang="ts">
import { computed } from 'vue'
import { formatMoney } from '@/utils/format'

const props = withDefaults(
  defineProps<{
    cents?: string | number | bigint | null
    size?: 'sm' | 'md' | 'lg'
    tone?: 'default' | 'muted' | 'negative' | 'strong'
    label?: string
    currency?: string
  }>(),
  { size: 'md', tone: 'default' },
)

const formatted = computed(() => formatMoney(props.cents, props.currency ?? 'CNY'))
const isNegative = computed(() => formatted.value.startsWith('-'))
</script>

<template>
  <span
    class="money-text"
    :class="[`money-text--${size}`, `money-text--${tone}`, { 'money-text--negative': isNegative }]"
    :aria-label="label ? `${label}：${formatted}` : undefined"
    :title="formatted"
  >
    <span v-if="label" class="money-text__label">{{ label }}</span>
    <span class="money-text__value">{{ formatted }}</span>
  </span>
</template>
