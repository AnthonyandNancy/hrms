<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { resourcePost } from '@/api/resourceClient'
import { talentErrorInfo, type TalentErrorInfo } from './talentErrors'
import { nextIdempotencyKey } from './talentRead'
import type { TalentFormKind, TalentRow } from './types'

type SectionDraft = {
  key: string
  weight: string
}

const props = defineProps<{
  kind: TalentFormKind
  routeId?: string
}>()

const emit = defineEmits<{
  created: [row: TalentRow]
}>()

const form = reactive({
  cycleName: '',
  periodFrom: '',
  periodTo: '',
  templateCode: '',
  templateName: '',
  sections: [
    { key: 'goals', weight: '60' },
    { key: 'competency', weight: '40' },
  ] as SectionDraft[],
  reviewCycleId: '',
  reviewEmploymentId: '',
  reviewManagerEmploymentId: '',
  reviewGoals: '',
  reviewSelfAssessment: '',
  reviewSelfRating: '',
  reviewId: '',
  managerAssessment: '',
  managerRating: '',
  appealReason: '',
  indicatorCode: '',
  indicatorName: '',
  indicatorCategory: '',
  indicatorDataSource: '',
  goalCycleId: '',
  goalEmploymentId: '',
  goalIndicatorId: '',
  goalTitle: '',
  goalTarget: '',
  goalWeight: '',
  goalDueOn: '',
  feedbackCycleId: '',
  feedbackReviewId: '',
  feedbackFromEmploymentId: '',
  feedbackToEmploymentId: '',
  feedbackType: 'PEER',
  feedbackRating: '',
  feedbackComments: '',
  feedbackAnonymous: false,
  calibrationReviewId: '',
  calibrationRating: '',
  calibrationReason: '',
  publishCycleId: '',
  requisitionNo: '',
  requisitionTitle: '',
  legalEntityId: '1',
  orgUnitId: '',
  headcount: '1',
  targetStartDate: '',
  openingRequisitionId: '',
  openingChannel: 'EXTERNAL',
  candidateName: '',
  candidateEmail: '',
  candidateMobile: '',
  candidateSource: '',
  interviewCandidateId: '',
  interviewRequisitionId: '',
  interviewScheduledAt: '',
  interviewDuration: '60',
  interviewInterviewer: '',
  interviewMode: 'VIDEO',
  offerCandidateId: '',
  offerRequisitionId: '',
  offerSalary: '',
  offerCurrency: 'CNY',
  offerStartDate: '',
  offerId: '',
  acceptLegalEntityId: '1',
  acceptOrgUnitId: '',
  acceptEmployeeNo: '',
  acceptHireDate: '',
  programCode: '',
  programTitle: '',
  programCapacity: '',
  sessionProgramId: '',
  sessionStartsOn: '',
  sessionEndsOn: '',
  sessionStartsAt: '',
  sessionEndsAt: '',
  sessionCapacity: '20',
  sessionLocation: '',
  enrollmentSessionId: '',
  enrollmentEmploymentId: '',
  completionEnrollmentId: '',
  completionResult: 'ATTENDED',
  completionScore: '',
  completedAttended: true,
})

const submitting = ref(false)
const formError = ref<TalentErrorInfo | null>(null)
const successMessage = ref('')

function resetFeedback(): void {
  formError.value = null
  successMessage.value = ''
}

function fail(error: unknown): void {
  formError.value = talentErrorInfo(error)
}

function success(message: string, row: TalentRow): void {
  successMessage.value = message
  emit('created', row)
}

function numeric(value: string): number | null {
  if (value.trim() === '') return null
  const parsed = Number(value)
  return Number.isFinite(parsed) ? parsed : null
}

const sectionTotal = computed(() =>
  form.sections.reduce((sum, section) => sum + (numeric(section.weight) ?? 0), 0),
)
const sectionTotalValid = computed(() => sectionTotal.value === 100)
const sectionTotalCopy = computed(() =>
  sectionTotalValid.value ? '权重合计 100，可提交' : `权重合计必须等于 100（当前 ${sectionTotal.value}）`,
)

function addSection(): void {
  form.sections.push({ key: '', weight: '' })
}

function removeSection(index: number): void {
  if (form.sections.length > 1) form.sections.splice(index, 1)
}

async function submitTemplate(): Promise<void> {
  resetFeedback()
  if (!sectionTotalValid.value) {
    formError.value = {
      code: 'CLIENT_INVALID_WEIGHT_TOTAL',
      status: null,
      message: '权重合计必须等于 100，请调整各分项权重后再提交。',
      requestId: null,
      reason: 'INVALID_WEIGHT_TOTAL',
      fieldErrors: {},
    }
    return
  }

  const sections = form.sections
    .map((section) => ({ key: section.key.trim(), weight: numeric(section.weight) }))
    .filter((section): section is { key: string; weight: number } => section.key !== '' && section.weight !== null)

  submitting.value = true
  try {
    const row = await resourcePost<TalentRow>(
      '/v1/performance/templates',
      { code: form.templateCode.trim(), name: form.templateName.trim(), sections },
      { idempotencyKey: nextIdempotencyKey('template') },
    )
    success(`模板“${form.templateName.trim()}”已发布`, row)
  } catch (error) {
    const info = talentErrorInfo(error)
    if (info.reason === null && info.status === 422 && info.fieldErrors.sections !== undefined) {
      info.reason = 'INVALID_WEIGHT_TOTAL'
    }
    formError.value = info
  } finally {
    submitting.value = false
  }
}

