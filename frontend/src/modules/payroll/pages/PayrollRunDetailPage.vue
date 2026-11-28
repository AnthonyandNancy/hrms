<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ApiError, errorCode, errorCopy } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import { formatBusinessDate, formatDateTimeInTimezone } from '@/utils/format'
import MoneyText from '@/components/MoneyText.vue'
import DataTable, { type DataTableColumn } from '@/components/DataTable.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import LoadingState from '@/components/LoadingState.vue'
import PayrollActionBar from '../components/PayrollActionBar.vue'
import PayslipDrawer from '../components/PayslipDrawer.vue'
import {
  exportPayrollRun,
  getPayrollEmployees,
  getPayrollInputs,
  getPayrollReconciliation,
  getPayrollRun,
  isRunReadOnly,
  PAYROLL_STATUS_LABELS,
  payrollActionErrorCopy,
  payrollEmployeeStatusLabel,
  payrollExportLabel,
  payrollPeriodLabel,
  payrollRunTypeLabel,
  payrollStage,
  payrollStageTone,
  runPayrollAction,
  savePayrollExport,
  type PayrollAction,
  type PayrollEmployee,
  type PayrollEmployeeStatus,
  type PayrollExportKind,
  type PayrollInputSnapshot,
  type PayrollReconciliation,
  type PayrollRunDetail,
} from '../api'

defineOptions({ name: 'PayrollRunDetailPage' })

const route = useRoute()
const router = useRouter()
const session = useSessionStore()

const canRead = computed(() => session.can('payroll.run.read'))
const canExportCapability = computed(() => session.can('payroll.export'))

const loading = ref(false)
const loaded = ref(false)
const errorMessage = ref('')
const errorRequestId = ref<string | null>(null)
const errorCodeValue = ref('')

const detail = ref<PayrollRunDetail | null>(null)
const inputs = ref<PayrollInputSnapshot[]>([])
const reconciliation = ref<PayrollReconciliation | null>(null)
const partialNote = ref('')
const actionSubmitting = ref<PayrollAction | null>(null)
const actionError = ref('')
const actionSuccess = ref('')

const exportKind = ref<PayrollExportKind | null>(null)
const exportError = ref('')
const exportSuccess = ref('')
const exportHash = ref('')

const drawerOpen = ref(false)
const selectedEmployee = ref<PayrollEmployee | null>(null)

const routeId = computed(() => {
  const value = route.params.id
  if (typeof value !== 'string' || !/^\d+$/.test(value)) return null
  const parsed = Number(value)
  return Number.isSafeInteger(parsed) && parsed > 0 ? parsed : null
})

type PayrollDetailTab = 'inputs' | 'review' | 'disbursement' | 'payslips' | 'export'

const DETAIL_TABS: ReadonlyArray<{ key: PayrollDetailTab; label: string }> = [
  { key: 'inputs', label: '计薪输入' },
  { key: 'review', label: '复核' },
  { key: 'disbursement', label: '发放登记' },
  { key: 'payslips', label: '工资条' },
  { key: 'export', label: '导出' },
]

function detailTabFromQuery(): PayrollDetailTab | null {
  const value = route.query.tab
  return typeof value === 'string' && DETAIL_TABS.some((tab) => tab.key === value)
    ? (value as PayrollDetailTab)
    : null
}

const activeDetailTab = ref<PayrollDetailTab>(detailTabFromQuery() ?? 'inputs')

function selectDetailTab(tab: PayrollDetailTab): void {
  activeDetailTab.value = tab
  void router.replace({ query: { ...route.query, tab } })
}

watch(
  () => route.query.tab,
  (value) => {
    if (typeof value === 'string' && DETAIL_TABS.some((tab) => tab.key === value)) {
      activeDetailTab.value = value as PayrollDetailTab
    }
  },
)

const readOnly = computed(() => (detail.value === null ? false : isRunReadOnly(detail.value.status)))
const periodLabel = computed(() => (detail.value === null ? '' : payrollPeriodLabel(detail.value.period)))
const stage = computed(() => (detail.value === null ? null : payrollStage(detail.value.status)))
const employees = computed<PayrollEmployee[]>(() => detail.value?.employees ?? [])
const showEmptyEmployees = computed(
  () => loaded.value && !loading.value && errorMessage.value === '' && employees.value.length === 0,
)

