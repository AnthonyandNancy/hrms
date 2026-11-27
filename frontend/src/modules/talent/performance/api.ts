import { ApiError, errorCopy } from '@/api/errors'
import { resourceGet, resourcePost } from '@/api/resourceClient'

/**
 * Performance workspace API boundary.
 *
 * The backend owns scope, the review lifecycle, calibration and the final
 * rating. The client only normalises transport shapes and never derives a
 * rating, a progress percentage for persistence, or a score from another
 * score. Scores stay strings exactly as the database/API stores them, so no
 * binary floating-point value is ever introduced.
 */

export type PerformanceCycleStatus = 'DRAFT' | 'ACTIVE' | 'SCORING' | 'PUBLISHED'

export type PerformanceCycleSummary = {
  id: number
  name: string
  periodFrom: string
  periodTo: string
  status: string
  participantCount: number
  submittedCount: number
  draftCount: number
  progress: number
  /** Present after a real publication; never invented for draft cycles. */
  publishedAt: string | null
  publishedBy: number | null
}

export type PerformanceCyclePage = {
  items: PerformanceCycleSummary[]
  page: number
  pageSize: number
  total: number
  partial: boolean
}

export type ReviewStatus = 'DRAFT' | 'SUBMITTED' | 'APPEALED' | string

export type PerformanceGoalRow = {
  id: number | null
  title: string
  targetValue: string
  weight: number
  selfScore: string
  managerScore: string
  status: string
}

export type PerformanceReviewRow = {
  id: number
  cycleId: number
  employmentId: number
  managerEmploymentId: number | null
  status: ReviewStatus
  snapshot: boolean
  publishedAt: string | null
  selfRating: string
  managerRating: string
  calibratedRating: string
  finalRating: string
  selfAssessment: string
  managerAssessment: string
  submittedAt: string | null
  goals: PerformanceGoalRow[]
}

export type PerformanceReviewPage = {
  items: PerformanceReviewRow[]
  page: number
  pageSize: number
  total: number
  partial: boolean
}

/** Editable goal row used by the goal table and the score drawer. */
export type PerformanceGoalDraft = {
  title: string
  targetValue: string
  weight: number
  selfScore: string
  managerScore: string
}

export type WeightValidation = {
  valid: boolean
  total: number
  errors: string[]
}

type Raw = Record<string, unknown>

export const PERFORMANCE_CYCLE_STATUS_LABELS = {
  DRAFT: '草稿',
  ACTIVE: '进行中',
  SCORING: '评分中',
  PUBLISHED: '已结束',
} as const

export const REVIEW_STATUS_LABELS: Record<string, string> = {
  DRAFT: '草稿',
  SUBMITTED: '已提交',
  APPEALED: '申诉中',
  PUBLISHED: '已发布',
}

export const RATING_LABELS: Record<string, string> = {
  EXCEEDS: '超出预期',
  MEETS: '符合预期',
  BELOW: '低于预期',
  OUTSTANDING: '卓越',
  GOOD: '良好',
  NEEDS_IMPROVEMENT: '待改进',
}

function isRecord(value: unknown): value is Raw {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
}

function contractError(field: string): never {
  throw new ApiError('INTERNAL', `服务端返回的绩效数据不符合契约：${field}。`, { status: 502 })
}

function text(value: unknown, field: string): string {
  if (typeof value === 'string' && value !== '') return value
  contractError(field)
}

function nullableText(value: unknown): string {
  return typeof value === 'string' ? value : ''
}

function requiredInteger(value: unknown, field: string): number {
  if (typeof value === 'number' && Number.isSafeInteger(value)) return value
  if (typeof value === 'string' && /^-?\d+$/.test(value)) return Number(value)
  contractError(field)
}

function nullableInteger(value: unknown): number | null {
  if (value === null || value === undefined) return null
  if (typeof value === 'number' && Number.isSafeInteger(value)) return value
  if (typeof value === 'string' && /^\d+$/.test(value)) return Number(value)
  return null
}