async function submitCycle(): Promise<void> {
  resetFeedback()
  submitting.value = true
  try {
    const row = await resourcePost<TalentRow>(
      '/v1/performance/cycles',
      {
        name: form.cycleName.trim(),
        period_from: form.periodFrom,
        period_to: form.periodTo,
      },
      { idempotencyKey: nextIdempotencyKey('cycle') },
    )
    success(`绩效周期“${form.cycleName.trim()}”已创建`, row)
  } catch (error) {
    fail(error)
  } finally {
    submitting.value = false
  }
}

async function submitReview(): Promise<void> {
  resetFeedback()
  submitting.value = true
  try {
    const row = await resourcePost<TalentRow>(
      '/v1/performance/reviews',
      {
        cycle_id: numeric(form.reviewCycleId),
        employment_id: numeric(form.reviewEmploymentId),
        manager_employment_id: numeric(form.reviewManagerEmploymentId),
        goals_text: form.reviewGoals.trim() || null,
        self_assessment: form.reviewSelfAssessment.trim() || null,
        self_rating: form.reviewSelfRating.trim() || null,
      },
      { idempotencyKey: nextIdempotencyKey('review') },
    )
    success('评审已创建，可继续提交评分', row)
  } catch (error) {
    fail(error)
  } finally {
    submitting.value = false
  }
}

async function submitReviewAction(): Promise<void> {
  resetFeedback()
  const reviewId = numeric(form.reviewId)
  if (reviewId === null) return
  submitting.value = true
  try {
    const row = await resourcePost<TalentRow>(
      `/v1/performance/reviews/${reviewId}/submit`,
      {
        manager_assessment: form.managerAssessment.trim() || null,
        manager_rating: form.managerRating.trim() || null,
      },
      { idempotencyKey: nextIdempotencyKey('review-submit') },
    )
    success(`评审 ${reviewId} 已提交`, row)
  } catch (error) {
    fail(error)
  } finally {
    submitting.value = false
  }
}

async function submitAppeal(): Promise<void> {
  resetFeedback()
  const reviewId = numeric(form.reviewId)
  if (reviewId === null) return
  submitting.value = true
  try {
    const row = await resourcePost<TalentRow>(
      `/v1/performance/reviews/${reviewId}/appeals`,
      { reason: form.appealReason.trim() },
      { idempotencyKey: nextIdempotencyKey('appeal') },
    )
    success(`评审 ${reviewId} 的申诉已创建`, row)
  } catch (error) {
    fail(error)
  } finally {
    submitting.value = false
  }
}

async function submitIndicator(): Promise<void> {
  resetFeedback()
  submitting.value = true
  try {
    const row = await resourcePost<TalentRow>(
      '/v1/performance/indicators',
      {
        code: form.indicatorCode.trim(),
        name: form.indicatorName.trim(),
        category: form.indicatorCategory.trim() || null,
        data_source: form.indicatorDataSource.trim() || null,
        active: true,
      },
      { idempotencyKey: nextIdempotencyKey('indicator') },
    )
    success(`指标“${form.indicatorCode.trim()}”已创建`, row)
  } catch (error) {
    fail(error)
  } finally {
    submitting.value = false
  }
}

async function submitGoal(): Promise<void> {
  resetFeedback()
  submitting.value = true
  try {
    const row = await resourcePost<TalentRow>(
      '/v1/performance/goals',
      {
        cycle_id: numeric(form.goalCycleId),
        employment_id: numeric(form.goalEmploymentId),
        indicator_id: numeric(form.goalIndicatorId),
        title: form.goalTitle.trim(),
        target_value: form.goalTarget.trim(),
        weight: numeric(form.goalWeight),
        due_on: form.goalDueOn || null,
      },
      { idempotencyKey: nextIdempotencyKey('goal') },
    )
    success('绩效目标已保存', row)
  } catch (error) {
    fail(error)
  } finally {
    submitting.value = false
  }
}

async function submitFeedback(): Promise<void> {
  resetFeedback()
  submitting.value = true
  try {
    const row = await resourcePost<TalentRow>(
      '/v1/performance/feedback',
      {
        cycle_id: numeric(form.feedbackCycleId),
        review_id: numeric(form.feedbackReviewId),
        from_employment_id: numeric(form.feedbackFromEmploymentId),
        to_employment_id: numeric(form.feedbackToEmploymentId),
        feedback_type: form.feedbackType,
        rating: form.feedbackRating.trim() || null,
        comments: form.feedbackComments.trim(),
        anonymous: form.feedbackAnonymous,
      },
      { idempotencyKey: nextIdempotencyKey('feedback') },
    )
    success('360 反馈已记录', row)
  } catch (error) {
    fail(error)
  } finally {
    submitting.value = false
  }
}

