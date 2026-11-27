<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { ApiError, errorCode, errorCopy } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import LoadingState from '@/components/LoadingState.vue'
import { checkIn, listCheckins, listEnrollments, trainingActionErrorCopy, type TrainingCheckin } from '../api'
import { formatDateTimeInTimezone } from '@/utils/format'

const props = defineProps<{ sessionId: number }>()

defineOptions({ name: 'TrainingCheckinPanel' })

const session = useSessionStore()
const canManage = computed(() => session.can('training.checkin.manage'))
const loading = ref(false)
const loaded = ref(false)
const errorMessage = ref('')
const errorCodeValue = ref('')
const errorRequestId = ref<string | null>(null)
const rows = ref<TrainingCheckin[]>([])
const enrolledIds = ref<number[]>([])
const success = ref('')

const formOpen = ref(false)
const employmentId = ref('')
const checkedInAt = ref('')
const checkedOutAt = ref('')
const submitting = ref(false)
const formError = ref('')
const lastInsertedId = ref<number | null>(null)

const empty = computed(
  () => loaded.value && !loading.value && errorMessage.value === '' && rows.value.length === 0,
)
const totalStudyMinutes = computed(() => rows.value.reduce((sum, row) => sum + row.studyMinutes, 0))

function captureError(reason: unknown): void {
  errorCodeValue.value = errorCode(reason)
  errorRequestId.value = reason instanceof ApiError ? reason.requestId : null
  errorMessage.value =
    reason instanceof ApiError && reason.code === 'FORBIDDEN'
      ? '无权读取签到台账。请确认已获得 training.checkin.manage 能力。'
      : trainingActionErrorCopy(reason) || errorCopy(reason)
}

async function load(): Promise<void> {
  if (!canManage.value) {
    loaded.value = true
    return
  }

  loading.value = true
  loaded.value = false
  errorMessage.value = ''

  try {
    const [checkins, enrollments] = await Promise.all([
      listCheckins(props.sessionId),
      listEnrollments(props.sessionId).catch(() => []),
    ])
    rows.value = checkins
    enrolledIds.value = enrollments
      .filter((row) => row.status === 'ENROLLED' || row.status === 'ATTENDED' || row.status === 'COMPLETED')
      .map((row) => row.employmentId)
  } catch (reason) {
    rows.value = []
    captureError(reason)
  } finally {
    loading.value = false
    loaded.value = true
  }
}

function openCreate(): void {
  if (!canManage.value) return
  employmentId.value = enrolledIds.value[0]?.toString() ?? ''
  checkedInAt.value = ''
  checkedOutAt.value = ''
  formError.value = ''
  formOpen.value = true
}

async function submit(): Promise<void> {
  formError.value = ''
  if (employmentId.value === '') {
    formError.value = '请选择员工。'
    return
  }
  if (!enrolledIds.value.includes(Number(employmentId.value))) {
    formError.value = '该员工尚未报名此场次，不能登记签到。'
    return
  }
  if (checkedInAt.value !== '' && checkedOutAt.value !== '' && checkedOutAt.value < checkedInAt.value) {
    formError.value = '签退时间不得早于签到时间。'
    return
  }

  submitting.value = true
  try {
    const created = await checkIn(
      props.sessionId,
      Number(employmentId.value),
      checkedInAt.value === '' ? null : checkedInAt.value,
      checkedOutAt.value === '' ? null : checkedOutAt.value,
    )
    rows.value = [created, ...rows.value.filter((row) => row.id !== created.id)]
    lastInsertedId.value = created.id
    formOpen.value = false
    success.value = '签到已记录；学时由签到/签退时间派生。'
  } catch (reason) {
    formError.value = trainingActionErrorCopy(reason)
  } finally {
    submitting.value = false
  }
}

watch(() => props.sessionId, () => void load(), { immediate: true })
</script>