function listOf(value: unknown, field: string): unknown[] {
  if (Array.isArray(value)) return value
  contractError(field)
}

/** Copies a score verbatim. Numbers are preserved as their decimal text. */
function scoreText(value: unknown): string {
  if (value === null || value === undefined || value === '') return ''
  if (typeof value === 'string') return value
  if (typeof value === 'number' && Number.isFinite(value)) return String(value)
  return ''
}

function page<T>(
  raw: unknown,
  field: string,
  normalise: (row: unknown) => T,
): { items: T[]; page: number; pageSize: number; total: number; partial: boolean } {
  const row = isRecord(raw) ? raw : contractError(field)
  return {
    items: listOf(row.items, `${field}.items`).map(normalise),
    page: requiredInteger(row.page, `${field}.page`),
    pageSize: requiredInteger(row.page_size, `${field}.page_size`),
    total: requiredInteger(row.total, `${field}.total`),
    partial: row.partial === true,
  }
}

export function normalisePerformanceCycle(raw: unknown): PerformanceCycleSummary {
  const row = isRecord(raw) ? raw : contractError('cycle')
  return {
    id: requiredInteger(row.cycle_id, 'cycle_id'),
    name: text(row.name, 'name'),
    periodFrom: text(row.period_from, 'period_from'),
    periodTo: text(row.period_to, 'period_to'),
    status: text(row.status, 'status').toUpperCase(),
    participantCount: requiredInteger(row.participant_count, 'participant_count'),
    submittedCount: requiredInteger(row.submitted_count, 'submitted_count'),
    draftCount: requiredInteger(row.draft_count, 'draft_count'),
    progress: requiredInteger(row.progress, 'progress'),
    publishedAt: typeof row.published_at === 'string' ? row.published_at : null,
    publishedBy: nullableInteger(row.published_by),
  }
}

function normaliseGoal(raw: unknown): PerformanceGoalRow {
  const row = isRecord(raw) ? raw : contractError('goal')
  return {
    id: nullableInteger(row.goal_id),
    title: text(row.title, 'goal.title'),
    targetValue: text(row.target_value, 'goal.target_value'),
    weight: requiredInteger(row.weight, 'goal.weight'),
    selfScore: scoreText(row.self_score ?? row.self_rating),
    managerScore: scoreText(row.manager_score ?? row.manager_rating),
    status: typeof row.status === 'string' ? row.status : 'DRAFT',
  }
}

/** Goals may arrive as structured rows or only as the legacy goals_text. */
function goalsFrom(row: Raw): PerformanceGoalRow[] {
  if (Array.isArray(row.goals)) {
    return row.goals.filter(isRecord).map(normaliseGoal)
  }

  if (typeof row.goals_text === 'string' && row.goals_text.trim() !== '') {
    try {
      const parsed: unknown = JSON.parse(row.goals_text)
      if (Array.isArray(parsed)) return parsed.filter(isRecord).map(normaliseGoal)
    } catch {
      // A human-readable goals_text is not structured and cannot be turned
      // into weighted rows. The detail page shows it as a note instead.
    }
  }

  return []
}

export function normalisePerformanceReview(raw: unknown): PerformanceReviewRow {
  const row = isRecord(raw) ? raw : contractError('review')
  return {
    id: requiredInteger(row.review_id, 'review_id'),
    cycleId: requiredInteger(row.cycle_id, 'cycle_id'),
    employmentId: requiredInteger(row.employment_id, 'employment_id'),
    managerEmploymentId: nullableInteger(row.manager_employment_id),
    status: text(row.status, 'status').toUpperCase(),
    snapshot: row.snapshot === true,
    publishedAt: typeof row.published_at === 'string' ? row.published_at : null,
    selfRating: scoreText(row.self_rating),
    managerRating: scoreText(row.manager_rating),
    calibratedRating: scoreText(row.calibrated_rating),
    // The final rating is whatever the server decided. It is never derived
    // from the two inputs above.
    finalRating: scoreText(row.final_rating ?? row.final_rating_label),
    selfAssessment: nullableText(row.self_assessment),
    managerAssessment: nullableText(row.manager_assessment),
    submittedAt: typeof row.submitted_at === 'string' ? row.submitted_at : null,
    goals: goalsFrom(row),
  }
}

