<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch, nextTick } from 'vue'
import * as echarts from 'echarts/core'
import { BarChart } from 'echarts/charts'
import { GridComponent, TooltipComponent } from 'echarts/components'
import { CanvasRenderer } from 'echarts/renderers'
import { ElMessage } from 'element-plus'
import { getApi } from '@/api'
import type { ExportJob, ExportPurpose, ExportRequest, ReportDefinition, ReportResult } from '@/api/contracts'
import { errorCopy } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import { formatDateTimeInTimezone, todayBusinessDate } from '@/utils/format'
import { exportSchema, validate } from '@/utils/validation'
import LoadingState from '@/components/LoadingState.vue'
import ErrorState from '@/components/ErrorState.vue'

echarts.use([BarChart, GridComponent, TooltipComponent, CanvasRenderer])

const session = useSessionStore()
const loading = ref(true)
const error = ref('')
const definitions = ref<ReportDefinition[]>([])
const selectedCode = ref('')
const result = ref<ReportResult | null>(null)
const running = ref(false)
const chartEl = ref<HTMLDivElement | null>(null)
let chart: echarts.ECharts | null = null

const exportOpen = ref(false)
const exporting = ref(false)
const exportError = ref('')
const exportFieldErrors = ref<Record<string, string>>({})
const exportJob = ref<ExportJob | null>(null)
const exportForm = reactive<{
  purpose: ExportPurpose | ''
  fields: string[]
  acknowledged: boolean
  filters: Record<string, string>
}>({
  purpose: '',
  fields: [],
  acknowledged: false,
  filters: {},
})

const selectedDefinition = computed(() => definitions.value.find((definition) => definition.code === selectedCode.value) ?? null)
const canExport = computed(() => session.can('reporting.export'))
const exportableFields = computed(() =>
  (selectedDefinition.value?.fields ?? []).filter((field) => field.exportable),
)
const hiddenFieldCount = computed(() =>
  (selectedDefinition.value?.fields ?? []).filter((field) => !field.exportable).length,
)
const filterValues = reactive<Record<string, string>>({})

async function loadDefinitions(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    definitions.value = await getApi().reporting.getReportDefinitions()
    if (!selectedCode.value && definitions.value[0]) selectedCode.value = definitions.value[0].code
  } catch (reason) {
    error.value = errorCopy(reason)
  } finally {
    loading.value = false
  }
}

async function runReport(): Promise<void> {
  const definition = selectedDefinition.value
  if (!definition) return
  running.value = true
  try {
    result.value = await getApi().reporting.runReport(definition.code, { ...filterValues })
    await nextTick()
    renderChart()
  } catch (reason) {
    ElMessage.error(errorCopy(reason))
  } finally {
    running.value = false
  }
}

function renderChart(): void {
  if (!chartEl.value || !result.value) return
  chart?.dispose()
  chart = echarts.init(chartEl.value)
  chart.setOption({
    grid: { left: 12, right: 18, top: 24, bottom: 8, containLabel: true },
    tooltip: { trigger: 'axis' },
    xAxis: {
      type: 'category',
      data: result.value.chart.map((point) => point.label),
      axisLabel: { color: '#667b80', fontSize: 10 },
      axisLine: { lineStyle: { color: '#d9e2de' } },
    },
    yAxis: {
      type: 'value',
      axisLabel: {
        color: '#667b80',
        fontSize: 10,
        formatter: (value: number) => (selectedCode.value === 'PAYROLL_COST' ? `¥${(value / 100).toLocaleString('zh-CN')}` : String(value)),
      },
      splitLine: { lineStyle: { color: '#edf1ef' } },
    },
    series: [
      {
        type: 'bar',
        data: result.value.chart.map((point) => point.value),
        barMaxWidth: 46,
        itemStyle: { color: '#1c6474', borderRadius: [2, 2, 0, 0] },
      },
    ],
  })
}