<template>
  <section class="training-panel" data-testid="training-checkins-panel">
    <header class="training-panel__header">
      <div>
        <h2>场次签到</h2>
        <p>同一场次与同一任职只保留一条签到；学时只从服务端时间证据派生，页面不接受手工学时。</p>
      </div>
      <button v-if="canManage" type="button" class="training-panel__primary" data-testid="training-checkin-create" @click="openCreate">
        登记签到
      </button>
    </header>

    <section v-if="!canManage" class="training-panel__forbidden" role="alert" data-testid="training-checkins-forbidden">
      <h3>无权查看签到</h3>
      <p>当前账号缺少 training.checkin.manage 能力，不会发起签到读取。</p>
    </section>
    <template v-else>
      <p v-if="success !== ''" class="training-panel__success" role="status">{{ success }}</p>
      <LoadingState v-if="loading" :rows="4" label="正在加载签到" />
      <ErrorState
        v-else-if="errorMessage !== ''"
        title="签到暂时无法加载"
        :description="errorMessage"
        :code="errorCodeValue"
        :request-id="errorRequestId"
        @retry="load"
      />
      <EmptyState
        v-else-if="empty"
        title="该场次还没有签到"
        description="必须已有有效报名才能登记签到；学时在签退后由服务端计算。"
      />
      <div v-else class="training-panel__table-wrap">
        <p class="training-panel__summary">
          签到 {{ rows.length }} 条 · 学时合计 {{ totalStudyMinutes }} 分钟（由签到/签退派生）
        </p>
        <table class="training-panel__table">
          <thead>
            <tr><th>签到编号</th><th>员工</th><th>签到</th><th>签退</th><th>学时</th></tr>
          </thead>
          <tbody>
            <tr v-for="row in rows" :key="row.id" :data-testid="`training-checkin-row-${row.id}`">
              <td class="mono">#{{ row.id }}</td>
              <td>#{{ row.employmentId }}</td>
              <td>{{ formatDateTimeInTimezone(row.checkedInAt) }}</td>
              <td>{{ row.checkedOutAt === null ? '未签退' : formatDateTimeInTimezone(row.checkedOutAt) }}</td>
              <td :data-testid="row.id === lastInsertedId ? 'training-checkin-study-minutes' : undefined">{{ row.studyMinutes }} 分钟</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <el-dialog v-model="formOpen" title="登记签到" width="500px" data-testid="training-checkin-dialog">
      <div class="training-panel__form">
        <p class="training-panel__meta">场次 #{{ sessionId }}</p>
        <label>已报名员工
          <el-select v-model="employmentId" data-testid="training-checkin-employment">
            <el-option value="" disabled label="请选择员工" />
            <el-option v-for="id in enrolledIds" :key="id" :value="String(id)" :label="`任职 #${id}`" />
          </el-select>
        </label>
        <label>签到时间<el-input v-model="checkedInAt" type="datetime-local" data-testid="training-checkin-in" /></label>
        <label>签退时间<el-input v-model="checkedOutAt" type="datetime-local" data-testid="training-checkin-out" /></label>
        <p class="training-panel__hint">保留空值时由服务端时钟记录；签退前学时为 0。</p>
        <p v-if="formError !== ''" class="training-panel__error" role="alert" data-testid="training-checkin-error">{{ formError }}</p>
      </div>
      <template #footer>
        <el-button @click="formOpen = false">取消</el-button>
        <el-button type="primary" :loading="submitting" data-testid="training-checkin-submit" @click="submit">保存</el-button>
      </template>
    </el-dialog>
  </section>
</template>

<style scoped>
.training-panel { display: flex; flex-direction: column; gap: 12px; }
.training-panel__header { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; }
.training-panel__header h2 { margin: 0 0 4px; font-size: 15px; color: #0f172a; }
.training-panel__header p { margin: 0; color: #64748b; font-size: 12px; line-height: 1.6; }
.training-panel__primary { padding: 7px 12px; border: 1px solid #1d4ed8; border-radius: 8px; background: #1d4ed8; color: #fff; font-size: 12px; cursor: pointer; }
.training-panel__table-wrap { overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 10px; }
.training-panel__summary { margin: 0; padding: 8px 11px; border-bottom: 1px solid #e2e8f0; color: #475569; font-size: 11.5px; }
.training-panel__table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.training-panel__table th, .training-panel__table td { padding: 9px 11px; text-align: left; border-bottom: 1px solid #e2e8f0; white-space: nowrap; }
.training-panel__table th { background: #f8fafc; color: #64748b; font-weight: 600; }
.training-panel__form { display: flex; flex-direction: column; gap: 10px; }
.training-panel__form label { display: flex; flex-direction: column; gap: 5px; font-size: 12.5px; color: #334155; }
.training-panel__meta, .training-panel__hint { margin: 0; color: #64748b; font-size: 12px; }
.training-panel__error { margin: 0; color: #b91c1c; font-size: 12px; }
.training-panel__success { margin: 0; padding: 9px 12px; border-radius: 8px; background: #ecfdf5; color: #166534; font-size: 12px; }
.training-panel__forbidden { padding: 20px; border: 1px solid #fecaca; border-radius: 10px; background: #fef2f2; color: #991b1b; }
.training-panel__forbidden h3 { margin: 0 0 6px; font-size: 14px; }
.training-panel__forbidden p { margin: 0; font-size: 12px; }
.mono { font-variant-numeric: tabular-nums; }
</style>
