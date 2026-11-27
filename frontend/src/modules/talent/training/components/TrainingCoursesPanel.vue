<script setup lang="ts">
import { DISPLAY_CODES } from '@/shared/display/dictionary'
import { computed, onMounted, ref } from 'vue'
import { ApiError, errorCode, errorCopy } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import { formatMoney } from '@/utils/format'
import ErrorState from '@/components/ErrorState.vue'
import LoadingState from '@/components/LoadingState.vue'
import EmptyState from '@/components/EmptyState.vue'
import {
  businessDate,
  createCourse,
  DELIVERY_MODE_LABELS,
  deliveryModeLabel,
  listCourses,
  programStatusLabel,
  trainingActionErrorCopy,
  updateCourse,
  type CourseDraft,
  type CoursePatch,
  type TrainingCourse,
} from '../api'

defineOptions({ name: 'TrainingCoursesPanel' })

const session = useSessionStore()
const canManage = computed(() => session.can('training.course.manage'))

const loading = ref(false)
const loaded = ref(false)
const errorMessage = ref('')
const errorCodeValue = ref('')
const errorRequestId = ref<string | null>(null)
const courses = ref<TrainingCourse[]>([])

const drawerOpen = ref(false)
const drawerMode = ref<'create' | 'edit'>('create')
const editing = ref<TrainingCourse | null>(null)
const submitting = ref(false)
const formError = ref('')
const draft = ref<CourseDraft>({
  planId: null,
  code: '',
  title: '',
  instructor: '',
  deliveryMode: 'ONSITE',
  durationMinutes: 0,
  costCents: 0,
  currency: 'CNY',
  materials: '',
})

const isEmpty = computed(
  () => loaded.value && !loading.value && errorMessage.value === '' && courses.value.length === 0,
)

function captureError(reason: unknown): void {
  errorCodeValue.value = errorCode(reason)
  errorRequestId.value = reason instanceof ApiError ? reason.requestId : null
  errorMessage.value =
    reason instanceof ApiError && reason.code === 'FORBIDDEN'
      ? '无权读取课程库。请确认已获得 training.course.manage 能力。'
      : trainingActionErrorCopy(reason) || errorCopy(reason)
}

async function load(): Promise<void> {
  loading.value = true
  loaded.value = false
  errorMessage.value = ''

  try {
    courses.value = await listCourses(null, businessDate())
  } catch (reason) {
    courses.value = []
    captureError(reason)
  } finally {
    loading.value = false
    loaded.value = true
  }
}

function openCreate(): void {
  if (!canManage.value) return
  drawerMode.value = 'create'
  editing.value = null
  formError.value = ''
  draft.value = {
    planId: null,
    code: '',
    title: '',
    instructor: '',
    deliveryMode: 'ONSITE',
    durationMinutes: 0,
    costCents: 0,
    currency: 'CNY',
    materials: '',
  }
  drawerOpen.value = true
}

function openEdit(course: TrainingCourse): void {
  if (!canManage.value) return
  drawerMode.value = 'edit'
  editing.value = course
  formError.value = ''
  draft.value = {
    planId: course.planId,
    code: course.code,
    title: course.title,
    instructor: course.instructor ?? '',
    deliveryMode: course.deliveryMode,
    durationMinutes: course.durationMinutes,
    costCents: course.costCents,
    currency: course.currency,
    materials: course.materials ?? '',
  }
  drawerOpen.value = true
}

async function submit(): Promise<void> {
  formError.value = ''
  const title = draft.value.title.trim()

  if (title === '') {
    formError.value = '请填写课程名称。'
    return
  }
  if (!Number.isSafeInteger(draft.value.durationMinutes) || draft.value.durationMinutes < 0) {
    formError.value = '课程时长必须为不小于 0 的整数分钟。'
    return
  }

  submitting.value = true
  try {
    if (drawerMode.value === 'edit' && editing.value !== null) {
      const patch: CoursePatch = {
        title,
        instructor: draft.value.instructor.trim() === '' ? null : draft.value.instructor.trim(),
        durationMinutes: draft.value.durationMinutes,
        materials: draft.value.materials.trim() === '' ? null : draft.value.materials.trim(),
      }
      const saved = await updateCourse(editing.value.id, patch)
      courses.value = courses.value.map((row) => (row.id === saved.id ? saved : row))
    } else {
      const code = draft.value.code.trim()
      if (code === '') {
        formError.value = '请填写课程编码。'
        return
      }
      if (!Number.isSafeInteger(draft.value.costCents) || draft.value.costCents < 0) {
        formError.value = '课程成本必须为不小于 0 的整数分。'
        return
      }
      const created = await createCourse({ ...draft.value, code, title })
      courses.value = [created, ...courses.value.filter((row) => row.id !== created.id)]
    }
    drawerOpen.value = false
  } catch (reason) {
    formError.value = trainingActionErrorCopy(reason)
  } finally {
    submitting.value = false
  }
}

