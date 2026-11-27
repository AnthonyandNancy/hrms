<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import DataTable, { type DataTableColumn } from '@/components/DataTable.vue'
import PageShell from '@/shared/ui/PageShell.vue'
import { useSessionStore } from '@/stores/session'
import { formatBusinessDate, formatMoney, todayBusinessDate } from '@/utils/format'
import { requestIdFromError } from '@/utils/renderErrors'
import {
  createBenefitPlan,
  isForbidden,
  listBenefitEnrollments,
  listBenefitPlans,
  listBenefitTransactions,
  platformErrorCopy,
  percentToRate,
  ratePercent,
  type BenefitEnrollment,
  type BenefitPlan,
  type BenefitTransaction,
} from '../api/settings'
import { nextIdempotencyKey } from '../platformRead'

const session = useSessionStore()
const canRead = computed(() => session.can('benefit.read'))
const canManage = computed(() => session.can('benefit.manage'))

const loading = ref(true)
const plansError = ref<unknown>(null)
const enrollmentsError = ref<unknown>(null)
const transactionsError = ref<unknown>(null)
const plansForbidden = ref(false)
const enrollmentsForbidden = ref(false)
const transactionsForbidden = ref(false)
const plans = ref<BenefitPlan[]>([])
const enrollments = ref<BenefitEnrollment[]>([])
const transactions = ref<BenefitTransaction[]>([])
const enrollmentCounts = ref<Record<number, number>>({})
const enrollmentTotal = ref(0)
const transactionTotal = ref(0)

const actionError = ref('')
const actionMessage = ref('')
const saving = ref(false)

const form = reactive({
  code: '',
  name: '',
  employerRate: '',
  employeeRate: '',
  effectiveFrom: todayBusinessDate(),
  effectiveTo: '',
})

const planRows = computed(() =>
  plans.value.map((plan) => ({
    ...plan,
    employerPercent: ratePercent(plan.employerRate),
    employeePercent: ratePercent(plan.employeeRate),
    effectiveFromLabel: formatBusinessDate(plan.effectiveFrom),
    effectiveToLabel: plan.effectiveTo === null ? '长期有效' : formatBusinessDate(plan.effectiveTo),
    enrollmentCount: enrollmentCounts.value[plan.id],
  })),
)

const enrollmentRows = computed(() =>
  enrollments.value.map((enrollment) => ({
    ...enrollment,
    planLabel: planLabel(enrollment.planId),
    baseLabel: formatMoney(enrollment.baseAmountCents),
    employeeContributionLabel: formatMoney(enrollment.employeeContributionCents),
    employerContributionLabel: formatMoney(enrollment.employerContributionCents),
    effectiveFromLabel: formatBusinessDate(enrollment.effectiveFrom),
  })),
)

const transactionRows = computed(() =>
  transactions.value.map((transaction) => ({
    ...transaction,
    employeeAmountLabel: formatMoney(transaction.employeeAmountCents),
    employerAmountLabel: formatMoney(transaction.employerAmountCents),
    totalAmountLabel: formatMoney(transaction.totalAmountCents),
    payrollLabel: transaction.payrollRunId === null ? '未关联' : `批次 #${transaction.payrollRunId}`,
  })),
)

