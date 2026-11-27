<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ApiError, errorCode, errorCopy } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import LoadingState from '@/components/LoadingState.vue'
import DataTable, { type DataTableColumn } from '@/components/DataTable.vue'
import PerformanceScoreDrawer from '../components/PerformanceScoreDrawer.vue'
import {
  listPerformanceCalibrations,
  listPerformanceFeedback,
  listPerformanceGoals,
  listPerformanceIndicators,
  listPerformanceReports,
  listPerformanceResults,
  listPerformanceTemplates,
  type PerformanceCalibrationRow,
  type PerformanceFeedbackRow,
  type PerformanceIndicatorRow,
  type PerformancePlanGoalRow,
  type PerformanceReportRow,
  type PerformanceResultPage,
  type PerformanceTemplateRow,
  canScoreReview,
  isCycleReadOnly,
  isDecidedReview,
  listPerformanceCycles,
  listPerformanceReviews,
  performanceActionErrorCopy,
  performanceCycleStage,
  appealPerformanceReview,
  calibratePerformanceReview,
  performancePeriodLabel,
  publishPerformanceCycle,
  ratingLabel,
  reviewStatusLabel,
  savePerformanceReview,
  submitPerformanceReview,
  type PerformanceCycleSummary,
  type PerformanceReviewRow,
} from '../api'

defineOptions({ name: 'PerformanceCycleDetailPage' })

const templateRows = computed<Array<Record<string, unknown>>>(() =>
  templates.value.map((row) => ({ ...row, versionLabel: `v${row.version}`, publishedLabel: row.publishedAt ?? '—' })),
)
const templateColumns: DataTableColumn[] = [
  { key: 'code', label: '编码' }, { key: 'name', label: '名称' },
  { key: 'weightTotal', label: '权重合计' }, { key: 'versionLabel', label: '版本' },
  { key: 'publishedLabel', label: '发布时间' },
]
const indicatorRows = computed<Array<Record<string, unknown>>>(() =>
  indicators.value.map((row) => ({ ...row, categoryLabel: row.category || '—', dataSourceLabel: row.dataSource || '—', activeLabel: row.active ? '启用' : '停用' })),
)
const indicatorColumns: DataTableColumn[] = [
  { key: 'code', label: '编码' }, { key: 'name', label: '名称' },
  { key: 'categoryLabel', label: '分类' }, { key: 'dataSourceLabel', label: '数据来源' },
  { key: 'activeLabel', label: '状态' },
]
const goalRows = computed<Array<Record<string, unknown>>>(() =>
  planGoals.value.map((row) => ({ ...row, employeeLabel: `员工 #${row.employmentId}`, weightLabel: `${row.weight}%` })),
)
const goalColumns: DataTableColumn[] = [
  { key: 'employeeLabel', label: '员工' }, { key: 'title', label: '目标' },
  { key: 'targetValue', label: '目标值' }, { key: 'weightLabel', label: '权重' },
  { key: 'status', label: '状态' },
]
const feedbackRowsForTable = computed<Array<Record<string, unknown>>>(() =>
  feedbackRows.value.map((row) => ({
    ...row,
    fromLabel: row.anonymous ? '匿名' : `员工 #${row.fromEmploymentId}`,
    toLabel: `员工 #${row.toEmploymentId}`,
    anonymousLabel: row.anonymous ? '是' : '否',
  })),
)
const feedbackColumns: DataTableColumn[] = [
  { key: 'fromLabel', label: '评价人' }, { key: 'toLabel', label: '被评价人' },
  { key: 'feedbackType', label: '类型' }, { key: 'rating', label: '评分' },
  { key: 'anonymousLabel', label: '匿名' },
]
const calibrationRowsForTable = computed<Array<Record<string, unknown>>>(() =>
  calibrationRows.value.map((row) => ({ ...row, reviewLabel: `#${row.reviewId}`, previousLabel: row.previousRating || '—', operatorLabel: `用户 #${row.calibratedBy}` })),
)
const calibrationColumns: DataTableColumn[] = [
  { key: 'reviewLabel', label: '评估' }, { key: 'previousLabel', label: '原等级' },
  { key: 'calibratedRating', label: '校准等级' }, { key: 'reason', label: '原因' },
  { key: 'operatorLabel', label: '操作人' },
]
const resultRowsForTable = computed<Array<Record<string, unknown>>>(() =>
  (resultPage.value?.items ?? []).map((row) => ({
    ...row,
    employeeLabel: `员工 #${row.employmentId}`,
    selfLabel: row.selfRating || '—',
    managerLabel: row.managerRating || '—',
    calibratedLabel: row.calibratedRating || '—',
    finalLabel: ratingLabel(row.finalRating),
    snapshotLabel: row.snapshot ? '发布快照' : '待发布',
  })),
)
const resultColumns: DataTableColumn[] = [
  { key: 'employeeLabel', label: '员工' }, { key: 'selfLabel', label: '自评' },
  { key: 'managerLabel', label: '主管评分' }, { key: 'calibratedLabel', label: '校准' },
  { key: 'finalLabel', label: '最终等级' }, { key: 'snapshotLabel', label: '快照' },
  { key: 'actions', label: '操作' },
]

const props = withDefaults(
  defineProps<{
    /** Used when this detail is embedded in the /performance workspace. */
    cycleIdOverride?: number | null
  }>(),
  { cycleIdOverride: null },
)

const route = useRoute()
const router = useRouter()
const session = useSessionStore()

