<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import type { PageSpec } from '@/router/registry'
import { PAGE_REGISTRY } from '@/router/registry'
import LoadingState from '@/components/LoadingState.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import NoActiveEmploymentState from '@/components/NoActiveEmploymentState.vue'
import AttendancePanel from './panels/AttendancePanel.vue'
import LeavePanel from './panels/LeavePanel.vue'
import OvertimePanel from './panels/OvertimePanel.vue'
import TravelPanel from './panels/TravelPanel.vue'
import PayrollPanel from './panels/PayrollPanel.vue'
import type { TimePayrollPageDefinition } from './types'
import { useTimePayrollLoad } from './useTimePayrollLoad'

const props = defineProps<{ definition: TimePayrollPageDefinition }>()

const page = computed<PageSpec | null>(
  () => PAGE_REGISTRY.find((entry) => entry.componentKey === props.definition.key) ?? null,
)

const panelComponent = computed(() => {
  switch (props.definition.domain) {
    case 'attendance':
      return AttendancePanel
    case 'leave':
      return LeavePanel
    case 'overtime':
      return OvertimePanel
    case 'travel':
      return TravelPanel
    case 'payroll':
      return PayrollPanel
    default:
      return null
  }
})

const { pageError, loading, loaded, rows, gap, partial, relatedErrors, related, selectedRunId, load } =
  useTimePayrollLoad(props.definition)

defineExpose({ load, pageError, loading, loaded, rows, partial })

const title = computed(() => props.definition.title)
const successMessage = ref('')
const FORM_PANELS = new Set([
  'attendance-corrections',
  'attendance-closing',
  'leave-apply',
  'travel-apply',
  'overtime-settlement',
  'leave-balances',
  'leave-reports',
  'overtime-policies',
  'payroll-components',
  'payroll-plans',
  'payroll-statutory',
  'payroll-inputs',
  'payroll-payslips',
  'payroll-employee-plans',
  'payroll-adjustments',
])
const showEmpty = computed(
  () =>
    loaded.value &&
    rows.value.length === 0 &&
    props.definition.emptyTitle !== undefined &&
    !FORM_PANELS.has(props.definition.panel),
)

function onActionSuccess(message: string): void {
  successMessage.value = message
}

onMounted(load)
</script>

<template>
  <div class="page timepay-page" :data-page="definition.testId">
    <header class="page-header">
      <div class="page-header__copy">
        <p class="page-header__breadcrumb">
          {{ page?.menu?.group ?? '假勤与薪酬' }}
        </p>
        <h1 class="page-header__title">{{ title }}</h1>
        <p class="page-header__description">{{ definition.description }}</p>
      </div>
      <div class="page-header__actions">
        <el-button @click="load">刷新</el-button>
      </div>
    </header>

    <NoActiveEmploymentState
      v-if="pageError.noActiveEmployment.value"
      :request-id="pageError.requestId.value"
      :links="pageError.safeLinks.value"
    />
    <ErrorState
      v-else-if="pageError.message.value"
      :title="`${title}暂时无法加载`"
      :description="pageError.message.value"
      :code="pageError.code.value"
      :request-id="pageError.requestId.value"
      @retry="load"
    />
    <LoadingState v-else-if="loading" :rows="6" :label="`正在加载${title}`" />

    <template v-else>
      <section v-if="gap" class="timepay-gap" data-testid="backend-gap" role="status">
        <p class="timepay-gap__title">后端接口待补</p>
        <p class="timepay-gap__path"><span class="mono">{{ gap.method }} {{ gap.path }}</span></p>
        <p class="timepay-gap__reason">{{ gap.reason }}</p>
      </section>

      <p v-if="successMessage" class="timepay-action-success" role="status">{{ successMessage }}</p>

      <section v-if="partial" class="timepay-partial" data-testid="partial-state" role="status">
        <p class="timepay-partial__title">部分数据暂不可用</p>
        <p v-if="relatedErrors.length > 0" class="timepay-partial__body">
          以下关联数据未能加载：<span class="mono">{{ relatedErrors.join('、') }}</span>。页面主体数据仍按服务端返回值展示。
        </p>
        <p v-else class="timepay-partial__body">页面主体数据可用；标注“后端接口待补”的能力尚未开放，不会展示虚构数据。</p>
      </section>

      <EmptyState
        v-if="showEmpty"
        :title="definition.emptyTitle ?? '当前没有数据'"
        :description="definition.emptyDescription ?? '当前筛选条件下没有记录。'"
      />

      <component
        :is="panelComponent"
        v-if="!showEmpty"
        :definition="definition"
        :rows="rows"
        :related="related"
        :selected-run-id="selectedRunId"
        @refresh="load"
        @action-success="onActionSuccess"
      />

      <slot />
    </template>
  </div>