function captureError(reason: unknown): void {
  errorCodeValue.value = errorCode(reason)
  if (reason instanceof ApiError) {
    errorRequestId.value = reason.requestId
    errorMessage.value =
      reason.code === 'FORBIDDEN'
        ? '无权读取该工资批次。当前账号必须持有 payroll.run.read 且属于该批次成员。'
        : reason.code === 'NOT_FOUND'
          ? '未找到该工资批次，可能已被移出你的数据范围或链接有误。'
          : reason.message || errorCopy(reason)
  } else {
    errorRequestId.value = null
    errorMessage.value = reason instanceof Error ? reason.message : '工资批次详情暂时无法加载。'
  }
}

async function load(): Promise<void> {
  if (!canRead.value) {
    loaded.value = true
    return
  }
  const id = routeId.value
  if (id === null) {
    loaded.value = true
    errorCodeValue.value = 'NOT_FOUND'
    errorMessage.value = '工资批次编号无效。'
    return
  }

  loading.value = true
  loaded.value = false
  errorMessage.value = ''
  errorRequestId.value = null
  errorCodeValue.value = ''
  actionError.value = ''
  actionSuccess.value = ''
  exportError.value = ''
  exportSuccess.value = ''
  exportHash.value = ''
  inputs.value = []
  reconciliation.value = null
  partialNote.value = ''

  try {
    const run = await getPayrollRun(id)
    detail.value = run

    // The per-employee endpoint is the payslip source and applies the caller's
    // P3 field profile per row. Reconciliation and input evidence are separate
    // reads; a failure there is partial data, not a blank page.
    const [employeesResult, reconciliationResult, inputsResult] = await Promise.allSettled([
      getPayrollEmployees(id),
      getPayrollReconciliation(id),
      getPayrollInputs(id),
    ])
    const partialReasons: string[] = []

    if (employeesResult.status === 'fulfilled') {
      run.employees = employeesResult.value.items
    } else {
      partialReasons.push('员工明细接口暂不可用，当前展示批次详情内嵌的员工快照')
    }

    if (reconciliationResult.status === 'fulfilled') {
      reconciliation.value = reconciliationResult.value
    } else {
      partialReasons.push('对账结果暂不可用')
    }

    if (inputsResult.status === 'fulfilled') {
      inputs.value = inputsResult.value.items
    } else {
      partialReasons.push('计薪输入证据暂不可用')
    }

    partialNote.value = partialReasons.join('；')
  } catch (reason) {
    detail.value = null
    captureError(reason)
  } finally {
    loading.value = false
    loaded.value = true
  }
}

async function performAction(action: PayrollAction): Promise<void> {
  const current = detail.value
  if (current === null) return
  actionSubmitting.value = action
  actionError.value = ''
  actionSuccess.value = ''

  try {
    const body: Record<string, unknown> = {}
    if (action === 'review' || action === 'approve') body.comment = ''
    if (action === 'publish') body.reason = ''
    if (action === 'mark-paid') body.reference = ''

    await runPayrollAction(current.id, action, body)
    await load()
    actionSuccess.value = `操作已完成：${payrollActionLabelText(action)}。`
  } catch (reason) {
    actionError.value = payrollActionErrorCopy(reason)
  } finally {
    actionSubmitting.value = null
  }
}

function payrollActionLabelText(action: PayrollAction): string {
  return {
    'lock-inputs': '锁定输入',
    calculate: '试算',
    review: '提交复核',
    approve: '批准',
    publish: '发布',
    'mark-paid': '标记发放',
    close: '关闭批次',
    cancel: '取消批次',
  }[action]
}

async function download(kind: PayrollExportKind): Promise<void> {
  const current = detail.value
  if (current === null) return
  exportKind.value = kind
  exportError.value = ''
  exportSuccess.value = ''

  try {
    const file = await exportPayrollRun(current.id, kind)
    savePayrollExport(file)
    exportHash.value = file.sha256
    exportSuccess.value = `${payrollExportLabel(kind)}已由服务端生成，并已开始下载。`
  } catch (reason) {
    exportError.value = payrollActionErrorCopy(reason)
  } finally {
    exportKind.value = null
  }
}

