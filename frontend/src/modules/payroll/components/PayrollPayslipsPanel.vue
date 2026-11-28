<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ApiError, errorCode, errorCopy } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import LoadingState from '@/components/LoadingState.vue'
import MoneyText from '@/components/MoneyText.vue'
import DataTable, { type DataTableColumn } from '@/components/DataTable.vue'
import { listMyPayslips, listPayrollRuns, type MyPayslip, type PayrollRunSummary } from '../api'

defineOptions({ name: 'PayrollPayslipsPanel' })

const router = useRouter()
const session = useSessionStore()

const canReadPayslip = computed(() => session.can('payroll.payslip.read.self'))
const canManage = computed(() => session.can('payroll.run.publish'))
const canRunRead = computed(() => session.can('payroll.run.read'))

const loading = ref(false)
const loaded = ref(false)
const errorMessage = ref('')
const errorCodeValue = ref('')
const errorRequestId = ref<string | null>(null)

const payslips = ref<MyPayslip[]>([])
const publishedRuns = ref<PayrollRunSummary[]>([])
const publishedLoading = ref(false)

const columns: DataTableColumn[] = [
  { key: 'period', label: '工资期间', minWidth: '130px' },
  { key: 'runId', label: '批次', width: '100px' },
  { key: 'version', label: '工资条版本', width: '110px' },
  { key: 'gross', label: '应发', width: '120px', align: 'right' },
  { key: 'deductions', label: '扣款', width: '120px', align: 'right' },
  { key: 'net', label: '实发', width: '120px', align: 'right' },
  { key: 'publishedAt', label: '发布时间', minWidth: '170px' },
]

const publishedRunColumns: DataTableColumn[] = [
  { key: 'period', label: '期间', minWidth: '120px' },
  { key: 'statusLabel', label: '批次状态', width: '120px' },
  { key: 'employeeCount', label: '人数', width: '90px', align: 'right' },
  { key: 'netCents', label: '实发合计', width: '140px', align: 'right' },
  { key: 'actions', label: '操作', width: '130px', align: 'right' },
]

function capture(reason: unknown): void {
  errorCodeValue.value = errorCode(reason)
  errorRequestId.value = reason instanceof ApiError ? reason.requestId : null
  errorMessage.value =
    reason instanceof ApiError && reason.code === 'FORBIDDEN'
      ? '无权查看工资条。工资条仅本人可见，未发布或他人工资条统一返回 404。'
      : errorCopy(reason)
}

async function load(): Promise<void> {
  if (!canReadPayslip.value) {
    loaded.value = true
    return
  }
  loading.value = true
  loaded.value = false
  errorMessage.value = ''
  try {
    payslips.value = await listMyPayslips()
  } catch (reason) {
    payslips.value = []
    capture(reason)
  } finally {
    loading.value = false
    loaded.value = true
  }
}

async function loadPublishedRuns(): Promise<void> {
  if (!canRunRead.value) return
  publishedLoading.value = true
  try {
    const page = await listPayrollRuns({ status: 'PUBLISHED' }, 1, 20)
    publishedRuns.value = page.items
  } catch {
    publishedRuns.value = []
  } finally {
    publishedLoading.value = false
  }
}

function runPeriod(runId: number): string {
  const run = publishedRuns.value.find((candidate) => candidate.id === runId)
  return run?.period ?? '—'
}

function publishedAtLabel(value: string | null): string {
  return value === null ? '—' : value.slice(0, 10)
}

function openRun(run: PayrollRunSummary): void {
  void router.push(`/payroll/runs/${run.id}?tab=payslips`)
}


onMounted(() => {
  void load()
  void loadPublishedRuns()
})
</script>