const partial = computed(() => enrollmentsError.value !== null || transactionsError.value !== null)
const partialReason = computed(() => {
  if (enrollmentsError.value !== null && transactionsError.value !== null) {
    return '参保版本与月度台账读取失败；方案列表仍来自服务端，未返回的数据不会补造。'
  }
  if (enrollmentsError.value !== null) return '参保版本读取失败；方案列表与月度台账仍来自服务端。'
  if (transactionsError.value !== null) return '月度台账读取失败；方案列表与参保版本仍来自服务端。'
  return ''
})
const isLoading = computed(
  () => loading.value && plans.value.length === 0 && enrollments.value.length === 0 && transactions.value.length === 0,
)
const isForbiddenState = computed(
  () => plansForbidden.value || (plans.value.length === 0 && (enrollmentsForbidden.value || transactionsForbidden.value)),
)
const isErrorState = computed(
  () =>
    !isForbiddenState.value &&
    plans.value.length === 0 &&
    enrollments.value.length === 0 &&
    transactions.value.length === 0 &&
    (plansError.value !== null || enrollmentsError.value !== null || transactionsError.value !== null),
)
const isEmpty = computed(
  () =>
    !loading.value &&
    !isForbiddenState.value &&
    plans.value.length === 0 &&
    enrollments.value.length === 0 &&
    transactions.value.length === 0 &&
    !isErrorState.value,
)
const requestId = computed(
  () =>
    requestIdFromError(plansError.value) ??
    requestIdFromError(enrollmentsError.value) ??
    requestIdFromError(transactionsError.value),
)
const primaryError = computed(
  () => plansError.value ?? enrollmentsError.value ?? transactionsError.value ?? null,
)

const planColumns: DataTableColumn[] = [
  { key: 'code', label: '方案代码', width: '160px' },
  { key: 'name', label: '方案名称', minWidth: '160px' },
  { key: 'employerPercent', label: '雇主费率', width: '110px' },
  { key: 'employeePercent', label: '个人费率', width: '110px' },
  { key: 'enrollmentCount', label: '参保人数', width: '100px' },
  { key: 'version', label: '版本', width: '70px' },
  { key: 'effectiveFromLabel', label: '生效日', width: '130px' },
  { key: 'effectiveToLabel', label: '失效日', width: '130px' },
]

const enrollmentColumns: DataTableColumn[] = [
  { key: 'employmentId', label: '任职编号', width: '100px' },
  { key: 'planLabel', label: '方案', minWidth: '180px' },
  { key: 'baseLabel', label: '参保基数', width: '130px' },
  { key: 'employeeContributionLabel', label: '个人缴费', width: '120px' },
  { key: 'employerContributionLabel', label: '雇主缴费', width: '120px' },
  { key: 'version', label: '版本', width: '70px' },
  { key: 'effectiveFromLabel', label: '生效日', width: '130px' },
]

const transactionColumns: DataTableColumn[] = [
  { key: 'period', label: '期间', width: '100px' },
  { key: 'employmentId', label: '任职编号', width: '100px' },
  { key: 'employeeAmountLabel', label: '个人缴费', width: '120px' },
  { key: 'employerAmountLabel', label: '雇主缴费', width: '120px' },
  { key: 'totalAmountLabel', label: '合计', width: '120px' },
  { key: 'payrollLabel', label: '工资批次', width: '110px' },
  { key: 'status', label: '状态', width: '90px' },
]

function planLabel(planId: number): string {
  const plan = plans.value.find((item) => item.id === planId)
  return plan === undefined ? `方案 #${planId}` : `${plan.code} · ${plan.name}`
}

function enrollmentCountFor(counts: Record<number, number>, planId: number): number | null {
  const count = counts[planId]
  return typeof count === 'number' && Number.isSafeInteger(count) ? count : null
}

async function loadEnrollmentCounts(nextPlans: BenefitPlan[]): Promise<Record<number, number>> {
  // The API exposes enrolment total only as a filtered projection. Query it
  // per plan so the UI can show a real count, or omit it when the read fails.
  const counts: Record<number, number> = {}
  await Promise.all(nextPlans.map(async (plan) => {
    try {
      const result = await listBenefitEnrollments({ planId: plan.id, page: 1, pageSize: 1 })
      const count = enrollmentCountFor({ [plan.id]: result.total }, plan.id)
      if (count !== null) counts[plan.id] = count
    } catch {
      // A per-plan count failure stays unknown; it is never shown as zero.
    }
  }))
  return counts
}

type Settled<T> =
  | { ok: true; value: T }
  | { ok: false; error: unknown }

async function settle<T>(read: Promise<T>): Promise<Settled<T>> {
  try {
    return { ok: true, value: await read }
  } catch (error) {
    return { ok: false, error }
  }
}

