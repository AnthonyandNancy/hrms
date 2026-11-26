<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ApiError, errorCode, errorCopy } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import { formatBusinessDate, formatDateTimeInTimezone, formatMoney, todayBusinessDate } from '@/utils/format'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import LoadingState from '@/components/LoadingState.vue'
import TrainingEnrollmentTable from '../components/TrainingEnrollmentTable.vue'
import {
  COMPLETION_RESULT_LABELS,
  completeEnrollment,
  deliveryModeLabel,
  enroll,
  enrollmentStatusLabel,
  isActiveEnrollment,
  listEmployees,
  listEnrollments,
  listPrograms,
  listSessions,
  programStatusLabel,
  sessionStatusLabel,
  trainingActionErrorCopy,
  type CompletionResult,
  type EmployeeOption,
  type EnrollmentDisplayRow,
  type TrainingEnrollment,
  type TrainingProgram,
  type TrainingSession,
} from '../api'

defineOptions({ name: 'TrainingProgramDetailPage' })

const route = useRoute()
const router = useRouter()
const session = useSessionStore()

const canRead = computed(() => session.can('training.program.read'))
const canEnroll = computed(() => session.can('training.enrollment.manage'))
const canComplete = computed(() => session.can('training.completion.manage'))

const loading = ref(false)
const loaded = ref(false)
const errorMessage = ref('')
const errorRequestId = ref<string | null>(null)
const errorCodeValue = ref('')
const partialNote = ref('')

const program = ref<TrainingProgram | null>(null)
const sessions = ref<TrainingSession[]>([])
const enrollments = ref<TrainingEnrollment[]>([])
const employees = ref<EmployeeOption[]>([])

const successMessage = ref('')

const routeId = computed(() => {
  const value = route.params.id
  if (typeof value !== 'string' || !/^\d+$/.test(value)) return null
  const parsed = Number(value)
  return Number.isSafeInteger(parsed) && parsed > 0 ? parsed : null
})

const employeeById = computed(() => {
  const map = new Map<number, EmployeeOption>()
  for (const employee of employees.value) map.set(employee.employmentId, employee)
  return map
})

const sessionById = computed(() => {
  const map = new Map<number, TrainingSession>()
  for (const item of sessions.value) map.set(item.id, item)
  return map
})

function sessionLabel(item: TrainingSession): string {
  const date = formatBusinessDate(item.startsOn)
  const time = item.startsAt === null ? '' : ` ${formatDateTimeInTimezone(item.startsAt).slice(11)}`
  return `${date}${time}`
}

const programSessionIds = computed(() => new Set(sessions.value.map((item) => item.id)))

const programEnrollments = computed(() =>
  enrollments.value.filter((item) => programSessionIds.value.has(item.sessionId)),
)

const displayRows = computed<EnrollmentDisplayRow[]>(() =>
  programEnrollments.value.map((item) => {
    const employee = employeeById.value.get(item.employmentId)
    const itemSession = sessionById.value.get(item.sessionId)
    return {
      ...item,
      employeeName: employee?.name ?? `员工 #${item.employmentId}`,
      employeeNo: employee?.employeeNo ?? '',
      sessionLabel: itemSession === undefined ? `场次 #${item.sessionId}` : sessionLabel(itemSession),
    }
  }),
)

const hasSessions = computed(() => sessions.value.length > 0)
const canSubmitEnrollment = computed(
  () => canEnroll.value && hasSessions.value && selectedSession.value !== null && remainingForSelectedSession.value > 0,
)

const enrollOpen = ref(false)
const enrollEmployeeId = ref('')
const enrollSessionId = ref('')
const enrollSubmitting = ref(false)
const enrollError = ref('')
const enrollErrorKind = ref<'capacity' | 'other' | ''>('')

const selectedSession = computed(
  () => sessions.value.find((item) => String(item.id) === enrollSessionId.value) ?? null,
)

function sessionEnrolledCount(sessionId: number): number {
  return enrollments.value.filter(
    (item) => item.sessionId === sessionId && isActiveEnrollment(item.status),
  ).length
}

