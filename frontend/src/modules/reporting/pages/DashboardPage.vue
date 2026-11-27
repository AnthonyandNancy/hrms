<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useSessionStore } from '@/stores/session'
import {
  getDashboard,
  getMetricDrilldown,
  formatMetric,
  metricAvailabilityLabel,
  metricLabel,
  metricReasonText,
  statusText,
  type DashboardSnapshot,
  type MetricCardMetric,
  type MetricDrilldown,
} from '../api'
import { errorCopy, ApiError } from '@/api/errors'
import { formatBusinessDate, formatDateTimeInTimezone, todayBusinessDate } from '@/utils/format'
import { requestIdFromError } from '@/utils/renderErrors'
import DataTable, { type DataTableColumn } from '@/components/DataTable.vue'
import LoadingState from '@/components/LoadingState.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import MetricCard from '../components/MetricCard.vue'
import DashboardSections from '../components/DashboardSections.vue'

const drilldownColumns = computed<DataTableColumn[]>(() =>
  (drilldown.value?.columns ?? []).map((column) => ({ key: column.key, label: column.label })),
)

const drilldownRows = computed<Array<Record<string, unknown>>>(() =>
  (drilldown.value?.rows ?? []).map((row, index) => {
    const output: Record<string, unknown> = { id: row.id ?? index }
    for (const column of drilldown.value?.columns ?? []) {
      output[column.key] = drillValue(row, column.key)
    }
    return output
  }),
)

const session = useSessionStore()
const router = useRouter()
const asOf = ref(todayBusinessDate())
const loading = ref(false)
const loadError = ref<unknown>(null)
const snapshot = ref<DashboardSnapshot | null>(null)

const drillLoading = ref(false)
const drillError = ref<unknown>(null)
const drilldown = ref<MetricDrilldown | null>(null)
const selectedMetric = ref<MetricCardMetric | null>(null)

const canRead = computed(() => session.can('reporting.dashboard.read'))
const errorMessage = computed(() => (loadError.value === null ? '' : errorCopy(loadError.value)))
const errorRequestId = computed(() => (loadError.value === null ? null : requestIdFromError(loadError.value)))
const drillErrorMessage = computed(() => (drillError.value === null ? '' : errorCopy(drillError.value)))
const metrics = computed(() => snapshot.value?.metrics ?? [])
const availableMetrics = computed(() => metrics.value.filter((metric) => metric.status === 'AVAILABLE'))
const unavailableMetrics = computed(() => metrics.value.filter((metric) => metric.status !== 'AVAILABLE'))
const empty = computed(() => snapshot.value !== null && metrics.value.length === 0)
const freshnessText = computed(() => {
  const value = snapshot.value?.freshnessState ?? 'LIVE'
  if (value === 'MATERIALIZED') return '缓存快照'
  if (value === 'STALE') return '过期快照'
  if (value === 'LIVE') return '实时快照'
  return '快照状态未知'
})
const drillTotalText = computed(() => {
  if (drilldown.value === null) return ''
  const formatted = formatMetric({
    code: drilldown.value.metric,
    unit: drilldown.value.unit,
    unitLabel: drilldown.value.unitLabel,
    value: drilldown.value.total,
    status: drilldown.value.status,
  })
  return formatted.unit === '' ? formatted.value : `${formatted.value}${formatted.unit}`
})

async function load(): Promise<void> {
  if (!canRead.value) return
  loading.value = true
  loadError.value = null
  drilldown.value = null
  selectedMetric.value = null
  try {
    snapshot.value = await getDashboard(asOf.value)
  } catch (reason) {
    snapshot.value = null
    loadError.value = reason
  } finally {
    loading.value = false
  }
}

function metricDrilldownRoute(metric: MetricCardMetric): string | null {
  const route = metric.drilldown?.route
  if (route === undefined || route === '') return null
  if (route === '/recruitment/candidates') return session.can('recruitment.candidate.read') ? route : null
  if (route === '/payroll') return session.can('payroll.run.read') ? route : null
  if (route === '/workflow') return session.can('workflow.case.read') ? route : null
  if (route === '/leave') return session.can('time.leave.read') ? route : null
  if (route === '/hr/contracts') return session.can('people.contract.read') ? route : null
  if (route === '/hr/employees') return session.can('people.employee.read') ? route : null
  return route
}

