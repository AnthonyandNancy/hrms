<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ApiError, errorCode, errorCopy } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import LoadingState from '@/components/LoadingState.vue'
import {
  businessDate,
  COMPLETION_RESULT_LABELS,
  enrollmentStatusLabel,
  exportTrainingRecords,
  listRecords,
  RECORD_EXPORT_FIELDS,
  trainingActionErrorCopy,
  type RecordExportField,
  type TrainingRecord,
} from '../api'

defineOptions({ name: 'TrainingRecordsPanel' })

const session = useSessionStore()
const canRead = computed(() => session.can('training.record.read'))
const loading = ref(false)
const loaded = ref(false)
const errorMessage = ref('')
const errorCodeValue = ref('')
const errorRequestId = ref<string | null>(null)
const rows = ref<TrainingRecord[]>([])
const employmentId = ref('')
const exporting = ref(false)
const exportError = ref('')
const exportFields = ref<RecordExportField[]>([
  'employment_id',
  'session_id',
  'course_code',
  'course_title',
  'status',
  'completion_result',
  'score',
  'study_minutes',
  'latest_exam_version',
])

const empty = computed(
  () => loaded.value && !loading.value && errorMessage.value === '' && rows.value.length === 0,
)
const totalStudyMinutes = computed(() => rows.value.reduce((sum, row) => sum + row.studyMinutes, 0))

function captureError(reason: unknown): void {
  errorCodeValue.value = errorCode(reason)
  errorRequestId.value = reason instanceof ApiError ? reason.requestId : null
  errorMessage.value =
    reason instanceof ApiError && reason.code === 'FORBIDDEN'
      ? '无权读取培训档案。请确认已获得 training.record.read 能力。'
      : trainingActionErrorCopy(reason) || errorCopy(reason)
}

async function load(): Promise<void> {
  if (!canRead.value) {
    loaded.value = true
    return
  }

  loading.value = true
  loaded.value = false
  errorMessage.value = ''

  try {
    const id = employmentId.value.trim() === '' ? null : Number(employmentId.value)
    if (id !== null && (!Number.isSafeInteger(id) || id < 1)) {
      errorMessage.value = '任职编号必须为正整数。'
      rows.value = []
      return
    }
    rows.value = await listRecords(id, businessDate())
  } catch (reason) {
    rows.value = []
    captureError(reason)
  } finally {
    loading.value = false
    loaded.value = true
  }
}

async function exportRecords(): Promise<void> {
  exportError.value = ''
  if (exportFields.value.length === 0) {
    exportError.value = '请至少选择一个导出字段。'
    return
  }
  const id = employmentId.value.trim() === '' ? null : Number(employmentId.value)

  exporting.value = true
  try {
    const exported = await exportTrainingRecords(exportFields.value, id, businessDate())
    exportError.value = exported.length === 0 ? '当前筛选没有可导出的档案行。' : `已导出 ${exported.length} 行，导出动作已写入敏感访问审计。`
  } catch (reason) {
    exportError.value = trainingActionErrorCopy(reason)
  } finally {
    exporting.value = false
  }
}

onMounted(load)
</script>