const canRead = computed(() => session.can('performance.cycle.read'))
const canManage = computed(() => session.can('performance.review.manage'))
const canSubmit = computed(() => session.can('performance.review.submit'))
const canPublish = computed(() => session.can('performance.result.publish'))
const canCalibrate = computed(() => session.can('performance.calibration.manage'))
const canAppeal = computed(() => session.can('performance.appeal.manage'))
const canReport = computed(() => session.can('performance.report.read'))

type DetailTab = 'overview' | 'templates' | 'indicators' | 'goals' | 'reviews' | 'feedback' | 'calibration' | 'results' | 'appeals' | 'reports'

const TABS: ReadonlyArray<{ key: DetailTab; label: string }> = [
  { key: 'overview', label: '概览' },
  { key: 'templates', label: '模板' },
  { key: 'indicators', label: '指标库' },
  { key: 'goals', label: '目标计划' },
  { key: 'reviews', label: '考核评价' },
  { key: 'feedback', label: '360 反馈' },
  { key: 'calibration', label: '绩效校准' },
  { key: 'results', label: '结果发布' },
  { key: 'appeals', label: '申诉' },
  { key: 'reports', label: '报表' },
]

function normaliseTab(value: unknown): DetailTab {
  if (typeof value !== 'string') return 'overview'
  return TABS.some((tab) => tab.key === value) ? value as DetailTab : 'overview'
}

const activeTab = ref<DetailTab>(normaliseTab(route.query.tab))
const tabLoading = ref(false)
const tabForbidden = ref(false)
const tabError = ref('')
const templates = ref<PerformanceTemplateRow[]>([])
const indicators = ref<PerformanceIndicatorRow[]>([])
const planGoals = ref<PerformancePlanGoalRow[]>([])
const feedbackRows = ref<PerformanceFeedbackRow[]>([])
const calibrationRows = ref<PerformanceCalibrationRow[]>([])
const resultPage = ref<PerformanceResultPage | null>(null)
const reportRow = ref<PerformanceReportRow | null>(null)

const loading = ref(false)
const loaded = ref(false)
const errorMessage = ref('')
const errorRequestId = ref<string | null>(null)
const errorCodeValue = ref('')
const partialNote = ref('')

const cycle = ref<PerformanceCycleSummary | null>(null)
const reviews = ref<PerformanceReviewRow[]>([])
const reviewPageTotal = ref(0)

const drawerOpen = ref(false)
const reviewColumns: DataTableColumn[] = [
  { key: 'employee', label: '员工', minWidth: '110px' },
  { key: 'manager', label: '主管', minWidth: '110px' },
  { key: 'status', label: '状态', minWidth: '140px' },
  { key: 'goals', label: '目标', minWidth: '220px' },
  { key: 'weightTotal', label: '权重合计', minWidth: '140px', align: 'right' },
  { key: 'selfRating', label: '自评分数', width: '110px', align: 'right' },
  { key: 'managerRating', label: '主管分数', width: '110px', align: 'right' },
  { key: 'finalRating', label: '最终等级', minWidth: '130px' },
  { key: 'actions', label: '操作', minWidth: '190px' },
]

const selectedReview = ref<PerformanceReviewRow | null>(null)
const saving = ref(false)
const actionError = ref('')
const actionSuccess = ref('')

const publishConfirmOpen = ref(false)
const publishing = ref(false)
const calibrationOpen = ref(false)
const calibrationTarget = ref<PerformanceReviewRow | null>(null)
const calibrationRating = ref('')
const calibrationReason = ref('')
const calibrationError = ref('')
const appealTarget = ref<PerformanceReviewRow | null>(null)
const appealOpen = ref(false)
const appealReason = ref('')
const appealError = ref('')

const cycleId = computed(() => {
  if (props.cycleIdOverride !== null && Number.isSafeInteger(props.cycleIdOverride) && props.cycleIdOverride > 0) {
    return props.cycleIdOverride
  }

  const queryValue = route.query.cycle_id
  if (typeof queryValue === 'string' && /^\d+$/.test(queryValue)) {
    const parsedQuery = Number(queryValue)
    if (Number.isSafeInteger(parsedQuery) && parsedQuery > 0) return parsedQuery
  }

  const value = route.params.id
  if (typeof value !== 'string' || !/^\d+$/.test(value)) return null
  const parsed = Number(value)
  return Number.isSafeInteger(parsed) && parsed > 0 ? parsed : null
})

const readOnlyCycle = computed(() => (cycle.value === null ? false : isCycleReadOnly(cycle.value.status)))
const cycleStage = computed(() => (cycle.value === null ? '' : performanceCycleStage(cycle.value)))
const showEmpty = computed(
  () => loaded.value && !loading.value && errorMessage.value === '' && reviews.value.length === 0,
)

const pendingCount = computed(
  () => reviews.value.filter((review) => !isDecidedReview(review.status)).length,
)
const publishDisabledReason = computed(() => {
  if (readOnlyCycle.value) return '周期已发布，不能重复发布。'
  if (!canPublish.value) return '缺少能力 performance.result.publish。'
  if (reviews.value.length === 0) return '周期没有评估，无法发布。'
  if (reviews.value.some((review) => review.status.toUpperCase() === 'DRAFT')) {
    return '仍有草稿评估未提交，需全部提交后才能发布。'
  }
  return ''
})

