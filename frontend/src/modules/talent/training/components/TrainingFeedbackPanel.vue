<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { ApiError, errorCode, errorCopy } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import LoadingState from '@/components/LoadingState.vue'
import {
  businessDate,
  createFeedback,
  listEnrollments,
  listFeedback,
  trainingActionErrorCopy,
  type TrainingEnrollment,
  type TrainingFeedback,
} from '../api'

const props = defineProps<{ sessionId: number }>()

defineOptions({ name: 'TrainingFeedbackPanel' })

const session = useSessionStore()
const canManage = computed(() => session.can('training.feedback.manage'))
const loading = ref(false)
const loaded = ref(false)
const errorMessage = ref('')
const errorCodeValue = ref('')
const errorRequestId = ref<string | null>(null)
const feedback = ref<TrainingFeedback[]>([])
const enrollments = ref<TrainingEnrollment[]>([])
const success = ref('')

const formOpen = ref(false)
const submitting = ref(false)
const formError = ref('')
const draft = ref({ enrollmentId: '', rating: '', comments: '' })

const empty = computed(
  () => loaded.value && !loading.value && errorMessage.value === '' && feedback.value.length === 0,
)
const ratingAverage = computed(() => {
  const rated = feedback.value.filter((row) => row.rating !== null)
  if (rated.length === 0) return null
  return (rated.reduce((sum, row) => sum + (row.rating ?? 0), 0) / rated.length).toFixed(1)
})

function captureError(reason: unknown): void {
  errorCodeValue.value = errorCode(reason)
  errorRequestId.value = reason instanceof ApiError ? reason.requestId : null
  errorMessage.value =
    reason instanceof ApiError && reason.code === 'FORBIDDEN'
      ? '无权读取培训反馈。请确认已获得 training.feedback.manage 能力。'
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
    const [sessionFeedback, sessionEnrollments] = await Promise.all([
      listFeedback(null, businessDate()),
      listEnrollments(props.sessionId),
    ])
    enrollments.value = sessionEnrollments
    const enrollmentIds = new Set(sessionEnrollments.map((row) => row.id))
    feedback.value = sessionFeedback.filter((row) => enrollmentIds.has(row.enrollmentId))
  } catch (reason) {
    feedback.value = []
    enrollments.value = []
    captureError(reason)
  } finally {
    loading.value = false
    loaded.value = true
  }
}

function openCreate(): void {
  if (!canManage.value) return
  draft.value = {
    enrollmentId: enrollments.value[0]?.id.toString() ?? '',
    rating: '',
    comments: '',
  }
  formError.value = ''
  formOpen.value = true
}

async function submit(): Promise<void> {
  formError.value = ''
  if (draft.value.enrollmentId === '') {
    formError.value = '请选择该场次的报名记录。'
    return
  }
  if (draft.value.comments.trim() === '') {
    formError.value = '请填写反馈内容。'
    return
  }
  const rating = draft.value.rating === '' ? null : Number(draft.value.rating)
  if (rating !== null && (!Number.isSafeInteger(rating) || rating < 1 || rating > 5)) {
    formError.value = '评分必须为 1 到 5 的整数或留空。'
    return
  }

  submitting.value = true
  try {
    const created = await createFeedback({
      enrollmentId: Number(draft.value.enrollmentId),
      rating,
      comments: draft.value.comments.trim(),
    })
    feedback.value = [created, ...feedback.value.filter((row) => row.id !== created.id)]
    formOpen.value = false
    success.value = '反馈已追加；历史反馈不会被覆盖。'
  } catch (reason) {
    formError.value = trainingActionErrorCopy(reason)
  } finally {
    submitting.value = false
  }
}

watch(() => props.sessionId, () => void load(), { immediate: true })
</script>