export async function listPerformanceCycles(
  filters: { status?: string; q?: string },
  pageNumber = 1,
  pageSize = 20,
): Promise<PerformanceCyclePage> {
  const params: Record<string, unknown> = {
    page: Math.max(1, Math.trunc(pageNumber)),
    page_size: Math.min(100, Math.max(1, Math.trunc(pageSize))),
  }
  if (filters.status !== undefined && filters.status !== '') params.status = filters.status
  if (filters.q !== undefined && filters.q.trim() !== '') params.q = filters.q.trim()

  return page(
    await resourceGet<unknown>('/v1/performance/cycles', params),
    'cycle_page',
    normalisePerformanceCycle,
  )
}

export async function listPerformanceReviews(
  filters: { cycleId?: number; status?: string },
  pageNumber = 1,
  pageSize = 100,
): Promise<PerformanceReviewPage> {
  const params: Record<string, unknown> = {
    page: Math.max(1, Math.trunc(pageNumber)),
    page_size: Math.min(100, Math.max(1, Math.trunc(pageSize))),
  }
  if (filters.cycleId !== undefined) params.cycle_id = filters.cycleId
  if (filters.status !== undefined && filters.status !== '') params.status = filters.status

  return page(
    await resourceGet<unknown>('/v1/performance/reviews', params),
    'review_page',
    normalisePerformanceReview,
  )
}

export function newPerformanceIdempotencyKey(prefix: string): string {
  const suffix =
    typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
      ? crypto.randomUUID()
      : `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`
  return `performance-${prefix}-${suffix}`.slice(0, 128)
}

/**
 * Save a draft review and its weighted goals. POST is the only real review
 * write route; the backend keeps the create-or-save idempotent for the same
 * cycle/employment while the review is still a draft.
 */
export async function savePerformanceReview(input: {
  cycleId: number
  employmentId: number
  managerEmploymentId: number | null
  selfRating: string
  selfAssessment: string
  managerRating: string
  managerAssessment: string
  goals: PerformanceGoalDraft[]
  idempotencyKey?: string
}): Promise<PerformanceReviewRow> {
  const goals = input.goals.map((goal) => ({
    title: goal.title.trim(),
    target_value: goal.targetValue.trim(),
    weight: goal.weight,
    self_score: goal.selfScore === '' ? null : goal.selfScore,
    manager_score: goal.managerScore === '' ? null : goal.managerScore,
  }))

  return normalisePerformanceReview(
    await resourcePost<unknown>(
      '/v1/performance/reviews',
      {
        cycle_id: input.cycleId,
        employment_id: input.employmentId,
        manager_employment_id: input.managerEmploymentId,
        self_rating: input.selfRating === '' ? null : input.selfRating,
        self_assessment: input.selfAssessment === '' ? null : input.selfAssessment,
        manager_rating: input.managerRating === '' ? null : input.managerRating,
        manager_assessment: input.managerAssessment === '' ? null : input.managerAssessment,
        goals,
      },
      { idempotencyKey: input.idempotencyKey ?? newPerformanceIdempotencyKey('save') },
    ),
  )
}