async function drill(metric: MetricCardMetric): Promise<void> {
  if (!canRead.value) return
  selectedMetric.value = metric
  drillError.value = null

  // An unavailable metric has no rows. Show its Chinese reason instead of
  // calling the drill-down endpoint and rendering a misleading empty zero.
  if (metric.status !== 'AVAILABLE') {
    drilldown.value = null
    drillLoading.value = false
    return
  }

  const externalRoute = metric.drilldown?.route
  const reachable = metricDrilldownRoute(metric)

  if (externalRoute !== undefined && externalRoute !== '' && externalRoute !== '/dashboard' && reachable !== null) {
    const query = metric.drilldown?.query ?? {}
    await router.push({ path: reachable, query })
    return
  }

  drillLoading.value = true
  drilldown.value = null
  try {
    drilldown.value = await getMetricDrilldown(metric.code, snapshot.value?.asOf ?? asOf.value)
  } catch (reason) {
    drillError.value = reason
    if (reason instanceof ApiError && reason.code === 'FORBIDDEN') {
      loadError.value = reason
    }
  } finally {
    drillLoading.value = false
  }
}

function drillValue(row: MetricDrilldown['rows'][number], columnKey: string): string {
  const value = row[columnKey]
  if (value === null || value === undefined || value === '') return '—'
  if (columnKey === 'status') return statusText(String(value))
  if (typeof value === 'number') return new Intl.NumberFormat('zh-CN').format(value)
  return String(value)
}

watch(asOf, () => {
  void load()
})

void load()
</script>