function openPayslip(employee: PayrollEmployee): void {
  selectedEmployee.value = employee
  drawerOpen.value = true
}

function backToWorkspace(): void {
  void router.push('/payroll')
}

watch(routeId, () => {
  void load()
})

type CompensationSegmentRow = {
  compensationId: number
  employmentId: number
  from: string
  to: string
  baseSalaryCents: number
}

const compensationSegments = computed<CompensationSegmentRow[]>(() =>
  inputs.value.flatMap((input) => {
    const rawSegments = input.payload.compensation_segments
    if (!Array.isArray(rawSegments)) return []

    return rawSegments.flatMap((raw) => {
      if (typeof raw !== 'object' || raw === null) return []
      const segment = raw as Record<string, unknown>
      const compensationId = segment.compensation_id
      const baseSalaryCents = segment.base_salary_cents
      const from = segment.segment_from
      const to = segment.segment_to
      if (
        typeof compensationId !== 'number'
        || !Number.isSafeInteger(compensationId)
        || typeof baseSalaryCents !== 'number'
        || !Number.isSafeInteger(baseSalaryCents)
        || typeof from !== 'string'
        || typeof to !== 'string'
      ) {
        return []
      }

      return [{
        compensationId,
        employmentId: input.employmentId,
        from,
        to,
        baseSalaryCents,
      }]
    })
  }),
)

const inputColumns: DataTableColumn[] = [
  { key: 'employee', label: '员工关系', minWidth: '130px' },
  { key: 'snapshotType', label: '快照类型', minWidth: '140px' },
  { key: 'version', label: '版本', width: '90px', align: 'right' },
  { key: 'sourceHash', label: '来源摘要', minWidth: '180px' },
  { key: 'createdAt', label: '记录时间', minWidth: '170px' },
]

const employeeColumns: DataTableColumn[] = [
  { key: 'employee', label: '员工关系', minWidth: '130px' },
  { key: 'statusLabel', label: '计算状态', minWidth: '120px' },
  { key: 'gross', label: '应发', minWidth: '110px', align: 'right' },
  { key: 'deductions', label: '扣款', minWidth: '110px', align: 'right' },
  { key: 'net', label: '实发', minWidth: '110px', align: 'right' },
  { key: 'failureReason', label: '失败原因', minWidth: '180px' },
  { key: 'actions', label: '工资条', width: '120px', align: 'right' },
]

function asPayrollEmployee(row: Record<string, unknown>): PayrollEmployee {
  return row as unknown as PayrollEmployee
}

onMounted(() => {
  void load()
})
</script>

