<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { ApiError, errorCode, errorCopy } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import { formatBusinessDate, formatDateTimeInTimezone } from '@/utils/format'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import LoadingState from '@/components/LoadingState.vue'
import {
  businessDate,
  createSession,
  listCourses,
  listSessions,
  sessionStatusLabel,
  trainingActionErrorCopy,
  updateSession,
  type TrainingCourse,
  type TrainingSession,
} from '../api'

const props = defineProps<{
  programId: number | null
  selectedSessionId?: number | null
}>()

const emit = defineEmits<{
  'open-session': [session: TrainingSession]
}>()

defineOptions({ name: 'TrainingSessionsPanel' })

const session = useSessionStore()
const canManage = computed(() => session.can('training.session.manage'))
const loading = ref(false)
const loaded = ref(false)
const errorMessage = ref('')
const errorCodeValue = ref('')
const errorRequestId = ref<string | null>(null)
const rows = ref<TrainingSession[]>([])
const courses = ref<TrainingCourse[]>([])

const formOpen = ref(false)
const formMode = ref<'create' | 'edit'>('create')
const editing = ref<TrainingSession | null>(null)
const submitting = ref(false)
const formError = ref('')
const draft = ref({
  planId: '' as string,
  courseId: '' as string,
  startsOn: businessDate(),
  endsOn: businessDate(),
  startsAt: '',
  endsAt: '',
  capacity: 10,
  location: '',
})

const isEmpty = computed(
  () => loaded.value && !loading.value && errorMessage.value === '' && rows.value.length === 0,
)

function captureError(reason: unknown): void {
  errorCodeValue.value = errorCode(reason)
  errorRequestId.value = reason instanceof ApiError ? reason.requestId : null
  errorMessage.value =
    reason instanceof ApiError && reason.code === 'FORBIDDEN'
      ? '无权读取培训场次。请确认已获得 training.session.manage 能力。'
      : trainingActionErrorCopy(reason) || errorCopy(reason)
}

async function load(): Promise<void> {
  loading.value = true
  loaded.value = false
  errorMessage.value = ''

  try {
    const [sessions, courseRows] = await Promise.all([
      listSessions(props.programId, undefined, businessDate()),
      canManage.value
        ? listCourses(null, businessDate()).catch(() => [])
        : Promise.resolve([] as TrainingCourse[]),
    ])
    rows.value = sessions
    courses.value = courseRows
  } catch (reason) {
    rows.value = []
    captureError(reason)
  } finally {
    loading.value = false
    loaded.value = true
  }
}

function openCreate(): void {
  if (!canManage.value || props.programId === null) return
  formMode.value = 'create'
  editing.value = null
  formError.value = ''
  draft.value = {
    planId: '',
    courseId: '',
    startsOn: businessDate(),
    endsOn: businessDate(),
    startsAt: '',
    endsAt: '',
    capacity: 10,
    location: '',
  }
  formOpen.value = true
}

function openEdit(item: TrainingSession): void {
  if (!canManage.value) return
  formMode.value = 'edit'
  editing.value = item
  formError.value = ''
  draft.value = {
    planId: item.planId === null ? '' : String(item.planId),
    courseId: item.courseId === null ? '' : String(item.courseId),
    startsOn: item.startsOn,
    endsOn: item.endsOn,
    startsAt: item.startsAt ?? '',
    endsAt: item.endsAt ?? '',
    capacity: item.capacity,
    location: item.location ?? '',
  }
  formOpen.value = true
}