<template>
  <section class="panel payroll-payslips" data-testid="payroll-payslips-panel">
    <div class="panel__header">
      <div>
        <h2 class="panel__title">工资条</h2>
        <p class="panel__subtitle">
          本人工资条只显示已发布记录；金额按字段策略返回，缺失时显示隐藏说明而不是 0。
        </p>
      </div>
      <el-button :disabled="loading" @click="load">刷新</el-button>
    </div>

    <section v-if="!canReadPayslip" class="payroll-payslips__state" role="alert" data-testid="payroll-payslip-forbidden">
      <h2>无本人工资条权限</h2>
      <p>当前账号缺少 payroll.payslip.read.self 能力，页面不会请求任何工资条。</p>
    </section>

    <ErrorState
      v-else-if="errorMessage !== ''"
      title="工资条暂时无法加载"
      :description="errorMessage"
      :code="errorCodeValue"
      :request-id="errorRequestId"
      @retry="load"
    />
    <LoadingState v-else-if="loading" :rows="4" label="正在加载本人工资条" />

    <template v-else>
      <EmptyState
        v-if="payslips.length === 0"
        title="还没有已发布工资条"
        description="工资批次发布后，本人工资条会出现在这里；未发布批次不会提前显示。"
      />
      <DataTable
        v-else
        :columns="columns"
        :rows="payslips as unknown as Array<Record<string, unknown>>"
        :total="payslips.length"
        :page="1"
        :page-size="Math.max(1, payslips.length)"
        row-key="id"
        data-testid="payroll-payslip-table"
        empty-title="还没有已发布工资条"
      >
        <template #cell-period="{ row }">{{ row.period ?? runPeriod(Number(row.runId)) }}</template>
        <template #cell-runId="{ row }">批次 #{{ row.runId }}</template>
        <template #cell-version="{ row }">v{{ row.version }}</template>
        <template #cell-gross="{ row }">
          <MoneyText v-if="row.grossCents !== undefined" :cents="Number(row.grossCents)" size="sm" />
          <span v-else class="payroll-payslips__masked">已按字段权限隐藏</span>
        </template>
        <template #cell-deductions="{ row }">
          <MoneyText v-if="row.deductionsCents !== undefined" :cents="Number(row.deductionsCents)" size="sm" />
          <span v-else class="payroll-payslips__masked">已按字段权限隐藏</span>
        </template>
        <template #cell-net="{ row }">
          <MoneyText v-if="row.netCents !== undefined" :cents="Number(row.netCents)" size="sm" tone="strong" />
          <span v-else class="payroll-payslips__masked">已按字段权限隐藏</span>
        </template>
        <template #cell-publishedAt="{ row }">{{ publishedAtLabel(row.publishedAt as string | null) }}</template>
      </DataTable>

      <template v-if="canManage && canRunRead">
        <h3 class="payroll-payslips__subtitle">已发布批次</h3>
        <p v-if="publishedLoading" class="payroll-payslips__muted">正在加载已发布批次…</p>
        <DataTable
          v-else
          :columns="publishedRunColumns"
          :rows="publishedRuns as unknown as Array<Record<string, unknown>>"
          :total="publishedRuns.length"
          :page="1"
          :page-size="Math.max(1, publishedRuns.length)"
          data-testid="payroll-published-runs-table"
          empty-title="还没有已发布批次"
        >
          <template #cell-statusLabel>已发布</template>
          <template #cell-employeeCount="{ row }">{{ row.employeeCount }} 人</template>
          <template #cell-netCents="{ row }">
            <MoneyText v-if="row.netCents !== undefined" :cents="Number(row.netCents)" size="sm" />
            <span v-else class="payroll-payslips__masked">已隐藏</span>
          </template>
          <template #cell-actions="{ row }">
            <el-button text size="small" @click="openRun(row as unknown as PayrollRunSummary)">批次工资条</el-button>
          </template>
        </DataTable>
      </template>
    </template>
  </section>
</template>

<style scoped>
.payroll-payslips {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.payroll-payslips__state {
  margin: 0 16px 16px;
  padding: 20px;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
}

.payroll-payslips__state h2 {
  margin: 0 0 6px;
  font-size: 16px;
}

.payroll-payslips__state p,
.payroll-payslips__muted {
  margin: 0;
  color: #475569;
  font-size: 12px;
}

.payroll-payslips__masked {
  color: #64748b;
  font-size: 11.5px;
}

.payroll-payslips__subtitle {
  margin: 8px 16px 0;
  font-size: 14px;
}
</style>
