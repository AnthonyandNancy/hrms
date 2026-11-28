<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { ApiError, errorCode, errorCopy } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import LoadingState from '@/components/LoadingState.vue'
import {
  businessDate,
  createExam,
  examStatusLabel,
  listExams,
  publishExam,
  submitExamAttempt,
  trainingActionErrorCopy,
  type TrainingExam,
  type TrainingExamAttempt,
} from '../api'

const props = defineProps<{
  sessionId: number | null
  courseId: number | null
}>()

defineOptions({ name: 'TrainingExamPanel' })

const session = useSessionStore()
const canManage = computed(() => session.can('training.exam.manage'))
const loading = ref(false)
const loaded = ref(false)
const errorMessage = ref('')
const errorCodeValue = ref('')
const errorRequestId = ref<string | null>(null)
const rows = ref<TrainingExam[]>([])
const success = ref('')
const attemptOpen = ref(false)
const attemptSubmitting = ref(false)
const attemptError = ref('')
const attemptExam = ref<TrainingExam | null>(null)
const attemptResult = ref<TrainingExamAttempt | null>(null)
const attemptDraft = ref({ enrollmentId: '', score: '' })

const formOpen = ref(false)
const submitting = ref(false)
const formError = ref('')
const draft = ref({
  code: '',
  title: '',
  passingScore: 60,
  maxScore: 100,
  publish: false,
})

const empty = computed(
  () => loaded.value && !loading.value && errorMessage.value === '' && rows.value.length === 0,
)

function captureError(reason: unknown): void {
  errorCodeValue.value = errorCode(reason)
  errorRequestId.value = reason instanceof ApiError ? reason.requestId : null
  errorMessage.value =
    reason instanceof ApiError && reason.code === 'FORBIDDEN'
      ? '无权读取考试版本。请确认已获得 training.exam.manage 能力。'
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
    rows.value = await listExams(props.sessionId, businessDate())
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
  draft.value = { code: '', title: '', passingScore: 60, maxScore: 100, publish: false }
  formError.value = ''
  formOpen.value = true
}

async function submit(): Promise<void> {
  formError.value = ''
  if (draft.value.code.trim() === '' || draft.value.title.trim() === '') {
    formError.value = '请填写考试编码与名称。'
    return
  }
  if (!Number.isSafeInteger(draft.value.passingScore) || !Number.isSafeInteger(draft.value.maxScore)) {
    formError.value = '分数必须为整数。'
    return
  }
  if (draft.value.maxScore < draft.value.passingScore) {
    formError.value = '满分不得低于及格分。'
    return
  }

  submitting.value = true
  try {
    const created = await createExam({
      courseId: props.courseId,
      sessionId: props.sessionId,
      code: draft.value.code.trim(),
      title: draft.value.title.trim(),
      passingScore: draft.value.passingScore,
      maxScore: draft.value.maxScore,
      publish: draft.value.publish,
    })
    rows.value = [created, ...rows.value.filter((row) => row.id !== created.id)]
    formOpen.value = false
    success.value = created.status === 'PUBLISHED' ? `考试版本 ${created.version} 已发布并冻结` : '考试草稿已保存'
  } catch (reason) {
    formError.value = trainingActionErrorCopy(reason)
  } finally {
    submitting.value = false
  }
}

function openAttempt(row: TrainingExam): void {
  if (!canManage.value || row.status !== 'PUBLISHED') return
  attemptExam.value = row
  attemptDraft.value = { enrollmentId: '', score: '' }
  attemptResult.value = null
  attemptError.value = ''
  attemptOpen.value = true
}

async function submitAttempt(): Promise<void> {
  const exam = attemptExam.value
  if (exam === null) return
  const enrollmentId = Number(attemptDraft.value.enrollmentId)
  const score = Number(attemptDraft.value.score)

  if (!Number.isSafeInteger(enrollmentId) || enrollmentId < 1) {
    attemptError.value = '请输入有效的报名编号。'
    return
  }
  if (!Number.isSafeInteger(score) || score < 0 || score > exam.maxScore) {
    attemptError.value = `分数必须是 0 到 ${exam.maxScore} 的整数。`
    return
  }

  attemptSubmitting.value = true
  attemptError.value = ''
  try {
    const result = await submitExamAttempt(exam.id, enrollmentId, score)
    attemptResult.value = result
    success.value = `考试尝试 #${result.attemptNo} 已记录，${result.passed ? '通过' : '未通过'}。`
  } catch (reason) {
    attemptError.value = trainingActionErrorCopy(reason)
  } finally {
    attemptSubmitting.value = false
  }
}

async function publish(row: TrainingExam): Promise<void> {
  if (!canManage.value || row.status === 'PUBLISHED') return
  try {
    const saved = await publishExam(row.id)
    rows.value = rows.value.map((item) => (item.id === saved.id ? saved : item))
    success.value = `考试版本 ${saved.version} 已发布并冻结`
  } catch (reason) {
    captureError(reason)
  }
}

watch(() => [props.sessionId, props.courseId], () => void load(), { immediate: true })
</script>