async function archive(course: TrainingCourse): Promise<void> {
  if (!canManage.value || course.status === 'ARCHIVED') return
  try {
    const saved = await updateCourse(course.id, { status: 'ARCHIVED' })
    courses.value = courses.value.map((row) => (row.id === saved.id ? saved : row))
  } catch (reason) {
    captureError(reason)
  }
}

onMounted(load)
</script>

<template>
  <section class="training-panel" data-testid="training-courses-panel">
    <header class="training-panel__header">
      <div>
        <h2>课程库</h2>
        <p>课程编码创建后不可改；编辑只允许标题、讲师、时长与资源，成本始终为整数分。</p>
      </div>
      <button v-if="canManage" type="button" class="training-panel__primary" data-testid="training-course-create" @click="openCreate">
        新建课程
      </button>
    </header>

    <LoadingState v-if="loading" :rows="5" label="正在加载课程库" />
    <ErrorState
      v-else-if="errorMessage !== ''"
      title="课程库暂时无法加载"
      :description="errorMessage"
      :code="errorCodeValue"
      :request-id="errorRequestId"
      @retry="load"
    />
    <EmptyState
      v-else-if="isEmpty"
      title="还没有课程"
      description="当前数据范围内没有课程。具备 training.course.manage 的账号可以新建第一门课程。"
    />
    <div v-else class="training-panel__table-wrap">
      <table class="training-panel__table">
        <thead>
          <tr>
            <th>课程编码</th>
            <th>课程名称</th>
            <th>讲师</th>
            <th>形式</th>
            <th>时长</th>
            <th>成本</th>
            <th>状态</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="course in courses" :key="course.id" :data-testid="`training-course-row-${course.id}`">
            <td class="mono">{{ course.code }}</td>
            <td>{{ course.title }}</td>
            <td>{{ course.instructor ?? '未设置' }}</td>
            <td>{{ deliveryModeLabel(course.deliveryMode) }}</td>
            <td>{{ course.durationMinutes }} 分钟</td>
            <td>{{ formatMoney(course.costCents) }}</td>
            <td>{{ programStatusLabel(course.status) }}</td>
            <td class="training-panel__actions">
              <button v-if="canManage" type="button" class="training-panel__secondary" :data-testid="`training-course-edit-${course.id}`" @click="openEdit(course)">
                编辑
              </button>
              <button
                v-if="canManage && course.status === DISPLAY_CODES.userStatus.active"
                type="button"
                class="training-panel__secondary is-danger"
                :data-testid="`training-course-archive-${course.id}`"
                @click="archive(course)"
              >
                停用
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <el-dialog v-model="drawerOpen" :title="drawerMode === 'create' ? '新建课程' : '编辑课程'" width="520px" data-testid="training-course-dialog">
      <form class="training-panel__form" @submit.prevent="submit">
        <label v-if="drawerMode === 'create'">课程编码<el-input v-model="draft.code" maxlength="64" data-testid="training-course-code" /></label>
        <label>课程名称<el-input v-model="draft.title" maxlength="160" data-testid="training-course-title" /></label>
        <label>讲师<el-input v-model="draft.instructor" maxlength="120" data-testid="training-course-instructor" /></label>
        <label v-if="drawerMode === 'create'">
          交付形式
          <el-select v-model="draft.deliveryMode" data-testid="training-course-delivery-mode">
            <el-option v-for="(label, value) in DELIVERY_MODE_LABELS" :key="value" :value="value" :label="label" />
          </el-select>
        </label>
        <label>时长（分钟）<el-input v-model.number="draft.durationMinutes" type="number" data-testid="training-course-duration" /></label>
        <label v-if="drawerMode === 'create'">成本（整数分）<el-input v-model.number="draft.costCents" type="number" data-testid="training-course-cost" /></label>
        <label>资源说明<el-input v-model="draft.materials" type="textarea" data-testid="training-course-materials" /></label>
        <p v-if="formError !== ''" class="training-panel__error" role="alert" data-testid="training-course-error">{{ formError }}</p>
      </form>
      <template #footer>
        <el-button @click="drawerOpen = false">取消</el-button>
        <el-button type="primary" :loading="submitting" data-testid="training-course-submit" @click="submit">保存</el-button>
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
.training-panel__table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.training-panel__table th, .training-panel__table td { padding: 9px 11px; text-align: left; border-bottom: 1px solid #e2e8f0; white-space: nowrap; }
.training-panel__table th { background: #f8fafc; color: #64748b; font-weight: 600; }
.training-panel__actions { display: flex; gap: 6px; }
.training-panel__secondary { padding: 4px 8px; border: 1px solid #cbd5e1; border-radius: 6px; background: #fff; color: #1d4ed8; font-size: 11px; cursor: pointer; }
.training-panel__secondary.is-danger { color: #b91c1c; border-color: #fecaca; }
.training-panel__form { display: flex; flex-direction: column; gap: 10px; }
.training-panel__form label { display: flex; flex-direction: column; gap: 5px; font-size: 12.5px; color: #334155; }
.training-panel__error { margin: 0; color: #b91c1c; font-size: 12px; }
.mono { font-variant-numeric: tabular-nums; }
</style>
