<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ApiError, errorCode, errorCopy } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import LoadingState from '@/components/LoadingState.vue'
import PayrollRunTable from '../components/PayrollRunTable.vue'
import {
  listPayrollRuns,
  PAYROLL_STATUS_LABELS,
  payrollPeriodLabel,
  payrollStage,
  type PayrollRunStatus,
  type PayrollRunSummary,
} from '../api'

defineOptions({ name: 'PayrollWorkspacePage' })

const router = useRouter()
const session = useSessionStore()

const canRead = computed(() => session.can('payroll.run.read'))
const canPrepare = computed(() => session.can('payroll.run.prepare'))

const loading = ref(false)
const loaded = ref(false)
const errorMessage = ref('')
const errorRequestId = ref<string | null>(null)
const errorCodeValue = ref('')
const partialNote = ref('')

const runs = ref<PayrollRunSummary[]>([])
const page = ref(1)
const pageSize = ref(20)
const total = ref(0)

const periodFilter = ref('')
const statusFilter = ref('')
const periodError = ref('')

const STATUS_OPTIONS: Array<{ value: PayrollRunStatus | ''; label: string }> = [
  { value: '', label: '全部状态' },
  { value: 'DRAFT', label: PAYROLL_STATUS_LABELS.DRAFT },
  { value: 'INPUT_LOCKED', label: PAYROLL_STATUS_LABELS.INPUT_LOCKED },
  { value: 'CALCULATED', label: PAYROLL_STATUS_LABELS.CALCULATED },
  { value: 'REVIEWED', label: `${payrollStage('REVIEWED')}（${PAYROLL_STATUS_LABELS.REVIEWED}）` },
  { value: 'APPROVED', label: PAYROLL_STATUS_LABELS.APPROVED },
  { value: 'PUBLISHED', label: PAYROLL_STATUS_LABELS.PUBLISHED },
  { value: 'PAID', label: PAYROLL_STATUS_LABELS.PAID },
  { value: 'CLOSED', label: PAYROLL_STATUS_LABELS.CLOSED },
  { value: 'CANCELLED', label: PAYROLL_STATUS_LABELS.CANCELLED },
]

const effectivePeriod = computed(() => {
  const trimmed = periodFilter.value.trim()
  return /^\d{4}-(0[1-9]|1[0-2])$/.test(trimmed) ? trimmed : ''
})

const filtersActive = computed(
  () => effectivePeriod.value !== '' || statusFilter.value !== '',
)

const showEmpty = computed(
  () => loaded.value && !loading.value && errorMessage.value === '' && runs.value.length === 0,
)

function captureError(reason: unknown): void {
  errorCodeValue.value = errorCode(reason)
  if (reason instanceof ApiError) {
    errorRequestId.value = reason.requestId
    errorMessage.value =
      reason.code === 'FORBIDDEN'
        ? '无权读取工资批次。请确认已获得 payroll.run.read 能力，并且当前账号属于该批次。'
        : reason.message || errorCopy(reason)
  } else {
    errorRequestId.value = null
    errorMessage.value = reason instanceof Error ? reason.message : '工资工作台暂时无法加载。'
  }
}

function validatePeriod(): boolean {
  const trimmed = periodFilter.value.trim()
  if (trimmed === '' || /^\d{4}-(0[1-9]|1[0-2])$/.test(trimmed)) {
    periodError.value = ''
    return true
  }
  periodError.value = '期间格式应为 YYYY-MM，例如 2026-11。'
  return false
}

async function load(): Promise<void> {
  if (!canRead.value) {
    loaded.value = true
    return
  }
  if (!validatePeriod()) return

  loading.value = true
  loaded.value = false
  errorMessage.value = ''
  errorRequestId.value = null
  errorCodeValue.value = ''
  partialNote.value = ''

  try {
    const result = await listPayrollRuns(
      { period: effectivePeriod.value, status: statusFilter.value },
      page.value,
      pageSize.value,
    )
    runs.value = result.items
    page.value = result.page
    pageSize.value = result.pageSize
    total.value = result.total

    if (result.items.some((run) => run.grossCents === undefined || run.netCents === undefined)) {
      partialNote.value = '部分金额列已按字段策略隐藏；没有返回的金额不会用 0 或估算值代替。'
    }
  } catch (reason) {
    runs.value = []
    total.value = 0
    captureError(reason)
  } finally {
    loading.value = false
    loaded.value = true
  }
}

function applyFilters(): void {
  if (!validatePeriod()) return
  page.value = 1
  void load()
}

function clearFilters(): void {
  periodFilter.value = ''
  statusFilter.value = ''
  periodError.value = ''
  page.value = 1
  void load()
}

function changePage(next: number): void {
  page.value = next
  void load()
}

function openRun(run: PayrollRunSummary): void {
  void router.push(`/payroll/runs/${run.id}`)
}

onMounted(() => {
  void load()
})
</script>

