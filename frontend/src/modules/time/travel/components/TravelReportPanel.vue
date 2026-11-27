<script setup lang="ts">
import { computed, ref } from 'vue'
import { ApiError } from '@/api/errors'
import DataTable, { type DataTableColumn } from '@/components/DataTable.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import LoadingState from '@/components/LoadingState.vue'
import {
  formatMoney,
  getTravelReport,
  type TravelReportRow,
} from '../api'

const emit = defineEmits<{
  success: [message: string]
  'open-travel': [travelId: number]
}>()

const loading = ref(false)
const errorMessage = ref('')
const errorCode = ref('')
const errorRequestId = ref<string | null>(null)
const rows = ref<TravelReportRow[]>([])
const total = ref(0)
const asOf = ref('')
const sourceHash = ref('')
const fromFilter = ref('')
const toFilter = ref('')

const columns: DataTableColumn[] = [
  { key: 'month', label: '月份', width: '100px' },
  { key: 'departmentId', label: '部门', width: '100px' },
  { key: 'employmentCount', label: '员工数', width: '90px', align: 'right' },
  { key: 'approvedTrips', label: '已批准次数', width: '120px', align: 'right' },
  { key: 'pendingTrips', label: '待审批次数', width: '120px', align: 'right' },
  { key: 'approvedDays', label: '已批准天数', width: '120px', align: 'right' },
  { key: 'pendingDays', label: '待审批天数', width: '120px', align: 'right' },
  { key: 'expenseCents', label: '费用合计（分）', width: '150px', align: 'right' },
  { key: 'detail', label: '明细', width: '120px' },
]

const totals = computed(() => ({
  approvedTrips: rows.value.reduce((sum, row) => sum + row.approvedTrips, 0),
  pendingTrips: rows.value.reduce((sum, row) => sum + row.pendingTrips, 0),
  approvedDays: rows.value.reduce((sum, row) => sum + row.approvedDays, 0),
  pendingDays: rows.value.reduce((sum, row) => sum + row.pendingDays, 0),
  expenseCents: rows.value.reduce((sum, row) => sum + row.expenseCents, 0),
}))

function captureError(reason: unknown): void {
  if (reason instanceof ApiError) {
    errorMessage.value = reason.message || '出差统计暂时无法加载。'
    errorCode.value = reason.code
    errorRequestId.value = reason.requestId
    return
  }
  errorMessage.value = '出差统计暂时无法加载。'
  errorCode.value = 'UNKNOWN'
  errorRequestId.value = null
}

async function load(): Promise<void> {
  if (fromFilter.value === '' || toFilter.value === '') {
    errorMessage.value = '请选择统计开始日期与结束日期。'
    errorCode.value = 'VALIDATION_FAILED'
    errorRequestId.value = null
    rows.value = []
    return
  }

  loading.value = true
  errorMessage.value = ''
  try {
    const result = await getTravelReport(fromFilter.value, toFilter.value)
    rows.value = result.rows
    total.value = result.total
    asOf.value = result.asOf
    sourceHash.value = result.sourceHash
  } catch (reason) {
    captureError(reason)
    rows.value = []
    total.value = 0
  } finally {
    loading.value = false
  }
}

function openDetail(row: TravelReportRow): void {
  if (row.travelIds.length === 0) return
  emit('open-travel', row.travelIds[0] ?? 0)
}
</script>

