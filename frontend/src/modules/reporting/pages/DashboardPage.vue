<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useSessionStore } from '@/stores/session'
import {
  getDashboard,
  getMetricDrilldown,
  metricLabel,
  statusText,
  unavailableMetricReason,
  type DashboardSnapshot,
  type MetricCardMetric,
  type MetricDrilldown,
} from '../api'
import { errorCopy, ApiError } from '@/api/errors'
import { formatBusinessDate, formatDateTimeInTimezone, todayBusinessDate } from '@/utils/format'
import { requestIdFromError } from '@/utils/renderErrors'
import LoadingState from '@/components/LoadingState.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import MetricCard from '../components/MetricCard.vue'

const session = useSessionStore()
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
const freshnessText = computed(() => {
  const value = snapshot.value?.freshness ?? ''
  if (value === 'LIVE') return '实时快照'
  if (value === 'MATERIALIZED') return '缓存快照'
  if (value === 'STALE') return '过期快照'
  // The dashboard endpoint returns the snapshot's computed timestamp rather
  // than a projection freshness enum. Every request recomputes the metrics, so
  // the honest presentation is a live snapshot at that timestamp.
  if (/^\d{4}-\d{2}-\d{2}T/.test(value)) return '实时快照'
  return '快照状态未知'
})
const unavailableMetrics = computed(() => metrics.value.filter((metric) => metric.status !== 'AVAILABLE'))
const partialReasons = computed(() => unavailableMetrics.value.map((metric) => ({
  code: metric.code,
  label: metricLabel(metric.code, metric.label),
  reason: metric.reason !== null && metric.reason !== '' ? unavailableMetricReason(metric.code) : unavailableMetricReason(metric.code),
})))

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

async function drill(metric: MetricCardMetric): Promise<void> {
  if (!canRead.value) return
  selectedMetric.value = metric
  drillLoading.value = true
  drillError.value = null
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
  if (typeof value === 'number') return new Intl.NumberFormat('zh-CN').format(value)
  if (columnKey === 'status') return statusText(String(value))
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
        <p class="page-header__breadcrumb">仪表盘</p>
        <h1 class="page-header__title">人力仪表盘</h1>
        <p class="page-header__description">
          指标来自 Reporting 的同一授权投影；卡片数值、下钻列表和导出不会各算一套口径。
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

    <section v-else-if="snapshot === null || metrics.length === 0" class="panel" data-testid="dashboard-empty">
      <EmptyState
        title="当前没有可展示的真实指标"
        description="仪表盘接口没有返回任何指标；页面不会使用静态占位数字或示例数据。"
      />
    </section>

    <template v-else>
      <div class="dashboard-meta">
        <span>截至 {{ formatBusinessDate(snapshot.asOf) }}</span>
        <span class="plain-chip">{{ freshnessText }}</span>
        <span>数据更新于 {{ formatDateTimeInTimezone(snapshot.freshness) }}</span>
        <span class="plain-chip">共 {{ metrics.length }} 个指标</span>
      </div>

      <el-alert v-if="partialReasons.length > 0" type="warning" :closable="false" show-icon data-testid="dashboard-partial">
        <template #title>
          <span v-for="reason in partialReasons" :key="reason.code" class="dashboard-partial__line">
            {{ reason.label }}：{{ reason.reason }}
          </span>
        </template>
      </el-alert>

      <section class="metric-grid" aria-label="关键指标">
        <MetricCard
          v-for="metric in metrics"
          :key="metric.code"
          :metric="metric"
          :as-of="snapshot.asOf"
          :freshness="freshnessText === '缓存快照' ? 'MATERIALIZED' : freshnessText === '过期快照' ? 'STALE' : 'LIVE'"
          :freshness-at="snapshot.freshness"
          :partial-reason="metric.status === 'AVAILABLE' ? null : metric.reason"
          @drill="drill"
        />
      </section>

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
            <h2 class="panel__title">{{ selectedMetric ? metricLabel(selectedMetric.code, selectedMetric.label) : drilldown.label }}下钻</h2>
            <p class="panel__subtitle">
              与卡片使用同一截至日期与授权范围；共 {{ drilldown.total }} 条。
            </p>
          </div>
          <span class="plain-chip">截至 {{ formatBusinessDate(drilldown.asOf) }}</span>
        </div>

        <EmptyState
          v-if="drilldown.rows.length === 0"
          title="当前条件下没有下钻记录"
          description="指标为 0 或授权范围为空时列表同步为空。"
        />
        <div v-else class="drilldown-table-wrap">
          <el-table :data="drilldown.rows" class="drilldown-table" data-testid="dashboard-drilldown-table">
            <el-table-column
              v-for="column in drilldown.columns"
              :key="column.key"
              :label="column.label"
              :min-width="140"
            >
              <template #default="{ row }">{{ drillValue(row as unknown as MetricDrilldown['rows'][number], column.key) }}</template>
            </el-table-column>
          </el-table>
        </div>
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

.dashboard-as-of input {
  padding: 7px 9px;
  font: inherit;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-sm);
}

.dashboard-meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px 18px;
  color: var(--color-ink-muted);
  font-size: 11.5px;
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