function openExport(): void {
  if (!selectedDefinition.value) return
  exportForm.purpose = ''
  exportForm.fields = exportableFields.value.map((field) => field.key)
  exportForm.acknowledged = false
  exportForm.filters = { ...filterValues }
  exportFieldErrors.value = {}
  exportError.value = ''
  exportJob.value = null
  exportOpen.value = true
}

async function submitExport(): Promise<void> {
  if (!selectedDefinition.value) return
  exportFieldErrors.value = {}
  exportError.value = ''
  const parsed = validate(exportSchema, exportForm)
  if (!parsed.success) {
    for (const issue of parsed.issues) exportFieldErrors.value[issue.path] = issue.message
    return
  }
  exporting.value = true
  try {
    const payload: ExportRequest = {
      reportCode: selectedDefinition.value.code,
      purpose: parsed.data!.purpose,
      fields: parsed.data!.fields,
      filters: exportForm.filters,
    }
    exportJob.value = await getApi().reporting.createExport(payload)
    ElMessage.success('导出任务已完成，可下载带水印文件。')
  } catch (reason) {
    exportError.value = errorCopy(reason)
  } finally {
    exporting.value = false
  }
}

async function download(): Promise<void> {
  if (!exportJob.value) return
  try {
    const blob = await getApi().reporting.downloadExport(exportJob.value.id)
    const url = URL.createObjectURL(blob)
    const anchor = document.createElement('a')
    anchor.href = url
    anchor.download = exportJob.value.fileName
    anchor.click()
    URL.revokeObjectURL(url)
    exportJob.value.downloadCount += 1
    ElMessage.success('下载已开始；本次下载已写入审计日志。')
  } catch (reason) {
    ElMessage.error(errorCopy(reason))
  }
}

watch(selectedCode, () => {
  result.value = null
  chart?.dispose()
  chart = null
  for (const key of Object.keys(filterValues)) delete filterValues[key]
  for (const filter of selectedDefinition.value?.filters ?? []) {
    filterValues[filter.key] = filter.type === 'DATE_RANGE' ? todayBusinessDate() : (filter.options?.[0] ?? '')
  }
  void runReport()
})

onMounted(async () => {
  await loadDefinitions()
  for (const filter of selectedDefinition.value?.filters ?? []) {
    filterValues[filter.key] = filter.type === 'DATE_RANGE' ? todayBusinessDate() : (filter.options?.[0] ?? '')
  }
  await runReport()
})
</script>