<template>
  <div class="payroll-detail" data-page="payroll-run-detail">
    <header class="payroll-detail__header">
      <div>
        <button type="button" class="payroll-detail__back" @click="backToWorkspace">← 返回薪资工作台</button>
        <p class="payroll-detail__breadcrumb">薪酬 · 工资批次详情</p>
        <h1 class="payroll-detail__title">
          <template v-if="detail !== null">{{ periodLabel }} 工资批次</template>
          <template v-else>工资批次详情</template>
        </h1>
        <p v-if="detail !== null" class="payroll-detail__description">
          {{ payrollRunTypeLabel(detail.runType) }} · 批次 #{{ detail.id }} · 发放日
          {{ formatBusinessDate(detail.payDate) }}
        </p>
      </div>
      <div v-if="detail !== null" class="payroll-detail__header-actions">
        <span
          v-if="stage !== null"
          class="payroll-detail__stage"
          :data-tone="payrollStageTone(stage)"
        >
          {{ stage }}
        </span>
        <span class="payroll-detail__status">{{ PAYROLL_STATUS_LABELS[detail.status] }}</span>
      </div>
    </header>

    <section v-if="!canRead" class="payroll-detail__state" role="alert" data-testid="payroll-detail-forbidden">
      <h2>无权读取工资批次</h2>
      <p>当前账号缺少 payroll.run.read 能力，页面不会请求任何批次详情。</p>
    </section>

    <ErrorState
      v-else-if="errorMessage !== ''"
      title="工资批次详情暂时无法加载"
      :description="errorMessage"
      :code="errorCodeValue"
      :request-id="errorRequestId"
      @retry="load"
    />

    <LoadingState v-else-if="loading" :rows="7" label="正在加载工资批次详情" />

    <template v-else-if="detail !== null">
      <p
        v-if="readOnly"
        class="payroll-detail__readonly"
        role="status"
        data-testid="payroll-readonly"
      >
        该批次已发布，为只读状态：准备、试算、复核、批准与发布均已禁用；银行与税务导出仍然可用，如需修正请新建更正批次。
      </p>

      <p
        v-if="partialNote !== ''"
        class="payroll-detail__warning"
        role="status"
        data-testid="payroll-detail-partial"
      >
        部分数据：{{ partialNote }}
      </p>

      <section class="payroll-detail__facts" aria-label="批次基本信息">
        <div>
          <dt>期间</dt>
          <dd>{{ detail.period }}（{{ periodLabel }}）</dd>
        </div>
        <div>
          <dt>发放日</dt>
          <dd>{{ formatBusinessDate(detail.payDate) }}</dd>
        </div>
        <div>
          <dt>人数</dt>
          <dd>{{ detail.employeeCount }} 人</dd>
        </div>
        <div>
          <dt>应发合计</dt>
          <dd>
            <MoneyText v-if="detail.grossCents !== undefined" :cents="detail.grossCents" size="sm" />
            <span v-else class="payroll-detail__masked">已按字段权限隐藏</span>
          </dd>
        </div>
        <div>
          <dt>扣款合计</dt>
          <dd>
            <MoneyText v-if="detail.deductionsCents !== undefined" :cents="detail.deductionsCents" size="sm" />
            <span v-else class="payroll-detail__masked">已按字段权限隐藏</span>
          </dd>
        </div>
        <div>
          <dt>实发合计</dt>
          <dd>
            <MoneyText v-if="detail.netCents !== undefined" :cents="detail.netCents" size="sm" tone="strong" />
            <span v-else class="payroll-detail__masked">已按字段权限隐藏</span>
          </dd>
        </div>
        <div>
          <dt>输入锁定时间</dt>
          <dd>{{ formatDateTimeInTimezone(detail.cutoffAt) }}</dd>
        </div>
        <div>
          <dt>快照版本</dt>
          <dd>v{{ detail.snapshotVersion }}</dd>
        </div>
      </section>

      <PayrollActionBar
        :status="detail.status"
        :period="detail.period"
        :capabilities="session.capabilities"
        :allowed-actions="detail.allowedActions"
        :submitting="actionSubmitting"
        :read-only="readOnly"
        @action="performAction"
      />

      <p v-if="actionError !== ''" class="payroll-detail__error" role="alert">{{ actionError }}</p>
      <p v-if="actionSuccess !== ''" class="payroll-detail__success" role="status">{{ actionSuccess }}</p>

      <nav class="payroll-detail__tabs" aria-label="工资批次详情分区" role="tablist">
        <button
          v-for="tab in DETAIL_TABS"
          :key="tab.key"
          type="button"
          role="tab"
          :aria-selected="activeDetailTab === tab.key"
          :data-testid="`payroll-detail-tab-${tab.key}`"
          @click="selectDetailTab(tab.key)"
        >
          {{ tab.label }}
        </button>
      </nav>

      <section
        v-if="activeDetailTab === 'payslips'"
        class="panel payroll-detail__payslip-summary"
        aria-label="工资条概览"
        data-testid="payroll-payslip-panel"
      >
        <div class="panel__header">
          <div>
            <h2 class="panel__title">工资条</h2>
            <p class="panel__subtitle">
              只显示服务端返回且当前字段策略允许的金额；缺失金额不会补 0。点击员工行可查看逐项工资条。
            </p>
          </div>
          <span class="plain-chip">{{ employees.length }} 名员工</span>
        </div>
        <dl class="payroll-detail__facts payroll-detail__facts--inline">
          <div>
            <dt>应发合计</dt>
            <dd>
              <MoneyText v-if="detail.grossCents !== undefined" :cents="detail.grossCents" size="sm" />
              <span v-else class="payroll-detail__masked">已按字段权限隐藏</span>
            </dd>
          </div>
          <div>
            <dt>扣款合计</dt>
            <dd>
              <MoneyText v-if="detail.deductionsCents !== undefined" :cents="detail.deductionsCents" size="sm" />
              <span v-else class="payroll-detail__masked">已按字段权限隐藏</span>
            </dd>
          </div>
          <div>
            <dt>实发合计</dt>
            <dd>
              <MoneyText v-if="detail.netCents !== undefined" :cents="detail.netCents" size="sm" tone="strong" />
              <span v-else class="payroll-detail__masked">已按字段权限隐藏</span>
            </dd>
          </div>
        </dl>
      </section>

      <section
        v-if="activeDetailTab === 'disbursement'"
        class="panel payroll-detail__disbursement"
        aria-label="发放登记"
        data-testid="payroll-disbursement-panel"
      >
        <div class="panel__header">
          <div>
            <h2 class="panel__title">发放登记</h2>
            <p class="panel__subtitle">
              登记发放只记录已完成的外部付款结果，不代表系统发起银行支付；实际支付凭证由授权人员留存。
            </p>
          </div>
        </div>
        <dl class="payroll-detail__facts payroll-detail__facts--inline">
          <div>
            <dt>批次状态</dt>
            <dd>{{ PAYROLL_STATUS_LABELS[detail.status] }}</dd>
          </div>
          <div>
            <dt>计划发放日</dt>
            <dd>{{ formatBusinessDate(detail.payDate) }}</dd>
          </div>
          <div>
            <dt>登记结果</dt>
            <dd>{{ detail.status === 'PAID' || detail.status === 'CLOSED' ? '已登记发放' : '尚未登记' }}</dd>
          </div>
          <div>
            <dt>支付凭证</dt>
            <dd>由服务端导入或留存，页面不伪造凭证号</dd>
          </div>
        </dl>
      </section>

      <section v-if="activeDetailTab === 'export'" class="panel payroll-detail__exports" aria-label="批次导出">
        <div class="panel__header">
          <div>
            <h2 class="panel__title">导出</h2>
            <p class="panel__subtitle">
              导出由服务端生成并返回文件名、CSV 内容与 SHA-256 摘要；页面不会拼接下载地址。
            </p>
          </div>
        </div>
        <div class="payroll-detail__export-actions">
          <button
            type="button"
            class="payroll-detail__secondary"
            :disabled="!canExportCapability || exportKind !== null || !['APPROVED', 'PUBLISHED', 'PAID', 'CLOSED'].includes(detail.status)"
            data-testid="payroll-export-bank"
            @click="download('bank')"
          >
            {{ exportKind === 'bank' ? '正在生成…' : '银行代发导出' }}
          </button>
          <button
            type="button"
            class="payroll-detail__secondary"
            :disabled="!canExportCapability || exportKind !== null || !['APPROVED', 'PUBLISHED', 'PAID', 'CLOSED'].includes(detail.status)"
            data-testid="payroll-export-tax"
            @click="download('tax')"
          >
            {{ exportKind === 'tax' ? '正在生成…' : '税务申报导出' }}
          </button>
        </div>
        <p v-if="!canExportCapability" class="payroll-detail__masked">
          缺少 payroll.export 能力，导出按钮已禁用。
        </p>
        <p
          v-else-if="!['APPROVED', 'PUBLISHED', 'PAID', 'CLOSED'].includes(detail.status)"
          class="payroll-detail__masked"
        >
          仅已批准或已发布的批次可以导出。
        </p>
        <p v-if="exportError !== ''" class="payroll-detail__error" role="alert">{{ exportError }}</p>
        <p v-if="exportSuccess !== ''" class="payroll-detail__success" role="status">
          {{ exportSuccess }}
          <template v-if="exportHash !== ''">
            摘要：<span class="mono">{{ exportHash }}</span>
          </template>
        </p>
      </section>

      <section
        v-if="activeDetailTab === 'inputs'"
        class="panel payroll-detail__inputs"
        aria-label="计薪输入证据"
      >
        <div class="panel__header">
          <div>
            <h2 class="panel__title">计薪输入证据</h2>
            <p class="panel__subtitle">
              来自 /v1/payroll/runs/{id}/inputs 的不可变输入快照；金额键只有具备工资条字段权限时才返回。
            </p>
          </div>
          <span class="plain-chip">{{ inputs.length }} 条快照</span>
        </div>

        <EmptyState
          v-if="inputs.length === 0"
          title="暂无计薪输入快照"
          description="锁定输入并试算后，服务端会写入该批次的不可变输入证据。"
        />

        <div v-else class="payroll-detail__scroll">
          <DataTable
            :columns="inputColumns"
            :rows="inputs as unknown as Array<Record<string, unknown>>"
            :total="inputs.length"
            :page="1"
            :page-size="Math.max(1, inputs.length)"
            data-testid="payroll-input-table"
            empty-title="暂无计薪输入快照"
          >
            <template #cell-employee="{ row }">员工 #{{ row.employmentId }}</template>
            <template #cell-version="{ row }">v{{ row.snapshotVersion }}</template>
            <template #cell-sourceHash="{ row }">
              <span class="mono">{{ String(row.sourceHash).slice(0, 16) }}</span>
            </template>
            <template #cell-createdAt="{ row }">{{ formatDateTimeInTimezone(String(row.createdAt)) }}</template>
          </DataTable>

          <section
            v-if="compensationSegments.length > 0"
            class="payroll-detail__segments"
            aria-label="薪酬分段"
            data-testid="payroll-compensation-segments"
          >
            <h3 class="payroll-detail__segments-title">薪酬分段</h3>
            <p class="payroll-detail__segments-note">
              按执行薪酬生效边界冻结；基础工资按工作日加权，税费与社保仍按月一次计算。
            </p>
            <ul class="payroll-detail__segments-list">
              <li
                v-for="segment in compensationSegments"
                :key="`${segment.employmentId}-${segment.compensationId}`"
                class="payroll-detail__segment"
              >
                <span>
                  员工 #{{ segment.employmentId }} ·
                  {{ formatBusinessDate(segment.from) }} 至 {{ formatBusinessDate(segment.to) }} ·
                  来源 #{{ segment.compensationId }}
                </span>
                <MoneyText :cents="segment.baseSalaryCents" size="sm" />
              </li>
            </ul>
          </section>
        </div>
      </section>

      <section
        v-if="activeDetailTab === 'review'"
        class="panel payroll-detail__review"
        aria-label="复核差异"
        data-testid="payroll-review-panel"
      >
        <div class="panel__header">
          <div>
            <h2 class="panel__title">复核差异</h2>
            <p class="panel__subtitle">
              复核人必须与制单人不同；差异直接来自服务端对账结果，页面不会把缺失金额补成 0。
            </p>
          </div>
        </div>
        <p v-if="reconciliation === null" class="payroll-detail__masked">
          对账结果暂不可用；复核动作仍由服务端状态机与职责分离校验。
        </p>
        <p v-else-if="reconciliation.differences.length === 0" class="payroll-detail__success" role="status">
          对账未发现差异，员工结果与工资条合计一致。
        </p>
        <p v-else class="payroll-detail__error" role="alert">
          发现 {{ reconciliation.differences.length }} 项差异，请逐项核对后再提交复核。
        </p>
      </section>

      <section
        v-if="reconciliation !== null && (activeDetailTab === 'inputs' || activeDetailTab === 'review')"
        class="panel payroll-detail__reconciliation"
        aria-label="批次对账"
      >
        <div class="panel__header">
          <div>
            <h2 class="panel__title">批次对账</h2>
            <p class="panel__subtitle">
              工资条、员工结果与批次合计必须一致；差异会逐条列出，不会自动忽略。
            </p>
          </div>
          <span
            class="payroll-detail__stage"
            :data-tone="reconciliation.passed ? 'published' : 'cancelled'"
            data-testid="payroll-reconciliation-status"
          >
            {{ reconciliation.passed ? '对账通过' : `发现 ${reconciliation.differences.length} 项差异` }}
          </span>
        </div>

        <dl class="payroll-detail__facts payroll-detail__facts--inline">
          <div>
            <dt>员工数</dt>
            <dd>{{ reconciliation.employeeCount }}</dd>
          </div>
          <div>
            <dt>工资条数</dt>
            <dd>{{ reconciliation.payslipCount }}</dd>
          </div>
          <div>
            <dt>应发合计</dt>
            <dd><MoneyText :cents="reconciliation.grossCents" size="sm" /></dd>
          </div>
          <div>
            <dt>实发合计</dt>
            <dd><MoneyText :cents="reconciliation.netCents" size="sm" tone="strong" /></dd>
          </div>
          <div>
            <dt>单位成本</dt>
            <dd><MoneyText :cents="reconciliation.employerCostCents" size="sm" /></dd>
          </div>
        </dl>

        <ul v-if="reconciliation.differences.length > 0" class="payroll-detail__differences" role="alert">
          <li v-for="(difference, index) in reconciliation.differences" :key="index">
            {{ difference.code ?? 'UNKNOWN_DIFFERENCE' }}
            <span v-if="difference.employment_id !== undefined">· 员工 #{{ difference.employment_id }}</span>
            <span v-if="difference.field !== undefined">· 字段 {{ difference.field }}</span>
          </li>
        </ul>
      </section>

      <section v-if="activeDetailTab === 'payslips'" class="panel payroll-detail__employees" aria-label="员工明细">
        <div class="panel__header">
          <div>
            <h2 class="panel__title">员工明细</h2>
            <p class="panel__subtitle">
              每名员工的应发、扣款与实发来自 /v1/payroll/runs/{id}/employees；点击行可查看工资条抽屉。
            </p>
          </div>
        </div>

        <EmptyState
          v-if="showEmptyEmployees"
          title="该批次没有员工行"
          description="试算完成后员工结果会出现在这里；当前批次尚未产生任何员工记录。"
        />

        <div v-else class="payroll-detail__scroll">
          <DataTable
            :columns="employeeColumns"
            :rows="employees as unknown as Array<Record<string, unknown>>"
            :total="employees.length"
            :page="1"
            :page-size="Math.max(1, employees.length)"
            row-key="employmentId"
            :row-test-id="(row) => `payroll-employee-${String(row.employmentId)}`"
            data-testid="payroll-employee-table"
            empty-title="该批次没有员工行"
          >
            <template #cell-employee="{ row }">员工 #{{ row.employmentId }}</template>
            <template #cell-statusLabel="{ row }">{{ payrollEmployeeStatusLabel(row.status as PayrollEmployeeStatus) }}</template>
            <template #cell-gross="{ row }">
              <MoneyText v-if="row.grossCents !== undefined" :cents="Number(row.grossCents)" size="sm" />
              <span v-else class="payroll-detail__masked">已隐藏</span>
            </template>
            <template #cell-deductions="{ row }">
              <MoneyText v-if="row.deductionsCents !== undefined" :cents="Number(row.deductionsCents)" size="sm" />
              <span v-else class="payroll-detail__masked">已隐藏</span>
            </template>
            <template #cell-net="{ row }">
              <MoneyText v-if="row.netCents !== undefined" :cents="Number(row.netCents)" size="sm" tone="strong" />
              <span v-else class="payroll-detail__masked">已隐藏</span>
            </template>
            <template #cell-failureReason="{ row }">
              <span class="payroll-detail__failure">{{ row.failureReason ?? '—' }}</span>
            </template>
            <template #cell-actions="{ row }">
              <button type="button" class="payroll-detail__link" @click="openPayslip(asPayrollEmployee(row))">
                查看工资条
              </button>
            </template>
          </DataTable>
        </div>
      </section>

      <PayslipDrawer
        v-model="drawerOpen"
        :run="detail"
        :employee="selectedEmployee"
        :loading="false"
        :error="''"
      />
    </template>
  </div>