<template>
  <section class="training-panel" data-testid="training-records-panel">
    <header class="training-panel__header">
      <div>
        <h2>个人培训档案</h2>
        <p>档案由报名、完成、签到、考试版本与反馈实时聚合；学时只从签到/签退派生。</p>
      </div>
      <button
        v-if="canRead"
        type="button"
        class="training-panel__secondary"
        :disabled="exporting"
        data-testid="training-record-export"
        @click="exportRecords"
      >
        导出
      </button>
    </header>

    <section v-if="!canRead" class="training-panel__forbidden" role="alert" data-testid="training-records-forbidden">
      <h3>无权查看培训档案</h3>
      <p>当前账号缺少 training.record.read 能力，不会发起档案读取或导出。</p>
    </section>
    <template v-else>
      <div class="training-panel__filters">
        <label>任职编号<el-input v-model="employmentId" placeholder="留空查看全部" data-testid="training-record-employment" /></label>
        <button type="button" class="training-panel__secondary" @click="load">查询</button>
      </div>
      <p v-if="exportError !== ''" class="training-panel__warning" role="status" data-testid="training-record-export-status">{{ exportError }}</p>
      <LoadingState v-if="loading" :rows="5" label="正在加载培训档案" />
      <ErrorState
        v-else-if="errorMessage !== ''"
        title="培训档案暂时无法加载"
        :description="errorMessage"
        :code="errorCodeValue"
        :request-id="errorRequestId"
        @retry="load"
      />
      <EmptyState
        v-else-if="empty"
        title="还没有培训档案"
        description="档案只来自真实报名与培训生命周期事实；没有记录时不会生成占位行。"
      />
      <div v-else class="training-panel__table-wrap">
        <p class="training-panel__summary">档案 {{ rows.length }} 条 · 学时合计 {{ totalStudyMinutes }} 分钟</p>
        <table class="training-panel__table">
          <thead>
            <tr><th>任职</th><th>课程</th><th>场次日期</th><th>状态</th><th>结果</th><th>分数</th><th>学时</th><th>考试版本</th></tr>
          </thead>
          <tbody>
            <tr v-for="row in rows" :key="row.enrollmentId" :data-testid="`training-record-row-${row.enrollmentId}`">
              <td>{{ row.employmentId }}</td>
              <td>{{ row.courseCode ?? '—' }} · {{ row.courseTitle ?? '未关联课程' }}</td>
              <td>{{ row.sessionStartsOn ?? '—' }}</td>
              <td>{{ enrollmentStatusLabel(row.status) }}</td>
              <td>{{ row.completionResult === null ? '—' : COMPLETION_RESULT_LABELS[row.completionResult] }}</td>
              <td>{{ row.score ?? '—' }}</td>
              <td>{{ row.studyMinutes }} 分钟</td>
              <td>{{ row.latestExamVersion === null ? '—' : `v${row.latestExamVersion}` }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <details class="training-panel__export">
        <summary>导出字段白名单</summary>
        <div class="training-panel__field-grid">
          <el-checkbox
            v-for="field in RECORD_EXPORT_FIELDS"
            :key="field"
            :model-value="exportFields.includes(field)"
            :data-testid="`training-record-field-${field}`"
            @update:model-value="(checked: boolean | string | number) => { exportFields = Boolean(checked) ? [...exportFields, field] : exportFields.filter((item) => item !== field) }"
          >
            {{ field }}
          </el-checkbox>
        </div>
      </details>
    </template>
  </section>
</template>

<style scoped>
.training-panel { display: flex; flex-direction: column; gap: 12px; }
.training-panel__header { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; }
.training-panel__header h2 { margin: 0 0 4px; font-size: 15px; color: #0f172a; }
.training-panel__header p { margin: 0; color: #64748b; font-size: 12px; line-height: 1.6; }
.training-panel__filters { display: flex; gap: 8px; align-items: flex-end; }
.training-panel__filters label { display: flex; flex-direction: column; gap: 5px; min-width: 200px; color: #334155; font-size: 12px; }
.training-panel__secondary { padding: 6px 11px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; color: #1d4ed8; font-size: 12px; cursor: pointer; }
.training-panel__table-wrap { overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 10px; }
.training-panel__summary { margin: 0; padding: 8px 11px; border-bottom: 1px solid #e2e8f0; color: #475569; font-size: 11.5px; }
.training-panel__table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.training-panel__table th, .training-panel__table td { padding: 9px 11px; text-align: left; border-bottom: 1px solid #e2e8f0; white-space: nowrap; }
.training-panel__table th { background: #f8fafc; color: #64748b; font-weight: 600; }
.training-panel__warning { margin: 0; padding: 9px 12px; border-radius: 8px; background: #fffbeb; color: #92400e; font-size: 12px; }
.training-panel__forbidden { padding: 20px; border: 1px solid #fecaca; border-radius: 10px; background: #fef2f2; color: #991b1b; }
.training-panel__forbidden h3 { margin: 0 0 6px; font-size: 14px; }
.training-panel__forbidden p { margin: 0; font-size: 12px; }
.training-panel__export { border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px 12px; font-size: 12px; color: #334155; }
.training-panel__field-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 4px; margin-top: 8px; }
</style>