/** Submit the saved review for manager review / finalisation. */
export async function submitPerformanceReview(
  reviewId: number,
  input: {
    managerRating?: string
    managerAssessment?: string
    calibratedRating?: string
  },
  idempotencyKey?: string,
): Promise<PerformanceReviewRow> {
  const body: Record<string, unknown> = {}
  if (input.managerRating !== undefined && input.managerRating !== '') body.manager_rating = input.managerRating
  if (input.managerAssessment !== undefined && input.managerAssessment !== '') {
    body.manager_assessment = input.managerAssessment
  }
  if (input.calibratedRating !== undefined && input.calibratedRating !== '') {
    body.calibrated_rating = input.calibratedRating
  }

  return normalisePerformanceReview(
    await resourcePost<unknown>(`/v1/performance/reviews/${reviewId}/submit`, body, {
      idempotencyKey: idempotencyKey ?? newPerformanceIdempotencyKey('submit'),
    }),
  )
}

/* ------------------------------------------------------------------ */
/* Render-only Chinese copy and pure validation.                       */
/* ------------------------------------------------------------------ */

export function performanceCycleStage(cycle: Pick<PerformanceCycleSummary, 'status' | 'submittedCount' | 'participantCount'>): string {
  const status = cycle.status.toUpperCase()
  if (status === 'PUBLISHED') return '已结束'
  if (status === 'DRAFT') return '草稿'
  if (status === 'SCORING') return '评分中'
  if (status === 'ACTIVE') {
    return cycle.submittedCount > 0 && cycle.participantCount > 0 ? '评分中' : '进行中'
  }
  return '进行中'
}

export function reviewStatusLabel(status: string): string {
  return REVIEW_STATUS_LABELS[status.toUpperCase()] ?? status
}

/**
 * Rating labels are Chinese for known server enums. A decimal score is
 * rendered verbatim with its unit; nothing is rounded or converted.
 */
export function ratingLabel(value: string | null | undefined): string {
  if (value === null || value === undefined || value === '') return '待服务端计算'
  const upper = value.toUpperCase()
  const known = RATING_LABELS[upper]
  if (known !== undefined) return known
  return `${value} 分`
}

export function goalWeightTotal(goals: PerformanceGoalDraft[]): number {
  return goals.reduce((total, goal) => total + (Number.isSafeInteger(goal.weight) ? goal.weight : 0), 0)
}

export function validateGoalWeights(goals: PerformanceGoalDraft[]): WeightValidation {
  const errors: string[] = []
  let total = 0

  for (const [index, goal] of goals.entries()) {
    if (!Number.isSafeInteger(goal.weight)) {
      errors.push(`第 ${index + 1} 项目标权重必须是整数。`)
      continue
    }
    if (goal.weight < 1 || goal.weight > 100) {
      errors.push(`第 ${index + 1} 项目标权重必须在 1 到 100 之间。`)
    }
    total += goal.weight
  }

  if (goals.length === 0) {
    errors.push('至少需要一项目标。')
  } else if (total !== 100) {
    errors.push(`权重合计必须等于 100，当前为 ${total}。`)
  }

  return { valid: errors.length === 0, total, errors }
}

/**
 * Score input validation. Scores are transmitted verbatim as decimal strings;
 * this never calls Number()/parseFloat() on a value that must stay exact.
 */
export function validateScoreInput(value: string): string | null {
  const trimmed = value.trim()
  if (trimmed === '') return null
  if (!/^\d+(\.\d{1,6})?$/.test(trimmed)) {
    if (/^\d+$/.test(trimmed)) return null
    if (trimmed.includes('.') && /^\d+(\.\d{7,})$/.test(trimmed)) return '分数最多 6 位小数。'
    return '分数必须是数字。'
  }
  const [whole, fraction] = trimmed.split('.')
  const integerPart = Number(whole)
  if (!fraction && integerPart > 100) return '分数必须在 0 到 100 之间。'
  if (integerPart > 100) return '分数必须在 0 到 100 之间。'
  return null
}

export function isDecidedReview(status: string): boolean {
  return status.toUpperCase() !== 'DRAFT'
}

export function isCycleReadOnly(status: string): boolean {
  return status.toUpperCase() === 'PUBLISHED'
}

export function canScoreReview(status: string): boolean {
  return status.toUpperCase() === 'DRAFT'
}

