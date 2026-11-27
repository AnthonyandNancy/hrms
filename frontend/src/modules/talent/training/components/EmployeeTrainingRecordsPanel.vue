<script setup lang="ts">
import { computed, ref } from 'vue'
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
} from '@/modules/talent/training/api'

/**
 * Read-only training records for the employee profile.
 *
 * People owns the profile; Talent only exposes a live projection here. This
 * component has no create/update/complete action and never accepts study
 * minutes or certificates from the client. Export uses the backend whitelist
 * and leaves its sensitive-access audit to the API.
 */
const props = defineProps<{ employmentId: number }>()

defineOptions({ name: 'EmployeeTrainingRecordsPanel' })

const session = useSessionStore()
const canRead = computed(() => session.can('training.record.read'))
const loading = ref(false)
const loaded = ref(false)
const errorMessage = ref('')
const errorCodeValue = ref('')
const errorRequestId = ref<string | null>(null)
const rows = ref<TrainingRecord[]>([])
const exporting = ref(false)
const exportStatus = ref('')
const exportFields = ref<RecordExportField[]>([
  'employment_id',
  'course_code',
  'course_title',
  'status',
  'completion_result',
  'study_minutes',
  'latest_exam_version',
])

const empty = computed(
  () => loaded.value && !loading.value && errorMessage.value === '' && rows.value.length === 0,
)
const visibleFields = RECORD_EXPORT_FIELDS.filter((field) =>
  ['employment_id', 'course_code', 'course_title', 'status', 'completion_result', 'score', 'study_minutes', 'latest_exam_version'].includes(field),
)

function captureError(reason: unknown): void {
  errorCodeValue.value = errorCode(reason)
  errorRequestId.value = reason instanceof ApiError ? reason.requestId : null
  errorMessage.value =
    reason instanceof ApiError && reason.code === 'FORBIDDEN'
      ? '无权读取该员工的培训记录。请确认已获得 training.record.read 能力。'
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
    rows.value = await listRecords(props.employmentId, businessDate())
  } catch (reason) {
    rows.value = []
    captureError(reason)
  } finally {
    loading.value = false
    loaded.value = true
  }
}

async function exportRecords(): Promise<void> {
  exportStatus.value = ''
  if (exportFields.value.length === 0) {
    exportStatus.value = '请至少选择一个导出字段。'
    return
  }
  exporting.value = true
  try {
    const exported = await exportTrainingRecords(exportFields.value, props.employmentId, businessDate())
    exportStatus.value =
      exported.length === 0 ? '该员工暂无培训记录可导出。' : `已导出 ${exported.length} 行；导出动作已记录敏感访问审计。`
  } catch (reason) {
    exportStatus.value = trainingActionErrorCopy(reason)
  } finally {
    exporting.value = false
  }
}

function toggleField(field: RecordExportField, checked: boolean): void {
  exportFields.value = checked
    ? [...exportFields.value, field]
    : exportFields.value.filter((item) => item !== field)
}

void load()
</script>

<template>
  <section class="employee-training" data-testid="employee-training-records">
    <header class="employee-training__header">
      <div>
        <h2>培训记录</h2>
        <p>只读投影：课程、学时、完成结果与最新考试版本来自培训模块实时聚合。员工档案不提供手工写入。</p>
      </div>
      <button
        v-if="canRead"
        type="button"
        class="employee-training__secondary"
        :disabled="exporting"
        data-testid="employee-training-export"
        @click="exportRecords"
      >
        导出
      </button>
    </header>

    <section v-if="!canRead" class="employee-training__forbidden" role="alert" data-testid="employee-training-forbidden">
      <h3>无权查看培训记录</h3>
      <p>当前账号缺少 training.record.read 能力；此分区只读，不会回写培训事实。</p>
    </section>
    <template v-else>
      <p v-if="exportStatus !== ''" class="employee-training__status" role="status" data-testid="employee-training-export-status">{{ exportStatus }}</p>
      <LoadingState v-if="loading" :rows="3" label="正在加载培训记录" />
      <ErrorState
        v-else-if="errorMessage !== ''"
        title="培训记录暂时无法加载"
        :description="errorMessage"
        :code="errorCodeValue"
        :request-id="errorRequestId"
        @retry="load"
      />
      <EmptyState
        v-else-if="empty"
        title="暂无培训记录"
        description="该员工尚无可展示的报名、完成或学时事实；不会生成占位证书或手工档案。"
      />
      <div v-else class="employee-training__table-wrap">
        <table class="employee-training__table">
          <thead><tr><th>课程</th><th>场次日期</th><th>状态</th><th>结果</th><th>学时</th><th>考试版本</th></tr></thead>
          <tbody>
            <tr v-for="row in rows" :key="row.enrollmentId" :data-testid="`employee-training-row-${row.enrollmentId}`">
              <td>{{ row.courseCode ?? '—' }} · {{ row.courseTitle ?? '未关联课程' }}</td>
              <td>{{ row.sessionStartsOn ?? '—' }}</td>
              <td>{{ enrollmentStatusLabel(row.status) }}</td>
              <td>{{ row.completionResult === null ? '—' : COMPLETION_RESULT_LABELS[row.completionResult] }}</td>
              <td>{{ row.studyMinutes }} 分钟</td>
              <td>{{ row.latestExamVersion === null ? '—' : `v${row.latestExamVersion}` }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <details v-if="canRead" class="employee-training__export">
        <summary>导出字段白名单</summary>
        <div class="employee-training__fields">
          <el-checkbox
            v-for="field in visibleFields"
            :key="field"
            :model-value="exportFields.includes(field)"
            :data-testid="`employee-training-field-${field}`"
            @update:model-value="(checked: boolean | string | number) => toggleField(field, checked === true)"
          >
            {{ field }}
          </el-checkbox>
        </div>
      </details>
    </template>
  </section>
</template>

<style scoped>
.employee-training { display: flex; flex-direction: column; gap: 10px; border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; padding: 14px; }
.employee-training__header { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; }
.employee-training__header h2 { margin: 0 0 4px; font-size: 15px; color: #0f172a; }
.employee-training__header p { margin: 0; color: #64748b; font-size: 12px; line-height: 1.6; }
.employee-training__secondary { padding: 5px 10px; border: 1px solid #cbd5e1; border-radius: 7px; background: #fff; color: #1d4ed8; font-size: 12px; cursor: pointer; }
.employee-training__table-wrap { overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 9px; }
.employee-training__table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.employee-training__table th, .employee-training__table td { padding: 8px 10px; text-align: left; border-bottom: 1px solid #e2e8f0; white-space: nowrap; }
.employee-training__table th { background: #f8fafc; color: #64748b; font-weight: 600; }
.employee-training__status { margin: 0; padding: 8px 10px; border-radius: 8px; background: #fffbeb; color: #92400e; font-size: 12px; }
.employee-training__forbidden { padding: 14px; border: 1px solid #fecaca; border-radius: 9px; background: #fef2f2; color: #991b1b; }
.employee-training__forbidden h3 { margin: 0 0 5px; font-size: 14px; }
.employee-training__forbidden p { margin: 0; font-size: 12px; }
.employee-training__export { color: #334155; font-size: 12px; }
.employee-training__fields { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 4px; margin-top: 8px; }
</style>