async function submitCalibration(): Promise<void> {
  resetFeedback()
  const reviewId = numeric(form.calibrationReviewId)
  if (reviewId === null) return
  submitting.value = true
  try {
    const row = await resourcePost<TalentRow>(
      `/v1/performance/reviews/${reviewId}/calibration`,
      {
        calibrated_rating: form.calibrationRating.trim(),
        reason: form.calibrationReason.trim(),
      },
      { idempotencyKey: nextIdempotencyKey('calibration') },
    )
    success(`评审 ${reviewId} 已校准；自评与主管评分保持不变`, row)
  } catch (error) {
    fail(error)
  } finally {
    submitting.value = false
  }
}

async function submitPublish(): Promise<void> {
  resetFeedback()
  const cycleId = numeric(form.publishCycleId)
  if (cycleId === null) return
  submitting.value = true
  try {
    const row = await resourcePost<TalentRow>(
      `/v1/performance/cycles/${cycleId}/publish`,
      {},
      { idempotencyKey: nextIdempotencyKey('publish') },
    )
    success(`周期 ${cycleId} 已发布，评审结果冻结`, row)
  } catch (error) {
    fail(error)
  } finally {
    submitting.value = false
  }
}

async function submitRequisition(): Promise<void> {
  resetFeedback()
  submitting.value = true
  try {
    const row = await resourcePost<TalentRow>(
      '/v1/recruitment/requisitions',
      {
        legal_entity_id: numeric(form.legalEntityId),
        org_unit_id: numeric(form.orgUnitId),
        requisition_no: form.requisitionNo.trim(),
        title: form.requisitionTitle.trim(),
        headcount: numeric(form.headcount),
        target_start_date: form.targetStartDate || null,
      },
      { idempotencyKey: nextIdempotencyKey('requisition') },
    )
    success(`招聘需求“${form.requisitionNo.trim()}”已创建并提交审批`, row)
  } catch (error) {
    fail(error)
  } finally {
    submitting.value = false
  }
}

async function submitOpening(): Promise<void> {
  resetFeedback()
  const requisitionId = numeric(form.openingRequisitionId)
  if (requisitionId === null) return
  submitting.value = true
  try {
    const row = await resourcePost<TalentRow>(
      `/v1/recruitment/requisitions/${requisitionId}/openings`,
      { channel: form.openingChannel },
      { idempotencyKey: nextIdempotencyKey('opening') },
    )
    success(`需求 ${requisitionId} 已发布 ${form.openingChannel} 渠道岗位`, row)
  } catch (error) {
    fail(error)
  } finally {
    submitting.value = false
  }
}

async function submitCandidate(): Promise<void> {
  resetFeedback()
  submitting.value = true
  try {
    const row = await resourcePost<TalentRow>(
      '/v1/recruitment/candidates',
      {
        display_name: form.candidateName.trim(),
        email: form.candidateEmail.trim() || null,
        mobile: form.candidateMobile.trim() || null,
        source: form.candidateSource.trim() || null,
      },
      { idempotencyKey: nextIdempotencyKey('candidate') },
    )
    success(`候选人“${form.candidateName.trim()}”已创建`, row)
  } catch (error) {
    fail(error)
  } finally {
    submitting.value = false
  }
}

async function submitInterview(): Promise<void> {
  resetFeedback()
  const candidateId = numeric(form.interviewCandidateId)
  if (candidateId === null) return
  submitting.value = true
  try {
    const row = await resourcePost<TalentRow>(
      `/v1/recruitment/candidates/${candidateId}/interviews`,
      {
        requisition_id: numeric(form.interviewRequisitionId),
        scheduled_at: form.interviewScheduledAt,
        duration_minutes: numeric(form.interviewDuration),
        interviewer: form.interviewInterviewer.trim(),
        mode: form.interviewMode,
      },
      { idempotencyKey: nextIdempotencyKey('interview') },
    )
    success(`候选人 ${candidateId} 的面试已安排`, row)
  } catch (error) {
    fail(error)
  } finally {
    submitting.value = false
  }
}

async function submitOffer(): Promise<void> {
  resetFeedback()
  submitting.value = true
  try {
    const row = await resourcePost<TalentRow>(
      '/v1/recruitment/offers',
      {
        candidate_id: numeric(form.offerCandidateId),
        requisition_id: numeric(form.offerRequisitionId),
        base_salary_minor: numeric(form.offerSalary),
        salary_currency: form.offerCurrency.trim().toUpperCase(),
        start_date: form.offerStartDate,
      },
      { idempotencyKey: nextIdempotencyKey('offer') },
    )
    success('Offer 已创建并提交审批', row)
  } catch (error) {
    fail(error)
  } finally {
    submitting.value = false
  }
}

