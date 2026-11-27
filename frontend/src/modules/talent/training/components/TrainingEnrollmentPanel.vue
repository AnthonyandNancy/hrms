<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { ApiError, errorCode, errorCopy } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import LoadingState from '@/components/LoadingState.vue'
import TrainingEnrollmentTable from './TrainingEnrollmentTable.vue'
import {
  cancelEnrollment,
  completeEnrollment,
  enroll,
  isActiveEnrollment,
  listEmployees,
  listEnrollments,
  listSessions,
  trainingActionErrorCopy,
  type CompletionResult,
  type EmployeeOption,
  type EnrollmentDisplayRow,
  type TrainingEnrollment,
  type TrainingSession,
} from '../api'
import { formatBusinessDate, formatDateTimeInTimezone, todayBusinessDate } from '@/utils/format'

const props = defineProps<{
  sessionId: number
  selectedProgramId: number | null
}>()

defineOptions({ name: 'TrainingEnrollmentPanel' })

const session = useSessionStore()
const canRead = computed(() => session.can('training.enrollment.manage'))
const canComplete = computed(() => session.can('training.completion.manage'))

const loading = ref(false)
const loaded = ref(false)
const errorMessage = ref('')
const errorCodeValue = ref('')
const errorRequestId = ref<string | null>(null)
const partialNote = ref('')
const success = ref('')

const enrollments = ref<TrainingEnrollment[]>([])
const employees = ref<EmployeeOption[]>([])
const sessionRow = ref<TrainingSession | null>(null)

const enrollOpen = ref(false)
const enrollEmployeeId = ref('')
const submitting = ref(false)
const enrollError = ref('')

const completeOpen = ref(false)
const completeTarget = ref<EnrollmentDisplayRow | null>(null)
const completeResult = ref<CompletionResult>('PASS')
const completeScore = ref('')
const completeDate = ref(todayBusinessDate())
const completeAttended = ref(true)
const completeError = ref('')

const cancelOpen = ref(false)
const cancelTarget = ref<EnrollmentDisplayRow | null>(null)
const cancelReason = ref('')
const cancelError = ref('')

const employeeById = computed(() => new Map(employees.value.map((row) => [row.employmentId, row])))
const sessionLabel = computed(() => {
  if (sessionRow.value === null) return `场次 #${props.sessionId}`
  const starts = sessionRow.value.startsAt === null ? '' : ` ${formatDateTimeInTimezone(sessionRow.value.startsAt).slice(11)}`
  return `${formatBusinessDate(sessionRow.value.startsOn)}${starts}`
})
const displayRows = computed<EnrollmentDisplayRow[]>(() =>
  enrollments.value.map((row) => {
    const employee = employeeById.value.get(row.employmentId)
    return {
      ...row,
      employeeName: employee?.name ?? `员工 #${row.employmentId}`,
      employeeNo: employee?.employeeNo ?? '',
      sessionLabel: sessionLabel.value,
    }
  }),
)
const activeCount = computed(
  () => enrollments.value.filter((row) => isActiveEnrollment(row.status)).length,
)
const remaining = computed(() =>
  sessionRow.value === null ? null : Math.max(0, sessionRow.value.capacity - activeCount.value),
)
const empty = computed(
  () => loaded.value && !loading.value && errorMessage.value === '' && enrollments.value.length === 0,
)

function captureError(reason: unknown): void {
  errorCodeValue.value = errorCode(reason)
  errorRequestId.value = reason instanceof ApiError ? reason.requestId : null
  errorMessage.value =
    reason instanceof ApiError && reason.code === 'FORBIDDEN'
      ? '无权读取该场次报名。请确认已获得 training.enrollment.manage 能力。'
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
  partialNote.value = ''

  try {
    const [sessions, rows] = await Promise.all([
      listSessions(props.selectedProgramId, undefined),
      listEnrollments(props.sessionId),
    ])
    sessionRow.value = sessions.find((item) => item.id === props.sessionId) ?? null
    enrollments.value = rows

    if (session.can('people.employee.read')) {
      try {
        employees.value = await listEmployees()
      } catch (reason) {
        employees.value = []
        partialNote.value = `员工目录暂不可读，报名行仅显示员工编号：${trainingActionErrorCopy(reason)}`
      }
    } else {
      partialNote.value = '当前账号没有 people.employee.read 能力；报名行仅显示员工编号，不展示姓名。'
    }
  } catch (reason) {
    enrollments.value = []
    sessionRow.value = null
    captureError(reason)
  } finally {
    loading.value = false
    loaded.value = true
  }
}

function openEnroll(): void {
  enrollEmployeeId.value = employees.value[0]?.employmentId.toString() ?? ''
  enrollError.value = ''
  enrollOpen.value = true
}