function captureError(reason: unknown): void {
  errorCodeValue.value = errorCode(reason)
  if (reason instanceof ApiError) {
    errorRequestId.value = reason.requestId
    errorMessage.value =
      reason.code === 'FORBIDDEN'
        ? '无权读取该绩效周期或其评估。当前账号必须持有对应能力，并且评估在授权范围内。'
        : reason.code === 'NOT_FOUND'
          ? '未找到该绩效周期，可能已被移出你的数据范围或链接有误。'
          : reason.message || errorCopy(reason)
  } else {
    errorRequestId.value = null
    errorMessage.value = reason instanceof Error ? reason.message : '绩效周期详情暂时无法加载。'
  }
}

async function load(): Promise<void> {
  if (!canRead.value) {
    loaded.value = true
    return
  }
  const id = cycleId.value
  if (id === null) {
    loaded.value = true
    errorCodeValue.value = 'NOT_FOUND'
    errorMessage.value = '绩效周期编号无效。'
    return
  }

  loading.value = true
  loaded.value = false
  errorMessage.value = ''
  errorRequestId.value = null
  errorCodeValue.value = ''
  partialNote.value = ''
  actionError.value = ''
  actionSuccess.value = ''
  reviews.value = []
  reviewPageTotal.value = 0

  try {
    // The router registers a stable /performance/cycles/:id deep link but the
    // backend still exposes cycle reads through the verified list projection.
    // Match the requested cycle by walking that real paginated read.
    let requested: PerformanceCycleSummary | null = null
    let pageNumber = 1
    let pages = 1
    do {
      const result = await listPerformanceCycles({}, pageNumber, 100)
      requested ??= result.items.find((item) => item.id === id) ?? null
      pages = Math.max(1, Math.ceil(result.total / Math.max(1, result.pageSize)))
      pageNumber += 1
    } while (requested === null && pageNumber <= pages && pageNumber <= 50)

    cycle.value = requested
    if (requested === null) {
      errorCodeValue.value = 'NOT_FOUND'
      errorMessage.value = '未找到该绩效周期，可能已被移出你的数据范围或链接有误。'
      return
    }

    const canReadReviews = canManage.value || (canAppeal.value && isCycleReadOnly(requested.status))
    if (!canReadReviews) {
      return
    }

    const result = await listPerformanceReviews({ cycleId: id }, 1, 100)
    reviews.value = result.items
    reviewPageTotal.value = result.total
    if (result.partial) {
      partialNote.value = '服务端标记本次评估投影不完整，页面只展示已返回的行。'
    }
  } catch (reason) {
    cycle.value = null
    reviews.value = []
    captureError(reason)
  } finally {
    loading.value = false
    loaded.value = true
  }
}

function openScoreDrawer(review: PerformanceReviewRow): void {
  if (!canManage.value) return
  // Decided reviews still open in the drawer, but every control is read-only;
  // this is how an allowed reviewer inspects the goals and stored scores.
  if (!readOnlyCycle.value && canScoreReview(review.status)) {
    selectedReview.value = review
  } else {
    selectedReview.value = review
  }
  drawerOpen.value = true
  actionError.value = ''
  actionSuccess.value = ''
}

async function saveReview(payload: {
  selfRating: string
  selfAssessment: string
  managerRating: string
  managerAssessment: string
  goals: import('../api').PerformanceGoalDraft[]
}): Promise<void> {
  const current = selectedReview.value
  if (current === null) return
  saving.value = true
  actionError.value = ''
  actionSuccess.value = ''
  try {
    await savePerformanceReview({
      cycleId: current.cycleId,
      employmentId: current.employmentId,
      managerEmploymentId: current.managerEmploymentId,
      selfRating: payload.selfRating,
      selfAssessment: payload.selfAssessment,
      managerRating: payload.managerRating,
      managerAssessment: payload.managerAssessment,
      goals: payload.goals,
    })
    drawerOpen.value = false
    actionSuccess.value = `员工 #${current.employmentId} 的评分与目标已保存。`
    await load()
  } catch (reason) {
    actionError.value = performanceActionErrorCopy(reason)
  } finally {
    saving.value = false
  }
}

async function submitReview(payload: { managerRating: string; managerAssessment: string }): Promise<void> {
  const current = selectedReview.value
  if (current === null) return
  saving.value = true
  actionError.value = ''
  actionSuccess.value = ''
  try {
    // The saved row is authoritative, so submit the server id and only the
    // real submit contract fields.
    await submitPerformanceReview(current.id, {
      managerRating: payload.managerRating,
      managerAssessment: payload.managerAssessment,
    })
    drawerOpen.value = false
    actionSuccess.value = `员工 #${current.employmentId} 的评估已提交主管复核。`
    await load()
  } catch (reason) {
    actionError.value = performanceActionErrorCopy(reason)
  } finally {
    saving.value = false
  }
}

function requestPublish(): void {
  if (publishDisabledReason.value !== '') return
  publishConfirmOpen.value = true
}

async function confirmPublish(): Promise<void> {
  const current = cycle.value
  if (current === null) return
  publishConfirmOpen.value = false
  publishing.value = true
  actionError.value = ''
  actionSuccess.value = ''
  try {
    await publishPerformanceCycle(current.id)
    actionSuccess.value = '绩效周期已发布，结果快照已生成，全部评估转为只读。'
    await load()
    if (activeTab.value === 'results') await loadTab('results')
  } catch (reason) {
    actionError.value = performanceActionErrorCopy(reason)
  } finally {
    publishing.value = false
  }
}

function openCalibration(review: PerformanceReviewRow): void {
  calibrationTarget.value = review
  calibrationRating.value = review.calibratedRating
  calibrationReason.value = ''
  calibrationError.value = ''
  calibrationOpen.value = true
}