async function submit(): Promise<void> {
  formError.value = ''
  if (props.programId === null) {
    formError.value = '请先在项目筛选器中选择培训项目。'
    return
  }
  if (!/^\d{4}-\d{2}-\d{2}$/.test(draft.value.startsOn) || !/^\d{4}-\d{2}-\d{2}$/.test(draft.value.endsOn)) {
    formError.value = '请填写有效的开始与结束日期。'
    return
  }
  if (draft.value.startsAt === '' || draft.value.endsAt === '') {
    formError.value = '请填写场次开始与结束时间。'
    return
  }
  if (draft.value.endsAt <= draft.value.startsAt) {
    formError.value = '结束时间必须晚于开始时间。'
    return
  }
  if (!Number.isSafeInteger(draft.value.capacity) || draft.value.capacity < 1) {
    formError.value = '容量必须为不小于 1 的整数。'
    return
  }

  const planId = draft.value.planId === '' ? null : Number(draft.value.planId)
  const courseId = draft.value.courseId === '' ? null : Number(draft.value.courseId)

  submitting.value = true
  try {
    if (formMode.value === 'edit' && editing.value !== null) {
      const updated = await updateSession(editing.value.id, {
        planId,
        courseId,
        startsOn: draft.value.startsOn,
        endsOn: draft.value.endsOn,
        startsAt: draft.value.startsAt,
        endsAt: draft.value.endsAt,
        capacity: draft.value.capacity,
        location: draft.value.location === '' ? null : draft.value.location,
      })
      rows.value = rows.value.map((row) => (row.id === updated.id ? updated : row))
    } else {
      const created = await createSession({
        programId: props.programId,
        planId,
        courseId,
        startsOn: draft.value.startsOn,
        endsOn: draft.value.endsOn,
        startsAt: draft.value.startsAt,
        endsAt: draft.value.endsAt,
        capacity: draft.value.capacity,
        location: draft.value.location,
      })
      rows.value = [created, ...rows.value.filter((row) => row.id !== created.id)]
    }
    formOpen.value = false
  } catch (reason) {
    formError.value = trainingActionErrorCopy(reason)
  } finally {
    submitting.value = false
  }
}

async function finish(item: TrainingSession, status: 'COMPLETED' | 'CANCELLED'): Promise<void> {
  if (!canManage.value) return
  try {
    const updated = await updateSession(item.id, { status })
    rows.value = rows.value.map((row) => (row.id === updated.id ? updated : row))
  } catch (reason) {
    captureError(reason)
  }
}

watch(() => props.programId, () => void load())
onMounted(load)
</script>