const remainingForSelectedSession = computed(() => {
  if (selectedSession.value === null) return 0
  return Math.max(0, selectedSession.value.capacity - sessionEnrolledCount(selectedSession.value.id))
})

const completeOpen = ref(false)
const completeTarget = ref<EnrollmentDisplayRow | null>(null)
const completeResult = ref<CompletionResult>('PASS')
const completeScore = ref('')
const completeDate = ref(todayBusinessDate())
const completeAttended = ref(true)
const completeSubmitting = ref(false)
const completeError = ref('')

const cancelOpen = ref(false)
const cancelTarget = ref<EnrollmentDisplayRow | null>(null)
const cancelError = ref('')
const cancelUnsupported = ref('')

function captureError(reason: unknown): void {
  errorCodeValue.value = errorCode(reason)
  if (reason instanceof ApiError) {
    errorRequestId.value = reason.requestId
    errorMessage.value =
      reason.code === 'FORBIDDEN'
        ? '无权读取该培训项目。请确认已获得 training.program.read 能力。'
        : reason.message || errorCopy(reason)
  } else {
    errorRequestId.value = null
    errorMessage.value = reason instanceof Error ? reason.message : '培训项目暂时无法加载。'
  }
}

async function load(): Promise<void> {
  const id = routeId.value
  if (!canRead.value || id === null) {
    loaded.value = true
    return
  }

  loading.value = true
  loaded.value = false
  errorMessage.value = ''
  errorRequestId.value = null
  errorCodeValue.value = ''
  partialNote.value = ''

  try {
    const programRows = await listPrograms()
    program.value = programRows.find((item) => item.id === id) ?? null
    if (program.value === null) {
      errorMessage.value = '未找到该培训项目，可能已被归档或不在当前数据范围内。'
      errorCodeValue.value = 'NOT_FOUND'
      return
    }

    const notes: string[] = []

    try {
      sessions.value = await listSessions(id)
    } catch (reason) {
      sessions.value = []
      notes.push(`培训场次暂不可读：${trainingActionErrorCopy(reason)}`)
    }

    try {
      enrollments.value = await listEnrollments()
    } catch (reason) {
      enrollments.value = []
      notes.push(`报名台账暂不可读：${trainingActionErrorCopy(reason)}`)
    }

    if (session.can('people.employee.read')) {
      try {
        employees.value = await listEmployees()
      } catch (reason) {
        employees.value = []
        notes.push(`员工目录暂不可读，报名行仅显示员工编号：${trainingActionErrorCopy(reason)}`)
      }
    } else {
      notes.push('当前账号没有 people.employee.read 能力；报名行仅显示员工编号，不展示姓名。')
    }

    partialNote.value = notes.join(' ')
  } catch (reason) {
    program.value = null
    sessions.value = []
    enrollments.value = []
    captureError(reason)
  } finally {
    loading.value = false
    loaded.value = true
  }
}

function openEnroll(): void {
  enrollEmployeeId.value = employees.value[0]?.employmentId.toString() ?? ''
  enrollSessionId.value = sessions.value
    .find((item) => sessionEnrolledCount(item.id) < item.capacity)
    ?.id.toString() ?? sessions.value[0]?.id.toString() ?? ''
  enrollError.value = ''
  enrollErrorKind.value = ''
  enrollOpen.value = true
}