</template>

<style scoped>
.payroll-detail {
  display: flex;
  flex-direction: column;
  gap: 18px;
  padding: 24px;
}

.payroll-detail__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
}

.payroll-detail__back {
  margin-bottom: 10px;
  padding: 0;
  background: none;
  border: none;
  color: #1d4ed8;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
}

.payroll-detail__breadcrumb {
  margin: 0;
  color: #64748b;
  font-size: 11.5px;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.payroll-detail__title {
  margin: 6px 0 0;
  font-size: 24px;
}

.payroll-detail__description {
  margin: 8px 0 0;
  color: #475569;
  font-size: 12.5px;
}

.payroll-detail__header-actions {
  display: inline-flex;
  align-items: center;
  gap: 8px;
}

.payroll-detail__stage {
  padding: 3px 10px;
  border-radius: 999px;
  font-size: 11.5px;
  font-weight: 600;
}

.payroll-detail__stage[data-tone='draft'] {
  color: #475569;
  background: #eef2f7;
}

.payroll-detail__stage[data-tone='submitted'] {
  color: #92400e;
  background: #fef3c7;
}

.payroll-detail__stage[data-tone='approved'] {
  color: #1e40af;
  background: #dbeafe;
}

.payroll-detail__stage[data-tone='published'] {
  color: #166534;
  background: #dcfce7;
}

.payroll-detail__stage[data-tone='cancelled'] {
  color: #7f1d1d;
  background: #fee2e2;
}

.payroll-detail__status {
  color: #64748b;
  font-size: 11.5px;
}

.payroll-detail__facts {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 12px;
  padding: 14px 16px;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  margin: 0;
}

.payroll-detail__facts dt {
  color: #64748b;
  font-size: 11px;
}

.payroll-detail__facts dd {
  margin: 3px 0 0;
  font-size: 13px;
  font-weight: 600;
}

.payroll-detail__readonly {
  margin: 0;
  padding: 11px 14px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 8px;
  color: #166534;
  font-size: 12px;
  line-height: 1.6;
}

.payroll-detail__warning {
  margin: 0;
  padding: 9px 12px;
  background: #fffbeb;
  border: 1px solid #fde68a;
  border-radius: 8px;
  color: #92400e;
  font-size: 11.5px;
}

.payroll-detail__facts--inline {
  margin: 0 16px 14px;
}

.payroll-detail__differences {
  margin: 0 16px 14px;
  padding-left: 20px;
  color: #b91c1c;
  font-size: 11.5px;
  line-height: 1.7;
}

.payroll-detail__tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  padding: 6px;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
}