async function confirmCalibration(): Promise<void> {
  const target = calibrationTarget.value
  if (target === null) return
  if (calibrationRating.value.trim() === '' || calibrationReason.value.trim().length < 3) {
    calibrationError.value = '请填写校准等级和至少 3 个字符的校准原因。'
    return
  }
  calibrationError.value = ''
  saving.value = true
  try {
    await calibratePerformanceReview(target.id, calibrationRating.value.trim(), calibrationReason.value.trim())
    calibrationOpen.value = false
    actionSuccess.value = `员工 #${target.employmentId} 的校准等级已记录；原始自评与主管分数不变。`
    await load()
  } catch (reason) {
    calibrationError.value = performanceActionErrorCopy(reason)
  } finally {
    saving.value = false
  }
}

function openAppeal(review: PerformanceReviewRow): void {
  appealTarget.value = review
  appealReason.value = ''
  appealError.value = ''
  appealOpen.value = true
}

async function confirmAppeal(): Promise<void> {
  const target = appealTarget.value
  if (target === null) return
  if (appealReason.value.trim().length < 10) {
    appealError.value = '申诉原因至少需要 10 个字符。'
    return
  }
  appealError.value = ''
  saving.value = true
  try {
    await appealPerformanceReview(target.id, appealReason.value.trim())
    appealOpen.value = false
    actionSuccess.value = `员工 #${target.employmentId} 的绩效申诉已提交为审批案例。`
    await load()
  } catch (reason) {
    appealError.value = performanceActionErrorCopy(reason)
  } finally {
    saving.value = false
  }
}

function backToWorkspace(): void {
  void router.push('/performance')
}

function selectTab(tab: DetailTab): void {
  activeTab.value = tab
  void router.replace({ query: { ...route.query, tab } })
  void loadTab(tab)
}

async function loadTab(tab: DetailTab): Promise<void> {
  tabError.value = ''
  tabForbidden.value = false

  if (tab === 'overview' || tab === 'reviews' || tab === 'appeals') {
    return
  }

  const id = cycleId.value
  if (id === null) return

  tabLoading.value = true
  try {
    if (tab === 'templates') {
      templates.value = await listPerformanceTemplates()
    } else if (tab === 'indicators') {
      indicators.value = await listPerformanceIndicators()
    } else if (tab === 'goals') {
      planGoals.value = await listPerformanceGoals(id)
    } else if (tab === 'feedback') {
      feedbackRows.value = await listPerformanceFeedback(id)
    } else if (tab === 'calibration') {
      calibrationRows.value = await listPerformanceCalibrations(id)
    } else if (tab === 'results') {
      resultPage.value = await listPerformanceResults(id)
    } else if (tab === 'reports') {
      if (!canReport.value) {
        reportRow.value = null
      } else {
        reportRow.value = await listPerformanceReports(id)
      }
    }
  } catch (reason) {
    if (reason instanceof ApiError && reason.code === 'FORBIDDEN') {
      tabForbidden.value = true
    } else {
      tabError.value = reason instanceof Error ? reason.message : '绩效详情暂时无法加载。'
    }
  } finally {
    tabLoading.value = false
  }
}

watch(cycleId, () => {
  void load()
  void loadTab(activeTab.value)
})

watch(
  () => route.query.tab,
  (value) => {
    const next = normaliseTab(value)
    if (next === activeTab.value) return
    activeTab.value = next
    void loadTab(next)
  },
)

onMounted(() => {
  void load()
  void loadTab(activeTab.value)
})
</script>

