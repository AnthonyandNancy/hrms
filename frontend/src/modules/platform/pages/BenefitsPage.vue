<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import DataTable, { type DataTableColumn } from '@/components/DataTable.vue'
import PageShell from '@/shared/ui/PageShell.vue'
import { useSessionStore } from '@/stores/session'
import { formatBusinessDate, formatMoney, parseYuanToCents, todayBusinessDate } from '@/utils/format'
import { requestIdFromError } from '@/utils/renderErrors'
import StatutoryParametersPanel from '../components/StatutoryParametersPanel.vue'
import {
  createBenefitEnrollment,
  createBenefitPlan,
  createBenefitTransaction,
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

type BenefitsTab = 'plans' | 'enrollments' | 'transactions' | 'statutory'

const TABS: ReadonlyArray<{ name: BenefitsTab; label: string }> = [
  { name: 'plans', label: '福利方案' },
  { name: 'enrollments', label: '参保登记' },
  { name: 'transactions', label: '缴费台账' },
  { name: 'statutory', label: '法定参数' },
]

const route = useRoute()
const router = useRouter()
const session = useSessionStore()

const canRead = computed(() => session.can('benefit.read'))
const canManage = computed(() => session.can('benefit.manage'))
const canReadStatutory = computed(() => session.can('payroll.parameters.read'))

function isTab(value: unknown): value is BenefitsTab {
  return typeof value === 'string' && TABS.some((tab) => tab.name === value)
}

const activeTab = ref<BenefitsTab>(isTab(route.query.tab) ? route.query.tab : 'plans')

watch(
  () => route.query.tab,
  (value) => {
    if (isTab(value)) activeTab.value = value
  },
)

const visibleTabs = computed(() => TABS.filter((tab) => tab.name !== 'statutory' || canReadStatutory.value))

const currentTab = computed<BenefitsTab>(() =>
  visibleTabs.value.some((tab) => tab.name === activeTab.value)
    ? activeTab.value
    : visibleTabs.value[0]?.name ?? 'plans',
)

function selectTab(name: string | number): void {
  if (!isTab(name)) return
  activeTab.value = name
  void router.replace({ query: { ...route.query, tab: name } })
}

const loading = ref(false)
const plansLoaded = ref(false)
const enrollmentsLoaded = ref(false)
const transactionsLoaded = ref(false)

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
const enrollmentCountsTruncated = ref(false)
const enrollmentTotal = ref(0)
const transactionTotal = ref(0)
const enrollmentFilter = ref('')

const actionError = ref('')
const actionMessage = ref('')
const saving = ref(false)
const enrollmentSaving = ref(false)
const enrollmentError = ref('')
const enrollmentMessage = ref('')

const form = reactive({
  code: '',
  name: '',
  employerRate: '',
  employeeRate: '',
  effectiveFrom: todayBusinessDate(),
  effectiveTo: '',
})

const enrollmentForm = reactive({
  planId: 0 as number,
  employmentId: '',
  baseYuan: '',
  effectiveFrom: todayBusinessDate(),
  effectiveTo: '',
})

const transactionForm = reactive({
  enrollmentId: '',
  period: todayBusinessDate().slice(0, 7),
  payrollRunId: '',
})

const transactionSaving = ref(false)
const transactionError = ref('')
const transactionMessage = ref('')

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

const visibleEnrollmentRows = computed(() => {
  const filter = enrollmentFilter.value.trim()
  if (filter === '') return enrollments.value
  return enrollments.value.filter((enrollment) =>
    String(enrollment.employmentId).includes(filter)
    || String(enrollment.planId).includes(filter)
    || planLabel(enrollment.planId).includes(filter),
  )
})

const enrollmentRows = computed(() =>
  visibleEnrollmentRows.value.map((enrollment) => ({
    ...enrollment,
    planLabel: planLabel(enrollment.planId),
    baseLabel: formatMoney(enrollment.baseAmountCents),
    employeeRateLabel: ratePercent(enrollment.employeeRate),
    employerRateLabel: ratePercent(enrollment.employerRate),
    employeeContributionLabel: formatMoney(enrollment.employeeContributionCents),
    employerContributionLabel: formatMoney(enrollment.employerContributionCents),
    effectiveFromLabel: formatBusinessDate(enrollment.effectiveFrom),
    effectiveToLabel: enrollment.effectiveTo === null ? '长期有效' : formatBusinessDate(enrollment.effectiveTo),
    statusLabel: '有效版本',
  })),
)

const transactionRows = computed(() =>
  transactions.value.map((transaction) => ({
    ...transaction,
    baseLabel: formatMoney(transaction.baseAmountCents),
    employeeAmountLabel: formatMoney(transaction.employeeAmountCents),
    employerAmountLabel: formatMoney(transaction.employerAmountCents),
    totalAmountLabel: formatMoney(transaction.totalAmountCents),
    payrollLabel: transaction.payrollRunId === null ? '未关联工资批次' : `工资批次 #${transaction.payrollRunId}`,
    statusLabel: '已登记',
  })),
)

const planOptions = computed(() => plans.value.map((plan) => ({
  value: plan.id,
  label: `${plan.code} · ${plan.name} · 第 ${plan.version} 版`,
})))

const partial = computed(() => enrollmentsError.value !== null || transactionsError.value !== null)
const partialReason = computed(() => {
  if (enrollmentsError.value !== null && transactionsError.value !== null) {
    return '参保版本与月度台账读取失败；方案列表仍来自服务端，未返回的数据不会补造。'
  }
  if (enrollmentsError.value !== null) return '参保版本读取失败；方案列表与月度台账仍来自服务端。'
  if (transactionsError.value !== null) return '月度台账读取失败；方案列表与参保版本仍来自服务端。'
  return ''
})

function tabLoading(tab: BenefitsTab): boolean {
  if (loading.value) return true
  if (tab === 'plans') return !plansLoaded.value
  if (tab === 'enrollments') return !enrollmentsLoaded.value
  if (tab === 'transactions') return !transactionsLoaded.value
  return false
}

const tabForbidden = computed(() => {
  if (currentTab.value === 'statutory') return !canReadStatutory.value
  if (currentTab.value === 'plans') return plansForbidden.value
  if (currentTab.value === 'enrollments') return enrollmentsForbidden.value
  if (currentTab.value === 'transactions') return transactionsForbidden.value
  return false
})

const tabError = computed(() => {
  if (currentTab.value === 'plans') return plansError.value
  if (currentTab.value === 'enrollments') return enrollmentsError.value
  if (currentTab.value === 'transactions') return transactionsError.value
  return null
})

const tabRequestId = computed(() => requestIdFromError(tabError.value))

const tabEmpty = computed(() => {
  if (tabForbidden.value || tabLoading(currentTab.value) || tabError.value !== null) return false
  if (currentTab.value === 'plans') return plans.value.length === 0
  if (currentTab.value === 'enrollments') return enrollments.value.length === 0
  if (currentTab.value === 'transactions') return transactions.value.length === 0
  return false
})

const planColumns: DataTableColumn[] = [
  { key: 'code', label: '方案代码', width: '160px' },
  { key: 'name', label: '方案名称', minWidth: '160px' },
  { key: 'employerPercent', label: '公司比例', width: '110px' },
  { key: 'employeePercent', label: '个人比例', width: '110px' },
  { key: 'enrollmentCount', label: '参保人数', width: '110px' },
  { key: 'version', label: '版本', width: '70px' },
  { key: 'effectiveFromLabel', label: '生效日', width: '130px' },
  { key: 'effectiveToLabel', label: '失效日', width: '130px' },
]

const enrollmentColumns: DataTableColumn[] = [
  { key: 'employmentId', label: '任职编号', width: '100px' },
  { key: 'planLabel', label: '福利方案', minWidth: '190px' },
  { key: 'baseLabel', label: '参保基数', width: '130px', align: 'right' },
  { key: 'employeeRateLabel', label: '个人比例', width: '100px', align: 'right' },
  { key: 'employerRateLabel', label: '公司比例', width: '100px', align: 'right' },
  { key: 'employeeContributionLabel', label: '个人缴费', width: '120px', align: 'right' },
  { key: 'employerContributionLabel', label: '公司缴费', width: '120px', align: 'right' },
  { key: 'version', label: '版本', width: '70px' },
  { key: 'effectiveFromLabel', label: '生效日', width: '125px' },
  { key: 'effectiveToLabel', label: '失效日', width: '125px' },
  { key: 'statusLabel', label: '状态', width: '90px' },
]

const transactionColumns: DataTableColumn[] = [
  { key: 'period', label: '期间', width: '100px' },
  { key: 'employmentId', label: '任职编号', width: '100px' },
  { key: 'baseLabel', label: '缴费基数', width: '130px', align: 'right' },
  { key: 'employeeAmountLabel', label: '个人缴费', width: '120px', align: 'right' },
  { key: 'employerAmountLabel', label: '公司缴费', width: '120px', align: 'right' },
  { key: 'totalAmountLabel', label: '合计', width: '130px', align: 'right' },
  { key: 'payrollLabel', label: '工资批次', width: '140px' },
  { key: 'statusLabel', label: '状态', width: '90px' },
]

function planLabel(planId: number): string {
  const plan = plans.value.find((item) => item.id === planId)
  return plan === undefined ? `方案 #${planId}` : `${plan.code} · ${plan.name}`
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

/**
 * Count enrolments from one bounded server read. The endpoint does not expose a
 * per-plan aggregate, so any total beyond this page is explicitly marked as
 * truncated rather than silently presenting a partial count as complete.
 */
function aggregateEnrollmentCounts(rows: BenefitEnrollment[]): { counts: Record<number, number>; truncated: boolean } {
  const counts: Record<number, number> = {}
  for (const enrollment of rows) {
    counts[enrollment.planId] = (counts[enrollment.planId] ?? 0) + 1
  }
  return { counts, truncated: false }
}

async function loadPlans(): Promise<void> {
  plansError.value = null
  plansForbidden.value = false
  plansLoaded.value = false
  try {
    const result = await listBenefitPlans()
    plans.value = result.items
  } catch (error) {
    plans.value = []
    if (isForbidden(error)) plansForbidden.value = true
    else plansError.value = error
  } finally {
    plansLoaded.value = true
  }
}

async function loadEnrollments(includeCounts = false): Promise<void> {
  enrollmentsError.value = null
  enrollmentsForbidden.value = false
  enrollmentsLoaded.value = false
  try {
    const result = await listBenefitEnrollments({ page: 1, pageSize: 100 })
    enrollments.value = result.items
    enrollmentTotal.value = result.total
    if (includeCounts) {
      const aggregate = aggregateEnrollmentCounts(result.items)
      enrollmentCounts.value = aggregate.counts
      enrollmentCountsTruncated.value = result.total > result.items.length
    }
  } catch (error) {
    enrollments.value = []
    enrollmentTotal.value = 0
    if (isForbidden(error)) enrollmentsForbidden.value = true
    else enrollmentsError.value = error
  } finally {
    enrollmentsLoaded.value = true
  }
}

async function loadTransactions(): Promise<void> {
  transactionsError.value = null
  transactionsForbidden.value = false
  transactionsLoaded.value = false
  try {
    const result = await listBenefitTransactions()
    transactions.value = result.items
    transactionTotal.value = result.total
  } catch (error) {
    transactions.value = []
    transactionTotal.value = 0
    if (isForbidden(error)) transactionsForbidden.value = true
    else transactionsError.value = error
  } finally {
    transactionsLoaded.value = true
  }
}

async function loadTab(tab: BenefitsTab): Promise<void> {
  if (tab === 'statutory') return
  if (tab === 'plans') {
    plansError.value = null
    plansForbidden.value = false
    enrollmentsError.value = null
    enrollmentsForbidden.value = false
    plansLoaded.value = false
    enrollmentsLoaded.value = false
    // Plans and enrolment counts share one enrolment read to avoid N+1.
    const [planResult, enrollmentResult] = await Promise.all([
      settle(listBenefitPlans()),
      settle(listBenefitEnrollments({ page: 1, pageSize: 100 })),
    ])
    plansLoaded.value = true
    enrollmentsLoaded.value = true

    if (planResult.ok) {
      plans.value = planResult.value.items
    } else {
      plans.value = []
      if (isForbidden(planResult.error)) plansForbidden.value = true
      else plansError.value = planResult.error
    }

    if (enrollmentResult.ok) {
      enrollments.value = enrollmentResult.value.items
      enrollmentTotal.value = enrollmentResult.value.total
      const aggregate = aggregateEnrollmentCounts(enrollmentResult.value.items)
      enrollmentCounts.value = aggregate.counts
      enrollmentCountsTruncated.value = enrollmentResult.value.total > enrollmentResult.value.items.length
    } else {
      enrollments.value = []
      enrollmentTotal.value = 0
      if (isForbidden(enrollmentResult.error)) enrollmentsForbidden.value = true
      else enrollmentsError.value = enrollmentResult.error
    }
    return
  }
  if (tab === 'enrollments') {
    plansLoaded.value = false
    enrollmentsLoaded.value = false
    await Promise.all([loadEnrollments(false), loadPlans()])
    if (enrollmentForm.planId < 1) resetEnrollmentForm()
    return
  }
  transactionsLoaded.value = false
  await loadTransactions()
}

async function ensureTabLoaded(tab: BenefitsTab): Promise<void> {
  if (tab === 'statutory') return
  const loaded = tab === 'plans' ? plansLoaded.value : tab === 'enrollments' ? enrollmentsLoaded.value : transactionsLoaded.value
  if (loaded) return
  loading.value = true
  try {
    await loadTab(tab)
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

function resetEnrollmentForm(): void {
  enrollmentForm.planId = planOptions.value[0]?.value ?? 0
  enrollmentForm.employmentId = ''
  enrollmentForm.baseYuan = ''
  enrollmentForm.effectiveFrom = todayBusinessDate()
  enrollmentForm.effectiveTo = ''
}

async function submitEnrollment(): Promise<void> {
  enrollmentError.value = ''
  enrollmentMessage.value = ''

  if (!canManage.value) {
    enrollmentError.value = '当前账号没有参保维护权限。'
    return
  }

  const employmentText = enrollmentForm.employmentId.trim()
  if (!/^\d+$/.test(employmentText) || Number(employmentText) < 1) {
    enrollmentError.value = '请输入有效的员工任职编号。'
    return
  }
  if (!Number.isSafeInteger(enrollmentForm.planId) || enrollmentForm.planId < 1) {
    enrollmentError.value = '请选择福利方案。'
    return
  }

  let baseAmountCents: number
  try {
    baseAmountCents = parseYuanToCents(enrollmentForm.baseYuan)
  } catch {
    enrollmentError.value = '参保基数必须是非负金额，最多两位小数。'
    return
  }
  if (baseAmountCents < 0) {
    enrollmentError.value = '参保基数必须是非负金额。'
    return
  }
  if (!/^\d{4}-\d{2}-\d{2}$/.test(enrollmentForm.effectiveFrom)) {
    enrollmentError.value = '请选择有效的生效日。'
    return
  }
  if (enrollmentForm.effectiveTo !== '' && enrollmentForm.effectiveTo < enrollmentForm.effectiveFrom) {
    enrollmentError.value = '失效日不得早于生效日；留空表示长期有效。'
    return
  }

  enrollmentSaving.value = true
  try {
    await createBenefitEnrollment({
      planId: enrollmentForm.planId,
      employmentId: Number(employmentText),
      baseAmountCents,
      effectiveFrom: enrollmentForm.effectiveFrom,
      effectiveTo: enrollmentForm.effectiveTo === '' ? null : enrollmentForm.effectiveTo,
    }, nextIdempotencyKey('benefit-enrollment'))
    enrollmentMessage.value = '参保版本已登记，并已写入审计记录。'
    ElMessage.success(enrollmentMessage.value)
    resetEnrollmentForm()
    await loadEnrollments(true)
    if (plans.value.length === 0) await loadPlans()
  } catch (error) {
    enrollmentError.value = enrollmentErrorCopy(error)
    ElMessage.error(enrollmentError.value)
  } finally {
    enrollmentSaving.value = false
  }
}

function enrollmentErrorCopy(error: unknown): string {
  return benefitManagerErrorCopy(error)
}

function resetTransactionForm(): void {
  transactionForm.enrollmentId = ''
  transactionForm.period = todayBusinessDate().slice(0, 7)
  transactionForm.payrollRunId = ''
}

async function submitTransaction(): Promise<void> {
  transactionError.value = ''
  transactionMessage.value = ''

  if (!canManage.value) {
    transactionError.value = '当前账号没有缴费台账登记权限。'
    return
  }

  const enrollmentText = transactionForm.enrollmentId.trim()
  if (!/^\d+$/.test(enrollmentText) || Number(enrollmentText) < 1) {
    transactionError.value = '请输入有效的参保编号。'
    return
  }
  if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(transactionForm.period.trim())) {
    transactionError.value = '计费期间必须使用 YYYY-MM 格式。'
    return
  }
  const payrollText = transactionForm.payrollRunId.trim()
  if (payrollText !== '' && (!/^\d+$/.test(payrollText) || Number(payrollText) < 1)) {
    transactionError.value = '工资批次编号必须是正整数，或留空表示暂不关联。'
    return
  }

  transactionSaving.value = true
  try {
    await createBenefitTransaction({
      enrollmentId: Number(enrollmentText),
      period: transactionForm.period.trim(),
      payrollRunId: payrollText === '' ? null : Number(payrollText),
    }, nextIdempotencyKey('benefit-transaction'))
    transactionMessage.value = '缴费台账已登记，并已写入审计记录。'
    ElMessage.success(transactionMessage.value)
    resetTransactionForm()
    await loadTransactions()
  } catch (error) {
    transactionError.value = transactionErrorCopy(error)
    ElMessage.error(transactionError.value)
  } finally {
    transactionSaving.value = false
  }
}

function benefitManagerErrorCopy(error: unknown): string {
  const reason = error instanceof Error && 'serverReason' in error
    ? String((error as { serverReason?: unknown }).serverReason ?? '')
    : ''
  if (reason === 'BENEFIT_EFFECTIVE_RANGE_INVALID') return '失效日不得早于生效日。'
  if (reason === 'BENEFIT_ENROLLMENT_VERSION_ORDER') return '新参保版本的生效日必须晚于该员工在此方案下的最新版本。'
  if (reason === 'BENEFIT_PLAN_VERSION_ORDER') return '新方案版本的生效日必须晚于现有最新版本。'
  if (reason === 'BENEFIT_BASE_INVALID') return '参保基数必须是非负整数分金额。'
  return platformErrorCopy(error)
}

async function submitPlan(): Promise<void> {
  actionError.value = ''
  actionMessage.value = ''

  if (!canManage.value) {
    actionError.value = '当前账号没有福利方案写入权限。'
    return
  }

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
    actionError.value = '公司费率与个人费率的比例必须是 0% 至 100% 的百分比，最多 6 位小数。'
    return
  }
  if (!/^\d{4}-\d{2}-\d{2}$/.test(form.effectiveFrom)) {
    actionError.value = '请选择有效的生效日。'
    return
  }
  if (form.effectiveTo !== '' && form.effectiveTo < form.effectiveFrom) {
    actionError.value = '失效日不得早于生效日；留空表示长期有效。'
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
    await loadTab('plans')
  } catch (error) {
    actionError.value = planErrorCopy(error)
    ElMessage.error(actionError.value)
  } finally {
    saving.value = false
  }
}

function planErrorCopy(error: unknown): string {
  const reason = error instanceof Error && 'serverReason' in error
    ? String((error as { serverReason?: unknown }).serverReason ?? '')
    : ''
  if (reason === 'BENEFIT_EFFECTIVE_RANGE_INVALID') return '失效日不得早于生效日。'
  if (reason === 'BENEFIT_PLAN_VERSION_ORDER') return '新方案版本的生效日必须晚于现有最新版本。'
  return platformErrorCopy(error)
}

function transactionErrorCopy(error: unknown): string {
  const reason = error instanceof Error && 'serverReason' in error
    ? String((error as { serverReason?: unknown }).serverReason ?? '')
    : ''
  if (reason === 'BENEFIT_PAYROLL_RUN_FROZEN') {
    return '目标工资批次已发布、已发放或已关闭，不能再关联新的福利台账。'
  }
  if (reason === 'BENEFIT_PERIOD_DUPLICATE') return '该参保版本在本期间已有缴费台账，不能重复登记。'
  if (reason === 'BENEFIT_PAYROLL_LINK_DUPLICATE') return '该工资批次已关联其他福利台账，不能重复使用。'
  return platformErrorCopy(error)
}

function retry(): void {
  if (currentTab.value === 'statutory') return
  if (currentTab.value === 'plans') {
    plansLoaded.value = false
    enrollmentsLoaded.value = false
  } else if (currentTab.value === 'enrollments') {
    enrollmentsLoaded.value = false
  } else {
    transactionsLoaded.value = false
  }
  void ensureTabLoaded(currentTab.value)
}

watch(currentTab, (tab) => {
  if (!canRead.value) return
  if (tab === 'enrollments' && enrollmentForm.planId < 1) resetEnrollmentForm()
  void ensureTabLoaded(tab)
})

function refreshCurrentTab(): void {
  if (currentTab.value === 'statutory') return
  plansLoaded.value = false
  enrollmentsLoaded.value = false
  transactionsLoaded.value = false
  void ensureTabLoaded(currentTab.value)
}

onMounted(() => {
  if (!canRead.value) return
  void ensureTabLoaded(currentTab.value)
})
</script>

<template>
  <PageShell
    data-testid="benefits-page"
    title="社保公积金与福利"
    description="法定参数、福利方案、员工参保与月度缴费台账在同一工作区维护；参数只有一个事实源，金额以整数分展示，费率以服务端定点小数列展示。"
  >
    <template #actions>
      <el-button plain :disabled="loading" @click="refreshCurrentTab">刷新</el-button>
    </template>

    <section v-if="!canRead" class="panel" data-testid="benefit-forbidden">
      <div class="panel__body">
        <h2 class="panel__title">无权查看社保公积金与福利</h2>
        <p class="muted">当前账号没有福利查看权限。请联系系统管理员授予福利查看能力。</p>
      </div>
    </section>

    <template v-else>
      <el-tabs
        :model-value="currentTab"
        data-testid="benefits-tabs"
        class="benefits-tabs"
        @tab-change="selectTab"
        @tab-click="selectTab"
      >
        <el-tab-pane
          v-for="tab in visibleTabs"
          :key="tab.name"
          :name="tab.name"
        >
          <template #label>
            <span :data-testid="`benefits-tab-${tab.name}`">{{ tab.label }}</span>
          </template>
        </el-tab-pane>
      </el-tabs>

      <p v-if="partial && currentTab === 'plans'" class="inline-warning" data-testid="benefit-partial">
        {{ partialReason }}
      </p>

      <StatutoryParametersPanel v-if="currentTab === 'statutory'" />

      <template v-else>
        <p v-if="!canManage" class="inline-info" data-testid="benefit-readonly">
          当前为只读视图。福利方案与参保维护需要福利管理权限；法定参数维护需要薪资参数管理权限。
        </p>

        <template v-if="currentTab === 'plans'">
          <section v-if="tabLoading('plans')" class="panel" data-testid="benefit-loading">
            <div class="panel__body">
              <el-skeleton :rows="6" animated />
              <span class="visually-hidden">正在加载福利方案</span>
            </div>
          </section>

          <section v-else-if="tabForbidden" class="panel" data-testid="benefit-forbidden">
            <div class="panel__body">
              <h2 class="panel__title">福利方案不可查看</h2>
              <p class="muted">服务端拒绝了本次读取。可能尚未配置方案，或账号的数据范围不包含这些记录。</p>
            </div>
          </section>

          <section v-else-if="tabError" class="panel" data-testid="benefit-error">
            <div class="panel__body">
              <h2 class="panel__title">福利方案加载失败</h2>
              <p class="muted">{{ platformErrorCopy(tabError) }}</p>
              <p v-if="tabRequestId" class="muted small">请求编号：<span class="mono">{{ tabRequestId }}</span></p>
              <el-button type="primary" plain @click="retry">重试</el-button>
            </div>
          </section>

          <template v-else>
            <section v-if="tabEmpty" class="panel" data-testid="benefit-empty">
              <div class="panel__body">
                <h2 class="panel__title">尚未配置福利方案</h2>
                <p class="muted">
                  服务端当前没有可展示的福利方案。
                  <template v-if="canManage">可在下方创建第一个方案版本。</template>
                </p>
              </div>
            </section>

            <section v-else class="panel" data-testid="benefit-plans-panel">
              <div class="panel__header">
                <div>
                  <h2 class="panel__title">福利方案版本</h2>
                  <p class="panel__subtitle">按生效日追加版本，历史版本保留；比例显示为服务端定点小数的百分比等值，不复制法定参数。</p>
                </div>
                <span class="muted small">{{ plans.length }} 个方案版本</span>
              </div>
              <DataTable
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
                  <span v-if="row.enrollmentCount !== undefined" data-testid="benefit-enrollment-count">{{ row.enrollmentCount }} 人</span>
                  <span v-else class="muted">—</span>
                </template>
              </DataTable>
              <p v-if="enrollmentCountsTruncated" class="muted small statutory-hint" data-testid="benefit-enrollment-count-truncated">
                参保人数按前 {{ enrollments.length }} 条记录聚合，超出分页范围的记录未计入。
              </p>
            </section>

            <section v-if="canManage" class="panel" data-testid="benefit-plan-editor">
              <div class="panel__header">
                <div>
                  <h2 class="panel__title">新增方案版本</h2>
                  <p class="panel__subtitle">输入百分比后由页面换算为服务端要求的定点小数字符串；不会自动填入法定费率。</p>
                </div>
              </div>
              <p v-if="actionMessage" class="inline-success" data-testid="benefit-action-message">{{ actionMessage }}</p>
              <p v-if="actionError" class="inline-error" role="alert" data-testid="benefit-action-error">{{ actionError }}</p>
              <div class="panel__body benefit-form">
                <label class="benefit-field">
                  <span>方案代码</span>
                  <el-input v-model="form.code" data-testid="benefit-plan-code" placeholder="例如 SUPPLEMENTARY_MEDICAL" maxlength="64" />
                </label>
                <label class="benefit-field">
                  <span>方案名称</span>
                  <el-input v-model="form.name" data-testid="benefit-plan-name" maxlength="160" />
                </label>
                <label class="benefit-field">
                  <span>公司比例（%）</span>
                  <el-input v-model="form.employerRate" data-testid="benefit-plan-employer-rate" inputmode="decimal" placeholder="例如 16" />
                </label>
                <label class="benefit-field">
                  <span>个人比例（%）</span>
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
          </template>
        </template>

        <template v-else-if="currentTab === 'enrollments'">
          <section v-if="tabLoading('enrollments')" class="panel" data-testid="benefit-enrollment-loading">
            <div class="panel__body">
              <el-skeleton :rows="6" animated />
              <span class="visually-hidden">正在加载参保登记</span>
            </div>
          </section>

          <section v-else-if="tabForbidden" class="panel" data-testid="benefit-forbidden">
            <div class="panel__body">
              <h2 class="panel__title">参保登记不可查看</h2>
              <p class="muted">服务端拒绝了本次读取。参保记录按公司范围授权。</p>
            </div>
          </section>

          <section v-else-if="tabError" class="panel" data-testid="benefit-error">
            <div class="panel__body">
              <h2 class="panel__title">参保登记加载失败</h2>
              <p class="muted">{{ platformErrorCopy(tabError) }}</p>
              <p v-if="tabRequestId" class="muted small">请求编号：<span class="mono">{{ tabRequestId }}</span></p>
              <el-button type="primary" plain @click="retry">重试</el-button>
            </div>
          </section>

          <template v-else>
            <section v-if="tabEmpty" class="panel" data-testid="benefit-enrollment-empty">
              <div class="panel__body">
                <h2 class="panel__title">尚无员工参保登记</h2>
                <p class="muted">员工参保记录来自真实福利方案与在职任职；当前服务端没有返回参保版本。</p>
              </div>
            </section>

            <section v-else class="panel" data-testid="benefit-enrollments-panel">
              <div class="panel__header">
                <div>
                  <h2 class="panel__title">参保登记</h2>
                  <p class="panel__subtitle">基数变更按生效日追加版本；员工来自真实任职，缴费金额由服务端按方案比例计算。</p>
                </div>
                <span class="muted small">{{ enrollmentTotal }} 条参保记录</span>
              </div>
              <p v-if="enrollmentMessage" class="inline-success" data-testid="benefit-enrollment-message">{{ enrollmentMessage }}</p>
              <p v-if="enrollmentError" class="inline-error" role="alert" data-testid="benefit-enrollment-error">{{ enrollmentError }}</p>
              <div class="panel__body benefit-filter">
                <label class="benefit-field benefit-field--filter">
                  <span>按员工筛选</span>
                  <el-input
                    v-model="enrollmentFilter"
                    data-testid="benefit-enrollment-filter"
                    clearable
                    placeholder="输入任职编号、方案编号或名称"
                  />
                </label>
              </div>
              <DataTable
                :columns="enrollmentColumns"
                :rows="enrollmentRows as unknown as Array<Record<string, unknown>>"
                :total="enrollmentRows.length"
                :page="1"
                :page-size="100"
                row-key="id"
                data-testid="benefit-enrollment-table"
                empty-title="当前没有参保版本"
              />
            </section>
            <section v-if="canManage" class="panel" data-testid="benefit-enrollment-editor-panel">
              <div v-if="canManage" class="panel__body benefit-form" data-testid="benefit-enrollment-editor">
                <label class="benefit-field">
                  <span>福利方案</span>
                  <el-select v-model="enrollmentForm.planId" data-testid="benefit-enrollment-plan" aria-label="福利方案">
                    <el-option v-for="option in planOptions" :key="option.value" :label="option.label" :value="option.value" />
                  </el-select>
                </label>
                <label class="benefit-field">
                  <span>员工任职编号</span>
                  <el-input v-model="enrollmentForm.employmentId" data-testid="benefit-enrollment-employment" inputmode="numeric" />
                </label>
                <label class="benefit-field">
                  <span>参保基数（元）</span>
                  <el-input v-model="enrollmentForm.baseYuan" data-testid="benefit-enrollment-base" inputmode="decimal" placeholder="0.00" />
                </label>
                <label class="benefit-field">
                  <span>生效日</span>
                  <el-date-picker
                    v-model="enrollmentForm.effectiveFrom"
                    class="benefit-field__date"
                    data-testid="benefit-enrollment-effective-from"
                    type="date"
                    value-format="YYYY-MM-DD"
                    aria-label="参保生效日"
                  />
                </label>
                <label class="benefit-field">
                  <span>失效日（可选）</span>
                  <el-date-picker
                    v-model="enrollmentForm.effectiveTo"
                    class="benefit-field__date"
                    data-testid="benefit-enrollment-effective-to"
                    type="date"
                    value-format="YYYY-MM-DD"
                    aria-label="参保失效日"
                  />
                </label>
                <el-button type="primary" :loading="enrollmentSaving" data-testid="benefit-enrollment-submit" @click="submitEnrollment">
                  登记参保版本
                </el-button>
              </div>
            </section>
          </template>
        </template>

        <template v-else>
          <section v-if="tabLoading('transactions')" class="panel" data-testid="benefit-transaction-loading">
            <div class="panel__body">
              <el-skeleton :rows="6" animated />
              <span class="visually-hidden">正在加载缴费台账</span>
            </div>
          </section>

          <section v-else-if="tabForbidden" class="panel" data-testid="benefit-forbidden">
            <div class="panel__body">
              <h2 class="panel__title">缴费台账不可查看</h2>
              <p class="muted">服务端拒绝了本次读取。台账按公司范围授权。</p>
            </div>
          </section>

          <section v-else-if="tabError" class="panel" data-testid="benefit-error">
            <div class="panel__body">
              <h2 class="panel__title">缴费台账加载失败</h2>
              <p class="muted">{{ platformErrorCopy(tabError) }}</p>
              <p v-if="tabRequestId" class="muted small">请求编号：<span class="mono">{{ tabRequestId }}</span></p>
              <el-button type="primary" plain @click="retry">重试</el-button>
            </div>
          </section>

          <template v-else>
            <section v-if="tabEmpty" class="panel" data-testid="benefit-transaction-empty">
              <div class="panel__body">
                <h2 class="panel__title">尚无缴费台账</h2>
                <p class="muted">月度缴费是只读登记事实；当前服务端没有返回缴费记录。</p>
              </div>
            </section>

            <section v-else class="panel" data-testid="benefit-transactions-panel">
              <div class="panel__header">
                <div>
                  <h2 class="panel__title">缴费台账</h2>
                  <p class="panel__subtitle">金额为整数分；工资批次只显示关联编号与期间，台账状态登记后只读。</p>
                </div>
                <span class="muted small">{{ transactionTotal }} 条台账</span>
              </div>
              <DataTable
                :columns="transactionColumns"
                :rows="transactionRows as unknown as Array<Record<string, unknown>>"
                :total="transactionRows.length"
                :page="1"
                :page-size="100"
                row-key="id"
                data-testid="benefit-transaction-table"
                empty-title="当前没有月度缴费台账"
              />
              <p v-if="transactionMessage" class="inline-success" data-testid="benefit-transaction-message">{{ transactionMessage }}</p>
              <p v-if="transactionError" class="inline-error" role="alert" data-testid="benefit-transaction-error">{{ transactionError }}</p>

              <p class="muted small statutory-hint">
                已发布、已发放或已关闭的工资批次处于冻结状态；登记新台账时会由服务端拒绝并提示联系工资管理员通过更正批次处理。
              </p>
            </section>
            <section v-if="canManage" class="panel" data-testid="benefit-transaction-editor-panel">
              <div v-if="canManage" class="panel__body benefit-form" data-testid="benefit-transaction-editor">
                <label class="benefit-field">
                  <span>参保编号</span>
                  <el-input v-model="transactionForm.enrollmentId" data-testid="benefit-transaction-enrollment" inputmode="numeric" />
                </label>
                <label class="benefit-field">
                  <span>计费期间</span>
                  <el-input v-model="transactionForm.period" data-testid="benefit-transaction-period" placeholder="YYYY-MM" maxlength="7" />
                </label>
                <label class="benefit-field">
                  <span>工资批次编号（可选）</span>
                  <el-input v-model="transactionForm.payrollRunId" data-testid="benefit-transaction-payroll-run" inputmode="numeric" />
                </label>
                <el-button type="primary" :loading="transactionSaving" data-testid="benefit-transaction-submit" @click="submitTransaction">
                  登记缴费台账
                </el-button>
              </div>
            </section>
          </template>
        </template>
      </template>
    </template>
  </PageShell>
</template>

<style scoped>
.benefits-tabs {
  padding: 0 4px;
}
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
.benefit-field--filter {
  max-width: 360px;
}
.benefit-filter {
  padding-bottom: 0;
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
.inline-info {
  padding: 9px 12px;
  color: #335c81;
  font-size: 12px;
  background: #eef5fb;
  border: 1px solid #c5d9ea;
  border-radius: 7px;
}
.inline-success {
  margin: 0 16px 10px;
  color: #1f6b45;
  font-size: 12px;
}
.inline-error {
  margin: 0 16px 10px;
  color: #a12a2a;
  font-size: 12px;
}
.statutory-hint {
  padding: 0 16px 14px;
}
</style>
