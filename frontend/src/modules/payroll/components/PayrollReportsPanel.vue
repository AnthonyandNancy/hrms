<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ApiError, errorCode, errorCopy } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import LoadingState from '@/components/LoadingState.vue'
import MoneyText from '@/components/MoneyText.vue'
import DataTable, { type DataTableColumn } from '@/components/DataTable.vue'
import { getPayrollReportSummary, type PayrollReportSummary } from '../api'

defineOptions({ name: 'PayrollReportsPanel' })

const session = useSessionStore()
const canRead = computed(() => session.can('payroll.report.read'))
const canExport = computed(() => session.can('payroll.export'))

const loading = ref(false)
const loaded = ref(false)
const errorMessage = ref('')
const errorCodeValue = ref('')
const errorRequestId = ref<string | null>(null)

const period = ref('')
const groupBy = ref<'period' | 'department'>('period')
const summary = ref<PayrollReportSummary | null>(null)

const columns = computed<DataTableColumn[]>(() => [
  { key: 'groupLabel', label: groupBy.value === 'department' ? '部门' : '期间', minWidth: '140px' },
  { key: 'runCount', label: '批次数', width: '90px', align: 'right' },
  { key: 'employeeCount', label: '人数', width: '90px', align: 'right' },
  { key: 'gross', label: '应发合计', width: '140px', align: 'right' },
  { key: 'deductions', label: '扣款合计', width: '140px', align: 'right' },
  { key: 'net', label: '实发合计', width: '140px', align: 'right' },
  { key: 'employerCost', label: '单位成本', width: '140px', align: 'right' },
])

const rows = computed(() => {
  const items = summary.value?.items ?? []
  return items.map((row) => ({
    ...row,
    groupLabel:
      groupBy.value === 'department'
        ? (row.departmentName ?? '未分配部门')
        : (row.period ?? '—'),
  })) as unknown as Array<Record<string, unknown>>
})

function capture(reason: unknown): void {
  errorCodeValue.value = errorCode(reason)
  errorRequestId.value = reason instanceof ApiError ? reason.requestId : null
  errorMessage.value =
    reason instanceof ApiError && reason.code === 'FORBIDDEN'
      ? '无权读取薪资汇总报表。报表只汇总已发布批次，并且不返回员工级工资明细。'
      : errorCopy(reason)
}

async function load(): Promise<void> {
  if (!canRead.value) {
    loaded.value = true
    return
  }
  loading.value = true
  loaded.value = false
  errorMessage.value = ''
  try {
    summary.value = await getPayrollReportSummary({
      ...(period.value !== '' ? { period: period.value.trim() } : {}),
      groupBy: groupBy.value,
    })
  } catch (reason) {
    summary.value = null
    capture(reason)
  } finally {
    loading.value = false
    loaded.value = true
  }
}

function refreshSummary(): void {
  void load()
}

onMounted(() => {
  void load()
})
</script>