async function submitAcceptOffer(): Promise<void> {
  resetFeedback()
  const offerId = numeric(props.routeId ?? form.offerId)
  if (offerId === null) return
  submitting.value = true
  try {
    const row = await resourcePost<TalentRow>(
      `/v1/recruitment/offers/${offerId}/accept`,
      {
        legal_entity_id: numeric(form.acceptLegalEntityId),
        org_unit_id: numeric(form.acceptOrgUnitId),
        employee_no: form.acceptEmployeeNo.trim(),
        hire_date: form.acceptHireDate || null,
      },
      { idempotencyKey: nextIdempotencyKey('offer-accept') },
    )
    success(`Offer ${offerId} 已接受，入职草稿已生成`, row)
  } catch (error) {
    fail(error)
  } finally {
    submitting.value = false
  }
}

async function submitProgram(): Promise<void> {
  resetFeedback()
  submitting.value = true
  try {
    const row = await resourcePost<TalentRow>(
      '/v1/training/programs',
      {
        code: form.programCode.trim(),
        title: form.programTitle.trim(),
        default_capacity: numeric(form.programCapacity),
      },
      { idempotencyKey: nextIdempotencyKey('program') },
    )
    success(`培训项目“${form.programCode.trim()}”已创建`, row)
  } catch (error) {
    fail(error)
  } finally {
    submitting.value = false
  }
}

async function submitSession(): Promise<void> {
  resetFeedback()
  submitting.value = true
  try {
    const row = await resourcePost<TalentRow>(
      '/v1/training/sessions',
      {
        program_id: numeric(form.sessionProgramId),
        starts_on: form.sessionStartsOn,
        ends_on: form.sessionEndsOn,
        starts_at: form.sessionStartsAt,
        ends_at: form.sessionEndsAt,
        capacity: numeric(form.sessionCapacity),
        location: form.sessionLocation.trim() || null,
      },
      { idempotencyKey: nextIdempotencyKey('session') },
    )
    success('培训场次已创建', row)
  } catch (error) {
    fail(error)
  } finally {
    submitting.value = false
  }
}

async function submitEnrollment(): Promise<void> {
  resetFeedback()
  submitting.value = true
  try {
    const row = await resourcePost<TalentRow>(
      '/v1/training/enrollments',
      {
        session_id: numeric(form.enrollmentSessionId),
        employment_id: numeric(form.enrollmentEmploymentId),
      },
      { idempotencyKey: nextIdempotencyKey('enrollment') },
    )
    success(`报名编号 ${String(row.enrollment_id ?? '')} 已确认`, row)
  } catch (error) {
    fail(error)
  } finally {
    submitting.value = false
  }
}

