<script setup lang="ts">
import { DISPLAY_CODES } from '@/shared/display/dictionary'
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ApiError, errorCode, errorCopy } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import LoadingState from '@/components/LoadingState.vue'
import PayrollRunTable from './PayrollRunTable.vue'
import {
  listPayrollRuns,
  listPayrollSchemes,
  PAYROLL_STATUS_LABELS,
  payrollPeriodLabel,
  payrollStage,
  type PayrollRunStatus,
  type PayrollRunSummary,
  type PayrollSchemeRecord,
} from '../api'

defineOptions({ name: 'PayrollBatchesPanel' })

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
const schemes = ref<PayrollSchemeRecord[]>([])
const page = ref(1)
const pageSize = ref(20)
const total = ref(0)

const periodFilter = ref('')
const statusFilter = ref('')
const periodError = ref('')

const createOpen = ref(false)
const creating = ref(false)
const createError = ref('')
const createPeriod = ref('')
const createSchemeId = ref('')
const createPayDate = ref('')

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

const filtersActive = computed(() => effectivePeriod.value !== '' || statusFilter.value !== '')

const showEmpty = computed(
  () => loaded.value && !loading.value && errorMessage.value === '' && runs.value.length === 0,
)

const activeSchemes = computed(() => schemes.value.filter((scheme) => scheme.status === DISPLAY_CODES.userStatus.active))

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

async function openCreate(): Promise<void> {
  createError.value = ''
  createPeriod.value = ''
  createPayDate.value = ''
  createSchemeId.value = ''
  createOpen.value = true

  if (schemes.value.length > 0 || !canPrepare.value) return

  try {
    schemes.value = await listPayrollSchemes()
    createSchemeId.value = activeSchemes.value[0]?.id !== undefined
      ? String(activeSchemes.value[0].id)
      : ''
  } catch (reason) {
    createError.value = `薪酬方案暂不可用：${errorCopy(reason)}`
  }
}

async function saveRun(): Promise<void> {
  createError.value = ''
  if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(createPeriod.value.trim())) {
    createError.value = '期间格式应为 YYYY-MM。'
    return
  }
  const schemeId = Number(createSchemeId.value)
  if (!Number.isSafeInteger(schemeId) || schemeId <= 0) {
    createError.value = '请选择一个有效的薪酬方案。'
    return
  }

  creating.value = true
  try {
    const { resourcePost } = await import('@/api/resourceClient')
    const suffix =
      typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
        ? crypto.randomUUID()
        : `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`
    await resourcePost('/v1/payroll/runs', {
      scheme_id: schemeId,
      period: createPeriod.value.trim(),
      ...(createPayDate.value !== '' ? { pay_date: createPayDate.value } : {}),
    }, { idempotencyKey: `payroll-run-${suffix}`.slice(0, 128) })
    createOpen.value = false
    await load()
  } catch (reason) {
    createError.value = errorCopy(reason)
  } finally {
    creating.value = false
  }
}


onMounted(() => {
  void load()
})
</script>