async function loadAll(): Promise<void> {
  loading.value = true
  plansError.value = null
  enrollmentsError.value = null
  transactionsError.value = null
  plansForbidden.value = false
  enrollmentsForbidden.value = false
  transactionsForbidden.value = false
  try {
    // Each panel owns its own read: one failed projection must not discard the
    // rows another endpoint actually returned.
    const [planResult, enrollmentResult, transactionResult] = await Promise.all([
      settle(listBenefitPlans()),
      settle(listBenefitEnrollments({ page: 1, pageSize: 100 })),
      settle(listBenefitTransactions()),
    ])

    if (planResult.ok) {
      plans.value = planResult.value.items
      enrollmentCounts.value = planResult.value.items.length === 0
        ? {}
        : await loadEnrollmentCounts(planResult.value.items)
    } else {
      plans.value = []
      enrollmentCounts.value = {}
      if (isForbidden(planResult.error)) plansForbidden.value = true
      else plansError.value = planResult.error
    }

    if (enrollmentResult.ok) {
      enrollments.value = enrollmentResult.value.items
      enrollmentTotal.value = enrollmentResult.value.total
    } else {
      enrollments.value = []
      enrollmentTotal.value = 0
      if (isForbidden(enrollmentResult.error)) enrollmentsForbidden.value = true
      else enrollmentsError.value = enrollmentResult.error
    }

    if (transactionResult.ok) {
      transactions.value = transactionResult.value.items
      transactionTotal.value = transactionResult.value.total
    } else {
      transactions.value = []
      transactionTotal.value = 0
      if (isForbidden(transactionResult.error)) transactionsForbidden.value = true
      else transactionsError.value = transactionResult.error
    }
  } finally {
    loading.value = false
  }
}

function resetForm(): void {
  form.code = ''
  form.name = ''
  form.employerRate = ''
  form.employeeRate = ''
  form.effectiveFrom = todayBusinessDate()
  form.effectiveTo = ''
}

async function submitPlan(): Promise<void> {
  actionError.value = ''
  actionMessage.value = ''

  const code = form.code.trim().toUpperCase()
  const name = form.name.trim()
  if (!/^[A-Z][A-Z0-9_]{2,63}$/.test(code)) {
    actionError.value = '方案代码必须为 3-64 位大写字母、数字或下划线，且以字母开头。'
    return
  }
  if (name === '') {
    actionError.value = '请填写方案名称。'
    return
  }
  const employerRate = percentToRate(form.employerRate)
  const employeeRate = percentToRate(form.employeeRate)
  if (employerRate === null || employeeRate === null) {
    actionError.value = '雇主费率与个人费率必须是 0% 至 100% 的百分比，最多 6 位小数。'
    return
  }
  if (!/^\d{4}-\d{2}-\d{2}$/.test(form.effectiveFrom)) {
    actionError.value = '请选择有效的生效日。'
    return
  }
  if (form.effectiveTo !== '' && !/^\d{4}-\d{2}-\d{2}$/.test(form.effectiveTo)) {
    actionError.value = '失效日必须是有效日期，或留空表示长期有效。'
    return
  }

  saving.value = true
  try {
    await createBenefitPlan(
      {
        code,
        name,
        employerRate,
        employeeRate,
        effectiveFrom: form.effectiveFrom,
        effectiveTo: form.effectiveTo === '' ? null : form.effectiveTo,
      },
      nextIdempotencyKey('benefit-plan'),
    )
    actionMessage.value = '福利方案版本已创建，并已写入审计记录。'
    ElMessage.success(actionMessage.value)
    resetForm()
    await loadAll()
  } catch (error) {
    actionError.value = platformErrorCopy(error)
    ElMessage.error(actionError.value)
  } finally {
    saving.value = false
  }
}

function retry(): void {
  void loadAll()
}

onMounted(() => {
  if (!canRead.value) {
    loading.value = false
    return
  }
  void loadAll()
})
</script>

