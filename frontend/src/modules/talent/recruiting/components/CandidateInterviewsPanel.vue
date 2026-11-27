<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ApiError, errorCopy } from '@/api/errors'
import { formatDateTimeInTimezone } from '@/utils/format'
import {
  createInterview,
  listApplications,
  listInterviews,
  type CandidateApplication,
  type Interview,
} from '../api'

const props = defineProps<{
  candidateId: number
  canManage: boolean
}>()

const emit = defineEmits<{
  changed: []
}>()

const interviews = ref<Interview[]>([])
const applications = ref<CandidateApplication[]>([])
const loading = ref(false)
const error = ref('')
const formError = ref('')
const saving = ref(false)
const form = reactive({
  requisitionId: '',
  scheduledAt: '',
  durationMinutes: '60',
  interviewer: '',
  mode: 'VIDEO',
})

const approvedApplications = computed(() =>
  applications.value.filter((application) => application.requisitionStatus === 'APPROVED'),
)

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const [rows, appRows] = await Promise.all([
      listInterviews(props.candidateId),
      listApplications(props.candidateId),
    ])
    interviews.value = rows
    applications.value = appRows
  } catch (failure) {
    interviews.value = []
    error.value = failure instanceof ApiError && failure.message !== '' ? failure.message : '面试记录加载失败。'
  } finally {
    loading.value = false
  }
}

async function submit(): Promise<void> {
  formError.value = ''
  const requisitionId = Number(form.requisitionId)
  if (!Number.isSafeInteger(requisitionId) || requisitionId <= 0) {
    formError.value = '请选择已批准的应聘需求。'
    return
  }
  if (form.scheduledAt.trim() === '' || form.interviewer.trim() === '') {
    formError.value = '请填写面试时间和面试官。'
    return
  }

  saving.value = true
  try {
    await createInterview(props.candidateId, {
      requisitionId,
      scheduledAt: form.scheduledAt,
      durationMinutes: Number(form.durationMinutes) || 60,
      interviewer: form.interviewer.trim(),
      mode: form.mode,
    })
    form.scheduledAt = ''
    form.interviewer = ''
    await load()
    emit('changed')
  } catch (failure) {
    formError.value = failure instanceof ApiError && failure.message !== '' ? failure.message : errorCopy(failure)
  } finally {
    saving.value = false
  }
}

function recommendationLabel(value: string | null): string {
  return value === null ? '未填写' : value
}

onMounted(load)
</script>

<template>
  <section data-testid="candidate-tab-interviews">
    <h3>面试安排</h3>
    <p v-if="loading" class="panel-muted">正在加载面试记录。</p>
    <p v-else-if="error !== ''" class="panel-error" role="alert">{{ error }}</p>
    <p v-else-if="interviews.length === 0" class="panel-muted">还没有面试记录；面试必须关联已批准的应聘需求。</p>
    <ul v-else class="panel-list">
      <li v-for="interview in interviews" :key="interview.id" :data-testid="`interview-row-${interview.id}`">
        <strong>{{ formatDateTimeInTimezone(interview.scheduledAt) }}</strong>
        <span>{{ interview.interviewer }} · {{ interview.mode }} · {{ interview.durationMinutes }} 分钟</span>
        <span v-if="interview.feedbackRestricted" class="panel-muted">评价反馈需 recruitment.evaluation.manage</span>
        <span v-else>建议：{{ recommendationLabel(interview.recommendation) }}<template v-if="interview.feedback !== null"> · {{ interview.feedback }}</template></span>
      </li>
    </ul>

    <form v-if="canManage" class="panel-form" @submit.prevent="submit">
      <h4>安排面试</h4>
      <p v-if="approvedApplications.length === 0" class="panel-muted">没有已批准的应聘需求，先在该候选人的“应聘申请”Tab 添加申请。</p>
      <label>
        <span>应聘需求</span>
        <el-select v-model="form.requisitionId" data-testid="interview-requisition">
          <el-option value="" label="请选择" />
          <el-option
            v-for="application in approvedApplications"
            :key="application.id"
            :value="String(application.requisitionId)"
            :label="`${application.requisitionNo ?? application.requisitionId} · ${application.requisitionTitle ?? ''}`"
          />
        </el-select>
      </label>
      <label>
        <span>面试时间</span>
        <el-input v-model="form.scheduledAt" data-testid="interview-scheduled-at" placeholder="2026-12-01 09:00:00" />
      </label>
      <label>
        <span>面试官</span>
        <el-input v-model="form.interviewer" data-testid="interview-interviewer" maxlength="120" />
      </label>
      <label>
        <span>时长（分钟）</span>
        <el-input v-model="form.durationMinutes" data-testid="interview-duration" inputmode="numeric" />
      </label>
      <label>
        <span>方式</span>
        <el-select v-model="form.mode" data-testid="interview-mode">
          <el-option value="VIDEO" label="视频" />
          <el-option value="ONSITE" label="现场" />
          <el-option value="PHONE" label="电话" />
        </el-select>
      </label>
      <p v-if="formError !== ''" class="panel-error" role="alert">{{ formError }}</p>
      <button type="submit" class="panel-primary" :disabled="saving || approvedApplications.length === 0" data-testid="interview-submit">
        {{ saving ? '提交中…' : '安排面试' }}
      </button>
    </form>
  </section>
</template>

<style scoped>
.panel-muted { color: #64748b; font-size: 12px; }
.panel-error { color: #b91c1c; font-size: 12px; }
.panel-list { display: flex; flex-direction: column; gap: 8px; margin: 0; padding: 0; list-style: none; }
.panel-list li { display: flex; flex-direction: column; gap: 3px; padding: 9px 10px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 12.5px; }
.panel-form { display: flex; flex-direction: column; gap: 10px; max-width: 460px; margin-top: 16px; }
.panel-form h4 { margin: 0; font-size: 13px; }
.panel-form label { display: flex; flex-direction: column; gap: 4px; color: #475569; font-size: 12px; }
.panel-primary { align-self: flex-start; height: 32px; padding: 0 12px; border: 1px solid #1d4ed8; border-radius: 7px; background: #1d4ed8; color: #fff; cursor: pointer; }
.panel-primary:disabled { opacity: 0.55; cursor: not-allowed; }
</style>