export function performanceActionErrorCopy(error: unknown): string {
  if (error instanceof ApiError) {
    switch (error.serverReason) {
      case 'INVALID_WEIGHT_TOTAL':
        return '目标权重合计必须等于 100，请调整后再保存。'
      case 'INVALID_WEIGHT_RANGE':
        return '每项目标权重必须是 1 到 100 之间的整数。'
      case 'REVIEW_DECIDED':
        return '该评估已完成决定，不能再次评分或提交。'
      case 'CYCLE_PUBLISHED':
        return '该周期已发布，全部评估为只读。'
      case 'INVALID_REVIEW_TRANSITION':
        return '当前状态不允许该评估动作，请刷新后重试。'
      case 'APPEAL_REQUIRES_PUBLISHED_RESULT':
        return '申诉只能针对已发布并生成结果快照的绩效结果。'
      case 'PERFORMANCE_APPEAL_DUPLICATE':
        return '该评估已经有进行中的申诉，不能重复提交。'
      case 'SUBJECT_APPROVER_NOT_SEPARATED':
        return '评估对象与主管不能是同一人，本人也不能填写主管评分。'
      case 'REVIEWS_NOT_SUBMITTED':
        return '仍有评估未提交，全部提交后才能发布周期。'
      case 'CAPABILITY_DENIED':
        return '当前账号没有执行该操作的权限。'
    }
  }

  return errorCopy(error)
}

/** Publish (finalize) a cycle only when every review is submitted. */
export async function publishPerformanceCycle(cycleId: number, idempotencyKey?: string): Promise<Record<string, unknown>> {
  return resourcePost<Record<string, unknown>>(
    `/v1/performance/cycles/${cycleId}/publish`,
    {},
    { idempotencyKey: idempotencyKey ?? newPerformanceIdempotencyKey('publish') },
  )
}

/** Calibration writes only the calibrated rating and its evidence; raw scores stay unchanged. */
export async function calibratePerformanceReview(
  reviewId: number,
  calibratedRating: string,
  reason: string,
  idempotencyKey?: string,
): Promise<PerformanceReviewRow> {
  return normalisePerformanceReview(
    await resourcePost<unknown>(
      `/v1/performance/reviews/${reviewId}/calibration`,
      { calibrated_rating: calibratedRating, reason },
      { idempotencyKey: idempotencyKey ?? newPerformanceIdempotencyKey('calibration') },
    ),
  )
}

/** Raise an appeal as a workflow Case. */
export async function appealPerformanceReview(
  reviewId: number,
  reason: string,
  idempotencyKey?: string,
): Promise<Record<string, unknown>> {
  return resourcePost<Record<string, unknown>>(
    `/v1/performance/reviews/${reviewId}/appeals`,
    { reason },
    { idempotencyKey: idempotencyKey ?? newPerformanceIdempotencyKey('appeal') },
  )
}

export function performancePeriodLabel(from: string, to: string): string {
  const start = /^(\d{4})-(\d{2})-(\d{2})$/.exec(from)
  const end = /^(\d{4})-(\d{2})-(\d{2})$/.exec(to)
  if (start === null || end === null) return `${from} 至 ${to}`
  return `${start[1]} 年 ${Number(start[2])} 月 ${Number(start[3])} 日 至 ${end[1]} 年 ${Number(end[2])} 月 ${Number(end[3])} 日`
}

/* ------------------------------------------------------------------ */
/* Cycle-detail tab projections and commands.                           */
/* ------------------------------------------------------------------ */

export type PerformanceTemplateRow = {
  id: number
  code: string
  name: string
  weightTotal: number
  version: number
  publishedAt: string | null
}

export type PerformanceIndicatorRow = {
  id: number
  code: string
  name: string
  category: string
  dataSource: string
  description: string
  active: boolean
}

export type PerformancePlanGoalRow = {
  id: number
  cycleId: number
  employmentId: number
  indicatorId: number | null
  title: string
  targetValue: string
  weight: number
  dueOn: string
  status: string
}