<template>
  <PageShell
    data-testid="benefits-page"
    title="社保公积金与福利"
    description="福利方案按生效日版本化；费率由服务端以定点小数字符串返回，页面仅按字符串换算为百分比。参保人数与月度台账取自真实接口。"
  >
    <template #actions>
      <el-button plain :disabled="loading" @click="retry">刷新</el-button>
    </template>

    <section v-if="!canRead" class="panel" data-testid="benefit-forbidden">
      <div class="panel__body">
        <h2 class="panel__title">无权查看福利方案</h2>
        <p class="muted">当前账号没有福利查看权限。请联系系统管理员授予福利查看能力。</p>
      </div>
    </section>

    <template v-else>
      <p v-if="actionMessage" class="inline-success" data-testid="benefit-action-message">{{ actionMessage }}</p>
      <p v-if="actionError" class="inline-error" role="alert" data-testid="benefit-action-error">{{ actionError }}</p>
      <p v-if="partial && !isErrorState" class="inline-warning" data-testid="benefit-partial">{{ partialReason }}</p>

      <section v-if="isLoading" class="panel" data-testid="benefit-loading">
        <div class="panel__body">
          <el-skeleton :rows="6" animated />
          <span class="visually-hidden">正在加载福利方案与参保数据</span>
        </div>
      </section>

      <section v-else-if="isForbiddenState" class="panel" data-testid="benefit-forbidden">
        <div class="panel__body">
          <h2 class="panel__title">福利数据不可查看</h2>
          <p class="muted">服务端拒绝了本次读取。可能尚未配置福利方案，或账号的数据范围不包含这些记录。</p>
        </div>
      </section>

      <section v-else-if="isErrorState" class="panel" data-testid="benefit-error">
        <div class="panel__body">
          <h2 class="panel__title">福利数据加载失败</h2>
          <p class="muted">{{ platformErrorCopy(primaryError) }}</p>
          <p v-if="requestId" class="muted small">请求编号：<span class="mono">{{ requestId }}</span></p>
          <el-button type="primary" plain @click="retry">重试</el-button>
        </div>
      </section>

      <section v-else-if="isEmpty" class="panel" data-testid="benefit-empty">
        <div class="panel__body">
          <h2 class="panel__title">尚未配置福利方案</h2>
          <p class="muted">
            服务端当前没有可展示的福利方案、参保版本或月度台账。
            <template v-if="canManage">具备福利管理能力时可创建第一个方案版本。</template>
          </p>
        </div>
      </section>

      <template v-else>
        <section class="panel" data-testid="benefit-plans-panel">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">福利方案版本</h2>
              <p class="panel__subtitle">按生效日追加版本，历史版本保留；费率显示为服务端定点小数的百分比等值。</p>
            </div>
            <span class="muted small">{{ plans.length }} 个方案版本</span>
          </div>
          <DataTable
            v-if="planRows.length > 0"
            :columns="planColumns"
            :rows="planRows as unknown as Array<Record<string, unknown>>"
            :total="planRows.length"
            :page="1"
            :page-size="100"
            row-key="id"
            data-testid="benefit-plan-table"
            empty-title="当前没有福利方案"
            :row-test-id="(row) => `benefit-plan-row-${String(row.id)}`"
          >
            <template #cell-employerPercent="{ row }">
              <span class="mono">{{ row.employerPercent }}</span>
            </template>
            <template #cell-employeePercent="{ row }">
              <span class="mono">{{ row.employeePercent }}</span>
            </template>
            <template #cell-enrollmentCount="{ row }">
              <span v-if="row.enrollmentCount !== null" data-testid="benefit-enrollment-count">{{ row.enrollmentCount }} 人</span>
              <span v-else class="muted">不提供</span>
            </template>
          </DataTable>
          <div v-else class="panel__body">
            <p class="muted">服务端没有返回任何方案版本。</p>
          </div>
        </section>

        <section v-if="canManage" class="panel" data-testid="benefit-plan-editor">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">新增方案版本</h2>
              <p class="panel__subtitle">
                输入百分比即可；提交前会换算为服务端要求的六位以上定点小数字符串，并在服务端写入审计。
              </p>
            </div>
          </div>
          <div class="panel__body benefit-form">
            <label class="benefit-field">
              <span>方案代码</span>
              <el-input v-model="form.code" data-testid="benefit-plan-code" placeholder="例如 SOCIAL_INSURANCE" maxlength="64" />
            </label>
            <label class="benefit-field">
              <span>方案名称</span>
              <el-input v-model="form.name" data-testid="benefit-plan-name" maxlength="160" />
            </label>
            <label class="benefit-field">
              <span>雇主费率（%）</span>
              <el-input v-model="form.employerRate" data-testid="benefit-plan-employer-rate" inputmode="decimal" placeholder="例如 16" />
            </label>
            <label class="benefit-field">
              <span>个人费率（%）</span>
              <el-input v-model="form.employeeRate" data-testid="benefit-plan-employee-rate" inputmode="decimal" placeholder="例如 8" />
            </label>
            <label class="benefit-field">
              <span>生效日</span>
              <el-date-picker
                v-model="form.effectiveFrom"
                class="benefit-field__date"
                data-testid="benefit-plan-effective-from"
                type="date"
                value-format="YYYY-MM-DD"
                aria-label="生效日"
              />
            </label>
            <label class="benefit-field">
              <span>失效日（可选）</span>
              <el-date-picker
                v-model="form.effectiveTo"
                class="benefit-field__date"
                data-testid="benefit-plan-effective-to"
                type="date"
                value-format="YYYY-MM-DD"
                aria-label="失效日"
              />
            </label>
            <el-button type="primary" :loading="saving" data-testid="benefit-plan-submit" @click="submitPlan">
              创建方案版本
            </el-button>
          </div>
        </section>

        <section class="panel" data-testid="benefit-enrollments-panel">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">参保基数版本</h2>
              <p class="panel__subtitle">基数变更按生效日追加，缴费金额为服务端按费率计算的整数分。</p>
            </div>
            <span class="muted small">{{ enrollmentTotal }} 条参保记录</span>
          </div>
          <DataTable
            v-if="enrollmentRows.length > 0"
            :columns="enrollmentColumns"
            :rows="enrollmentRows as unknown as Array<Record<string, unknown>>"
            :total="enrollmentRows.length"
            :page="1"
            :page-size="100"
            row-key="id"
            data-testid="benefit-enrollment-table"
            empty-title="当前没有参保版本"
          />
          <div v-else class="panel__body">
            <p class="muted">服务端当前没有返回参保版本。</p>
          </div>
        </section>

        <section class="panel" data-testid="benefit-transactions-panel">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">月度缴费台账</h2>
              <p class="panel__subtitle">金额为整数分，工资批次是真实关联，不做推算。</p>
            </div>
            <span class="muted small">{{ transactionTotal }} 条台账</span>
          </div>
          <DataTable
            v-if="transactionRows.length > 0"
            :columns="transactionColumns"
            :rows="transactionRows as unknown as Array<Record<string, unknown>>"
            :total="transactionRows.length"
            :page="1"
            :page-size="100"
            row-key="id"
            data-testid="benefit-transaction-table"
            empty-title="当前没有月度缴费台账"
          />
          <div v-else class="panel__body">
            <p class="muted">服务端当前没有返回月度缴费台账。</p>
          </div>
        </section>
      </template>
    </template>
  </PageShell>
</template>

<style scoped>
.benefit-form {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: flex-end;
}
.benefit-field {
  display: flex;
  flex: 1 1 180px;
  flex-direction: column;
  gap: 6px;
  font-size: 12px;
  font-weight: 600;
}
.benefit-field__date {
  min-height: 32px;
  padding: 4px 8px;
  color: var(--color-ink);
  font: inherit;
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: 6px;
}
.inline-warning {
  padding: 9px 12px;
  color: #8a5a00;
  font-size: 12px;
  background: #fff6e5;
  border: 1px solid #ead49f;
  border-radius: 7px;
}
</style>