<template>
  <div class="page dashboard-page" data-testid="dashboard-page">
    <header class="page-header">
      <div class="page-header__copy">
        <p class="page-header__breadcrumb">工作台 / 首页看板</p>
        <h1 class="page-header__title">人力仪表盘</h1>
        <p class="page-header__description">
          部门人数、员工构成、近期动态、工作提醒与招聘进展在本页统一展示；指标、单位、口径与不可用原因均由服务端提供。
        </p>
      </div>
      <div class="page-header__actions dashboard-actions">
        <label class="dashboard-as-of">
          <span>业务日期</span>
          <el-input v-model="asOf" type="date" data-testid="dashboard-as-of" aria-label="业务日期" />
        </label>
        <el-button :disabled="loading" @click="load">刷新快照</el-button>
      </div>
    </header>

    <nav class="dashboard-anchors" aria-label="看板分区">
      <a href="#dashboard-departments">部门人数</a>
      <a href="#dashboard-workforce">员工构成</a>
      <a href="#dashboard-changes">近期动态</a>
      <a href="#dashboard-tasks">工作提醒</a>
      <a href="#dashboard-recruitment">招聘进展</a>
    </nav>

    <section v-if="!canRead" class="panel dashboard-forbidden" data-testid="dashboard-forbidden">
      <h2 class="panel__title">无权查看仪表盘</h2>
      <p class="panel__subtitle">当前账号缺少报表看板读取能力。如确需访问，请提交授权申请。</p>
    </section>

    <LoadingState v-else-if="loading" :rows="7" label="正在加载仪表盘" data-testid="dashboard-loading" />

    <ErrorState
      v-else-if="loadError"
      description="仪表盘数据暂时无法加载。"
      :code="errorMessage"
      :request-id="errorRequestId"
      data-testid="dashboard-error"
      @retry="load"
    />

    <section v-else-if="empty" class="panel" data-testid="dashboard-empty">
      <EmptyState
        title="当前没有可展示的真实指标"
        description="仪表盘接口没有返回任何指标；页面不会使用静态占位数字或示例数据。"
      />
    </section>

    <template v-else-if="snapshot">
      <div class="dashboard-meta">
        <span>截至 {{ formatBusinessDate(snapshot.asOf) }}</span>
        <span class="plain-chip">{{ freshnessText }}</span>
        <span>数据更新于 {{ formatDateTimeInTimezone(snapshot.freshness) }}</span>
        <span class="plain-chip">可用指标 {{ availableMetrics.length }} / {{ metrics.length }}</span>
      </div>

      <el-alert v-if="unavailableMetrics.length > 0" type="warning" :closable="false" show-icon data-testid="dashboard-partial">
        <template #title>
          <span v-for="metric in unavailableMetrics" :key="metric.code" class="dashboard-partial__line">
            {{ metricLabel(metric.code, metric.label) }}（{{ metricAvailabilityLabel(metric.status) }}）：{{ metricReasonText(metric) }}
          </span>
        </template>
      </el-alert>

      <section class="metric-grid" aria-label="关键指标">
        <MetricCard
          v-for="metric in metrics"
          :key="metric.code"
          :metric="metric"
          :as-of="snapshot.asOf"
          :freshness="snapshot.freshnessState || 'LIVE'"
          :freshness-at="snapshot.freshness"
          :partial-reason="metric.reason"
          @drill="drill"
        />
      </section>

      <DashboardSections
        :sections="snapshot.sections ?? { departments: [], workforce: [], recruitment: [] }"
        :metrics="metrics"
        :pending-approvals="snapshot.pendingApprovals ?? []"
        :contract-alerts="snapshot.contractAlerts ?? []"
        :recent-notices="snapshot.recentNotices ?? []"
        :as-of="snapshot.asOf"
      />

      <section v-if="drillLoading" data-testid="dashboard-drilldown-loading">
        <LoadingState :rows="4" label="正在加载指标下钻列表" />
      </section>

      <ErrorState
        v-else-if="drillError"
        description="指标下钻列表暂时无法加载。"
        :code="drillErrorMessage"
        data-testid="dashboard-drilldown-error"
        @retry="selectedMetric ? drill(selectedMetric) : load()"
      />

      <section v-else-if="drilldown" class="panel" data-testid="dashboard-drilldown">
        <div class="panel__header">
          <div>
            <h2 class="panel__title">{{ selectedMetric ? metricLabel(selectedMetric.code, selectedMetric.label) : drilldown.label }}明细</h2>
            <p class="panel__subtitle">与指标卡使用同一截至日期与授权范围；合计 {{ drillTotalText }}。</p>
          </div>
          <span class="plain-chip">截至 {{ formatBusinessDate(drilldown.asOf) }}</span>
        </div>

        <EmptyState
          v-if="drilldown.rows.length === 0"
          title="当前条件下没有明细记录"
          description="指标为 0 或授权范围为空时列表同步为空。"
        />
        <DataTable
          v-else
          :columns="drilldownColumns"
          :rows="drilldownRows"
          :total="drilldownRows.length"
          :page="1"
          :page-size="Math.max(1, drilldownRows.length)"
          row-key="id"
          data-testid="dashboard-drilldown-table"
          empty-title="当前条件下没有明细记录"
        />
      </section>

      <section v-else-if="selectedMetric && selectedMetric.status !== 'AVAILABLE'" class="panel" data-testid="dashboard-selected-unavailable">
        <div class="panel__header">
          <h2 class="panel__title">{{ metricLabel(selectedMetric.code, selectedMetric.label) }}</h2>
          <span class="plain-chip">{{ metricAvailabilityLabel(selectedMetric.status) }}</span>
        </div>
        <p class="panel__body">{{ metricReasonText(selectedMetric) }}</p>
      </section>
    </template>
  </div>
</template>

<style scoped>
.dashboard-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 10px;
}

.dashboard-as-of {
  display: flex;
  flex-direction: column;
  gap: 4px;
  color: var(--color-ink-secondary);
  font-size: 11px;
}

.dashboard-meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px 18px;
  color: var(--color-ink-muted);
  font-size: 11.5px;
}

.dashboard-anchors {
  display: flex;
  flex-wrap: wrap;
  gap: 6px 16px;
  padding-bottom: 4px;
  font-size: 12px;
}

.dashboard-anchors a {
  color: var(--color-ink-secondary);
  text-decoration: none;
}

.dashboard-anchors a:hover {
  color: var(--color-ink);
  text-decoration: underline;
}

.dashboard-partial__line {
  display: block;
  font-size: 11.5px;
  line-height: 1.5;
}

.dashboard-forbidden {
  max-width: 560px;
}

.drilldown-table-wrap {
  overflow-x: auto;
}

.drilldown-table {
  width: 100%;
  min-width: 620px;
  border-collapse: collapse;
  font-size: 12px;
}

.drilldown-table th {
  padding: 9px 14px;
  color: var(--color-ink-muted);
  font-size: 10.5px;
  font-weight: 650;
  text-align: left;
  background: #f8faf9;
  border-bottom: 1px solid var(--color-line);
}

.drilldown-table td {
  padding: 10px 14px;
  border-bottom: 1px solid #edf1ef;
}
</style>