<template>
  <div class="performance-detail" data-page="performance-cycle-detail">
    <header class="performance-detail__header">
      <div>
        <button type="button" class="performance-detail__back" @click="backToWorkspace">← 返回绩效考核工作台</button>
        <p class="performance-detail__breadcrumb">人才 · 绩效周期详情</p>
        <h1 class="performance-detail__title">
          <template v-if="cycle !== null">{{ cycle.name }}</template>
          <template v-else>绩效周期详情</template>
        </h1>
        <p v-if="cycle !== null" class="performance-detail__description">
          {{ performancePeriodLabel(cycle.periodFrom, cycle.periodTo) }} · 周期 #{{ cycle.id }} ·
          {{ cycle.participantCount }} 名参与者
        </p>
      </div>
      <div v-if="cycle !== null" class="performance-detail__header-actions">
        <span class="performance-detail__stage">{{ cycleStage }}</span>
        <button
          v-if="activeTab === 'results' || activeTab === 'overview'"
          type="button"
          class="performance-detail__primary"
          :disabled="publishDisabledReason !== '' || publishing"
          :title="publishDisabledReason"
          data-testid="performance-publish"
          @click="requestPublish"
        >
          发布结果
        </button>
      </div>
    </header>

    <section v-if="!canRead" class="performance-detail__state" role="alert" data-testid="performance-detail-forbidden">
      <h2>无权读取绩效周期</h2>
      <p>当前账号缺少 performance.cycle.read 能力，页面不会发起任何周期或评估读取请求。</p>
    </section>

    <ErrorState
      v-else-if="errorMessage !== ''"
      title="绩效周期详情暂时无法加载"
      :description="errorMessage"
      :code="errorCodeValue"
      :request-id="errorRequestId"
      @retry="load"
    />

    <LoadingState v-else-if="loading" :rows="7" label="正在加载绩效周期详情" />

    <template v-else-if="cycle !== null">
      <p v-if="readOnlyCycle" class="performance-detail__readonly" role="status" data-testid="performance-readonly">
        该周期已发布，全部评估为只读：评分、保存、提交与再次发布均已禁用。最终等级仅展示服务端结果。
      </p>

      <nav class="performance-detail__tabs" role="tablist" aria-label="绩效周期详情" data-testid="performance-tabs">
        <button
          v-for="tab in TABS"
          :key="tab.key"
          type="button"
          role="tab"
          class="performance-detail__tab"
          :aria-selected="activeTab === tab.key"
          :data-active="activeTab === tab.key"
          :data-testid="`performance-tab-${tab.key}`"
          @click="selectTab(tab.key)"
        >
          {{ tab.label }}
        </button>
      </nav>

      <p v-if="tabError !== ''" class="performance-detail__error" role="alert" data-testid="performance-tab-error">
        {{ tabError }}
        <button type="button" class="performance-detail__link" @click="loadTab(activeTab)">重试</button>
      </p>
      <p v-else-if="tabForbidden" class="performance-detail__warning" role="status" data-testid="performance-tab-forbidden">
        当前账号没有读取该详情分区的权限；页面不会请求或展示该分区数据。
      </p>
      <LoadingState v-else-if="tabLoading" :rows="4" label="正在加载绩效详情分区" />

      <section v-else-if="activeTab === 'templates'" class="panel" data-testid="performance-tab-panel-templates">
        <header class="panel__header"><h2 class="panel__title">考核模板</h2></header>
        <EmptyState v-if="templates.length === 0" title="还没有考核模板" description="发布带版本号的考核模板后，这里按权重合计展示。" />
        <DataTable v-else :columns="templateColumns" :rows="templateRows" :total="templateRows.length" :page="1" :page-size="Math.max(1, templateRows.length)" row-key="id" data-testid="performance-template-table" empty-title="还没有考核模板" />
      </section>

      <section v-else-if="activeTab === 'indicators'" class="panel" data-testid="performance-tab-panel-indicators">
        <header class="panel__header"><h2 class="panel__title">指标库</h2></header>
        <EmptyState v-if="indicators.length === 0" title="还没有绩效指标" description="创建可复用指标后，这里显示编码、分类和数据来源。" />
        <DataTable v-else :columns="indicatorColumns" :rows="indicatorRows" :total="indicatorRows.length" :page="1" :page-size="Math.max(1, indicatorRows.length)" row-key="id" data-testid="performance-indicator-table" empty-title="还没有绩效指标" />
      </section>

      <section v-else-if="activeTab === 'goals'" class="panel" data-testid="performance-tab-panel-goals">
        <header class="panel__header"><h2 class="panel__title">目标计划</h2></header>
        <EmptyState v-if="planGoals.length === 0" title="该周期还没有目标" description="为参与者设定目标、目标值和权重后，这里显示目标台账。" />
        <DataTable v-else :columns="goalColumns" :rows="goalRows" :total="goalRows.length" :page="1" :page-size="Math.max(1, goalRows.length)" row-key="id" data-testid="performance-goal-table" empty-title="该周期还没有目标" />
      </section>

      <section v-else-if="activeTab === 'feedback'" class="panel" data-testid="performance-tab-panel-feedback">
        <header class="panel__header"><h2 class="panel__title">360 反馈</h2></header>
        <EmptyState v-if="feedbackRows.length === 0" title="还没有 360 反馈" description="记录同级、主管与跨团队反馈后，这里显示反馈类型与匿名标记。" />
        <DataTable v-else :columns="feedbackColumns" :rows="feedbackRowsForTable" :total="feedbackRowsForTable.length" :page="1" :page-size="Math.max(1, feedbackRowsForTable.length)" row-key="id" data-testid="performance-feedback-table" empty-title="还没有 360 反馈" />
      </section>

      <section v-else-if="activeTab === 'calibration'" class="panel" data-testid="performance-tab-panel-calibration">
        <header class="panel__header"><h2 class="panel__title">绩效校准</h2></header>
        <EmptyState v-if="calibrationRows.length === 0" title="还没有校准记录" description="校准只追加证据，不覆盖自评或主管原始分数。" />
        <DataTable v-else :columns="calibrationColumns" :rows="calibrationRowsForTable" :total="calibrationRowsForTable.length" :page="1" :page-size="Math.max(1, calibrationRowsForTable.length)" row-key="id" data-testid="performance-calibration-table" empty-title="还没有校准记录" />
      </section>

      <section v-else-if="activeTab === 'results'" class="panel" data-testid="performance-tab-panel-results">
        <header class="panel__header"><h2 class="panel__title">结果发布</h2></header>
        <p v-if="resultPage === null" class="performance-detail__warning" data-testid="performance-result-unloaded">
          结果投影尚未加载；页面不会用周期状态推断发布状态。
        </p>
        <p v-else-if="resultPage.snapshot" class="performance-detail__success" data-testid="performance-result-published">
          已发布 · 结果读取自发布快照，评分与校准均冻结。
        </p>
        <p v-else class="performance-detail__warning" data-testid="performance-result-pending">
          尚未发布 · 当前展示的是实时评估，不是最终结果。
        </p>
        <EmptyState v-if="resultPage === null" title="正在读取结果投影" description="页面不会根据周期状态推测最终分数。" />
        <EmptyState v-else-if="resultPage.items.length === 0" title="还没有结果行" description="周期产生评估行后，这里会展示实时或发布后的结果。" />
        <DataTable v-else :columns="resultColumns" :rows="resultRowsForTable" :total="resultRowsForTable.length" :page="1" :page-size="Math.max(1, resultRowsForTable.length)" row-key="reviewId" :row-test-id="(row) => `performance-result-${String(row.reviewId)}`" data-testid="performance-result-table" empty-title="还没有结果行">
          <template #cell-actions="{ row }">
            <button
              v-if="canAppeal && row.snapshot"
              type="button"
              class="performance-detail__link"
              :data-testid="`performance-result-appeal-${row.reviewId}`"
              @click="openAppeal(row as unknown as PerformanceReviewRow)"
            >
              申诉
            </button>
          </template>
        </DataTable>
      </section>

      <section v-else-if="activeTab === 'reports'" class="panel" data-testid="performance-tab-panel-reports">
        <header class="panel__header"><h2 class="panel__title">绩效报表</h2></header>
        <p v-if="!canReport" class="performance-detail__warning" role="status" data-testid="performance-report-forbidden">
          当前账号缺少 performance.report.read 能力；页面不会请求或展示报表。
        </p>
        <EmptyState v-else-if="reportRow === null" title="还没有报表数据" description="周期与评估产生后，这里显示已发布完成度。" />
        <dl v-else-if="canReport" class="performance-detail__facts">
          <div><dt>评估总数</dt><dd>{{ reportRow.reviewCount }}</dd></div>
          <div><dt>已提交</dt><dd>{{ reportRow.submittedReviews }}</dd></div>
          <div><dt>草稿</dt><dd>{{ reportRow.draftReviews }}</dd></div>
          <div><dt>已发布快照</dt><dd>{{ reportRow.publishedReviews }}</dd></div>
          <div><dt>发布完成率</dt><dd>{{ reportRow.completionRate }}%</dd></div>
        </dl>
      </section>

      <p v-if="partialNote !== ''" class="performance-detail__warning" role="status" data-testid="performance-partial">
        部分数据：{{ partialNote }}
      </p>

      <p v-if="actionSuccess !== ''" class="performance-detail__success" role="status" data-testid="performance-action-success">
        {{ actionSuccess }}
      </p>
      <p v-if="actionError !== ''" class="performance-detail__error" role="alert" data-testid="performance-action-error">
        {{ actionError }}
      </p>

      <section v-if="activeTab === 'overview'" class="performance-detail__facts" aria-label="绩效周期基本信息">
        <div>
          <dt>周期名称</dt>
          <dd>{{ cycle.name }}</dd>
        </div>
        <div>
          <dt>考核期间</dt>
          <dd>{{ performancePeriodLabel(cycle.periodFrom, cycle.periodTo) }}</dd>
        </div>
        <div>
          <dt>参与人数</dt>
          <dd>{{ cycle.participantCount }} 人</dd>
        </div>
        <div>
          <dt>已提交</dt>
          <dd>{{ cycle.submittedCount }} 人</dd>
        </div>
        <div>
          <dt>待提交</dt>
          <dd>{{ pendingCount }} 人</dd>
        </div>
        <div>
          <dt>进度</dt>
          <dd>{{ cycle.progress }}%</dd>
        </div>
      </section>

      <section
        v-if="activeTab === 'reviews' && !canManage"
        class="performance-detail__state"
        role="alert"
        data-testid="performance-review-forbidden"
      >
        <h2>无权查看评估明细</h2>
        <p>当前账号缺少 performance.review.manage 能力，只展示周期汇总，不请求员工评估行。</p>
      </section>

      <section
        v-if="activeTab === 'appeals' && (!canAppeal || !readOnlyCycle)"
        class="performance-detail__state"
        role="status"
        data-testid="performance-appeals-unavailable"
      >
        <h2>{{ readOnlyCycle ? '无权发起绩效申诉' : '申诉需要已发布结果' }}</h2>
        <p>{{ readOnlyCycle ? '当前账号缺少 performance.appeal.manage 能力。' : '周期发布并生成结果快照后，本人或授权 HR 才能发起申诉。' }}</p>
      </section>

      <template v-if="(activeTab === 'reviews' && canManage) || (activeTab === 'appeals' && canAppeal && readOnlyCycle)">
        <section class="panel" :aria-label="activeTab === 'appeals' ? '绩效申诉' : '评估参与者'">
          <header class="panel__header">
            <div>
              <h2 class="panel__title">{{ activeTab === 'appeals' ? '绩效申诉' : '参与者评估' }}</h2>
              <p class="panel__subtitle">
                {{ activeTab === 'appeals'
                  ? '仅已发布结果可申诉；申诉创建审批 Case，评分保持冻结。'
                  : `共 ${reviewPageTotal} 条评估 · 每条评估显示目标与评分状态` }}
              </p>
            </div>
          </header>

          <EmptyState
            v-if="showEmpty"
            title="该周期还没有评估"
            description="主管或人力资源为参与者创建评估后，这里会显示目标和评分状态。"
          />

          <div v-else class="panel__body panel__body--flush">
            <div class="performance-detail__wrap">
              <DataTable
                :columns="reviewColumns"
                :rows="reviews as unknown as Array<Record<string, unknown>>"
                :total="reviewPageTotal"
                :page="1"
                :page-size="Math.max(1, reviews.length)"
                row-key="id"
                :row-test-id="(row) => `performance-review-${String(row.id)}`"
                data-testid="performance-review-table"
                empty-title="该周期还没有评估"
              >
                <template #cell-employee="{ row }">员工 #{{ row.employmentId }}</template>
                <template #cell-manager="{ row }">
                  {{ row.managerEmploymentId === null ? '—' : `员工 #${row.managerEmploymentId}` }}
                </template>
                <template #cell-status="{ row }">
                  <span class="performance-review-status" :data-decided="isDecidedReview(String(row.status))">
                    {{ isDecidedReview(String(row.status)) ? `${reviewStatusLabel(String(row.status))}（只读）` : reviewStatusLabel(String(row.status)) }}
                  </span>
                </template>
                <template #cell-goals="{ row }">
                  <ul v-if="(row.goals as PerformanceReviewRow['goals']).length > 0" class="performance-detail__goals">
                    <li v-for="goal in row.goals as PerformanceReviewRow['goals']" :key="goal.title">
                      {{ goal.title }} · {{ goal.weight }}%
                    </li>
                  </ul>
                  <span v-else>—</span>
                </template>
                <template #cell-weightTotal="{ row }">
                  {{ (row.goals as PerformanceReviewRow['goals']).reduce((sum, goal) => sum + goal.weight, 0) }}%
                  <template v-if="(row.goals as PerformanceReviewRow['goals']).length > 0 && (row.goals as PerformanceReviewRow['goals']).reduce((sum, goal) => sum + goal.weight, 0) === 100">
                    · 100% 权重已分配
                  </template>
                </template>
                <template #cell-selfRating="{ row }">{{ row.selfRating === '' ? '—' : `${row.selfRating} 分` }}</template>
                <template #cell-managerRating="{ row }">{{ row.managerRating === '' ? '—' : `${row.managerRating} 分` }}</template>
                <template #cell-finalRating="{ row }">
                  <span data-testid="performance-row-final-rating">
                    {{ row.finalRating === '' ? '待服务端计算' : ratingLabel(String(row.finalRating)) }}
                  </span>
                </template>
                <template #cell-actions="{ row }">
                  <button
                    v-if="activeTab === 'reviews'"
                    type="button"
                    class="performance-detail__link"
                    :disabled="!canManage"
                    :data-testid="`performance-score-${String(row.id)}`"
                    @click="openScoreDrawer(row as unknown as PerformanceReviewRow)"
                  >
                    {{ !readOnlyCycle && canScoreReview(String(row.status)) ? '评分' : '查看' }}
                  </button>
                  <button
                    v-if="activeTab === 'reviews' && canCalibrate && !readOnlyCycle && isDecidedReview(String(row.status))"
                    type="button"
                    class="performance-detail__link"
                    :data-testid="`performance-calibrate-${String(row.id)}`"
                    @click="openCalibration(row as unknown as PerformanceReviewRow)"
                  >
                    校准
                  </button>
                  <button
                    v-if="canAppeal && readOnlyCycle && row.status === 'SUBMITTED'"
                    type="button"
                    class="performance-detail__link"
                    :data-testid="`performance-appeal-${String(row.id)}`"
                    @click="openAppeal(row as unknown as PerformanceReviewRow)"
                  >
                    申诉
                  </button>
                </template>
              </DataTable>
            </div>
          </div>
        </section>
      </template>

      <el-dialog
        v-model="calibrationOpen"
        title="校准绩效等级"
        width="480px"
        :close-on-click-modal="false"
        data-testid="performance-calibration-dialog"
      >
        <p>校准只写入校准等级和原因，不覆盖自评或主管原始分数。</p>
        <label class="performance-dialog__field">
          <span>校准等级</span>
          <el-input v-model="calibrationRating" type="text" maxlength="16" placeholder="例如 MEETS" data-testid="performance-calibration-rating" />
        </label>
        <label class="performance-dialog__field">
          <span>校准原因</span>
          <el-input v-model="calibrationReason" type="textarea" :rows="3" maxlength="4000" data-testid="performance-calibration-reason" />
        </label>
        <p v-if="calibrationError !== ''" class="performance-dialog__error" role="alert">{{ calibrationError }}</p>
        <template #footer>
          <el-button data-testid="performance-calibration-cancel" @click="calibrationOpen = false">取消</el-button>
          <el-button type="primary" :loading="saving" data-testid="performance-calibration-submit" @click="confirmCalibration">
            确认校准
          </el-button>
        </template>
      </el-dialog>

      <el-dialog
        v-model="appealOpen"
        title="发起绩效申诉"
        width="480px"
        :close-on-click-modal="false"
        data-testid="performance-appeal-dialog"
      >
        <p>申诉将创建审批案例，原评估保持只读。</p>
        <label class="performance-dialog__field">
          <span>申诉原因</span>
          <el-input v-model="appealReason" type="textarea" :rows="3" maxlength="4000" data-testid="performance-appeal-reason" />
        </label>
        <p v-if="appealError !== ''" class="performance-dialog__error" role="alert">{{ appealError }}</p>
        <template #footer>
          <el-button data-testid="performance-appeal-cancel" @click="appealOpen = false">取消</el-button>
          <el-button type="primary" :loading="saving" data-testid="performance-appeal-submit" @click="confirmAppeal">
            提交申诉
          </el-button>
        </template>
      </el-dialog>

      <el-dialog
        v-model="publishConfirmOpen"
        title="发布绩效结果"
        width="480px"
        :close-on-click-modal="false"
        data-testid="performance-publish-confirm"
      >
        <p>将发布 {{ cycle.name }} 的绩效结果，写入不可变结果快照，并把全部评估转为只读。</p>
        <p>当前已提交 {{ cycle.submittedCount }} / {{ cycle.participantCount }} 人，待提交 {{ pendingCount }} 人。</p>
        <template #footer>
          <el-button data-testid="performance-publish-cancel" @click="publishConfirmOpen = false">取消</el-button>
          <el-button type="primary" :loading="publishing" data-testid="performance-publish-confirm-submit" @click="confirmPublish">
            确认发布
          </el-button>
        </template>
      </el-dialog>

      <PerformanceScoreDrawer
        v-model="drawerOpen"
        :cycle-name="cycle.name"
        :review="selectedReview"
        :read-only="readOnlyCycle || (selectedReview !== null && isDecidedReview(selectedReview.status))"
        :can-manage="canManage"
        :can-submit="canSubmit"
        :saving="saving"
        @save="saveReview"
        @submit="submitReview"
      />
    </template>
  </div>
