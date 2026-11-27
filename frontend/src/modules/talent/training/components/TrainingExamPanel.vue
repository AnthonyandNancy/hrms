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
  trainingActionErrorCopy,
  type TrainingExam,
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
                <span v-else class="training-panel__meta">已冻结</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

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