<template>
  <section class="training-panel" data-testid="training-sessions-panel">
    <header class="training-panel__header">
      <div>
        <h2>培训场次</h2>
        <p>场次必须有项目和容量；结束时间、日期与讲师重叠由服务端再次校验。</p>
      </div>
      <button
        v-if="canManage"
        type="button"
        class="training-panel__primary"
        :disabled="programId === null"
        data-testid="training-session-create"
        @click="openCreate"
      >
        新建场次
      </button>
    </header>

    <EmptyState
      v-if="programId === null"
      title="请选择培训项目"
      description="场次、报名、签到、考试和反馈都按具体场次加载；请先在上方选择计划或项目。"
    />
    <LoadingState v-else-if="loading" :rows="5" label="正在加载培训场次" />
    <ErrorState
      v-else-if="errorMessage !== ''"
      title="培训场次暂时无法加载"
      :description="errorMessage"
      :code="errorCodeValue"
      :request-id="errorRequestId"
      @retry="load"
    />
    <EmptyState
      v-else-if="isEmpty"
      title="该项目还没有培训场次"
      description="具备 training.session.manage 的账号可以新建第一个场次。"
    />
    <div v-else class="training-panel__table-wrap">
      <table class="training-panel__table">
        <thead>
          <tr>
            <th>场次</th>
            <th>日期</th>
            <th>时间</th>
            <th>地点</th>
            <th>容量</th>
            <th>状态</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="item in rows"
            :key="item.id"
            :data-testid="`training-session-row-${item.id}`"
            :class="{ 'is-selected': selectedSessionId === item.id }"
          >
            <td class="mono">#{{ item.id }}</td>
            <td>{{ formatBusinessDate(item.startsOn) }}</td>
            <td>
              {{ item.startsAt === null ? '—' : formatDateTimeInTimezone(item.startsAt).slice(11) }}
              <template v-if="item.endsAt !== null">–{{ formatDateTimeInTimezone(item.endsAt).slice(11) }}</template>
            </td>
            <td>{{ item.location ?? '未设置地点' }}</td>
            <td>{{ item.capacity }}</td>
            <td>{{ sessionStatusLabel(item.status) }}</td>
            <td class="training-panel__actions">
              <button type="button" class="training-panel__secondary" :data-testid="`training-session-open-${item.id}`" @click="emit('open-session', item)">
                操作中心
              </button>
              <button v-if="canManage" type="button" class="training-panel__secondary" :data-testid="`training-session-edit-${item.id}`" @click="openEdit(item)">
                编辑
              </button>
              <button
                v-if="canManage && item.status === 'SCHEDULED'"
                type="button"
                class="training-panel__secondary"
                :data-testid="`training-session-complete-${item.id}`"
                @click="finish(item, 'COMPLETED')"
              >
                结束
              </button>
              <button
                v-if="canManage && item.status === 'SCHEDULED'"
                type="button"
                class="training-panel__secondary is-danger"
                :data-testid="`training-session-cancel-${item.id}`"
                @click="finish(item, 'CANCELLED')"
              >
                取消
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <el-dialog v-model="formOpen" :title="formMode === 'create' ? '新建培训场次' : '编辑培训场次'" width="520px" data-testid="training-session-dialog">
      <form class="training-panel__form" @submit.prevent="submit">
        <p v-if="programId !== null" class="training-panel__meta">培训项目 #{{ programId }}</p>
        <label>关联课程
          <el-select v-model="draft.courseId" clearable data-testid="training-session-course">
            <el-option value="" label="不关联课程" />
            <el-option v-for="course in courses" :key="course.id" :value="String(course.id)" :label="`${course.code} · ${course.title}`" />
          </el-select>
        </label>
        <label>开始日期<el-input v-model="draft.startsOn" type="date" data-testid="training-session-starts-on" /></label>
        <label>结束日期<el-input v-model="draft.endsOn" type="date" data-testid="training-session-ends-on" /></label>
        <label>开始时间<el-input v-model="draft.startsAt" type="datetime-local" data-testid="training-session-starts-at" /></label>
        <label>结束时间<el-input v-model="draft.endsAt" type="datetime-local" data-testid="training-session-ends-at" /></label>
        <label>容量<el-input v-model.number="draft.capacity" type="number" data-testid="training-session-capacity" /></label>
        <label>地点<el-input v-model="draft.location" maxlength="160" data-testid="training-session-location" /></label>
        <p v-if="formError !== ''" class="training-panel__error" role="alert" data-testid="training-session-error">{{ formError }}</p>
      </form>
      <template #footer>
        <el-button @click="formOpen = false">取消</el-button>
        <el-button type="primary" :loading="submitting" data-testid="training-session-submit" @click="submit">保存</el-button>
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
.training-panel__primary:disabled { background: #94a3b8; border-color: #94a3b8; }
.training-panel__table-wrap { overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 10px; }
.training-panel__table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.training-panel__table th, .training-panel__table td { padding: 9px 11px; text-align: left; border-bottom: 1px solid #e2e8f0; white-space: nowrap; }
.training-panel__table th { background: #f8fafc; color: #64748b; font-weight: 600; }
.training-panel__table tr.is-selected td { background: #eff6ff; }
.training-panel__actions { display: flex; gap: 6px; flex-wrap: wrap; }
.training-panel__secondary { padding: 4px 8px; border: 1px solid #cbd5e1; border-radius: 6px; background: #fff; color: #1d4ed8; font-size: 11px; cursor: pointer; }
.training-panel__secondary.is-danger { color: #b91c1c; border-color: #fecaca; }
.training-panel__form { display: flex; flex-direction: column; gap: 10px; }
.training-panel__form label { display: flex; flex-direction: column; gap: 5px; font-size: 12.5px; color: #334155; }
.training-panel__meta { margin: 0; color: #64748b; font-size: 12px; }
.training-panel__error { margin: 0; color: #b91c1c; font-size: 12px; }
.mono { font-variant-numeric: tabular-nums; }
</style>