<template>
  <section class="training-panel" data-testid="training-exams-panel">
    <header class="training-panel__header">
      <div>
        <h2>场次考核</h2>
        <p>考试版本发布后冻结；同一编码再次保存会生成下一版本。场次关联的课程必须一致。</p>
      </div>
      <button
        v-if="canManage"
        type="button"
        class="training-panel__primary"
        :disabled="sessionId === null"
        data-testid="training-exam-create"
        @click="openCreate"
      >
        新建考试
      </button>
    </header>

    <section v-if="!canManage" class="training-panel__forbidden" role="alert" data-testid="training-exams-forbidden">
      <h3>无权查看考试</h3>
      <p>当前账号缺少 training.exam.manage 能力，不会发起考试读取。</p>
    </section>
    <template v-else>
      <p v-if="success !== ''" class="training-panel__success" role="status">{{ success }}</p>
      <LoadingState v-if="loading" :rows="4" label="正在加载考试" />
      <ErrorState
        v-else-if="errorMessage !== ''"
        title="考试暂时无法加载"
        :description="errorMessage"
        :code="errorCodeValue"
        :request-id="errorRequestId"
        @retry="load"
      />
      <EmptyState
        v-else-if="empty"
        title="该场次还没有考试"
        description="考试可以只属于课程，也可以关联到具体场次；场次与课程不一致会被服务端拒绝。"
      />
      <div v-else class="training-panel__table-wrap">
        <table class="training-panel__table">
          <thead>
            <tr><th>编码</th><th>名称</th><th>版本</th><th>及格/满分</th><th>状态</th><th>操作</th></tr>
          </thead>
          <tbody>
            <tr v-for="row in rows" :key="row.id" :data-testid="`training-exam-row-${row.id}`">
              <td class="mono">{{ row.code }}</td>
              <td>{{ row.title }}</td>
              <td>v{{ row.version }}</td>
              <td>{{ row.passingScore }} / {{ row.maxScore }}</td>
              <td>{{ examStatusLabel(row.status) }}</td>
              <td>
                <button
                  v-if="row.status === 'DRAFT'"
                  type="button"
                  class="training-panel__secondary"
                  :data-testid="`training-exam-publish-${row.id}`"
                  @click="publish(row)"
                >
                  发布
                </button>
                <button
                  v-else
                  type="button"
                  class="training-panel__secondary"
                  :data-testid="`training-exam-attempt-${row.id}`"
                  @click="openAttempt(row)"
                >
                  提交成绩
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <el-dialog v-model="attemptOpen" title="提交考试尝试" width="500px" data-testid="training-attempt-dialog">
      <div class="training-panel__form">
        <p class="training-panel__meta">考试 #{{ attemptExam?.id }} · {{ attemptExam?.title }} · 满分 {{ attemptExam?.maxScore }}</p>
        <label>报名编号<el-input v-model="attemptDraft.enrollmentId" inputmode="numeric" data-testid="training-attempt-enrollment" /></label>
        <label>得分<el-input v-model="attemptDraft.score" inputmode="numeric" data-testid="training-attempt-score" /></label>
        <p class="training-panel__meta">只有有效报名且已签到的学员可以提交；成绩来源固定为本次 attempt。</p>
        <p v-if="attemptError !== ''" class="training-panel__error" role="alert" data-testid="training-attempt-error">{{ attemptError }}</p>
        <p v-if="attemptResult !== null" class="training-panel__success" role="status" data-testid="training-attempt-result">
          尝试 #{{ attemptResult.attemptNo }} · {{ attemptResult.score }} 分 · {{ attemptResult.passed ? '通过' : '未通过' }} · 摘要 {{ attemptResult.sourceHash.slice(0, 12) }}
        </p>
      </div>
      <template #footer>
        <el-button @click="attemptOpen = false">关闭</el-button>
        <el-button type="primary" :loading="attemptSubmitting" data-testid="training-attempt-submit" @click="submitAttempt">提交成绩</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="formOpen" title="新建考试" width="500px" data-testid="training-exam-dialog">
      <div class="training-panel__form">
        <p class="training-panel__meta">场次 #{{ sessionId ?? '未选择' }} · 课程 #{{ courseId ?? '未设置' }}</p>
        <label>考试编码<el-input v-model="draft.code" maxlength="64" data-testid="training-exam-code" /></label>
        <label>考试名称<el-input v-model="draft.title" maxlength="200" data-testid="training-exam-title" /></label>
        <label>及格分<el-input v-model.number="draft.passingScore" type="number" data-testid="training-exam-passing" /></label>
        <label>满分<el-input v-model.number="draft.maxScore" type="number" data-testid="training-exam-max" /></label>
        <el-checkbox v-model="draft.publish" data-testid="training-exam-publish-on-save">保存后立即发布</el-checkbox>
        <p v-if="formError !== ''" class="training-panel__error" role="alert" data-testid="training-exam-error">{{ formError }}</p>
      </div>
      <template #footer>
        <el-button @click="formOpen = false">取消</el-button>
        <el-button type="primary" :loading="submitting" data-testid="training-exam-submit" @click="submit">保存</el-button>
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
.training-panel__secondary { padding: 4px 8px; border: 1px solid #cbd5e1; border-radius: 6px; background: #fff; color: #1d4ed8; font-size: 11px; cursor: pointer; }
.training-panel__form { display: flex; flex-direction: column; gap: 10px; }
.training-panel__form label { display: flex; flex-direction: column; gap: 5px; font-size: 12.5px; color: #334155; }
.training-panel__meta { margin: 0; color: #64748b; font-size: 12px; }
.training-panel__error { margin: 0; color: #b91c1c; font-size: 12px; }
.training-panel__success { margin: 0; padding: 9px 12px; border-radius: 8px; background: #ecfdf5; color: #166534; font-size: 12px; }
.training-panel__forbidden { padding: 20px; border: 1px solid #fecaca; border-radius: 10px; background: #fef2f2; color: #991b1b; }
.training-panel__forbidden h3 { margin: 0 0 6px; font-size: 14px; }
.training-panel__forbidden p { margin: 0; font-size: 12px; }
.mono { font-variant-numeric: tabular-nums; }
</style>
