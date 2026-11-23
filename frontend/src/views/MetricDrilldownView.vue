<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { getApi } from '@/api'
import type { DashboardMetricCode, MetricDrilldown } from '@/api/contracts'
import { errorCopy } from '@/api/errors'
import { formatBusinessDate } from '@/utils/format'
import LoadingState from '@/components/LoadingState.vue'
import ErrorState from '@/components/ErrorState.vue'
import EmptyState from '@/components/EmptyState.vue'

const props = defineProps<{ metric: string }>()
const router = useRouter()
const loading = ref(true)
const error = ref('')
const data = ref<MetricDrilldown | null>(null)

const metricCode = computed(() => props.metric as DashboardMetricCode)

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    data.value = await getApi().dashboard.getMetricDrilldown(metricCode.value)
  } catch (reason) {
    error.value = errorCopy(reason)
  } finally {
    loading.value = false
  }
}

function openRow(link: string | null): void {
  if (link) void router.push(link)
  else window.scrollTo({ top: 0, behavior: 'smooth' })
}

onMounted(load)
</script>

<template>
  <div class="page drilldown-page">
    <div class="row">
      <el-button text @click="router.push('/dashboard')">← 返回仪表盘</el-button>
    </div>

    <header class="page-header">
      <div class="page-header__copy">
        <p class="page-header__breadcrumb">指标下钻</p>
        <h1 class="page-header__title">{{ data?.label ?? '指标下钻' }}</h1>
        <p class="page-header__description">
          本列表与仪表盘数字使用同一查询条件与授权约束；切换授权后下一次请求会按新范围返回。
        </p>
      </div>
      <div class="page-header__actions">
        <span v-if="data" class="plain-chip">共 {{ data.total }} 条</span>
      </div>
    </header>

    <section v-if="data" class="predicate-panel" role="note">
      <span class="predicate-panel__label">相同谓词</span>
      <code class="predicate-panel__code">{{ data.predicate }}</code>
      <span class="predicate-panel__asof">as-of {{ formatBusinessDate(data.asOf) }}</span>
    </section>

    <ErrorState v-if="error" description="下钻列表暂时无法加载。" :code="error" @retry="load" />
    <LoadingState v-else-if="loading" :rows="6" label="正在加载下钻列表" />
    <EmptyState
      v-else-if="data && data.rows.length === 0"
      title="当前条件下没有记录"
      description="指标为 0 或授权范围为空时，列表同步为空。请返回仪表盘检查筛选口径。"
    />
    <section v-else-if="data" class="panel">
      <div class="panel__header">
        <div>
          <h2 class="panel__title">{{ data.label }}列表</h2>
          <p class="panel__subtitle">数字与列表同源；不是先做全量再在前端过滤。</p>
        </div>
        <span class="plain-chip">{{ data.total }} 条</span>
      </div>
      <div class="drilldown-table-wrap">
        <table class="drilldown-table">
          <caption class="visually-hidden">{{ data.label }}下钻列表</caption>
          <thead>
            <tr>
              <th v-for="column in data.columns" :key="column.key" scope="col">{{ column.label }}</th>
              <th scope="col"><span class="visually-hidden">操作</span></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in data.rows" :key="row.id" class="drilldown-row" @click="openRow(row.link)">
              <td>{{ row.primary }}</td>
              <td class="small">{{ row.secondary }}</td>
              <td><span class="plain-chip">{{ row.status }}</span></td>
              <td class="small">{{ row.detail }}</td>
              <td>
                <el-button v-if="row.link" text size="small" @click.stop="openRow(row.link)">查看</el-button>
                <span v-else class="small muted">无详情页</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>

<style scoped>
.predicate-panel {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
  padding: 9px 13px;
  background: #fff;
  border: 1px solid var(--color-line);
  border-left: 3px solid var(--color-brand);
  border-radius: var(--radius-sm);
}

.predicate-panel__label {
  color: var(--color-ink-muted);
  font-size: 9.5px;
  font-weight: 700;
  letter-spacing: 0.1em;
}

.predicate-panel__code {
  flex: 1;
  min-width: 220px;
  color: var(--color-ink-secondary);
  font-family: "Cascadia Code", Consolas, monospace;
  font-size: 10px;
  overflow-wrap: anywhere;
}

.predicate-panel__asof {
  color: var(--color-ink-muted);
  font-size: 10px;
}

.drilldown-table-wrap {
  overflow-x: auto;
}

.drilldown-table {
  width: 100%;
  min-width: 760px;
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
  padding: 11px 14px;
  border-bottom: 1px solid #edf1ef;
}

.drilldown-row {
  cursor: pointer;
}

.drilldown-row:hover td {
  background: #fafcfb;
}
</style>
