<script setup lang="ts">
import { computed } from 'vue'
import { formatBusinessDate, formatDateTimeInTimezone } from '@/utils/format'
import {
  formatMetric,
  freshnessLabel,
  metricAvailabilityLabel,
  metricLabel,
  metricReasonText,
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
const formatted = computed(() => formatMetric(props.metric))
const availability = computed(() => metricAvailabilityLabel(props.metric.status))
const unavailable = computed(() => props.metric.status !== 'AVAILABLE')
const showAlert = computed(() => unavailable.value || (props.partialReason?.trim() ?? '') !== '')
const computedAtText = computed(() => formatDateTimeInTimezone(props.freshnessAt ?? props.metric.computedAt))
const displayPartialReason = computed(() => unavailable.value ? metricReasonText(props.metric) : (props.partialReason ?? ''))
const scopeLabel = computed(() => props.metric.scopeLabel?.trim() || '按当前授权范围')
const drillLabel = computed(() => {
  if (unavailable.value) return '查看不可用原因'
  if (props.metric.code === 'headcount' || props.metric.code === 'probation') return '查看员工明细 →'
  if (props.metric.code === 'contract_expiring') return '查看合同明细 →'
  if (props.metric.code === 'pending_approvals') return '进入流程中心 →'
  if (props.metric.code === 'leave_liability') return '查看假期余额 →'
  if (props.metric.code === 'payroll_cost_by_entity') return '进入薪资核算 →'
  if (props.metric.code === 'recruitment_funnel') return '进入候选人 →'
  return '查看明细 →'
})
</script>

<template>
  <button
    type="button"
    class="metric-card metric-card--reporting"
    :data-testid="`metric-card-${metric.code}`"
    :aria-label="`${label}，${formatted.value}${formatted.unit}，${formatBusinessDate(asOf)}，${drillLabel}`"
    @click="emit('drill', metric)"
  >
    <span class="metric-card__top">
      <span class="metric-card__label">{{ label }}</span>
      <span class="plain-chip" :class="{ 'plain-chip--warning': unavailable }">{{ availability }}</span>
    </span>

    <span class="metric-card__value" data-testid="metric-value">
      {{ formatted.value }}<span v-if="formatted.unit && !unavailable" class="metric-card__unit">{{ formatted.unit }}</span>
    </span>

    <span class="metric-card__meta">
      <span>截至 {{ formatBusinessDate(asOf) }}</span>
      <span>{{ scopeLabel }}</span>
      <span v-if="freshnessAt || metric.computedAt" class="small muted">计算于 {{ computedAtText }}</span>
      <span class="small muted">{{ freshnessLabel(freshness) }}</span>
    </span>

    <el-alert
      v-if="showAlert"
      type="warning"
      :closable="false"
      show-icon
      class="metric-card__alert"
      data-testid="metric-partial"
    >
      <template #title>
        <span v-if="unavailable">{{ availability }}：{{ displayPartialReason }}</span>
        <span v-else>{{ displayPartialReason }}</span>
      </template>
    </el-alert>

    <span class="metric-card__footer">
      <span class="metric-card__drill">{{ drillLabel }}</span>
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
  font-size: 11px;
  line-height: 1.45;
}

.metric-card__footer {
  padding-top: 12px;
}

.plain-chip--warning {
  color: #8a5a12;
  background: #fdf3e2;
}
</style>