.payroll-detail__tabs button {
  padding: 8px 16px;
  color: #475569;
  font-size: 12.5px;
  font-weight: 600;
  background: transparent;
  border: 1px solid transparent;
  border-radius: 8px;
  cursor: pointer;
}

.payroll-detail__tabs button[aria-selected='true'] {
  color: #1d4ed8;
  background: #eff6ff;
  border-color: #bfdbfe;
}

.payroll-detail__export-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  padding: 0 16px 14px;
}

.payroll-detail__secondary {
  padding: 8px 14px;
  font-size: 12.5px;
  font-weight: 600;
  background: #fff;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  cursor: pointer;
}

.payroll-detail__secondary:disabled {
  color: #94a3b8;
  background: #f1f5f9;
  cursor: not-allowed;
}

.payroll-detail__scroll {
  overflow-x: auto;
}

.payroll-detail__table {
  width: 100%;
  min-width: 880px;
  border-collapse: collapse;
  font-size: 12.5px;
}

.payroll-detail__table th,
.payroll-detail__table td {
  padding: 10px 12px;
  border-bottom: 1px solid #e2e8f0;
  text-align: left;
}

.payroll-detail__table thead th {
  color: #64748b;
  font-size: 11px;
  font-weight: 600;
  background: #f8fafc;
}