export type PerformanceFeedbackRow = {
  id: number
  cycleId: number
  reviewId: number | null
  fromEmploymentId: number
  toEmploymentId: number
  feedbackType: string
  rating: string
  anonymous: boolean
  status: string
  recordedAt: string | null
}

export type PerformanceCalibrationRow = {
  id: number
  cycleId: number
  reviewId: number
  previousRating: string
  calibratedRating: string
  reason: string
  calibratedBy: number
  recordedAt: string | null
}

export type PerformanceResultRow = {
  reviewId: number
  cycleId: number
  employmentId: number
  cycleStatus: string
  status: string
  selfRating: string
  managerRating: string
  calibratedRating: string
  finalRating: string
  snapshot: boolean
  publishedAt: string | null
  publishedBy: number | null
}

export type PerformanceResultPage = {
  items: PerformanceResultRow[]
  snapshot: boolean
  pending: boolean
  total: number
}

export type PerformanceReportRow = {
  cycleId: number
  name: string
  status: string
  reviewCount: number
  submittedReviews: number
  draftReviews: number
  calibratedReviews: number
  publishedReviews: number
  completionRate: number
  snapshot: boolean
}

function normaliseTemplate(raw: unknown): PerformanceTemplateRow {
  const row = isRecord(raw) ? raw : contractError('template')
  return {
    id: requiredInteger(row.template_id, 'template_id'),
    code: text(row.code, 'template.code'),
    name: text(row.name, 'template.name'),
    weightTotal: requiredInteger(row.weight_total, 'template.weight_total'),
    version: requiredInteger(row.version, 'template.version'),
    publishedAt: typeof row.published_at === 'string' ? row.published_at : null,
  }
}

function normaliseIndicator(raw: unknown): PerformanceIndicatorRow {
  const row = isRecord(raw) ? raw : contractError('indicator')
  return {
    id: requiredInteger(row.indicator_id, 'indicator_id'),
    code: text(row.code, 'indicator.code'),
    name: text(row.name, 'indicator.name'),
    category: nullableText(row.category),
    dataSource: nullableText(row.data_source),
    description: nullableText(row.description),
    active: row.active === true,
  }
}

function normalisePlanGoal(raw: unknown): PerformancePlanGoalRow {
  const row = isRecord(raw) ? raw : contractError('performance_goal')
  return {
    id: requiredInteger(row.goal_id, 'goal_id'),
    cycleId: requiredInteger(row.cycle_id, 'goal.cycle_id'),
    employmentId: requiredInteger(row.employment_id, 'goal.employment_id'),
    indicatorId: nullableInteger(row.indicator_id),
    title: text(row.title, 'goal.title'),
    targetValue: text(row.target_value, 'goal.target_value'),
    weight: requiredInteger(row.weight, 'goal.weight'),
    dueOn: nullableText(row.due_on),
    status: text(row.status, 'goal.status'),
  }
}

function normaliseFeedback(raw: unknown): PerformanceFeedbackRow {
  const row = isRecord(raw) ? raw : contractError('feedback')
  return {
    id: requiredInteger(row.feedback_id, 'feedback_id'),
    cycleId: requiredInteger(row.cycle_id, 'feedback.cycle_id'),
    reviewId: nullableInteger(row.review_id),
    fromEmploymentId: requiredInteger(row.from_employment_id, 'feedback.from_employment_id'),
    toEmploymentId: requiredInteger(row.to_employment_id, 'feedback.to_employment_id'),
    feedbackType: text(row.feedback_type, 'feedback.feedback_type'),
    rating: scoreText(row.rating),
    anonymous: row.anonymous === true,
    status: text(row.status, 'feedback.status'),
    recordedAt: typeof row.recorded_at === 'string' ? row.recorded_at : null,
  }
}

