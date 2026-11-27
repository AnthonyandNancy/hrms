<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ApiError, errorCopy } from '@/api/errors'
import { formatDateTimeInTimezone } from '@/utils/format'
import {
  createEvaluation,
  listEvaluations,
  listInterviews,
  type Evaluation,
  type Interview,
} from '../api'

const props = defineProps<{
  candidateId: number
  canManage: boolean
}>()

const evaluations = ref<Evaluation[]>([])
const interviews = ref<Interview[]>([])
const loading = ref(false)
const error = ref('')
const formError = ref('')
const saving = ref(false)
const form = reactive({
  interviewId: '',
  rating: '4',
  recommendation: 'YES',
  strengths: '',
  concerns: '',
  comments: '',
})

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const [rows, interviewRows] = await Promise.all([
      listEvaluations(props.candidateId),
      listInterviews(props.candidateId),
    ])
    evaluations.value = rows
    interviews.value = interviewRows
  } catch (failure) {
    evaluations.value = []
    error.value = failure instanceof ApiError && failure.message !== '' ? failure.message : '面试评价加载失败。'
  } finally {
    loading.value = false
  }
}

async function submit(): Promise<void> {
  formError.value = ''
  if (form.interviewId === '') {
    formError.value = '请选择该候选人已完成的面试；不能为未参加面试的人造评价。'
    return
  }

  saving.value = true
  try {
    await createEvaluation(props.candidateId, {
      interviewId: Number(form.interviewId),
      rating: Number(form.rating),
      recommendation: form.recommendation,
      strengths: form.strengths.trim() || null,
      concerns: form.concerns.trim() || null,
      comments: form.comments.trim() || null,
    })
    form.strengths = ''
    form.concerns = ''
    form.comments = ''
    await load()
  } catch (failure) {
    formError.value = failure instanceof ApiError && failure.message !== '' ? failure.message : errorCopy(failure)
  } finally {
    saving.value = false
  }
}

function recommendationLabel(value: string): string {
  return {
    STRONG_YES: '强烈推荐',
    YES: '推荐',
    NO: '不推荐',
    STRONG_NO: '强烈不推荐',
  }[value] ?? value
}

onMounted(load)
</script>

<template>
  <section data-testid="candidate-tab-evaluations">
    <h3>面试评价</h3>
    <p v-if="loading" class="panel-muted">正在加载面试评价。</p>
    <p v-else-if="error !== ''" class="panel-error" role="alert">{{ error }}</p>
    <p v-else-if="evaluations.length === 0" class="panel-muted">还没有面试评价；评价必须归属该候选人的真实面试。</p>
    <ul v-else class="panel-list">
      <li v-for="evaluation in evaluations" :key="evaluation.id" :data-testid="`evaluation-row-${evaluation.id}`">
        <strong>{{ evaluation.rating }} / 5 · {{ recommendationLabel(evaluation.recommendation) }}</strong>
        <span>面试 #{{ evaluation.interviewId ?? '—' }} · {{ formatDateTimeInTimezone(evaluation.recordedAt) }}</span>
        <span v-if="evaluation.strengths !== null">优势：{{ evaluation.strengths }}</span>
        <span v-if="evaluation.concerns !== null">顾虑：{{ evaluation.concerns }}</span>
        <span v-if="evaluation.comments !== null">意见：{{ evaluation.comments }}</span>
      </li>
    </ul>

    <form v-if="canManage" class="panel-form" @submit.prevent="submit">
      <h4>记录评价</h4>
      <label>
        <span>关联面试</span>
        <el-select v-model="form.interviewId" data-testid="evaluation-interview">
          <el-option value="" label="请选择面试" />
          <el-option
            v-for="interview in interviews"
            :key="interview.id"
            :value="String(interview.id)"
            :label="`#${interview.id} · ${interview.interviewer} · ${formatDateTimeInTimezone(interview.scheduledAt)}`"
          />
        </el-select>
      </label>
      <label>
        <span>评分（1-5）</span>
        <el-input v-model="form.rating" data-testid="evaluation-rating" inputmode="numeric" />
      </label>
      <label>
        <span>建议</span>
        <el-select v-model="form.recommendation" data-testid="evaluation-recommendation">
          <el-option value="STRONG_YES" label="强烈推荐" />
          <el-option value="YES" label="推荐" />
          <el-option value="NO" label="不推荐" />
          <el-option value="STRONG_NO" label="强烈不推荐" />
        </el-select>
      </label>
      <label>
        <span>优势</span>
        <el-input v-model="form.strengths" data-testid="evaluation-strengths" type="textarea" :rows="2" maxlength="4000" />
      </label>
      <label>
        <span>顾虑</span>
        <el-input v-model="form.concerns" data-testid="evaluation-concerns" type="textarea" :rows="2" maxlength="4000" />
      </label>
      <label>
        <span>意见</span>
        <el-input v-model="form.comments" data-testid="evaluation-comments" type="textarea" :rows="2" maxlength="4000" />
      </label>
      <p v-if="formError !== ''" class="panel-error" role="alert">{{ formError }}</p>
      <button type="submit" class="panel-primary" :disabled="saving || interviews.length === 0" data-testid="evaluation-submit">
        {{ saving ? '提交中…' : '记录评价' }}
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