.payroll-detail__table .is-numeric {
  text-align: right;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

.payroll-detail__table .is-actions {
  text-align: right;
  white-space: nowrap;
}

.payroll-detail__link {
  padding: 0;
  background: none;
  border: none;
  color: #1d4ed8;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
}

.payroll-detail__failure {
  color: #b91c1c;
  font-size: 11.5px;
}

.payroll-detail__masked {
  color: #64748b;
  font-size: 11.5px;
}

.payroll-detail__error {
  margin: 0;
  color: #b91c1c;
  font-size: 11.5px;
}

.payroll-detail__success {
  margin: 0;
  color: #047857;
  font-size: 11.5px;
}

.payroll-detail__state {
  padding: 20px;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
}

.payroll-detail__state h2 {
  margin: 0 0 6px;
  font-size: 16px;
}

.payroll-detail__state p {
  margin: 0;
  color: #475569;
  font-size: 12px;
}
.payroll-detail__segments {
  margin-top: 16px;
  padding-top: 12px;
  border-top: 1px solid var(--color-border-subtle, #e5e7eb);
}

.payroll-detail__segments-title {
  margin: 0 0 4px;
  font-size: 14px;
}

.payroll-detail__segments-note {
  margin: 0 0 8px;
  color: var(--color-text-secondary, #6b7280);
  font-size: 12px;
}

.payroll-detail__segments-list {
  display: grid;
  gap: 6px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.payroll-detail__segment {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  font-size: 13px;
}
</style>