async function submitCompletion(): Promise<void> {
  resetFeedback()
  const enrollmentId = numeric(form.completionEnrollmentId)
  if (enrollmentId === null) return
  submitting.value = true
  try {
    const row = await resourcePost<TalentRow>(
      `/v1/training/enrollments/${enrollmentId}/complete`,
      {
        attended: form.completedAttended,
        completion_result: form.completionResult,
        score: numeric(form.completionScore),
      },
      { idempotencyKey: nextIdempotencyKey('completion') },
    )
    success(`报名 ${enrollmentId} 的完成记录已保存`, row)
  } catch (error) {
    fail(error)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <section class="talent-card">
    <header class="talent-card__header">
      <div>
        <h2 class="talent-card__title">操作表单</h2>
        <p class="talent-card__subtitle">写入只调用本页已上线的真实接口；需要 Idempotency-Key 的动作由前端逐次生成。</p>
      </div>
      <span class="talent-form-kind">{{ kind }}</span>
    </header>

    <div v-if="formError" class="talent-form-error" role="alert" data-testid="talent-form-error">
      <p class="talent-form-error__title">提交未完成</p>
      <p class="talent-form-error__body">{{ formError.message }}</p>
      <p v-if="formError.reason" class="talent-form-error__reason" data-testid="talent-form-reason">
        服务端原因：<span class="mono">{{ formError.reason }}</span>
      </p>
      <p v-if="formError.requestId" class="talent-form-error__request" data-testid="request-id">
        请求编号：<span class="mono">{{ formError.requestId }}</span>
      </p>
      <p v-if="formError.code" class="talent-form-error__code">错误类别：{{ formError.code }}</p>
      <ul v-if="Object.keys(formError.fieldErrors).length > 0" class="talent-form-error__fields">
        <li v-for="(messages, field) in formError.fieldErrors" :key="field">
          <strong>{{ field }}</strong>：{{ messages.join('；') }}
        </li>
      </ul>
    </div>
    <p v-if="successMessage" class="talent-form-success" role="status" data-testid="talent-form-success">{{ successMessage }}</p>

    <form v-if="kind === 'cycle-create'" class="talent-form" @submit.prevent="submitCycle">
      <label class="talent-field">周期名称<input v-model="form.cycleName" required maxlength="120" /></label>
      <label class="talent-field">开始日期<input v-model="form.periodFrom" required type="date" /></label>
      <label class="talent-field">结束日期<input v-model="form.periodTo" required type="date" /></label>
      <button type="submit" :disabled="submitting" data-testid="cycle-submit">创建周期</button>
    </form>

    <form v-else-if="kind === 'template-create'" class="talent-form" @submit.prevent="submitTemplate">
      <label class="talent-field">模板编码<input v-model="form.templateCode" data-testid="template-code" required maxlength="64" /></label>
      <label class="talent-field">模板名称<input v-model="form.templateName" data-testid="template-name" required maxlength="120" /></label>
      <fieldset class="talent-sections">
        <legend>分项权重</legend>
        <div v-for="(section, index) in form.sections" :key="index" class="talent-section-row">
          <label class="talent-field">
            分项 {{ index + 1 }} 标识
            <input v-model="section.key" :data-testid="`template-section-${index + 1}-key`" required maxlength="64" />
          </label>
          <label class="talent-field">
            权重
            <input v-model="section.weight" :data-testid="`template-section-${index + 1}-weight`" required inputmode="numeric" />
          </label>
          <button type="button" class="talent-button-secondary" @click="removeSection(index)">移除</button>
        </div>
        <button type="button" class="talent-button-secondary" @click="addSection">添加分项</button>
      </fieldset>
      <p :class="['talent-weight-total', { 'talent-weight-total--invalid': !sectionTotalValid }]" data-testid="template-weight-total">
        {{ sectionTotalCopy }}
      </p>
      <button type="submit" :disabled="submitting" data-testid="template-submit">发布模板</button>
    </form>

    <form v-else-if="kind === 'indicator-create'" class="talent-form" @submit.prevent="submitIndicator">
      <label class="talent-field">指标编码<input v-model="form.indicatorCode" data-testid="indicator-code" required maxlength="64" /></label>
      <label class="talent-field">指标名称<input v-model="form.indicatorName" data-testid="indicator-name" required maxlength="160" /></label>
      <label class="talent-field">分类<input v-model="form.indicatorCategory" maxlength="64" /></label>
      <label class="talent-field">数据来源<input v-model="form.indicatorDataSource" maxlength="120" /></label>
      <button type="submit" :disabled="submitting" data-testid="indicator-submit">创建指标</button>
    </form>

    <form v-else-if="kind === 'goal-create'" class="talent-form" @submit.prevent="submitGoal">
      <label class="talent-field">周期 ID<input v-model="form.goalCycleId" data-testid="goal-cycle-id" inputmode="numeric" required /></label>
      <label class="talent-field">员工任职 ID<input v-model="form.goalEmploymentId" data-testid="goal-employment-id" inputmode="numeric" required /></label>
      <label class="talent-field">指标 ID<input v-model="form.goalIndicatorId" data-testid="goal-indicator-id" inputmode="numeric" /></label>
      <label class="talent-field">目标标题<input v-model="form.goalTitle" data-testid="goal-title" required maxlength="200" /></label>
      <label class="talent-field">目标值<input v-model="form.goalTarget" data-testid="goal-target" required maxlength="120" /></label>
      <label class="talent-field">权重<input v-model="form.goalWeight" data-testid="goal-weight" inputmode="numeric" /></label>
      <label class="talent-field">截止日期<input v-model="form.goalDueOn" type="date" /></label>
      <button type="submit" :disabled="submitting" data-testid="goal-submit">保存目标</button>
    </form>

    <form v-else-if="kind === 'feedback-create'" class="talent-form" @submit.prevent="submitFeedback">
      <label class="talent-field">周期 ID<input v-model="form.feedbackCycleId" data-testid="feedback-cycle-id" inputmode="numeric" required /></label>
      <label class="talent-field">评审 ID<input v-model="form.feedbackReviewId" data-testid="feedback-review-id" inputmode="numeric" /></label>
      <label class="talent-field">评价人任职 ID<input v-model="form.feedbackFromEmploymentId" data-testid="feedback-from-employment-id" inputmode="numeric" required /></label>
      <label class="talent-field">被评价人任职 ID<input v-model="form.feedbackToEmploymentId" data-testid="feedback-to-employment-id" inputmode="numeric" required /></label>
      <label class="talent-field">
        反馈类型
        <select v-model="form.feedbackType" data-testid="feedback-type">
          <option value="PEER">同级</option>
          <option value="MANAGER">主管</option>
          <option value="SELF">自评</option>
          <option value="UPWARD">向上</option>
          <option value="CROSS">跨团队</option>
        </select>
      </label>
      <label class="talent-field">评级<input v-model="form.feedbackRating" maxlength="16" /></label>
      <label class="talent-field talent-field--wide">反馈内容<textarea v-model="form.feedbackComments" data-testid="feedback-comments" rows="3" required maxlength="10000" /></label>
      <label class="talent-checkbox"><input v-model="form.feedbackAnonymous" type="checkbox" /> 匿名反馈</label>
      <button type="submit" :disabled="submitting" data-testid="feedback-submit">记录反馈</button>
    </form>

    <form v-else-if="kind === 'calibration-create'" class="talent-form" @submit.prevent="submitCalibration">
      <label class="talent-field">评审 ID<input v-model="form.calibrationReviewId" data-testid="calibration-review-id" inputmode="numeric" required /></label>
      <label class="talent-field">校准等级<input v-model="form.calibrationRating" data-testid="calibration-rating" required maxlength="16" /></label>
      <label class="talent-field talent-field--wide">校准理由<textarea v-model="form.calibrationReason" data-testid="calibration-reason" rows="3" required minlength="3" maxlength="4000" /></label>
      <button type="submit" :disabled="submitting" data-testid="calibration-submit">保存校准</button>
    </form>

    <form v-else-if="kind === 'cycle-publish'" class="talent-form" @submit.prevent="submitPublish">
      <label class="talent-field">周期 ID<input v-model="form.publishCycleId" data-testid="publish-cycle-id" inputmode="numeric" required /></label>
      <button type="submit" :disabled="submitting" data-testid="publish-submit">发布周期结果</button>
    </form>

    <div v-else-if="kind === 'review-create'" class="talent-form">
      <label class="talent-field">周期 ID<input v-model="form.reviewCycleId" inputmode="numeric" required /></label>
      <label class="talent-field">员工任职 ID<input v-model="form.reviewEmploymentId" inputmode="numeric" required /></label>
      <label class="talent-field">主管任职 ID<input v-model="form.reviewManagerEmploymentId" inputmode="numeric" /></label>
      <label class="talent-field talent-field--wide">目标<textarea v-model="form.reviewGoals" rows="3" maxlength="10000" /></label>
      <label class="talent-field talent-field--wide">自评<textarea v-model="form.reviewSelfAssessment" rows="3" maxlength="10000" /></label>
      <label class="talent-field">自评等级<input v-model="form.reviewSelfRating" maxlength="16" /></label>
      <button type="button" :disabled="submitting" data-testid="review-create-submit" @click="submitReview">创建评审</button>
    </div>

    <div v-else-if="kind === 'review-submit'" class="talent-form">
      <label class="talent-field">评审 ID<input v-model="form.reviewId" inputmode="numeric" required /></label>
      <label class="talent-field talent-field--wide">主管评语<textarea v-model="form.managerAssessment" rows="3" maxlength="10000" /></label>
      <label class="talent-field">主管评分<input v-model="form.managerRating" maxlength="16" /></label>
      <button type="button" :disabled="submitting" data-testid="review-submit-submit" @click="submitReviewAction">提交评审</button>
    </div>

    <div v-else-if="kind === 'review-appeal'" class="talent-form">
      <label class="talent-field">评审 ID<input v-model="form.reviewId" inputmode="numeric" required /></label>
      <label class="talent-field talent-field--wide">申诉理由<textarea v-model="form.appealReason" rows="4" minlength="10" maxlength="4000" required /></label>
      <button type="button" :disabled="submitting" data-testid="appeal-submit" @click="submitAppeal">提交申诉</button>
    </div>

    <form v-else-if="kind === 'requisition-create'" class="talent-form" @submit.prevent="submitRequisition">
      <label class="talent-field">法人 ID<input v-model="form.legalEntityId" inputmode="numeric" required /></label>
      <label class="talent-field">组织单元 ID<input v-model="form.orgUnitId" inputmode="numeric" required /></label>
      <label class="talent-field">需求编号<input v-model="form.requisitionNo" required maxlength="64" /></label>
      <label class="talent-field">岗位名称<input v-model="form.requisitionTitle" required maxlength="160" /></label>
      <label class="talent-field">招聘人数<input v-model="form.headcount" inputmode="numeric" required /></label>
      <label class="talent-field">计划入职日<input v-model="form.targetStartDate" type="date" /></label>
      <button type="submit" :disabled="submitting" data-testid="requisition-submit">创建并提交审批</button>
    </form>

    <form v-else-if="kind === 'opening-publish'" class="talent-form" @submit.prevent="submitOpening">
      <label class="talent-field">需求 ID<input v-model="form.openingRequisitionId" data-testid="opening-requisition-id" inputmode="numeric" required /></label>
      <label class="talent-field">
        发布渠道
        <select v-model="form.openingChannel" data-testid="opening-channel">
          <option value="INTERNAL">内部</option>
          <option value="EXTERNAL">外部</option>
          <option value="REFERRAL">内推</option>
          <option value="AGENCY">猎头</option>
        </select>
      </label>
      <button type="submit" :disabled="submitting" data-testid="opening-submit">发布岗位</button>
    </form>

    <form v-else-if="kind === 'candidate-create'" class="talent-form" @submit.prevent="submitCandidate">
      <label class="talent-field">姓名<input v-model="form.candidateName" required maxlength="120" /></label>
      <label class="talent-field">邮箱<input v-model="form.candidateEmail" type="email" maxlength="254" /></label>
      <label class="talent-field">手机号<input v-model="form.candidateMobile" maxlength="40" /></label>
      <label class="talent-field">来源<input v-model="form.candidateSource" maxlength="64" /></label>
      <button type="submit" :disabled="submitting" data-testid="candidate-submit">创建候选人</button>
    </form>

    <form v-else-if="kind === 'interview-schedule'" class="talent-form" @submit.prevent="submitInterview">
      <label class="talent-field">候选人 ID<input v-model="form.interviewCandidateId" inputmode="numeric" required /></label>
      <label class="talent-field">需求 ID<input v-model="form.interviewRequisitionId" inputmode="numeric" required /></label>
      <label class="talent-field">面试时间<input v-model="form.interviewScheduledAt" type="datetime-local" required /></label>
      <label class="talent-field">时长（分钟）<input v-model="form.interviewDuration" inputmode="numeric" /></label>
      <label class="talent-field">面试官<input v-model="form.interviewInterviewer" required maxlength="120" /></label>
      <label class="talent-field">
        方式
        <select v-model="form.interviewMode">
          <option value="VIDEO">视频</option>
          <option value="ONSITE">现场</option>
          <option value="PHONE">电话</option>
        </select>
      </label>
      <button type="submit" :disabled="submitting" data-testid="interview-submit">安排面试</button>
    </form>

    <form v-else-if="kind === 'offer-create'" class="talent-form" @submit.prevent="submitOffer">
      <label class="talent-field">候选人 ID<input v-model="form.offerCandidateId" inputmode="numeric" required /></label>
      <label class="talent-field">需求 ID<input v-model="form.offerRequisitionId" inputmode="numeric" required /></label>
      <label class="talent-field">基本薪资（最小货币单位）<input v-model="form.offerSalary" inputmode="numeric" required /></label>
      <label class="talent-field">币种<input v-model="form.offerCurrency" required maxlength="3" /></label>
      <label class="talent-field">入职日期<input v-model="form.offerStartDate" type="date" required /></label>
      <button type="submit" :disabled="submitting" data-testid="offer-submit">创建 Offer</button>
    </form>

    <form v-else-if="kind === 'offer-accept'" class="talent-form" @submit.prevent="submitAcceptOffer">
      <label class="talent-field">Offer ID<input v-model="form.offerId" :disabled="Boolean(routeId)" :value="routeId ?? form.offerId" inputmode="numeric" required /></label>
      <label class="talent-field">法人 ID<input v-model="form.acceptLegalEntityId" inputmode="numeric" required /></label>
      <label class="talent-field">组织单元 ID<input v-model="form.acceptOrgUnitId" inputmode="numeric" required /></label>
      <label class="talent-field">员工编号<input v-model="form.acceptEmployeeNo" required maxlength="64" /></label>
      <label class="talent-field">入职日期<input v-model="form.acceptHireDate" type="date" /></label>
      <button type="submit" :disabled="submitting" data-testid="offer-accept-submit">接受 Offer</button>
    </form>

    <form v-else-if="kind === 'program-create'" class="talent-form" @submit.prevent="submitProgram">
      <label class="talent-field">项目编码<input v-model="form.programCode" required maxlength="64" /></label>
      <label class="talent-field">项目名称<input v-model="form.programTitle" required maxlength="160" /></label>
      <label class="talent-field">默认容量<input v-model="form.programCapacity" inputmode="numeric" /></label>
      <button type="submit" :disabled="submitting" data-testid="program-submit">创建培训项目</button>
    </form>

    <form v-else-if="kind === 'session-create'" class="talent-form" @submit.prevent="submitSession">
      <label class="talent-field">培训项目 ID<input v-model="form.sessionProgramId" data-testid="session-program-id" inputmode="numeric" required /></label>
      <label class="talent-field">开始日期<input v-model="form.sessionStartsOn" data-testid="session-starts-on" type="date" required /></label>
      <label class="talent-field">结束日期<input v-model="form.sessionEndsOn" data-testid="session-ends-on" type="date" required /></label>
      <label class="talent-field">开始时间<input v-model="form.sessionStartsAt" data-testid="session-starts-at" type="datetime-local" required /></label>
      <label class="talent-field">结束时间<input v-model="form.sessionEndsAt" data-testid="session-ends-at" type="datetime-local" required /></label>
      <label class="talent-field">容量<input v-model="form.sessionCapacity" data-testid="session-capacity" inputmode="numeric" required /></label>
      <label class="talent-field">地点<input v-model="form.sessionLocation" maxlength="160" /></label>
      <button type="submit" :disabled="submitting" data-testid="session-submit">创建场次</button>
    </form>

    <div v-else-if="kind === 'enrollment-create'" class="talent-form">
      <label class="talent-field">场次 ID<input v-model="form.enrollmentSessionId" data-testid="enrollment-session-id" inputmode="numeric" required /></label>
      <label class="talent-field">员工任职 ID<input v-model="form.enrollmentEmploymentId" data-testid="enrollment-employment-id" inputmode="numeric" required /></label>
      <button type="button" :disabled="submitting" data-testid="enrollment-submit" @click="submitEnrollment">报名</button>
    </div>

    <div v-else-if="kind === 'enrollment-complete'" class="talent-form">
      <label class="talent-field">报名 ID<input v-model="form.completionEnrollmentId" inputmode="numeric" required /></label>
      <label class="talent-field">
        完成结果
        <select v-model="form.completionResult">
          <option value="PASS">通过</option>
          <option value="FAIL">未通过</option>
          <option value="ATTENDED">已参加</option>
        </select>
      </label>
      <label class="talent-field">成绩<input v-model="form.completionScore" inputmode="numeric" /></label>
      <label class="talent-checkbox"><input v-model="form.completedAttended" type="checkbox" /> 已到场</label>
      <button type="button" :disabled="submitting" data-testid="completion-submit" @click="submitCompletion">保存完成记录</button>
    </div>

    <p v-else class="talent-pending-copy">此操作没有已上线的写入接口。</p>
  </section>
</template>

<style scoped>
.talent-card {
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-soft);
}

.talent-card__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 14px;
  padding: 15px 17px;
  border-bottom: 1px solid var(--color-line);
}

