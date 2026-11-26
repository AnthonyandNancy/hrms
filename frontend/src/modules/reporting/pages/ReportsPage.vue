<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import {
  fieldLabel,
  listReportDefinitions,
  listOrgUnits,
  runReport,
  type OrgUnitOption,
  type ReportDefinition,
  type ReportResult,
} from '../api'
import { formatBusinessDate, formatDateTimeInTimezone, todayBusinessDate } from '@/utils/format'
import LoadingState from '@/components/LoadingState.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import { errorCopy } from '@/api/errors'
import { translate } from '@/shared/display/dictionary'
import { requestIdFromError } from '@/utils/renderErrors'
import { useSessionStore } from '@/stores/session'
import ExportPanel from '../components/ExportPanel.vue'
import ReportRunner from '../components/ReportRunner.vue'

const session = useSessionStore()
const loading = ref(true)
const loadError = ref<unknown>(null)
const definitionsPartial = ref('')
const definitions = ref<ReportDefinition[]>([])
const selectedCode = ref('')
const asOf = ref(todayBusinessDate())
const filters = reactive<Record<string, string>>({})
const orgUnits = ref<OrgUnitOption[]>([])
const running = ref(false)
const runError = ref('')
const result = ref<ReportResult | null>(null)

const selectedDefinition = computed(
  () => definitions.value.find((definition) => definition.code === selectedCode.value) ?? null,
)
const canRead = computed(() => session.can('reporting.report.read'))
const canExport = computed(() => session.can('reporting.export'))
const loadErrorMessage = computed(() => (loadError.value === null ? '' : errorCopy(loadError.value)))
const loadRequestId = computed(() => (loadError.value === null ? null : requestIdFromError(loadError.value)))

function resetFilters(definition: ReportDefinition | null): void {
  for (const key of Object.keys(filters)) delete filters[key]
  for (const filter of definition?.filters ?? []) {
    filters[filter.key] = filter.options[0] ?? ''
  }
}

watch(selectedCode, () => {
  result.value = null
  runError.value = ''
  resetFilters(selectedDefinition.value)
})

async function load(): Promise<void> {
  if (!canRead.value) {
    loading.value = false
    return
  }
  loading.value = true
  loadError.value = null
  definitionsPartial.value = ''
  try {
    const [page, units] = await Promise.allSettled([
      listReportDefinitions(),
      listOrgUnits(asOf.value),
    ])
    if (page.status === 'rejected') throw page.reason
    definitions.value = page.value.items
    if (units.status === 'fulfilled') {
      orgUnits.value = units.value
    } else {
      orgUnits.value = []
      definitionsPartial.value = '组织树暂不可用，按组织的筛选选项为空；报表目录仍可使用。'
    }
    if (page.value.partial) definitionsPartial.value = page.value.partialReason ?? '报表目录只返回了部分结果。'
    if (!definitions.value.some((definition) => definition.code === selectedCode.value)) {
      selectedCode.value = definitions.value[0]?.code ?? ''
    }
    resetFilters(selectedDefinition.value)
  } catch (reason) {
    definitions.value = []
    selectedCode.value = ''
    loadError.value = reason
  } finally {
    loading.value = false
  }
}

async function execute(): Promise<void> {
  const definition = selectedDefinition.value
  if (definition === null) return
  running.value = true
  runError.value = ''
  result.value = null
  try {
    result.value = await runReport({
      definition_code: definition.code,
      filters: { ...filters },
      as_of: asOf.value,
    })
  } catch (reason) {
    runError.value = errorCopy(reason)
  } finally {
    running.value = false
  }
}

function cellText(value: string | number | null, key: string): string {
  if (value === null || value === '') return '—'
  if (typeof value === 'number') return new Intl.NumberFormat('zh-CN').format(value)
  if (key === 'status') return statusCopy(value)
  return String(value)
}

function statusCopy(value: string): string {
  return translate('reporting', 'reportStatus', value, '其他状态').label
}

void load()
</script>