</template>

<style scoped>
.timepay-gap {
  margin-bottom: 14px;
  padding: 12px 14px;
  background: #fffaf0;
  border: 1px solid #f1d7a5;
  border-radius: var(--radius-md);
}

.timepay-gap__title {
  font-size: 13px;
  font-weight: 680;
  color: #8f5b00;
}

.timepay-gap__path {
  margin-top: 3px;
  font-size: 11.5px;
  color: #6b4a00;
}

.timepay-gap__reason {
  margin-top: 4px;
  color: var(--color-ink-muted);
  font-size: 11.5px;
}

.timepay-partial {
  margin-bottom: 14px;
  padding: 10px 14px;
  background: var(--color-canvas);
  border: 1px dashed var(--color-line-strong);
  border-radius: var(--radius-md);
}

.timepay-partial__title {
  font-size: 12.5px;
  font-weight: 650;
}

.timepay-partial__body {
  margin-top: 2px;
  color: var(--color-ink-muted);
  font-size: 11.5px;
}

.timepay-action-success {
  margin: 12px 0;
  padding: 8px 10px;
  color: #1f6b3a;
  font-size: 12px;
  background: #eef8f1;
  border: 1px solid #bfe3ca;
  border-radius: 6px;
}

.timepay-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12px;
  background: #fff;
}

.timepay-table th,
.timepay-table td {
  padding: 8px 10px;
  text-align: left;
  white-space: nowrap;
  border-bottom: 1px solid var(--color-line);
}

.timepay-table th {
  color: var(--color-ink-muted);
  font-weight: 600;
  background: var(--color-canvas);
}

.timepay-summary,
.timepay-metrics {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-bottom: 14px;
}

.timepay-summary > div,
.timepay-metrics > div {
  min-width: 118px;
  padding: 10px 12px;
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
}

.timepay-summary span,
.timepay-metrics span {
  display: block;
  color: var(--color-ink-muted);
  font-size: 10.5px;
}

.timepay-summary strong,
.timepay-metrics strong {
  display: block;
  margin-top: 2px;
  font-size: 16px;
}

.timepay-cards {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
  gap: 12px;
}

.timepay-card {
  padding: 14px;
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
}

.timepay-card header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.timepay-card h2 {
  font-size: 13px;
  font-weight: 650;
}

.timepay-card__value {
  margin: 10px 0;
  font-size: 22px;
  font-weight: 700;
}

.timepay-card__value small {
  margin-left: 4px;
  color: var(--color-ink-muted);
  font-size: 11px;
  font-weight: 400;
}

.timepay-card dl,
.timepay-facts {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  margin: 0;
}

.timepay-card dt,
.timepay-facts dt {
  color: var(--color-ink-muted);
  font-size: 10.5px;
}

.timepay-card dd,
.timepay-facts dd {
  margin: 2px 0 0;
  font-size: 12.5px;
}

.timepay-form__grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
  gap: 10px;
  margin: 12px 0;
}

.timepay-form label {
  display: flex;
  flex-direction: column;
  gap: 4px;
  font-size: 11.5px;
  color: var(--color-ink-muted);
}

.timepay-form input,
.timepay-form select,
.timepay-form textarea {
  padding: 7px 9px;
  font: inherit;
  font-size: 12px;
  color: var(--color-ink);
  background: #fff;
  border: 1px solid var(--color-line-strong);
  border-radius: 5px;
}

.timepay-form__wide {
  margin-bottom: 12px;
}

.timepay-form__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 8px;
}

.timepay-check {
  flex-direction: row !important;
  align-items: center;
  gap: 6px !important;
}

.timepay-status {
  display: inline-block;
  padding: 2px 7px;
  font-size: 10.5px;
  border-radius: 999px;
  background: var(--color-canvas);
}

.timepay-status--success {
  color: #1f6b3a;
  background: #eef8f1;
}

.timepay-status--warning {
  color: #8f5b00;
  background: #fffaf0;
}

.timepay-status--danger {
  color: #9a2e2e;
  background: #fff1f1;
}

.timepay-pii-note {
  margin-top: 10px;
  padding: 8px 10px;
  color: var(--color-ink-muted);
  font-size: 11.5px;
  background: var(--color-canvas);
  border-radius: 5px;
}

.timepay-action-error {
  margin: 12px 0;
  padding: 8px 10px;
  color: #9a2e2e;
  font-size: 12px;
  background: #fff1f1;
  border: 1px solid #f1c1c1;
  border-radius: 6px;
}
</style>