</template>

<style scoped>
.performance-detail__tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  padding: 4px;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  background: #f8fafc;
}

.performance-detail__tab {
  padding: 6px 12px;
  border: 0;
  border-radius: 7px;
  background: transparent;
  color: #475569;
  font-size: 12px;
  cursor: pointer;
}

.performance-detail__tab[data-active='true'] {
  background: #fff;
  color: #1d4ed8;
  font-weight: 600;
  box-shadow: 0 1px 2px rgb(15 23 42 / 8%);
}

.performance-detail__table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12.5px;
}

.performance-detail__table th,
.performance-detail__table td {
  padding: 8px 10px;
  border-bottom: 1px solid #e2e8f0;
  text-align: left;
}

.performance-detail__table thead th {
  color: #64748b;
  font-size: 11px;
  font-weight: 600;
}

.performance-detail {
  display: flex;
  flex-direction: column;
  gap: 16px;
  padding: 24px;
}

.performance-detail__back {
  padding: 0;
  border: 0;
  background: none;
  color: #1d4ed8;
  font-size: 12px;
  cursor: pointer;
}

.performance-detail__breadcrumb {
  margin: 6px 0 0;
  color: #64748b;
  font-size: 11px;
  letter-spacing: 0.08em;
}

.performance-detail__title {
  margin: 4px 0 0;
  font-size: 22px;
}