<template>
  <div class="page reports-page" data-testid="reports-page">
    <header class="page-header">
      <div class="page-header__copy">
        <p class="page-header__breadcrumb">报表中心</p>
        <h1 class="page-header__title">报表与导出</h1>
        <p class="page-header__description">
          选择报表定义、设置业务日期与筛选条件后运行。结果、as-of、source hash 与总数均由服务端投影返回。
        </p>
      </div>
      <div class="page-header__actions">
        <el-button :disabled="loading" @click="load">刷新目录</el-button>
      </div>
    </header>

    <section v-if="!canRead" class="panel reports-forbidden" data-testid="reports-forbidden">
      <h2 class="panel__title">无权查看报表</h2>
      <p class="panel__subtitle">当前账号缺少报表读取能力。如确需访问，请提交授权申请。</p>
    </section>

    <section v-else-if="loading" data-testid="reports-loading">
      <LoadingState :rows="7" label="正在加载报表目录" />
    </section>

    <ErrorState
      v-else-if="loadError"
      description="报表目录暂时无法加载。"
      :code="loadErrorMessage"
      :request-id="loadRequestId"
      data-testid="reports-error"
      @retry="load"
    />

    <section v-else-if="definitions.length === 0" class="panel" data-testid="reports-empty">
      <EmptyState title="当前没有可用报表" description="报表定义接口没有返回任何记录。请由管理员发布报表定义后再试。" />
    </section>

    <template v-else>
      <el-alert v-if="definitionsPartial" type="warning" :closable="false" show-icon data-testid="reports-partial">
        <template #title>部分报表定义：{{ definitionsPartial }}</template>
      </el-alert>

      <div class="report-layout">
        <aside class="panel report-catalog" aria-label="报表目录">
          <div class="panel__header">
            <h2 class="panel__title">报表目录</h2>
          </div>
          <nav class="report-catalog__list">
            <button
              v-for="definition in definitions"
              :key="definition.code"
              type="button"
              class="report-catalog__item"
              :class="{ 'report-catalog__item--active': definition.code === selectedCode }"
              :data-testid="`report-definition-${definition.code}`"
              @click="selectedCode = definition.code"
            >
              <span class="report-catalog__name">{{ definition.name }}</span>
              <span class="report-catalog__description">{{ definition.description }}</span>
            </button>
          </nav>
        </aside>

        <div class="stack report-main">
          <ReportRunner
            v-if="selectedDefinition"
            :definition="selectedDefinition"
            :as-of="asOf"
            :filters="filters"
            :org-units="orgUnits"
            :running="running"
            @update:as-of="asOf = $event"
            @update:filters="Object.assign(filters, $event)"
            @run="execute"
          />

          <p v-if="runError" class="report-error" role="alert" data-testid="report-run-error">{{ runError }}</p>

          <section v-if="result" class="panel" data-testid="report-result">
            <div class="panel__header">
              <div>
                <h2 class="panel__title">{{ result.title }}</h2>
                <p class="panel__subtitle">
                  as-of {{ formatBusinessDate(result.asOf) }} · 共 {{ result.total }} 行 · 生成于
                  {{ formatDateTimeInTimezone(result.generatedAt) }}
                </p>
              </div>
              <span class="plain-chip">{{ result.freshness === 'MATERIALIZED' ? '缓存结果' : result.freshness === 'LIVE' ? '实时结果' : '结果状态未知' }}</span>
            </div>

            <div class="panel__body report-meta">
              <span><strong>as_of</strong> {{ result.asOf }}</span>
              <span><strong>source_hash</strong> <code class="mono">{{ result.sourceHash }}</code></span>
              <span><strong>total</strong> {{ result.total }}</span>
            </div>

            <el-alert v-if="result.partial" type="warning" :closable="false" show-icon data-testid="report-partial">
              <template #title>部分结果：{{ result.partialReason ?? '服务端只返回了部分结果，请缩小筛选范围或稍后重试。' }}</template>
            </el-alert>

            <div v-if="result.rows.length === 0" class="panel__body" data-testid="report-empty">
              <EmptyState
                title="当前报表没有数据"
                description="该定义在所选业务日期和筛选条件下没有返回任何行；页面不会用示例数据填充。"
              />
            </div>

            <div v-else class="report-table-wrap">
              <el-table :data="result.rows" class="report-table" data-testid="report-table">
                <el-table-column
                  v-for="column in result.columns"
                  :key="column.key"
                  :label="column.label"
                  :min-width="140"
                >
                  <template #default="{ row }">
                    {{ cellText(row[column.key] ?? null, column.key) }}
                  </template>
                </el-table-column>
              </el-table>
            </div>

            <div class="panel__footer">
              <span class="small muted">字段：{{ result.columns.map((column) => fieldLabel(column.key, column.label)).join('、') }}</span>
              <span class="small muted">source_hash 用于确认本次结果与导出使用同一投影。</span>
            </div>
          </section>

          <ExportPanel
            v-if="selectedDefinition && canExport"
            :definition="selectedDefinition"
            :as-of="asOf"
            :filters="filters"
          />
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.report-layout {
  display: grid;
  grid-template-columns: minmax(220px, 280px) minmax(0, 1fr);
  gap: 16px;
  align-items: start;
}

.report-catalog__list {
  display: flex;
  flex-direction: column;
}

.report-catalog__item {
  display: flex;
  flex-direction: column;
  gap: 3px;
  padding: 10px 16px;
  font: inherit;
  text-align: left;
  color: inherit;
  background: transparent;
  border: 0;
  border-bottom: 1px solid #edf1ef;
  cursor: pointer;
}

.report-catalog__item:hover,
.report-catalog__item--active {
  background: #f4f8f7;
}

.report-catalog__item--active {
  box-shadow: inset 3px 0 0 var(--color-brand);
}

.report-catalog__name {
  font-size: 12.5px;
  font-weight: 600;
}

.report-catalog__description {
  color: var(--color-ink-muted);
  font-size: 10.5px;
}

.report-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 22px;
  color: var(--color-ink-muted);
  font-size: 11px;
}

.report-meta strong {
  margin-right: 4px;
  color: var(--color-ink-secondary);
}

.report-table-wrap {
  overflow-x: auto;
}

.report-table {
  width: 100%;
  min-width: 680px;
  border-collapse: collapse;
  font-size: 12px;
}

.report-table th {
  padding: 9px 14px;
  color: var(--color-ink-muted);
  font-size: 10.5px;
  font-weight: 650;
  text-align: left;
  background: #f8faf9;
  border-bottom: 1px solid var(--color-line);
}

.report-table td {
  padding: 10px 14px;
  border-bottom: 1px solid #edf1ef;
}

.report-error {
  margin: 0;
  padding: 10px 13px;
  color: #8a2c2c;
  font-size: 12px;
  background: #fff4f2;
  border: 1px solid #f0c7c0;
  border-radius: var(--radius-sm);
}

.reports-forbidden {
  max-width: 560px;
}

@media (max-width: 980px) {
  .report-layout {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