async function submitEnrollment(): Promise<void> {
  enrollError.value = ''
  enrollErrorKind.value = ''

  if (enrollEmployeeId.value === '' || enrollSessionId.value === '') {
    enrollError.value = '请选择员工与培训场次。'
    return
  }

  if (selectedSession.value === null) {
    enrollError.value = '所选培训场次不存在。'
    return
  }

  if (remainingForSelectedSession.value <= 0) {
    enrollErrorKind.value = 'capacity'
    enrollError.value = `该场次名额已满（容量 ${selectedSession.value.capacity} 人），无法继续报名。`
    return
  }

  enrollSubmitting.value = true
  try {
    await enroll(Number(enrollSessionId.value), Number(enrollEmployeeId.value))
    enrollOpen.value = false
    successMessage.value = '报名成功，报名台账已按服务端最新数据刷新。'
    await load()
  } catch (reason) {
    enrollErrorKind.value =
      reason instanceof ApiError && reason.serverReason === 'TRAINING_CAPACITY_REACHED' ? 'capacity' : 'other'
    enrollError.value = trainingActionErrorCopy(reason)
  } finally {
    enrollSubmitting.value = false
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

  completeError.value = ''
  const scoreText = String(completeScore.value).trim()
  const score = scoreText === '' ? null : Number(scoreText)
  if (score !== null && (!Number.isSafeInteger(score) || score < 0 || score > 100)) {
    completeError.value = '分数必须为 0 到 100 之间的整数。'
    return
  }
  if (!/^\d{4}-\d{2}-\d{2}$/.test(completeDate.value)) {
    completeError.value = '请选择有效的完成日期。'
    return
  }

  completeSubmitting.value = true
  try {
    await completeEnrollment(target.id, {
      completionResult: completeResult.value,
      attended: completeAttended.value,
      score,
      completedOn: completeDate.value,
    })
    completeOpen.value = false
    successMessage.value = '培训完成已登记，服务端已写入完成日期。'
    await load()
  } catch (reason) {
    completeError.value = trainingActionErrorCopy(reason)
  } finally {
    completeSubmitting.value = false
  }
}

function openCancel(row: EnrollmentDisplayRow): void {
  if (!canEnroll.value || !isActiveEnrollment(row.status)) return
  cancelTarget.value = row
  cancelError.value = ''
  cancelUnsupported.value = ''
  cancelOpen.value = true
}

function confirmCancel(): void {
  const target = cancelTarget.value
  if (target === null) return

  // Cancellation has no backend command yet. The confirmation is explicit and
  // the page reports the exact missing contract rather than mutating locally.
  cancelUnsupported.value = `/v1/training/enrollments/${target.id}/cancel`
  cancelOpen.value = false
}

onMounted(() => {
  void load()
})
</script>

<template>
  <div class="training-detail" data-page="training-program-detail">
    <header class="training-detail__header">
      <div>
        <p class="training-detail__breadcrumb">
          <button type="button" class="training-detail__back" @click="router.push('/training')">培训工作台</button>
          · 课程详情
        </p>
        <h1 class="training-detail__title">{{ program?.title ?? '培训项目详情' }}</h1>
        <p v-if="program !== null" class="training-detail__code">
          {{ program.code }} · {{ programStatusLabel(program.status) }} ·
          {{ deliveryModeLabel(program.deliveryMode) }}
        </p>
      </div>
      <div class="training-detail__header-actions">
        <button type="button" class="training-detail__secondary" :disabled="loading" @click="load">
          刷新
        </button>
        <button
          v-if="canEnroll"
          type="button"
          class="training-detail__primary"
          data-testid="training-enroll-open"
          @click="openEnroll"
        >
          报名
        </button>
      </div>
    </header>

    <section v-if="!canRead" class="training-detail__state" role="alert" data-testid="training-detail-forbidden">
      <h2>无权查看培训项目</h2>
      <p>当前账号缺少 training.program.read 能力，页面不会发起任何培训读取请求。</p>
    </section>

    <template v-else>
      <p v-if="successMessage !== ''" class="training-detail__success" role="status">{{ successMessage }}</p>
      <p v-if="partialNote !== ''" class="training-detail__warning" role="status" data-testid="training-detail-partial">
        部分数据：{{ partialNote }}
      </p>
      <p v-if="cancelUnsupported !== ''" class="training-detail__warning" role="alert" data-testid="training-cancel-unsupported">
        服务端尚未提供取消报名接口，未提交任何状态变更。缺少的路由：<strong>{{ cancelUnsupported }}</strong>。
      </p>

      <ErrorState
        v-if="errorMessage !== ''"
        title="培训项目暂时无法加载"
        :description="errorMessage"
        :code="errorCodeValue"
        :request-id="errorRequestId"
        @retry="load"
      />
      <LoadingState v-else-if="loading" :rows="8" label="正在加载培训项目" />

      <template v-else-if="program !== null">
        <section class="training-detail__summary" aria-label="项目概况">
          <div class="training-detail__metric">
            <span class="training-detail__metric-label">名额使用</span>
            <strong>{{ program.enrolledCount }} / {{ program.capacity }}</strong>
            <span class="training-detail__metric-hint">
              {{ program.remainingSeats > 0 ? `剩余 ${program.remainingSeats} 个名额` : '名额已满' }}
            </span>
          </div>
          <div class="training-detail__metric">
            <span class="training-detail__metric-label">场次</span>
            <strong>{{ program.sessionCount }}</strong>
            <span class="training-detail__metric-hint">当前项目已排期场次</span>
          </div>
          <div class="training-detail__metric">
            <span class="training-detail__metric-label">已完成</span>
            <strong>{{ program.completedCount }}</strong>
            <span class="training-detail__metric-hint">来自报名台账真实完成行</span>
          </div>
          <div class="training-detail__metric">
            <span class="training-detail__metric-label">培训成本</span>
            <strong>
              <template v-if="program.trainingCostCents === undefined">已按字段权限隐藏</template>
              <template v-else>{{ formatMoney(program.trainingCostCents) }}</template>
            </strong>
            <span class="training-detail__metric-hint">课程成本以整数最小单位返回</span>
          </div>
        </section>

        <section class="training-detail__panel" aria-label="培训场次">
          <div class="training-detail__panel-header">
            <h2>培训场次</h2>
            <p>时间、地点与容量均来自服务端场次台账；剩余名额按实时报名行计算。</p>
          </div>

          <EmptyState
            v-if="!hasSessions"
            title="该项目还没有培训场次"
            description="请先在培训场次页面排期后，再为本项目报名。"
          />

          <table v-else data-testid="training-session-table">
            <caption class="visually-hidden">培训场次与剩余名额</caption>
            <thead>
              <tr>
                <th scope="col">日期</th>
                <th scope="col">时间</th>
                <th scope="col">地点</th>
                <th scope="col">容量</th>
                <th scope="col">状态</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in sessions" :key="item.id" :data-testid="`training-session-${item.id}`">
                <td>{{ formatBusinessDate(item.startsOn) }}</td>
                <td>
                  {{ item.startsAt === null ? '—' : formatDateTimeInTimezone(item.startsAt).slice(11) }}
                  <template v-if="item.endsAt !== null">–{{ formatDateTimeInTimezone(item.endsAt).slice(11) }}</template>
                </td>
                <td>{{ item.location ?? '未设置地点' }}</td>
                <td>
                  {{ sessionEnrolledCount(item.id) }} / {{ item.capacity }}
                  <span class="training-detail__meta">
                    {{ sessionEnrolledCount(item.id) < item.capacity ? `剩余 ${item.capacity - sessionEnrolledCount(item.id)} 个名额` : '名额已满' }}
                  </span>
                </td>
                <td>{{ sessionStatusLabel(item.status) }}</td>
              </tr>
            </tbody>
          </table>
        </section>

        <section class="training-detail__panel" aria-label="报名名单">
          <div class="training-detail__panel-header">
            <h2>报名名单</h2>
            <p>
              状态与完成结果由服务端维护；登记完成要求报名处于“已报名”或“已签到”状态。
              培训档案可通过员工链接查看，本页不内嵌员工编辑器。
            </p>
          </div>
          <TrainingEnrollmentTable
            :enrollments="displayRows"
            :capabilities="session.capabilities"
            :busy-enrollment-id="completeSubmitting ? completeTarget?.id ?? null : null"
            @complete="openComplete"
            @cancel="openCancel"
          />
        </section>
      </template>
    </template>

    <el-dialog
      v-model="enrollOpen"
      title="报名培训"
      width="460px"
      :close-on-click-modal="false"
      data-testid="training-enroll-dialog"
    >
      <div class="training-detail__form">
        <label>
          <span>员工</span>
          <select v-model="enrollEmployeeId" data-testid="training-enroll-employee">
            <option value="" disabled>请选择员工</option>
            <option v-for="employee in employees" :key="employee.employmentId" :value="String(employee.employmentId)">
              {{ employee.name }}<template v-if="employee.employeeNo !== ''">（{{ employee.employeeNo }}）</template>
            </option>
          </select>
        </label>
        <label>
          <span>培训场次</span>
          <select v-model="enrollSessionId" data-testid="training-enroll-session">
            <option value="" disabled>请选择场次</option>
            <option v-for="item in sessions" :key="item.id" :value="String(item.id)">
              {{ sessionLabel(item) }} · 容量 {{ item.capacity }}
            </option>
          </select>
        </label>
        <p class="training-detail__seats" data-testid="training-enroll-seats">
          <template v-if="selectedSession === null">选择场次后显示剩余名额。</template>
          <template v-else-if="remainingForSelectedSession > 0">
            该场次剩余 {{ remainingForSelectedSession }} 个名额（容量 {{ selectedSession.capacity }}）。
          </template>
          <template v-else>该场次名额已满，无法继续报名。</template>
        </p>
        <p v-if="enrollError !== ''" class="training-detail__error" role="alert" data-testid="training-enroll-error">
          {{ enrollError }}
        </p>
      </div>
      <template #footer>
        <el-button :disabled="enrollSubmitting" @click="enrollOpen = false">取消</el-button>
        <el-button
          type="primary"
          :disabled="!canSubmitEnrollment"
          :loading="enrollSubmitting"
          data-testid="training-enroll-submit"
          @click="submitEnrollment"
        >
          确认报名
        </el-button>
      </template>
    </el-dialog>

    <el-dialog
      v-model="completeOpen"
      title="登记培训完成"
      width="480px"
      :close-on-click-modal="false"
      data-testid="training-complete-dialog"
    >
      <div class="training-detail__form">
        <p v-if="completeTarget !== null" class="training-detail__target">
          员工：{{ completeTarget.employeeName }}（{{ completeTarget.employeeNo || `#${completeTarget.employmentId}` }}）
        </p>
        <label>
          <span>完成结果</span>
          <select v-model="completeResult" data-testid="training-complete-result">
            <option v-for="(label, value) in COMPLETION_RESULT_LABELS" :key="value" :value="value">
              {{ label }}
            </option>
          </select>
        </label>
        <label>
          <span>完成日期</span>
          <input v-model="completeDate" type="date" data-testid="training-complete-date" />
        </label>
        <label>
          <span>考试分数（0–100，可留空）</span>
          <input v-model="completeScore" type="number" min="0" max="100" data-testid="training-complete-score" />
        </label>
        <label class="training-detail__checkbox">
          <input v-model="completeAttended" type="checkbox" data-testid="training-complete-attended" />
          <span>本人实际出席</span>
        </label>
        <p v-if="completeError !== ''" class="training-detail__error" role="alert" data-testid="training-complete-error">
          {{ completeError }}
        </p>
      </div>
      <template #footer>
        <el-button :disabled="completeSubmitting" @click="completeOpen = false">取消</el-button>
        <el-button
          type="primary"
          :loading="completeSubmitting"
          data-testid="training-complete-submit"
          @click="submitCompletion"
        >
          确认登记
        </el-button>
      </template>
    </el-dialog>

    <el-dialog
      v-model="cancelOpen"
      title="取消报名"
      width="460px"
      :close-on-click-modal="false"
      data-testid="training-cancel-dialog"
    >
      <div class="training-detail__form">
        <p v-if="cancelTarget !== null" class="training-detail__target">
          确认取消 {{ cancelTarget.employeeName }}（{{ cancelTarget.employeeNo || `#${cancelTarget.employmentId}` }}）
          在 {{ cancelTarget.sessionLabel }} 的报名？
        </p>
        <p class="training-detail__meta">
          当前状态：{{ cancelTarget === null ? '—' : enrollmentStatusLabel(cancelTarget.status) }}。取消后名额会释放，
          历史签到与考试记录不会删除。
        </p>
        <p v-if="cancelError !== ''" class="training-detail__error" role="alert">{{ cancelError }}</p>
      </div>
      <template #footer>
        <el-button @click="cancelOpen = false">返回</el-button>
        <el-button type="danger" data-testid="training-cancel-submit" @click="confirmCancel">
          确认取消报名
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.training-detail {
  display: flex;
  flex-direction: column;
  gap: 16px;
  padding: 20px 24px 48px;
}

.training-detail__header {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  align-items: flex-start;
}

.training-detail__breadcrumb {
  margin: 0 0 4px;
  color: #64748b;
  font-size: 12px;
}

.training-detail__back {
  border: none;
  background: none;
  padding: 0;
  color: #1d4ed8;
  font-size: 12px;
  cursor: pointer;
}

.training-detail__title {
  margin: 0 0 4px;
  font-size: 22px;
  color: #0f172a;
}

.training-detail__code {
  margin: 0;
  color: #475569;
  font-size: 12.5px;
}

.training-detail__header-actions {
  display: flex;
  gap: 8px;
}

.training-detail__primary,
.training-detail__secondary {
  padding: 8px 14px;
  border-radius: 8px;
  border: 1px solid #cbd5e1;
  background: #fff;
  font-size: 12.5px;
  cursor: pointer;
}

.training-detail__primary {
  background: #1d4ed8;
  border-color: #1d4ed8;
  color: #fff;
}

.training-detail__success,
.training-detail__warning {
  margin: 0;
  padding: 10px 12px;
  border-radius: 8px;
  font-size: 12.5px;
  line-height: 1.6;
}

.training-detail__success {
  background: #ecfdf5;
  color: #166534;
}

.training-detail__warning {
  background: #fffbeb;
  color: #92400e;
}

.training-detail__state {
  padding: 24px;
  border: 1px solid #fecaca;
  border-radius: 12px;
  background: #fef2f2;
  color: #991b1b;
}

.training-detail__summary {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 10px;
}

.training-detail__metric {
  display: flex;
  flex-direction: column;
  gap: 3px;
  padding: 12px 14px;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  background: #fff;
}

.training-detail__metric-label {
  color: #64748b;
  font-size: 11.5px;
}

.training-detail__metric strong {
  font-size: 17px;
  color: #0f172a;
}

.training-detail__metric-hint {
  color: #94a3b8;
  font-size: 11px;
}

.training-detail__panel {
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  background: #fff;
  overflow: hidden;
}

.training-detail__panel-header {
  padding: 12px 14px;
  border-bottom: 1px solid #e2e8f0;
}

.training-detail__panel-header h2 {
  margin: 0 0 2px;
  font-size: 14px;
  color: #0f172a;
}

.training-detail__panel-header p {
  margin: 0;
  color: #64748b;
  font-size: 11.5px;
  line-height: 1.55;
}

.training-detail__panel table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12.5px;
}

.training-detail__panel th,
.training-detail__panel td {
  padding: 8px 12px;
  text-align: left;
  border-bottom: 1px solid #e2e8f0;
}

.training-detail__panel th {
  background: #f8fafc;
  color: #64748b;
  font-weight: 600;
}

.training-detail__meta {
  display: block;
  color: #94a3b8;
  font-size: 11px;
}

.training-detail__form {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.training-detail__form label {
  display: flex;
  flex-direction: column;
  gap: 5px;
  font-size: 12.5px;
  color: #334155;
}

.training-detail__form input,
.training-detail__form select {
  height: 34px;
  padding: 0 9px;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  font-size: 13px;
  background: #fff;
}

.training-detail__checkbox {
  flex-direction: row !important;
  align-items: center;
  gap: 8px !important;
}

.training-detail__checkbox input {
  width: 16px;
  height: 16px;
}

.training-detail__seats,
.training-detail__target {
  margin: 0;
  font-size: 12.5px;
  color: #334155;
}

.training-detail__error {
  margin: 0;
  color: #b91c1c;
  font-size: 12px;
  line-height: 1.5;
}
</style>