<template>
  <div class="page reports-page">
    <header class="page-header">
      <div class="page-header__copy">
        <p class="page-header__breadcrumb">洞察</p>
        <h1 class="page-header__title">报表中心</h1>
        <p class="page-header__description">
          报表读取投影与模块契约，不直接查业务表。导出需要选择目的与字段白名单，文件带水印、短效且下载次数受限。
        </p>
      </div>
      <div class="page-header__actions">
        <el-button v-if="canExport" type="primary" :disabled="!result" @click="openExport">导出报表</el-button>
        <span v-else class="plain-chip">无导出权限</span>
      </div>
    </header>

    <ErrorState v-if="error" description="报表定义暂时无法加载。" :code="error" @retry="loadDefinitions" />
    <LoadingState v-else-if="loading" :rows="7" label="正在加载报表中心" />

    <template v-else>
      <div class="report-layout">
        <aside class="report-nav panel" aria-label="报表列表">
          <div class="panel__header">
            <h2 class="panel__title">可用报表</h2>
          </div>
          <nav class="report-nav__list">
            <button
              v-for="definition in definitions"
              :key="definition.code"
              type="button"
              class="report-nav__item"
              :class="{ 'report-nav__item--active': selectedCode === definition.code }"
              :aria-current="selectedCode === definition.code ? 'page' : undefined"
              @click="selectedCode = definition.code"
            >
              <span class="report-nav__name">{{ definition.name }}</span>
              <span class="report-nav__desc">{{ definition.description }}</span>
              <span class="report-nav__flags">
                <span v-if="definition.asOfSupported" class="plain-chip">支持 as-of</span>
                <span v-if="definition.drilldownRoute" class="plain-chip">可下钻</span>
              </span>
            </button>
          </nav>
        </aside>

        <div class="stack report-main">
          <section v-if="selectedDefinition" class="panel">
            <div class="panel__header">
              <div>
                <h2 class="panel__title">{{ selectedDefinition.name }}</h2>
                <p class="panel__subtitle">{{ selectedDefinition.description }}</p>
              </div>
              <span class="mono small muted">{{ selectedDefinition.code }}</span>
            </div>
            <div class="panel__body report-filters">
              <label v-for="filter in selectedDefinition.filters" :key="filter.key" class="report-field">
                <span>{{ filter.label }}</span>
                <el-select v-if="filter.type === 'SELECT'" v-model="filterValues[filter.key]" :aria-label="filter.label">
                  <el-option v-for="option in filter.options ?? []" :key="option" :label="option" :value="option" />
                </el-select>
                <el-input v-else v-model="filterValues[filter.key]" :aria-label="filter.label" />
              </label>
              <el-button type="primary" :loading="running" @click="runReport">运行报表</el-button>
            </div>
          </section>

          <section v-if="result" class="panel">
            <div class="panel__header">
              <div>
                <h2 class="panel__title">{{ result.title }}</h2>
                <p class="panel__subtitle">{{ result.subtitle }} · 共 {{ result.totalRows }} 行</p>
              </div>
              <span class="plain-chip">{{ result.watermark }}</span>
            </div>
            <div ref="chartEl" class="report-chart" role="img" aria-label="报表图表：按组织汇总"></div>
          </section>

          <section v-if="result" class="panel">
            <div class="panel__header">
              <div>
                <h2 class="panel__title">数据明细</h2>
                <p class="panel__subtitle">列上方标注字段档位；P2 字段在前端也只以授权投影展示。</p>
              </div>
            </div>
            <div class="report-table-wrap">
              <table class="report-table">
                <caption class="visually-hidden">{{ result.title }}明细</caption>
                <thead>
                  <tr>
                    <th v-for="column in result.columns" :key="column.key" scope="col">
                      {{ column.label }}
                      <span class="report-field-profile">{{ column.profile }}</span>
                    </th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(row, index) in result.rows" :key="index">
                    <td v-for="column in result.columns" :key="column.key">{{ row[column.key] ?? '—' }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div class="panel__footer">
              <span class="small muted">生成于 {{ formatDateTimeInTimezone(result.generatedAt) }}</span>
              <span class="small muted">导出使用同一授权与字段白名单</span>
            </div>
          </section>
        </div>
      </div>
    </template>

    <el-dialog v-model="exportOpen" title="导出报表" width="560px" :close-on-click-modal="false">
      <div class="stack">
        <el-alert v-if="exportError" :title="exportError" type="error" :closable="false" show-icon />
        <el-alert
          v-if="hiddenFieldCount > 0"
          :title="`有 ${hiddenFieldCount} 个字段不在你的导出白名单内，已自动排除。`"
          type="info"
          :closable="false"
          show-icon
        />
        <label class="field-label">
          <span>导出目的（必选）</span>
          <el-select v-model="exportForm.purpose" placeholder="选择用途" aria-label="导出目的">
            <el-option label="业务运营" value="BUSINESS_OPERATION" />
            <el-option label="审计检查" value="AUDIT" />
            <el-option label="合规取证" value="COMPLIANCE" />
            <el-option label="薪酬对账" value="PAYROLL_RECONCILIATION" />
          </el-select>
          <em v-if="exportFieldErrors.purpose" class="field-error">{{ exportFieldErrors.purpose }}</em>
        </label>
        <div class="field-label">
          <span>导出字段</span>
          <el-checkbox-group v-model="exportForm.fields" class="export-fields">
            <el-checkbox v-for="field in exportableFields" :key="field.key" :value="field.key">
              {{ field.label }}
            </el-checkbox>
          </el-checkbox-group>
          <em v-if="exportFieldErrors.fields" class="field-error">{{ exportFieldErrors.fields }}</em>
        </div>
        <el-checkbox v-model="exportForm.acknowledged">
          我确认导出数据含接收人与时间水印，并将记录审计；不得转发给无关人员。
        </el-checkbox>
        <em v-if="exportFieldErrors.acknowledged" class="field-error">{{ exportFieldErrors.acknowledged }}</em>

        <div v-if="exportJob" class="export-result">
          <div class="export-result__head">
            <span class="plain-chip">{{ exportJob.status === 'COMPLETED' ? '已生成' : '生成中' }}</span>
            <span class="mono small">{{ exportJob.fileName }}</span>
          </div>
          <p class="small">
            {{ exportJob.rowCount }} 行 · 水印：{{ exportJob.watermark }}
          </p>
          <p class="small muted">
            有效期至 {{ formatDateTimeInTimezone(exportJob.expiresAt) }} · 已下载
            {{ exportJob.downloadCount }} / {{ exportJob.maxDownloads }} 次
          </p>
          <el-button type="primary" plain size="small" @click="download">下载 CSV（含 UTF-8 BOM）</el-button>
        </div>
      </div>
      <template #footer>
        <el-button @click="exportOpen = false">关闭</el-button>
        <el-button type="primary" :loading="exporting" :disabled="Boolean(exportJob)" @click="submitExport">
          创建导出任务
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.report-layout {
  display: grid;
  grid-template-columns: 260px minmax(0, 1fr);
  gap: 16px;
  align-items: start;
}

.report-nav__list {
  display: flex;
  flex-direction: column;
  padding: 6px;
}

.report-nav__item {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 10px;
  color: var(--color-ink-secondary);
  text-align: left;
  background: transparent;
  border: 1px solid transparent;
  border-radius: 6px;
  cursor: pointer;
}

.report-nav__item:hover {
  background: #fafcfb;
}

.report-nav__item--active {
  background: var(--color-brand-soft);
  border-color: #b7d0ca;
}

.report-nav__name {
  color: var(--color-ink);
  font-size: 12.5px;
  font-weight: 650;
}

.report-nav__desc {
  font-size: 10.5px;
  line-height: 1.45;
}

.report-nav__flags {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  margin-top: 4px;
}

.report-main {
  min-width: 0;
}

.report-filters {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 12px;
}

.report-field {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 170px;
  color: var(--color-ink-muted);
  font-size: 10.5px;
  font-weight: 600;
}

.report-chart {
  width: 100%;
  height: 250px;
}

.report-table-wrap {
  overflow-x: auto;
}

.report-table {
  width: 100%;
  min-width: 700px;
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

.report-field-profile {
  display: block;
  color: #94a7a2;
  font-family: "Cascadia Code", Consolas, monospace;
  font-size: 8.5px;
  font-weight: 400;
}

.report-table td {
  padding: 9px 14px;
  border-bottom: 1px solid #edf1ef;
}

.field-label {
  display: flex;
  flex-direction: column;
  gap: 5px;
  color: var(--color-ink-muted);
  font-size: 11px;
  font-weight: 600;
}

.field-error {
  color: var(--color-danger);
  font-size: 11px;
  font-style: normal;
  font-weight: 400;
}

.export-fields {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 2px 8px;
}

.export-result {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 5px;
  padding: 10px;
  background: var(--color-success-soft);
  border: 1px solid #b7dcc7;
  border-radius: 6px;
}

.export-result__head {
  display: flex;
  align-items: center;
  gap: 8px;
}

@media (max-width: 900px) {
  .report-layout {
    grid-template-columns: minmax(0, 1fr);
  }

  .report-nav__list {
    flex-direction: row;
    overflow-x: auto;
  }

  .report-nav__item {
    min-width: 180px;
  }
}

@media (max-width: 520px) {
  .export-fields {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