.talent-card__title {
  font-size: 14px;
  font-weight: 650;
}

.talent-card__subtitle {
  margin-top: 3px;
  color: var(--color-ink-muted);
  font-size: 12px;
}

.talent-form-kind {
  flex: 0 0 auto;
  padding: 3px 7px;
  color: var(--color-ink-muted);
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 10.5px;
  background: var(--color-canvas);
  border: 1px solid var(--color-line);
  border-radius: 4px;
}

.talent-form {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 12px;
  padding: 16px 17px;
}

.talent-field {
  display: flex;
  flex: 1 1 180px;
  flex-direction: column;
  gap: 5px;
  color: var(--color-ink-secondary);
  font-size: 12px;
  font-weight: 600;
}

.talent-field--wide {
  flex-basis: 100%;
}

.talent-field input,
.talent-field select,
.talent-field textarea {
  width: 100%;
  min-height: 34px;
  padding: 7px 9px;
  color: var(--color-ink);
  font: inherit;
  font-weight: 400;
  background: #fff;
  border: 1px solid var(--color-line-strong);
  border-radius: var(--radius-sm);
}

.talent-field input:disabled {
  background: var(--color-canvas);
  color: var(--color-ink-muted);
}

.talent-form button,
.talent-button-secondary {
  min-height: 34px;
  padding: 7px 13px;
  color: #fff;
  font: inherit;
  font-size: 12.5px;
  font-weight: 600;
  background: var(--color-brand);
  border: 1px solid var(--color-brand);
  border-radius: var(--radius-sm);
  cursor: pointer;
}