<template>
  <section class="training-panel" data-testid="training-feedback-panel">
    <header class="training-panel__header">
      <div>
        <h2>场次反馈</h2>
        <p>反馈必须关联本场次的有效报名，记录 append-only；匿名反馈不展示评价人身份。</p>
      </div>
      <button v-if="canManage" type="button" class="training-panel__primary" data-testid="training-feedback-create" @click="openCreate">
        追加反馈
      </button>
    </header>

    <section v-if="!canManage" class="training-panel__forbidden" role="alert" data-testid="training-feedback-forbidden">
      <h3>无权查看反馈</h3>
      <p>当前账号缺少 training.feedback.manage 能力，不会发起反馈读取。</p>
    </section>
    <template v-else>
      <p v-if="success !== ''" class="training-panel__success" role="status">{{ success }}</p>
      <LoadingState v-if="loading" :rows="4" label="正在加载反馈" />
      <ErrorState
        v-else-if="errorMessage !== ''"
        title="反馈暂时无法加载"
        :description="errorMessage"
        :code="errorCodeValue"
        :request-id="errorRequestId"
        @retry="load"
      />
      <EmptyState
        v-else-if="empty"
        title="该场次还没有反馈"
        description="反馈只能从已有报名派生；没有报名时不会生成评价人身份。"
      />
      <div v-else class="training-panel__table-wrap">
        <p class="training-panel__summary">反馈 {{ feedback.length }} 条 · 平均评分 {{ ratingAverage ?? '—' }}</p>
        <table class="training-panel__table">
          <thead><tr><th>反馈编号</th><th>报名编号</th><th>评分</th><th>内容</th><th>状态</th></tr></thead>
          <tbody>
            <tr v-for="row in feedback" :key="row.id" :data-testid="`training-feedback-row-${row.id}`">
              <td class="mono">#{{ row.id }}</td>
              <td>#{{ row.enrollmentId }}</td>
              <td>{{ row.rating ?? '未评分' }}</td>
              <td>{{ row.comments }}</td>
              <td>{{ row.status === 'RECORDED' ? '已记录' : row.status === 'REVERSED' ? '已冲销' : '未知状态' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <el-dialog v-model="formOpen" title="追加培训反馈" width="500px" data-testid="training-feedback-dialog">
      <div class="training-panel__form">
        <label>报名记录
          <el-select v-model="draft.enrollmentId" data-testid="training-feedback-enrollment">
            <el-option value="" disabled label="请选择报名" />
            <el-option v-for="row in enrollments" :key="row.id" :value="String(row.id)" :label="`报名 #${row.id} · 任职 #${row.employmentId}`" />
          </el-select>
        </label>
        <label>评分（1–5，可留空）<el-input v-model="draft.rating" type="number" min="1" max="5" data-testid="training-feedback-rating" /></label>
        <label>反馈内容<el-input v-model="draft.comments" type="textarea" maxlength="10000" data-testid="training-feedback-comments" /></label>
        <p v-if="formError !== ''" class="training-panel__error" role="alert" data-testid="training-feedback-error">{{ formError }}</p>
      </div>
      <template #footer>
        <el-button @click="formOpen = false">取消</el-button>
        <el-button type="primary" :loading="submitting" data-testid="training-feedback-submit" @click="submit">追加</el-button>
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
.training-panel__table th, .training-panel__table td { padding: 9px 11px; text-align: left; border-bottom: 1px solid #e2e8f0; }
.training-panel__table th { background: #f8fafc; color: #64748b; font-weight: 600; }
.training-panel__form { display: flex; flex-direction: column; gap: 10px; }
.training-panel__form label { display: flex; flex-direction: column; gap: 5px; font-size: 12.5px; color: #334155; }
.training-panel__error { margin: 0; color: #b91c1c; font-size: 12px; }
.training-panel__success { margin: 0; padding: 9px 12px; border-radius: 8px; background: #ecfdf5; color: #166534; font-size: 12px; }
.training-panel__forbidden { padding: 20px; border: 1px solid #fecaca; border-radius: 10px; background: #fef2f2; color: #991b1b; }
.training-panel__forbidden h3 { margin: 0 0 6px; font-size: 14px; }
.training-panel__forbidden p { margin: 0; font-size: 12px; }
.mono { font-variant-numeric: tabular-nums; }
</style>