function normaliseCalibration(raw: unknown): PerformanceCalibrationRow {
  const row = isRecord(raw) ? raw : contractError('calibration')
  return {
    id: requiredInteger(row.calibration_id, 'calibration_id'),
    cycleId: requiredInteger(row.cycle_id, 'calibration.cycle_id'),
    reviewId: requiredInteger(row.review_id, 'calibration.review_id'),
    previousRating: scoreText(row.previous_rating),
    calibratedRating: scoreText(row.calibrated_rating),
    reason: text(row.reason, 'calibration.reason'),
    calibratedBy: requiredInteger(row.calibrated_by, 'calibration.calibrated_by'),
    recordedAt: typeof row.recorded_at === 'string' ? row.recorded_at : null,
  }
}

function normaliseResult(raw: unknown): PerformanceResultRow {
  const row = isRecord(raw) ? raw : contractError('result')
  return {
    reviewId: requiredInteger(row.review_id, 'result.review_id'),
    cycleId: requiredInteger(row.cycle_id, 'result.cycle_id'),
    employmentId: requiredInteger(row.employment_id, 'result.employment_id'),
    cycleStatus: nullableText(row.cycle_status),
    status: text(row.status, 'result.status'),
    selfRating: scoreText(row.self_rating),
    managerRating: scoreText(row.manager_rating),
    calibratedRating: scoreText(row.calibrated_rating),
    finalRating: scoreText(row.final_rating),
    snapshot: row.snapshot === true,
    publishedAt: typeof row.published_at === 'string' ? row.published_at : null,
    publishedBy: nullableInteger(row.published_by),
  }
}

function normaliseReport(raw: unknown): PerformanceReportRow {
  const row = isRecord(raw) ? raw : contractError('report')
  return {
    cycleId: requiredInteger(row.cycle_id, 'report.cycle_id'),
    name: text(row.name, 'report.name'),
    status: text(row.status, 'report.status'),
    reviewCount: requiredInteger(row.review_count, 'report.review_count'),
    submittedReviews: requiredInteger(row.submitted_reviews, 'report.submitted_reviews'),
    draftReviews: requiredInteger(row.draft_reviews, 'report.draft_reviews'),
    calibratedReviews: requiredInteger(row.calibrated_reviews, 'report.calibrated_reviews'),
    publishedReviews: nullableInteger(row.published_reviews) ?? 0,
    completionRate: nullableInteger(row.completion_rate) ?? 0,
    snapshot: row.snapshot === true,
  }
}

/** The projection envelope carries snapshot/pending evidence in drilldown. */
function projectionRows(raw: unknown, field: string): { rows: unknown[]; snapshot: boolean; pending: boolean; total: number } {
  const row = isRecord(raw) ? raw : contractError(field)
  const drilldown = isRecord(row.drilldown) ? row.drilldown : {}
  return {
    rows: listOf(row.items, `${field}.items`),
    snapshot: drilldown.snapshot === true,
    pending: drilldown.pending === true,
    total: requiredInteger(row.total, `${field}.total`),
  }
}

export async function listPerformanceTemplates(): Promise<PerformanceTemplateRow[]> {
  const result = await resourceGet<unknown>('/v1/performance/templates')
  const row = isRecord(result) ? result : contractError('template_page')
  return listOf(row.items, 'template_page.items').map(normaliseTemplate)
}

export async function createPerformanceTemplate(input: {
  code: string
  name: string
  sections: Array<{ key: string; weight: number }>
}): Promise<PerformanceTemplateRow> {
  return normaliseTemplate(
    await resourcePost<unknown>('/v1/performance/templates', input, {
      idempotencyKey: newPerformanceIdempotencyKey('template'),
    }),
  )
}

export async function listPerformanceIndicators(): Promise<PerformanceIndicatorRow[]> {
  const result = await resourceGet<unknown>('/v1/performance/indicators')
  const row = isRecord(result) ? result : contractError('indicator_projection')
  return listOf(row.items, 'indicator_projection.items').map(normaliseIndicator)
}