<template>
  <section class="travel-report" data-testid="travel-reports-panel">
    <header class="travel-report__header">
      <div>
        <h2>出差统计</h2>
        <p class="travel-report__muted">
          按服务端范围聚合月度出差次数、自然日天数与费用整数分合计；不推算费用，不暴露范围外员工。
        </p>
      </div>
      <span class="travel-report__badge">{{ total }} 行</span>
    </header>

    <form class="travel-report__filters" data-testid="travel-report-filters" @submit.prevent="load">
      <label>
        <span>开始日期</span>
        <input v-model="fromFilter" data-testid="travel-report-from" type="date" required />
      </label>
      <label>
        <span>结束日期</span>
        <input v-model="toFilter" data-testid="travel-report-to" type="date" required />
      </label>
      <button type="submit" class="travel-report__primary" :disabled="loading" data-testid="travel-report-load">查询</button>
    </form>

    <p v-if="errorMessage !== ''" class="travel-report__error" role="alert" data-testid="travel-report-error">{{ errorMessage }}</p>
    <p v-if="asOf !== ''" class="travel-report__meta" data-testid="travel-report-as-of">
      数据截至：{{ asOf }} · 来源哈希：<span class="mono">{{ sourceHash.slice(0, 12) }}</span>
    </p>

    <div v-if="loading" data-testid="travel-report-loading">
      <LoadingState :rows="4" label="正在加载出差统计" />
    </div>
    <ErrorState
      v-else-if="errorMessage !== ''"
      data-testid="travel-report-load-error"
      title="出差统计暂时无法加载"
      :description="errorMessage"
      :code="errorCode"
      :request-id="errorRequestId"
      @retry="load"
    />
    <EmptyState
      v-else-if="rows.length === 0"
      data-testid="travel-report-empty"
      title="所选期间没有出差记录"
      description="服务端返回空聚合结果；请调整日期后重试。"
    />
    <template v-else>
      <section class="travel-report__metrics" aria-label="出差统计合计">
        <div><span>已批准次数</span><strong>{{ totals.approvedTrips }}</strong></div>
        <div><span>待审批次数</span><strong>{{ totals.pendingTrips }}</strong></div>
        <div><span>已批准天数</span><strong>{{ totals.approvedDays }}</strong></div>
        <div><span>待审批天数</span><strong>{{ totals.pendingDays }}</strong></div>
        <div><span>费用合计</span><strong>{{ formatMoney(totals.expenseCents) }}</strong></div>
      </section>

      <DataTable
        :columns="columns"
        :rows="rows as unknown as Array<Record<string, unknown>>"
        :total="total"
        :page="1"
        :page-size="Math.max(1, rows.length)"
        row-key="month"
        data-testid="travel-report-table"
        empty-title="所选期间没有出差记录"
      >
        <template #cell-month="{ row }">{{ row.month }}</template>
        <template #cell-departmentId="{ row }">{{ row.departmentId ?? '未分配' }}</template>
        <template #cell-employmentCount="{ row }">{{ row.employmentCount }}</template>
        <template #cell-approvedTrips="{ row }">{{ row.approvedTrips }}</template>
        <template #cell-pendingTrips="{ row }">{{ row.pendingTrips }}</template>
        <template #cell-approvedDays="{ row }">{{ row.approvedDays }}</template>
        <template #cell-pendingDays="{ row }">{{ row.pendingDays }}</template>
        <template #cell-expenseCents="{ row }">
          <span class="mono">{{ formatMoney(Number(row.expenseCents)) }}</span>
        </template>
        <template #cell-detail="{ row }">
          <button
            type="button"
            class="travel-report__tertiary"
            :data-testid="`travel-report-detail-${String(row.month)}`"
            @click="openDetail(row as unknown as TravelReportRow)"
          >
            查看明细
          </button>
        </template>
      </DataTable>
    </template>
  </section>
</template>

<style scoped>
.travel-report { display: grid; gap: 14px; }
.travel-report__header { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; }
.travel-report__header h2 { margin: 0; font-size: 18px; }
.travel-report__muted { margin: 3px 0 0; color: #64748b; font-size: 11.5px; line-height: 1.5; }
.travel-report__badge { padding: 3px 8px; color: #1d4ed8; font-size: 10.5px; background: #eff6ff; border-radius: 999px; }
.travel-report__filters { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; padding: 12px; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; }
.travel-report__filters label { display: flex; flex-direction: column; gap: 3px; font-size: 11px; }
.travel-report__filters input { min-width: 150px; padding: 6px 8px; font: inherit; font-size: 11.5px; border: 1px solid #cbd5e1; border-radius: 6px; }
.travel-report__metrics { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 10px; padding: 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; }
.travel-report__metrics span { color: #64748b; font-size: 10.5px; }
.travel-report__metrics strong { display: block; margin-top: 3px; font-size: 16px; }
.travel-report__meta { margin: 0; color: #64748b; font-size: 11px; }
.travel-report__error { margin: 0; padding: 9px 12px; color: #991b1b; font-size: 11.5px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; }
.travel-report__primary, .travel-report__tertiary { padding: 6px 11px; font-size: 11.5px; border-radius: 6px; cursor: pointer; }
.travel-report__primary { color: #fff; background: #1d4ed8; border: 1px solid #1d4ed8; }
.travel-report__tertiary { color: #1d4ed8; background: transparent; border: 1px solid #bfdbfe; }
.mono { font-family: ui-monospace, monospace; }
@media (max-width: 900px) { .travel-report__metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
