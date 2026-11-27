<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  fieldLabel,
  listReportDefinitions,
  listOrgUnits,
  reportCellText,
  reportDomainLabel,
  runReport,
  statusText,
  type OrgUnitOption,
  type ReportDefinition,
  type ReportResult,
} from '../api'
import { formatBusinessDate, formatDateTimeInTimezone, todayBusinessDate } from '@/utils/format'
import DataTable, { type DataTableColumn } from '@/components/DataTable.vue'
import LoadingState from '@/components/LoadingState.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import { errorCopy } from '@/api/errors'
import { requestIdFromError } from '@/utils/renderErrors'
import { useSessionStore } from '@/stores/session'
import ExportPanel from '../components/ExportPanel.vue'
import ReportRunner from '../components/ReportRunner.vue'
import ReportCatalog from '../components/ReportCatalog.vue'

const reportColumns = computed<DataTableColumn[]>(() =>
  (result.value?.columns ?? []).map((column) => ({
    key: column.key,
    label: fieldLabel(column.key, column.label),
  })),
)

const reportRows = computed<Array<Record<string, unknown>>>(() =>
  (result.value?.rows ?? []).map((row, index) => {
    const output: Record<string, unknown> = { id: index }
    for (const column of result.value?.columns ?? []) {
      output[column.key] = cellText(row[column.key] ?? null, column.key)
    }
    return output
  }),
)

const session = useSessionStore()
const route = useRoute()
const router = useRouter()
const loading = ref(true)
const loadError = ref<unknown>(null)
const definitionsPartial = ref('')
const definitions = ref<ReportDefinition[]>([])
const selectedCode = ref(typeof route?.query?.report === 'string' ? route.query.report : '')
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

watch(selectedCode, (code) => {
  result.value = null
  runError.value = ''
  resetFilters(selectedDefinition.value)

  // Deep-link state lives in the URL so refresh keeps the chosen report. The
  // router is optional in focused component tests and never blocks rendering.
  if (router === undefined || route === undefined) return
  const query: Record<string, string | null | Array<string | null>> = { ...route.query }
  if (code === '') delete query.report
  else query.report = code
  void router.replace({ query })
})

watch(
  () => route?.query?.report ?? '',
  (value) => {
    const code = typeof value === 'string' ? value : ''
    if (code !== '' && code !== selectedCode.value && definitions.value.some((definition) => definition.code === code)) {
      selectedCode.value = code
    }
  },
)

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
    const requestedCode = typeof route?.query?.report === 'string' ? route.query.report : ''
    const requestedExists = definitions.value.some((definition) => definition.code === requestedCode)

    if (requestedExists) {
      selectedCode.value = requestedCode
    } else if (!definitions.value.some((definition) => definition.code === selectedCode.value)) {
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
    // A failed query is never an empty result. `result` remains null and the
    // template renders the error branch only.
    runError.value = errorCopy(reason)
  } finally {
    running.value = false
  }
}

function cellText(value: string | number | null, key: string): string {
  if (key === 'status' && typeof value === 'string' && value !== '') {
    return statusText(value)
  }
  return reportCellText(value, key)
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
          选择报表定义、设置业务日期与筛选条件后运行。结果、截至日期、数据版本与总数均由服务端投影返回。
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
        <ReportCatalog
          :definitions="definitions"
          :selected="selectedCode"
          @select="selectedCode = $event"
        />

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

          <ErrorState
            v-if="runError"
            title="报表查询失败"
            :description="runError"
            data-testid="report-error"
            @retry="execute"
          />

          <template v-else-if="result">
            <el-alert
              v-if="result.partial"
              type="warning"
              :closable="false"
              show-icon
              data-testid="report-partial"
            >
              <template #title>部分结果：{{ result.partialReason ?? '服务端投影仍在物化，当前结果可能不完整。' }}</template>
            </el-alert>

            <section class="panel" data-testid="report-result">
              <div class="panel__header">
                <div>
                  <h2 class="panel__title">{{ result.title }}</h2>
                  <p class="panel__subtitle">
                    截至 {{ formatBusinessDate(result.asOf) }} · 共 {{ result.total }} 行 · 生成于
                    {{ formatDateTimeInTimezone(result.generatedAt) }}
                  </p>
                </div>
                <span class="plain-chip">{{ reportDomainLabel(selectedDefinition?.domain ?? '') }}</span>
              </div>

              <div v-if="result.rows.length === 0" class="panel__body" data-testid="report-empty">
                <EmptyState title="当前报表没有数据" description="服务端已成功返回结果，当前筛选条件下没有匹配记录。" />
              </div>

              <DataTable
                v-else
                :columns="reportColumns"
                :rows="reportRows"
                :total="reportRows.length"
                :page="1"
                :page-size="Math.max(1, reportRows.length)"
                row-key="id"
                data-testid="report-run-table"
                empty-title="当前报表没有数据"
              />

              <div class="panel__body report-meta">
                <span><strong>数据版本</strong><span class="mono">{{ result.sourceHash }}</span></span>
                <span><strong>统计范围</strong>{{ formatBusinessDate(result.asOf) }}</span>
                <span><strong>结果行数</strong>{{ result.total }}</span>
              </div>
            </section>
          </template>

          <section v-else-if="!running && selectedDefinition" class="panel" data-testid="report-idle">
            <div class="panel__body">
              <EmptyState title="尚未运行报表" description="设置业务日期与筛选条件后点击“运行报表”。未运行不等于 0 行。" />
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

.reports-forbidden {
  max-width: 560px;
}

@media (max-width: 980px) {
  .report-layout {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