<template>
  <section class="panel payroll-reports" data-testid="payroll-reports-panel">
    <div class="panel__header">
      <div>
        <h2 class="panel__title">薪资报表</h2>
        <p class="panel__subtitle">
          仅汇总已发布、已发放与已关闭批次；服务端返回整数分合计，页面不做金额推算。
        </p>
      </div>
      <el-button :disabled="loading" @click="refreshSummary">刷新</el-button>
    </div>

    <section v-if="!canRead" class="payroll-reports__state" role="alert" data-testid="payroll-report-forbidden">
      <h2>无权查看薪资报表</h2>
      <p>当前账号缺少 payroll.report.read，页面不会请求任何金额汇总。</p>
    </section>

    <template v-else>
      <section class="payroll-reports__filters" aria-label="薪资报表筛选">
        <label>
          <span>期间（可选）</span>
          <el-input v-model="period" placeholder="2026-11" data-testid="payroll-report-period" @keyup.enter="refreshSummary" />
        </label>
        <label>
          <span>分组</span>
          <el-select v-model="groupBy" data-testid="payroll-report-group" @change="refreshSummary">
            <el-option label="按期间" value="period" />
            <el-option label="按部门" value="department" />
          </el-select>
        </label>
        <el-button type="primary" :disabled="loading" data-testid="payroll-report-query" @click="refreshSummary">查询</el-button>
      </section>

      <ErrorState
        v-if="errorMessage !== ''"
        title="薪资报表暂时无法加载"
        :description="errorMessage"
        :code="errorCodeValue"
        :request-id="errorRequestId"
        @retry="load"
      />
      <LoadingState v-else-if="loading" :rows="4" label="正在加载薪资汇总" />

      <template v-else>
        <EmptyState
          v-if="rows.length === 0"
          title="还没有可汇总的已发布批次"
          description="只有发布后的工资批次会进入报表；草稿或计算中的批次不会显示为 0 元。"
        />

        <template v-else-if="summary !== null">
          <dl class="payroll-reports__totals" data-testid="payroll-report-totals">
            <div>
              <dt>应发合计</dt>
              <dd><MoneyText :cents="summary.totals.grossCents" size="md" /></dd>
            </div>
            <div>
              <dt>扣款合计</dt>
              <dd><MoneyText :cents="summary.totals.deductionsCents" size="md" /></dd>
            </div>
            <div>
              <dt>实发合计</dt>
              <dd><MoneyText :cents="summary.totals.netCents" size="md" tone="strong" /></dd>
            </div>
            <div>
              <dt>单位成本</dt>
              <dd><MoneyText :cents="summary.totals.employerCostCents" size="md" /></dd>
            </div>
          </dl>

          <DataTable
            :columns="columns"
            :rows="rows"
            :total="rows.length"
            :page="1"
            :page-size="Math.max(1, rows.length)"
            data-testid="payroll-report-table"
            empty-title="还没有可汇总的已发布批次"
          >
            <template #cell-groupLabel="{ row }">{{ row.groupLabel }}</template>
            <template #cell-runCount="{ row }">{{ row.runCount }}</template>
            <template #cell-employeeCount="{ row }">{{ row.employeeCount }} 人</template>
            <template #cell-gross="{ row }"><MoneyText :cents="Number(row.grossCents)" size="sm" /></template>
            <template #cell-deductions="{ row }"><MoneyText :cents="Number(row.deductionsCents)" size="sm" /></template>
            <template #cell-net="{ row }"><MoneyText :cents="Number(row.netCents)" size="sm" tone="strong" /></template>
            <template #cell-employerCost="{ row }"><MoneyText :cents="Number(row.employerCostCents)" size="sm" /></template>
          </DataTable>

          <p class="payroll-reports__note">
            统计口径：{{ summary.groupBy === 'department' ? '按批次截止日的任职部门' : '按工资期间' }}。
            导出仍从批次详情发起，并按 payroll.export 与字段策略重新授权。
          </p>
          <p v-if="!canExport" class="payroll-reports__note">当前账号没有 payroll.export；报表仅供查看。</p>
        </template>
      </template>
    </template>
  </section>
</template>

<style scoped>
.payroll-reports {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.payroll-reports__state {
  margin: 0 16px 16px;
  padding: 20px;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
}

.payroll-reports__state h2 {
  margin: 0 0 6px;
  font-size: 16px;
}

.payroll-reports__state p,
.payroll-reports__note {
  margin: 0;
  color: #475569;
  font-size: 12px;
}

.payroll-reports__filters {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 12px;
  padding: 0 16px;
}

.payroll-reports__filters label {
  display: flex;
  flex-direction: column;
  gap: 4px;
  color: #475569;
  font-size: 11.5px;
}

.payroll-reports__totals {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 10px;
  margin: 0 16px;
  padding: 14px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
}

.payroll-reports__totals dt {
  color: #64748b;
  font-size: 11px;
}

.payroll-reports__totals dd {
  margin: 4px 0 0;
}
</style>