.talent-form button:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.talent-button-secondary {
  align-self: flex-end;
  color: var(--color-brand);
  background: #fff;
}

.talent-sections {
  display: flex;
  flex: 1 1 100%;
  flex-direction: column;
  gap: 10px;
  padding: 12px;
  border: 1px dashed var(--color-line-strong);
  border-radius: var(--radius-sm);
}

.talent-sections legend {
  padding: 0 5px;
  color: var(--color-ink-muted);
  font-size: 12px;
  font-weight: 650;
}

.talent-section-row {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 10px;
}

.talent-weight-total {
  flex: 1 1 100%;
  color: var(--color-success);
  font-size: 12px;
  font-weight: 650;
}

.talent-weight-total--invalid {
  color: var(--color-danger);
}

.talent-form-error {
  margin: 14px 17px 0;
  padding: 11px 13px;
  border: 1px solid #ecc3c3;
  border-radius: var(--radius-sm);
  background: #fffafa;
}

.talent-form-error__title {
  color: var(--color-danger);
  font-size: 12.5px;
  font-weight: 650;
}

.talent-form-error__body,
.talent-form-error__request,
.talent-form-error__reason,
.talent-form-error__code,
.talent-form-error__fields {
  margin-top: 4px;
  color: var(--color-ink-secondary);
  font-size: 12px;
}

.talent-form-error__request,
.talent-form-error__reason {
  color: var(--color-danger);
  font-weight: 600;
}

.talent-form-success {
  margin: 14px 17px 0;
  padding: 10px 12px;
  color: var(--color-success);
  font-size: 12.5px;
  font-weight: 600;
  background: var(--color-success-soft);
  border-radius: var(--radius-sm);
}

.talent-pending-copy {
  padding: 16px 17px;
  color: var(--color-ink-muted);
  font-size: 12.5px;
}

.talent-checkbox {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
}
</style>