<template>
  <section class="panel payroll-batches" data-testid="payroll-batches-panel">
    <div class="panel__header">
      <div>
        <h2 class="panel__title">工资批次</h2>
        <p class="panel__subtitle">
          期间、状态与金额均来自真实批次；金额只按整数分传输，准备/复核/批准/发布分别授权。
        </p>
      </div>
      <div class="payroll-batches__actions">
        <el-button :disabled="loading" @click="load">刷新</el-button>
        <el-button
          v-if="canPrepare"
          type="primary"
          :disabled="loading"
          data-testid="payroll-run-create"
          @click="openCreate"
        >
          创建批次
        </el-button>
      </div>
    </div>

    <section v-if="!canRead" class="payroll-batches__state" role="alert" data-testid="payroll-forbidden">
      <h2>无权访问薪资工作台</h2>
      <p>当前账号缺少 payroll.run.read 能力，页面不会发起任何工资批次读取请求。</p>
    </section>

    <template v-else>
      <section class="payroll-filters" aria-label="工资批次筛选">
        <label>
          <span>期间</span>
          <el-input
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
          <el-select v-model="statusFilter" data-testid="payroll-status-filter" @change="applyFilters">
            <el-option
              v-for="option in STATUS_OPTIONS"
              :key="option.value"
              :label="option.label"
              :value="option.value"
            />
          </el-select>
        </label>
        <div class="payroll-filters__actions">
          <el-button type="primary" :disabled="loading" @click="applyFilters">查询</el-button>
          <el-button :disabled="loading || !filtersActive" @click="clearFilters">清除筛选</el-button>
        </div>
        <p v-if="periodError !== ''" class="payroll-filters__error" role="alert">{{ periodError }}</p>
      </section>

      <p v-if="canPrepare" class="payroll-batches__hint" role="note">
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
        <p v-if="partialNote !== ''" class="payroll-batches__warning" role="status" data-testid="payroll-partial">
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

        <p v-if="filtersActive" class="payroll-batches__filter-note">
          筛选结果共 {{ total }} 批，当前第 {{ page }} 页。
          <template v-if="effectivePeriod !== ''">期间：{{ payrollPeriodLabel(effectivePeriod) }}。</template>
        </p>
      </template>
    </template>

    <el-dialog v-model="createOpen" title="创建工资批次" width="480px" :close-on-click-modal="false" data-testid="payroll-run-dialog">
      <label class="payroll-batches__field">期间
        <el-input v-model="createPeriod" placeholder="2026-11" data-testid="payroll-run-period" />
      </label>
      <label class="payroll-batches__field">薪酬方案
        <el-select v-model="createSchemeId" data-testid="payroll-run-scheme">
          <el-option
            v-for="scheme in activeSchemes"
            :key="scheme.id"
            :label="`${scheme.name}（${scheme.code} v${scheme.version}）`"
            :value="String(scheme.id)"
          />
        </el-select>
      </label>
      <label class="payroll-batches__field">发放日（可选）
        <el-input v-model="createPayDate" type="date" data-testid="payroll-run-pay-date" />
      </label>
      <p v-if="activeSchemes.length === 0" class="payroll-batches__warning" role="status">
        尚无可用薪酬方案，请先在“配置”中创建方案。
      </p>
      <p v-if="createError !== ''" class="payroll-batches__error" role="alert">{{ createError }}</p>
      <template #footer>
        <el-button @click="createOpen = false">取消</el-button>
        <el-button
          type="primary"
          :loading="creating"
          :disabled="activeSchemes.length === 0"
          data-testid="payroll-run-create-confirm"
          @click="saveRun"
        >
          创建
        </el-button>
      </template>
    </el-dialog>
  </section>
</template>

<style scoped>
.payroll-batches {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.payroll-batches__actions {
  display: flex;
  gap: 8px;
}

.payroll-filters {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 14px;
  padding: 0 16px;
}

.payroll-filters label {
  display: flex;
  flex-direction: column;
  gap: 4px;
  font-size: 11.5px;
  color: #475569;
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

.payroll-batches__hint,
.payroll-batches__filter-note {
  margin: 0 16px;
  color: #475569;
  font-size: 11.5px;
}

.payroll-batches__warning,
.payroll-batches__error {
  margin: 0 16px;
  padding: 9px 12px;
  font-size: 11.5px;
  border-radius: 8px;
}

.payroll-batches__warning {
  color: #92400e;
  background: #fffbeb;
  border: 1px solid #fde68a;
}

.payroll-batches__error {
  color: #b91c1c;
  background: #fef2f2;
  border: 1px solid #fecaca;
}

.payroll-batches__state {
  margin: 0 16px 16px;
  padding: 20px;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
}

.payroll-batches__state h2 {
  margin: 0 0 6px;
  font-size: 16px;
}

.payroll-batches__state p {
  margin: 0;
  color: #475569;
  font-size: 12px;
}

.payroll-batches__field {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin-bottom: 12px;
  color: #475569;
  font-size: 12px;
}
</style>
