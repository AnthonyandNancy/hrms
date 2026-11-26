<script setup lang="ts">
import { computed } from 'vue'
import { formatBusinessDate, formatDateTimeInTimezone } from '@/utils/format'
import {
  formatMetricValue,
  freshnessLabel,
  metricLabel,
  metricUnitText,
  unavailableMetricReason,
  type MetricCardMetric,
} from '../api'

const props = withDefaults(
  defineProps<{
    metric: MetricCardMetric
    asOf: string
    freshness: string
    freshnessAt?: string | null
    partialReason?: string | null
  }>(),
  { freshnessAt: null, partialReason: null },
)

const emit = defineEmits<{
  drill: [metric: MetricCardMetric]
}>()

const label = computed(() => metricLabel(props.metric.code, props.metric.label))
const unit = computed(() => metricUnitText(props.metric))
const unavailable = computed(() => props.metric.status !== 'AVAILABLE')
const valueText = computed(() => (unavailable.value ? '—' : formatMetricValue(props.metric.value)))
const computedAtText = computed(() => formatDateTimeInTimezone(props.freshnessAt ?? props.metric.computedAt))
const displayPartialReason = computed(() =>
  unavailable.value ? unavailableMetricReason(props.metric.code) : props.partialReason,
)
</script>

<template>
  <button
    type="button"
    class="metric-card metric-card--reporting"
    :data-testid="`metric-card-${metric.code}`"
    :aria-label="`${label}，${valueText}${unit}，${formatBusinessDate(asOf)}，查看下钻`"
    @click="emit('drill', metric)"
  >
    <span class="metric-card__top">
      <span class="metric-card__label">{{ label }}</span>
      <span class="plain-chip">{{ freshnessLabel(freshness) }}</span>
    </span>

    <span class="metric-card__value" data-testid="metric-value">
      {{ valueText }}<span v-if="unit && !unavailable" class="metric-card__unit">{{ unit }}</span>
    </span>

    <span class="metric-card__meta">
      <span>as-of {{ formatBusinessDate(asOf) }}</span>
      <span v-if="freshnessAt || metric.computedAt" class="small muted">计算于 {{ computedAtText }}</span>
    </span>

    <el-alert
      v-if="unavailable || displayPartialReason"
      type="warning"
      :closable="false"
      show-icon
      class="metric-card__alert"
      data-testid="metric-partial"
    >
      <template #title>
        <span v-if="unavailable">该指标当前不可用，未以 0 或估算值替代。</span>
        <span v-if="displayPartialReason" class="metric-card__reason">{{ displayPartialReason }}</span>
      </template>
    </el-alert>

    <span class="metric-card__footer">
      <span v-if="!unavailable" class="metric-card__drill">{{ metric.drilldownLabel || '查看明细 →' }}</span>
      <span v-else class="metric-card__drill">查看不可用原因 →</span>
    </span>
  </button>
</template>

<style scoped>
.metric-card--reporting {
  width: 100%;
  font: inherit;
  color: inherit;
}

.metric-card__top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 8px;
}

.metric-card__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 12px;
  margin-top: 8px;
  color: var(--color-ink-muted);
  font-size: 11px;
}

.metric-card__alert {
  margin-top: 10px;
  border-radius: var(--radius-sm);
}

.metric-card__alert :deep(.el-alert__title) {
  display: flex;
  flex-direction: column;
  gap: 2px;
  font-size: 11px;
  line-height: 1.45;
}

.metric-card__reason {
  color: #8a5a12;
}

.metric-card__footer {
  padding-top: 12px;
}
</style>