async function submitEnrollment(): Promise<void> {
  enrollError.value = ''
  if (enrollEmployeeId.value === '') {
    enrollError.value = '请选择员工。'
    return
  }

  submitting.value = true
  try {
    const created = await enroll(props.sessionId, Number(enrollEmployeeId.value))
    enrollments.value = [created, ...enrollments.value.filter((row) => row.id !== created.id)]
    enrollOpen.value = false
    success.value = '报名成功，容量与名单来自服务端最新事实。'
  } catch (reason) {
    enrollError.value = trainingActionErrorCopy(reason)
  } finally {
    submitting.value = false
  }
}

function openComplete(row: EnrollmentDisplayRow): void {
  if (!canComplete.value || !isActiveEnrollment(row.status)) return
  completeTarget.value = row
  completeResult.value = 'PASS'
  completeScore.value = ''
  completeDate.value = todayBusinessDate()
  completeAttended.value = true
  completeError.value = ''
  completeOpen.value = true
}

async function submitCompletion(): Promise<void> {
  const target = completeTarget.value
  if (target === null) return

  const scoreText = String(completeScore.value).trim()
  const score = scoreText === '' ? null : Number(scoreText)
  if (score !== null && (!Number.isSafeInteger(score) || score < 0 || score > 100)) {
    completeError.value = '分数必须为 0 到 100 之间的整数。'
    return
  }

  submitting.value = true
  try {
    const updated = await completeEnrollment(target.id, {
      completionResult: completeResult.value,
      attended: completeAttended.value,
      score,
      completedOn: completeDate.value,
    })
    enrollments.value = enrollments.value.map((row) => (row.id === updated.id ? updated : row))
    completeOpen.value = false
    success.value = '培训完成已登记。'
  } catch (reason) {
    completeError.value = trainingActionErrorCopy(reason)
  } finally {
    submitting.value = false
  }
}

function openCancel(row: EnrollmentDisplayRow): void {
  if (!canRead.value || !isActiveEnrollment(row.status)) return
  cancelTarget.value = row
  cancelReason.value = ''
  cancelError.value = ''
  cancelOpen.value = true
}

async function submitCancel(): Promise<void> {
  const target = cancelTarget.value
  if (target === null) return

  submitting.value = true
  try {
    const updated = await cancelEnrollment(target.id, cancelReason.value.trim())
    enrollments.value = enrollments.value.map((row) => (row.id === updated.id ? updated : row))
    cancelOpen.value = false
    success.value = '报名已取消，名额已释放。'
  } catch (reason) {
    cancelError.value = trainingActionErrorCopy(reason)
  } finally {
    submitting.value = false
  }
}

watch(() => props.sessionId, () => void load(), { immediate: true })
watch(() => props.selectedProgramId, () => void load())
</script>

