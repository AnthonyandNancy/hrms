<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import {
  EXPORT_PURPOSE_OPTIONS,
  createExport,
  exportDenialCopy,
  exportStatusText,
  fieldLabel,
  listExportHistory,
  type ExportJob,
  type ExportPurpose,
  type ReportDefinition,
} from '../api'

const props = withDefaults(
  defineProps<{
    definition: ReportDefinition
    asOf: string
    filters?: Record<string, string>
  }>(),
  { filters: () => ({}) },
)

const emit = defineEmits<{
  created: [job: ExportJob]
}>()

const purpose = ref<ExportPurpose | ''>('BUSINESS_OPERATION')
const selectedFields = ref<string[]>([])
const submitting = ref(false)
const errorMessage = ref('')
const successMessage = ref('')
const job = ref<ExportJob | null>(null)
let pollTimer: ReturnType<typeof setInterval> | null = null

const exportableFields = computed(() => props.definition.fields.filter((field) => field.exportable))
const downloadHref = computed(() => {
  if (job.value === null || job.value.status !== 'COMPLETED') return ''
  if (job.value.downloadUrl !== null && job.value.downloadUrl !== '') return job.value.downloadUrl
  if (job.value.downloadToken === null || job.value.downloadToken === '') return ''
  return `/v1/reporting/exports/${job.value.id}/download?token=${encodeURIComponent(job.value.downloadToken)}`
})
const jobStatus = computed(() => (job.value === null ? '' : exportStatusText(job.value.status)))

function resetFields(): void {
  selectedFields.value = exportableFields.value.map((field) => field.key)
}

watch(
  () => props.definition.code,
  () => {
    stopPolling()
    job.value = null
    errorMessage.value = ''
    successMessage.value = ''
    resetFields()
  },
  { immediate: true },
)

function stopPolling(): void {
  if (pollTimer !== null) {
    clearInterval(pollTimer)
    pollTimer = null
  }
}

async function submit(): Promise<void> {
  errorMessage.value = ''
  successMessage.value = ''
  if (selectedFields.value.length === 0) {
    errorMessage.value = '请至少选择一个可导出字段。'
    return
  }
  if (purpose.value === '') {
    errorMessage.value = '请选择导出目的。'
    return
  }

  submitting.value = true
  try {
    const created = await createExport({
      reportCode: props.definition.code,
      purpose: purpose.value,
      fields: [...selectedFields.value],
      asOf: props.asOf,
      filters: { ...props.filters },
    })
    job.value = created
    successMessage.value = '导出任务已创建；文件带水印并记录审计日志。'
    emit('created', created)
    // The API dispatches exports asynchronously in production. The status field
    // is authoritative, so poll only while the job is not terminal.
    if (created.status === 'QUEUED' || created.status === 'RUNNING') {
      startPolling()
    }
  } catch (reason) {
    errorMessage.value = exportDenialCopy(reason)
  } finally {
    submitting.value = false
  }
}

function startPolling(): void {
  stopPolling()
  pollTimer = setInterval(async () => {
    // A single POST response is the only status source the current public
    // contract exposes for a just-created job; the owner-scoped history
    // endpoint is the read seam. Polling stops as soon as the job reports a
    // terminal state. Any transport failure leaves the last honest status.
    try {
      const history = await listExportHistory()
      const updated = history.items.find((item) => item.id === job.value?.id)
      if (updated !== undefined) {
        job.value = updated
        if (updated.status === 'COMPLETED' || updated.status === 'FAILED') stopPolling()
      }
    } catch {
      stopPolling()
    }
  }, 2500)
}

onBeforeUnmount(stopPolling)
</script>

<template>
  <section class="panel export-panel" data-testid="export-panel">
    <div class="panel__header">
      <div>
        <h2 class="panel__title">导出报表</h2>
        <p class="panel__subtitle">
          导出独立于报表读取能力；字段必须来自服务端白名单，文件带水印、短效且记录审计轨迹。
        </p>
      </div>
      <span class="plain-chip">截至 {{ asOf }}</span>
    </div>

    <div class="panel__body export-panel__body">
      <label class="export-field">
        <span>导出目的</span>
        <select v-model="purpose" data-testid="export-purpose" aria-label="导出目的">
          <option v-for="option in EXPORT_PURPOSE_OPTIONS" :key="option.value" :value="option.value">
            {{ option.label }}
          </option>
        </select>
      </label>

      <fieldset class="export-fields">
        <legend>可导出字段</legend>
        <label v-for="field in exportableFields" :key="field.key" class="export-field__check">
          <input v-model="selectedFields" type="checkbox" :value="field.key" :data-testid="`export-field-${field.key}`" />
          <span>{{ fieldLabel(field.key, field.label) }}</span>
        </label>
        <p v-if="exportableFields.length === 0" class="small muted">该报表当前没有可导出字段。</p>
      </fieldset>

      <p v-if="errorMessage" class="export-denial" role="alert" data-testid="export-denial">{{ errorMessage }}</p>
      <p v-if="successMessage" class="export-success" role="status" data-testid="export-success">{{ successMessage }}</p>

      <div class="export-actions">
        <el-button
          type="primary"
          :loading="submitting"
          :disabled="submitting || exportableFields.length === 0"
          data-testid="export-submit"
          @click="submit"
        >
          创建导出任务
        </el-button>
      </div>

      <div v-if="job" class="export-job" data-testid="export-job">
        <p class="export-job__line">
          <strong>{{ job.fileName || '导出文件' }}</strong>
          <span class="plain-chip">{{ jobStatus }}</span>
        </p>
        <p class="small muted">
          行数 {{ job.rowCount }} · 下载 {{ job.downloadCount }} / {{ job.maxDownloads }} · 字段
          {{ job.fields.length > 0 ? job.fields.map((key) => fieldLabel(key)).join('、') : '未返回' }}
        </p>
        <p v-if="job.watermark" class="small muted">水印：{{ job.watermark }}</p>
        <p v-if="job.status === 'FAILED'" class="export-denial">导出生成失败，请稍后重新创建任务。</p>
        <a v-if="downloadHref" :href="downloadHref" class="export-download" data-testid="export-download">
          下载 CSV（短效签名）
        </a>
        <span v-else-if="job.status === 'QUEUED' || job.status === 'RUNNING'" class="small muted">
          文件生成中，完成后显示下载链接。
        </span>
      </div>
    </div>
  </section>
</template>

<style scoped>
.export-panel__body {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.export-field,
.export-fields {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.export-field span,
.export-fields legend {
  color: var(--color-ink-secondary);
  font-size: 11.5px;
  font-weight: 600;
}

.export-fields {
  margin: 0;
  padding: 12px;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-sm);
}

.export-field__check {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 12px;
}

.export-field select,
.export-field input {
  padding: 7px 9px;
  font: inherit;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-sm);
}

.export-denial {
  margin: 0;
  padding: 9px 11px;
  color: #8a2c2c;
  font-size: 12px;
  background: #fff4f2;
  border: 1px solid #f0c7c0;
  border-radius: var(--radius-sm);
}

.export-success {
  margin: 0;
  padding: 9px 11px;
  color: #1d5b43;
  font-size: 12px;
  background: #f1faf5;
  border: 1px solid #bfe3d0;
  border-radius: var(--radius-sm);
}

.export-job {
  padding: 11px 12px;
  background: var(--color-canvas);
  border: 1px solid var(--color-line);
  border-radius: var(--radius-sm);
}

.export-job__line {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin: 0 0 4px;
  font-size: 12.5px;
}

.export-download {
  font-size: 12px;
  font-weight: 600;
}
</style>