.performance-detail__description {
  margin: 6px 0 0;
  color: #475569;
  font-size: 12.5px;
}

.performance-detail__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
}

.performance-detail__header-actions {
  display: flex;
  align-items: center;
  gap: 10px;
}

.performance-detail__stage {
  padding: 3px 10px;
  border-radius: 999px;
  background: #eff6ff;
  color: #1d4ed8;
  font-size: 11.5px;
  font-weight: 600;
}

.performance-detail__primary {
  padding: 7px 12px;
  border: 1px solid #1d4ed8;
  border-radius: 8px;
  background: #1d4ed8;
  color: #fff;
  font-size: 12px;
  cursor: pointer;
}

.performance-detail__primary:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.performance-detail__state {
  padding: 18px;
  border: 1px solid #fecaca;
  border-radius: 10px;
  background: #fef2f2;
  color: #991b1b;
}

.performance-detail__state h2 {
  margin: 0 0 6px;
  font-size: 15px;
}

.performance-detail__state p {
  margin: 0;
  font-size: 12px;
}

.performance-detail__readonly,
.performance-detail__warning {
  margin: 0;
  padding: 10px 12px;
  border: 1px solid #fde68a;
  border-radius: 8px;
  background: #fffbeb;
  color: #92400e;
  font-size: 12px;
}