<template>
  <section class="training-panel" data-testid="training-enrollments-panel">
    <header class="training-panel__header">
      <div>
        <h2>场次报名</h2>
        <p>容量按有效报名实时计算；重复报名与重复取消都是幂等命令，不会新增第二行。</p>
      </div>
      <div class="training-panel__header-actions">
        <span v-if="remaining !== null" class="training-panel__meta">剩余 {{ remaining }} / 容量 {{ sessionRow?.capacity ?? '—' }}</span>
        <button v-if="canRead" type="button" class="training-panel__primary" data-testid="training-enroll-open" @click="openEnroll">
          报名
        </button>
      </div>
    </header>

    <section v-if="!canRead" class="training-panel__forbidden" role="alert" data-testid="training-enrollments-forbidden">
      <h3>无权查看报名</h3>
      <p>当前账号缺少 training.enrollment.manage 能力，不会发起报名读取。</p>
    </section>
    <template v-else>
      <p
        v-if="success !== ''"
        class="training-panel__success"
        role="status"
        :data-testid="success === '报名已取消，名额已释放。' ? 'training-enrollment-cancelled' : 'training-enrollment-feedback'"
      >
        {{ success }}
      </p>
      <p v-if="partialNote !== ''" class="training-panel__warning" role="status">{{ partialNote }}</p>
      <LoadingState v-if="loading" :rows="4" label="正在加载报名" />
      <ErrorState
        v-else-if="errorMessage !== ''"
        title="报名暂时无法加载"
        :description="errorMessage"
        :code="errorCodeValue"
        :request-id="errorRequestId"
        @retry="load"
      />
      <EmptyState
        v-else-if="empty"
        title="该场次还没有报名"
        description="具备 training.enrollment.manage 的账号可以为员工报名。"
      />
      <TrainingEnrollmentTable
        v-else
        :enrollments="displayRows"
        :capabilities="session.capabilities"
        :busy-enrollment-id="submitting ? completeTarget?.id ?? cancelTarget?.id ?? null : null"
        @complete="openComplete"
        @cancel="openCancel"
      />
    </template>

    <el-dialog v-model="enrollOpen" title="报名培训" width="460px" data-testid="training-enroll-dialog">
      <div class="training-panel__form">
        <label>员工
          <el-select v-model="enrollEmployeeId" data-testid="training-enroll-employee">
            <el-option value="" disabled label="请选择员工" />
            <el-option
              v-for="employee in employees"
              :key="employee.employmentId"
              :value="String(employee.employmentId)"
              :label="employee.employeeNo !== '' ? `${employee.name}（${employee.employeeNo}）` : employee.name"
            />
          </el-select>
        </label>
        <p class="training-panel__meta">{{ sessionLabel }}</p>
        <p v-if="enrollError !== ''" class="training-panel__error" role="alert" data-testid="training-enroll-error">{{ enrollError }}</p>
      </div>
      <template #footer>
        <el-button @click="enrollOpen = false">取消</el-button>
        <el-button type="primary" :loading="submitting" data-testid="training-enroll-submit" @click="submitEnrollment">确认报名</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="completeOpen" title="登记培训完成" width="480px" data-testid="training-complete-dialog">
      <div class="training-panel__form">
        <p v-if="completeTarget !== null" class="training-panel__meta">
          员工：{{ completeTarget.employeeName }}（{{ completeTarget.employeeNo || `#${completeTarget.employmentId}` }}）
        </p>
        <label>完成结果
          <el-select v-model="completeResult" data-testid="training-complete-result">
            <el-option value="PASS" label="通过" />
            <el-option value="FAIL" label="未通过" />
            <el-option value="ATTENDED" label="仅出席" />
          </el-select>
        </label>
        <label>完成日期<el-input v-model="completeDate" type="date" data-testid="training-complete-date" /></label>
        <label>考试分数（0–100，可留空）<el-input v-model="completeScore" type="number" min="0" max="100" data-testid="training-complete-score" /></label>
        <el-checkbox v-model="completeAttended" data-testid="training-complete-attended">本人实际出席</el-checkbox>
        <p v-if="completeError !== ''" class="training-panel__error" role="alert" data-testid="training-complete-error">{{ completeError }}</p>
      </div>
      <template #footer>
        <el-button @click="completeOpen = false">取消</el-button>
        <el-button type="primary" :loading="submitting" data-testid="training-complete-submit" @click="submitCompletion">确认登记</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="cancelOpen" title="取消报名" width="460px" data-testid="training-cancel-dialog">
      <div class="training-panel__form">
        <p v-if="cancelTarget !== null" class="training-panel__meta">
          确认取消 {{ cancelTarget.employeeName }}（{{ cancelTarget.employeeNo || `#${cancelTarget.employmentId}` }}）的报名？
        </p>
        <label>取消原因<el-input v-model="cancelReason" maxlength="500" data-testid="training-cancel-reason" /></label>
        <p v-if="cancelError !== ''" class="training-panel__error" role="alert" data-testid="training-cancel-error">{{ cancelError }}</p>
      </div>
      <template #footer>
        <el-button @click="cancelOpen = false">返回</el-button>
        <el-button type="danger" :loading="submitting" data-testid="training-cancel-submit" @click="submitCancel">确认取消报名</el-button>
      </template>
    </el-dialog>
  </section>
</template>

<style scoped>
.training-panel { display: flex; flex-direction: column; gap: 12px; }
.training-panel__header { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; }
.training-panel__header-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.training-panel__header h2 { margin: 0 0 4px; font-size: 15px; color: #0f172a; }
.training-panel__header p { margin: 0; color: #64748b; font-size: 12px; line-height: 1.6; }
.training-panel__primary { padding: 7px 12px; border: 1px solid #1d4ed8; border-radius: 8px; background: #1d4ed8; color: #fff; font-size: 12px; cursor: pointer; }
.training-panel__meta { margin: 0; color: #64748b; font-size: 12px; }
.training-panel__form { display: flex; flex-direction: column; gap: 10px; }
.training-panel__form label { display: flex; flex-direction: column; gap: 5px; font-size: 12.5px; color: #334155; }
.training-panel__error { margin: 0; color: #b91c1c; font-size: 12px; }
.training-panel__success { margin: 0; padding: 9px 12px; border-radius: 8px; background: #ecfdf5; color: #166534; font-size: 12px; }
.training-panel__warning { margin: 0; padding: 9px 12px; border-radius: 8px; background: #fffbeb; color: #92400e; font-size: 12px; }
.training-panel__forbidden { padding: 20px; border: 1px solid #fecaca; border-radius: 10px; background: #fef2f2; color: #991b1b; }
.training-panel__forbidden h3 { margin: 0 0 6px; font-size: 14px; }
.training-panel__forbidden p { margin: 0; font-size: 12px; }
</style>