<template>
  <div class="payroll-workspace" data-page="payroll-workspace">
    <header class="payroll-workspace__header">
      <div>
        <p class="payroll-workspace__breadcrumb">薪酬 · 工资核算</p>
        <h1 class="payroll-workspace__title">薪资工作台</h1>
        <p class="payroll-workspace__description">
          查看工资批次的期间、状态、人数与合计金额。金额由服务端以整数最小单位返回，页面仅做渲染格式化；
          准备、复核、批准与发布各自独立授权。
        </p>
      </div>
      <div class="payroll-workspace__header-actions">
        <button type="button" class="payroll-workspace__secondary" :disabled="loading" @click="load">
          刷新
        </button>
      </div>
    </header>

    <section v-if="!canRead" class="payroll-workspace__state" role="alert" data-testid="payroll-forbidden">
      <h2>无权访问薪资工作台</h2>
      <p>当前账号缺少 payroll.run.read 能力，页面不会发起任何工资批次读取请求。</p>
    </section>

    <template v-else>
      <section class="payroll-filters" aria-label="工资批次筛选">
        <label>
          <span>期间</span>
          <input
            v-model="periodFilter"
            type="text"
            inputmode="numeric"
            placeholder="2026-11"
            data-testid="payroll-period-filter"
            @keyup.enter="applyFilters"
          />
        </label>
        <label>
          <span>状态</span>
          <select v-model="statusFilter" data-testid="payroll-status-filter" @change="applyFilters">
            <option v-for="option in STATUS_OPTIONS" :key="option.value" :value="option.value">
              {{ option.label }}
            </option>
          </select>
        </label>
        <div class="payroll-filters__actions">
          <button type="button" class="payroll-workspace__primary" :disabled="loading" @click="applyFilters">
            查询
          </button>
          <button
            type="button"
            class="payroll-workspace__secondary"
            :disabled="loading || !filtersActive"
            @click="clearFilters"
          >
            清除筛选
          </button>
        </div>
        <p v-if="periodError !== ''" class="payroll-filters__error" role="alert">{{ periodError }}</p>
      </section>

      <p v-if="canPrepare" class="payroll-workspace__hint" role="note">
        当前账号可准备批次；试算、复核、批准与发布仍由服务端按批次成员与职责分离校验。
      </p>

      <ErrorState
        v-if="errorMessage !== ''"
        title="薪资工作台暂时无法加载"
        :description="errorMessage"
        :code="errorCodeValue"
        :request-id="errorRequestId"
        @retry="load"
      />
      <LoadingState v-else-if="loading" :rows="6" label="正在加载工资批次" />

      <template v-else>
        <p
          v-if="partialNote !== ''"
          class="payroll-workspace__warning"
          role="status"
          data-testid="payroll-partial"
        >
          部分数据：{{ partialNote }}
        </p>

        <EmptyState
          v-if="showEmpty"
          :title="filtersActive ? '当前筛选没有工资批次' : '还没有工资批次'"
          :description="
            filtersActive
              ? '没有匹配该期间或状态的批次。清除筛选后可查看当前账号所属的全部批次。'
              : '薪酬专员创建批次后，这里会展示准备、试算、复核、批准与发布状态。'
          "
          :action-label="filtersActive ? '清除筛选' : undefined"
          @action="clearFilters"
        />

        <PayrollRunTable
          v-else
          :runs="runs"
          :total="total"
          :page="page"
          :page-size="pageSize"
          :loading="loading"
          @open="openRun"
          @page-change="changePage"
        />

        <p v-if="filtersActive" class="payroll-workspace__filter-note">
          筛选结果共 {{ total }} 批，当前第 {{ page }} 页。
          <template v-if="effectivePeriod !== ''">期间：{{ payrollPeriodLabel(effectivePeriod) }}。</template>
        </p>
      </template>
    </template>
  </div>
</template>

<style scoped>
.payroll-workspace {
  display: flex;
  flex-direction: column;
  gap: 18px;
  padding: 24px;
}

.payroll-workspace__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
}

.payroll-workspace__breadcrumb {
  margin: 0;
  color: #64748b;
  font-size: 11.5px;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.payroll-workspace__title {
  margin: 6px 0 0;
  font-size: 24px;
}

.payroll-workspace__description {
  max-width: 820px;
  margin: 8px 0 0;
  color: #475569;
  font-size: 12.5px;
  line-height: 1.65;
}

.payroll-workspace__header-actions {
  display: inline-flex;
  gap: 8px;
}

.payroll-workspace__primary,
.payroll-workspace__secondary {
  padding: 8px 14px;
  font-size: 12.5px;
  font-weight: 600;
  border-radius: 8px;
  cursor: pointer;
}

.payroll-workspace__primary {
  color: #fff;
  background: #1d4ed8;
  border: 1px solid #1d4ed8;
}

.payroll-workspace__secondary {
  color: #334155;
  background: #fff;
  border: 1px solid #cbd5e1;
}

.payroll-workspace__primary:disabled,
.payroll-workspace__secondary:disabled {
  cursor: not-allowed;
  opacity: 0.6;
}

.payroll-filters {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 14px;
  padding: 14px 16px;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
}

.payroll-filters label {
  display: flex;
  flex-direction: column;
  gap: 4px;
  font-size: 11.5px;
  color: #475569;
}

.payroll-filters input,
.payroll-filters select {
  min-width: 168px;
  padding: 7px 10px;
  font-size: 12.5px;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  background: #fff;
}

.payroll-filters__actions {
  display: inline-flex;
  gap: 8px;
}

.payroll-filters__error {
  flex-basis: 100%;
  margin: 0;
  color: #b91c1c;
  font-size: 11.5px;
}

.payroll-workspace__hint,
.payroll-workspace__filter-note {
  margin: 0;
  color: #475569;
  font-size: 11.5px;
}

.payroll-workspace__warning {
  margin: 0;
  padding: 9px 12px;
  background: #fffbeb;
  border: 1px solid #fde68a;
  border-radius: 8px;
  color: #92400e;
  font-size: 11.5px;
}

.payroll-workspace__state {
  padding: 20px;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
}

.payroll-workspace__state h2 {
  margin: 0 0 6px;
  font-size: 16px;
}

.payroll-workspace__state p {
  margin: 0;
  color: #475569;
  font-size: 12px;
}
</style>