export async function createPerformanceIndicator(input: {
  code: string
  name: string
  category?: string
  dataSource?: string
  description?: string
}): Promise<PerformanceIndicatorRow> {
  return normaliseIndicator(
    await resourcePost<unknown>('/v1/performance/indicators', {
      code: input.code,
      name: input.name,
      category: input.category || null,
      data_source: input.dataSource || null,
      description: input.description || null,
      active: true,
    }, { idempotencyKey: newPerformanceIdempotencyKey('indicator') }),
  )
}

export async function listPerformanceGoals(cycleId: number): Promise<PerformancePlanGoalRow[]> {
  const result = await resourceGet<unknown>('/v1/performance/goals')
  const row = isRecord(result) ? result : contractError('goal_projection')
  return listOf(row.items, 'goal_projection.items')
    .map(normalisePlanGoal)
    .filter((goal) => goal.cycleId === cycleId)
}

export async function createPerformanceGoal(input: {
  cycleId: number
  employmentId: number
  title: string
  targetValue: string
  weight: number
  indicatorId?: number | null
  dueOn?: string
}): Promise<PerformancePlanGoalRow> {
  return normalisePlanGoal(
    await resourcePost<unknown>('/v1/performance/goals', {
      cycle_id: input.cycleId,
      employment_id: input.employmentId,
      indicator_id: input.indicatorId ?? null,
      title: input.title,
      target_value: input.targetValue,
      weight: input.weight,
      due_on: input.dueOn || null,
    }, { idempotencyKey: newPerformanceIdempotencyKey('goal') }),
  )
}

export async function listPerformanceFeedback(cycleId: number): Promise<PerformanceFeedbackRow[]> {
  const result = await resourceGet<unknown>('/v1/performance/feedback')
  const row = isRecord(result) ? result : contractError('feedback_projection')
  return listOf(row.items, 'feedback_projection.items')
    .map(normaliseFeedback)
    .filter((feedback) => feedback.cycleId === cycleId)
}

export async function createPerformanceFeedback(input: {
  cycleId: number
  reviewId: number | null
  fromEmploymentId: number
  toEmploymentId: number
  feedbackType: string
  rating: string
  comments: string
  anonymous: boolean
}): Promise<PerformanceFeedbackRow> {
  return normaliseFeedback(
    await resourcePost<unknown>('/v1/performance/feedback', {
      cycle_id: input.cycleId,
      review_id: input.reviewId,
      from_employment_id: input.fromEmploymentId,
      to_employment_id: input.toEmploymentId,
      feedback_type: input.feedbackType,
      rating: input.rating || null,
      comments: input.comments,
      anonymous: input.anonymous,
    }, { idempotencyKey: newPerformanceIdempotencyKey('feedback') }),
  )
}

export async function listPerformanceCalibrations(cycleId: number): Promise<PerformanceCalibrationRow[]> {
  const result = await resourceGet<unknown>('/v1/performance/calibration')
  const row = isRecord(result) ? result : contractError('calibration_projection')
  return listOf(row.items, 'calibration_projection.items')
    .map(normaliseCalibration)
    .filter((calibration) => calibration.cycleId === cycleId)
}

export async function listPerformanceResults(cycleId: number): Promise<PerformanceResultPage> {
  const result = projectionRows(
    await resourceGet<unknown>('/v1/performance/results'),
    'result_projection',
  )
  const items = result.rows
    .map(normaliseResult)
    .filter((row) => row.cycleId === cycleId)

  return {
    items,
    snapshot: result.snapshot || items.some((row) => row.snapshot),
    pending: result.pending || items.some((row) => !row.snapshot),
    total: items.length,
  }
}

export async function listPerformanceReports(cycleId: number): Promise<PerformanceReportRow | null> {
  const result = projectionRows(
    await resourceGet<unknown>('/v1/performance/reports'),
    'report_projection',
  )
  return result.rows.map(normaliseReport).find((row) => row.cycleId === cycleId) ?? null
}