.performance-detail__warning {
  border-color: #bfdbfe;
  background: #eff6ff;
  color: #1e40af;
}

.performance-detail__success {
  margin: 0;
  padding: 9px 12px;
  border: 1px solid #a7f3d0;
  border-radius: 8px;
  background: #ecfdf5;
  color: #047857;
  font-size: 12px;
}

.performance-detail__error {
  margin: 0;
  padding: 9px 12px;
  border: 1px solid #fecaca;
  border-radius: 8px;
  background: #fef2f2;
  color: #b91c1c;
  font-size: 12px;
}

.performance-detail__facts {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 10px;
  padding: 14px;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  background: #fff;
}

.performance-detail__facts dt {
  color: #64748b;
  font-size: 11px;
}

.performance-detail__facts dd {
  margin: 3px 0 0;
  font-size: 13px;
  font-weight: 600;
}

.performance-detail__wrap {
  overflow-x: auto;
}

.performance-detail__table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12.5px;
}

.performance-detail__table th,
.performance-detail__table td {
  padding: 9px 12px;
  border-bottom: 1px solid #e2e8f0;
  text-align: left;
  vertical-align: top;
}

.performance-detail__table thead th {
  color: #64748b;
  font-size: 11px;
  font-weight: 600;
  white-space: nowrap;
}

.performance-detail__table .is-numeric {
  text-align: right;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

.performance-detail__goals {
  margin: 0;
  padding: 0;
  list-style: none;
  color: #475569;
}

.performance-review-status {
  display: inline-flex;
  padding: 2px 9px;
  border-radius: 999px;
  background: #eff6ff;
  color: #1d4ed8;
  font-size: 11px;
  font-weight: 600;
  white-space: nowrap;
}

.performance-review-status[data-decided='true'] {
  background: #f1f5f9;
  color: #475569;
}

.performance-detail__link {
  border: 0;
  background: none;
  color: #1d4ed8;
  font-size: 12px;
  cursor: pointer;
}

.performance-detail__link:disabled {
  color: #94a3b8;
  cursor: not-allowed;
}

.performance-detail__readonly-label {
  color: #94a3b8;
  font-size: 11.5px;
}

.performance-dialog__field {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin-top: 10px;
  color: #475569;
  font-size: 12px;
}

.performance-dialog__field input,
.performance-dialog__field textarea {
  padding: 7px 9px;
  border: 1px solid #cbd5e1;
  border-radius: 7px;
  font: inherit;
}

.performance-dialog__error {
  margin: 8px 0 0;
  color: #b91c1c;
  font-size: 11.5px;
}
</style>
