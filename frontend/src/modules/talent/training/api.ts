import { ApiError } from '@/api/errors'
import { resourceGet, resourcePatch, resourcePost } from '@/api/resourceClient'
import { todayBusinessDate } from '@/utils/format'

/**
 * Training module API boundary.
 *
 * Every read is a live projection and every write carries an Idempotency-Key.
 * This file normalises the real backend contract; it never synthesises rows,
 * study minutes, capacity, exam versions or feedback identities.
 */

export type DeliveryMode = 'ONSITE' | 'ONLINE' | 'HYBRID'
export type ProgramStatus = 'ACTIVE' | 'ARCHIVED'
export type EnrollmentStatus = 'ENROLLED' | 'ATTENDED' | 'COMPLETED' | 'CANCELLED'
export type CompletionResult = 'PASS' | 'FAIL' | 'ATTENDED'
export type ExamStatus = 'DRAFT' | 'PUBLISHED'
export type PlanStatus = 'ACTIVE' | 'ARCHIVED'

export type TrainingPlan = {
  id: number
  code: string
  title: string
  year: number
  budgetCents: number
  currency: string
  status: PlanStatus
  recordedAt: string | null
}

export type TrainingCourse = {
  id: number
  planId: number | null
  code: string
  title: string
  instructor: string | null
  deliveryMode: DeliveryMode
  durationMinutes: number
  costCents: number
  currency: string
  materials: string | null
  status: ProgramStatus
  recordedAt: string | null
}

export type TrainingProgram = {
  id: number
  code: string
  title: string
  category: string | null
  deliveryMode: DeliveryMode | null
  capacity: number
  defaultCapacity: number
  enrolledCount: number
  completedCount: number
  remainingSeats: number
  sessionCount: number
  trainingCostCents?: number
  status: ProgramStatus
}

export type TrainingSession = {
  id: number
  programId: number
  planId: number | null
  courseId: number | null
  startsOn: string
  endsOn: string
  startsAt: string | null
  endsAt: string | null
  capacity: number
  location: string | null
  status: string
}

export type TrainingEnrollment = {
  id: number
  sessionId: number
  employmentId: number
  status: EnrollmentStatus
  attended: boolean
  completionResult: CompletionResult | null
  score: number | null
  completedAt: string | null
}

export type EnrollmentDisplayRow = TrainingEnrollment & {
  employeeName: string
  employeeNo: string
  sessionLabel: string
}

export type EmployeeOption = {
  employmentId: number
  name: string
  employeeNo: string
  status: string
}

export type TrainingCheckin = {
  id: number
  sessionId: number
  employmentId: number
  checkedInAt: string | null
  checkedOutAt: string | null
  studyMinutes: number
  recordedAt: string | null
}

export type TrainingExam = {
  id: number
  courseId: number | null
  sessionId: number | null
  code: string
  title: string
  version: number
  passingScore: number
  maxScore: number
  status: ExamStatus
  publishedAt: string | null
  recordedAt: string | null
}

export type TrainingFeedback = {
  id: number
  enrollmentId: number
  sessionId: number
  employmentId: number
  rating: number | null
  comments: string
  questionnaire: Record<string, unknown> | null
  status: 'RECORDED'
  recordedAt: string | null
}

export type TrainingRecord = {
  enrollmentId: number
  employmentId: number
  sessionId: number
  courseCode: string | null
  courseTitle: string | null
  sessionStartsOn: string | null
  status: EnrollmentStatus
  attended: boolean
  completionResult: CompletionResult | null
  score: number | null
  completedAt: string | null
  checkinCount: number
  studyMinutes: number
  feedbackCount: number
  latestExamVersion: number | null
}

export type TrainingReport = {
  planId: number
  code: string
  title: string
  year: number
  budgetCents: number
  trainingCostCents: number
  currency: string
  courseCount: number
  sessionCount: number
  enrolledCount: number
  completedCount: number
  completionRateBps: number
  examPassCount: number
  examPassRateBps: number
  studyMinutesTotal: number
  feedbackCount: number
  averageFeedbackRatingBps: number
}

export type ProgramDraft = {
  code: string
  title: string
  defaultCapacity: number
}

export type CreatedProgram = {
  id: number
  code: string
  title: string
  status: ProgramStatus
  defaultCapacity: number
}

export type ProgramPatch = {
  title?: string
  defaultCapacity?: number
  status?: ProgramStatus
}

export type CourseDraft = {
  planId: number | null
  code: string
  title: string
  instructor: string
  deliveryMode: DeliveryMode
  durationMinutes: number
  costCents: number
  currency: string
  materials: string
}

export type CoursePatch = {
  title?: string
  instructor?: string | null
  durationMinutes?: number
  materials?: string | null
  status?: ProgramStatus
}

export type SessionDraft = {
  programId: number
  planId: number | null
  courseId: number | null
  startsOn: string
  endsOn: string
  startsAt: string
  endsAt: string
  capacity: number
  location: string
}

export type SessionPatch = {
  programId?: number
  planId?: number | null
  courseId?: number | null
  startsOn?: string
  endsOn?: string
  startsAt?: string
  endsAt?: string
  capacity?: number
  location?: string | null
  status?: 'SCHEDULED' | 'COMPLETED' | 'CANCELLED'
}

export type PlanDraft = {
  code: string
  title: string
  year: number
  budgetCents: number
  currency: string
}

export type ExamDraft = {
  courseId: number | null
  sessionId: number | null
  code: string
  title: string
  passingScore: number
  maxScore: number
  publish: boolean
}

export type FeedbackDraft = {
  enrollmentId: number
  rating: number | null
  comments: string
}

type Raw = Record<string, unknown>

const DELIVERY_MODES: readonly DeliveryMode[] = ['ONSITE', 'ONLINE', 'HYBRID']
const PROGRAM_STATUSES: readonly ProgramStatus[] = ['ACTIVE', 'ARCHIVED']
const ENROLLMENT_STATUSES: readonly EnrollmentStatus[] = [
  'ENROLLED',
  'ATTENDED',
  'COMPLETED',
  'CANCELLED',
]
const COMPLETION_RESULTS: readonly CompletionResult[] = ['PASS', 'FAIL', 'ATTENDED']
const EXAM_STATUSES: readonly ExamStatus[] = ['DRAFT', 'PUBLISHED']

function isRecord(value: unknown): value is Raw {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
}

function contractError(field: string): never {
  throw new ApiError('INTERNAL', `服务端返回的培训数据不符合契约：${field}。`, { status: 502 })
}

function integer(value: unknown, field: string): number {
  if (typeof value === 'number' && Number.isSafeInteger(value)) return value
  contractError(field)
}

function nullableInteger(value: unknown, field: string): number | null {
  if (value === null || value === undefined) return null
  return integer(value, field)
}

function text(value: unknown, field: string): string {
  if (typeof value === 'string' && value !== '') return value
  contractError(field)
}

function nullableText(value: unknown, field: string): string | null {
  if (value === null || value === undefined || value === '') return null
  if (typeof value === 'string') return value
  contractError(field)
}

function record(value: unknown, field: string): Raw {
  if (isRecord(value)) return value
  contractError(field)
}

function items(value: unknown, field: string): unknown[] {
  if (isRecord(value) && Array.isArray(value.items)) return value.items
  if (Array.isArray(value)) return value
  contractError(field)
}

function enumValue<T extends string>(value: unknown, allowed: readonly T[], field: string): T {
  const candidate = text(value, field).toUpperCase() as T
  if (!allowed.includes(candidate)) contractError(field)
  return candidate
}

function nullableEnum<T extends string>(value: unknown, allowed: readonly T[], field: string): T | null {
  const candidate = nullableText(value, field)
  if (candidate === null) return null
  const upper = candidate.toUpperCase() as T
  if (!allowed.includes(upper)) contractError(field)
  return upper
}

export function normalisePlan(raw: unknown): TrainingPlan {
  const row = record(raw, 'plan')
  return {
    id: integer(row.plan_id, 'plan_id'),
    code: text(row.code, 'code'),
    title: text(row.title, 'title'),
    year: integer(row.year, 'year'),
    budgetCents: integer(row.budget_cents, 'budget_cents'),
    currency: text(row.currency, 'currency'),
    status: enumValue(row.status, PROGRAM_STATUSES, 'status'),
    recordedAt: nullableText(row.recorded_at, 'recorded_at'),
  }
}

export function normaliseCourse(raw: unknown): TrainingCourse {
  const row = record(raw, 'course')
  return {
    id: integer(row.course_id, 'course_id'),
    planId: nullableInteger(row.plan_id, 'plan_id'),
    code: text(row.code, 'code'),
    title: text(row.title, 'title'),
    instructor: nullableText(row.instructor, 'instructor'),
    deliveryMode: enumValue(row.delivery_mode, DELIVERY_MODES, 'delivery_mode'),
    durationMinutes: integer(row.duration_minutes, 'duration_minutes'),
    costCents: integer(row.cost_cents, 'cost_cents'),
    currency: text(row.currency, 'currency'),
    materials: nullableText(row.materials, 'materials'),
    status: enumValue(row.status, PROGRAM_STATUSES, 'status'),
    recordedAt: nullableText(row.recorded_at, 'recorded_at'),
  }
}

export function normaliseProgram(raw: unknown): TrainingProgram {
  const row = record(raw, 'program')
  const rawMode = nullableText(row.delivery_mode, 'delivery_mode')
  const deliveryMode = rawMode === null ? null : enumValue(rawMode, DELIVERY_MODES, 'delivery_mode')
  const cost = row.training_cost_cents

  return {
    id: integer(row.program_id, 'program_id'),
    code: text(row.code, 'code'),
    title: text(row.title, 'title'),
    category: nullableText(row.category, 'category'),
    deliveryMode,
    capacity: integer(row.capacity, 'capacity'),
    defaultCapacity: integer(row.default_capacity ?? row.capacity, 'default_capacity'),
    enrolledCount: integer(row.enrolled_count, 'enrolled_count'),
    completedCount: integer(row.completed_count, 'completed_count'),
    remainingSeats: integer(row.remaining_seats, 'remaining_seats'),
    sessionCount: integer(row.session_count, 'session_count'),
    trainingCostCents:
      cost === undefined ? undefined : cost === null ? undefined : integer(cost, 'training_cost_cents'),
    status: enumValue(row.status, PROGRAM_STATUSES, 'status'),
  }
}

export function normaliseSession(raw: unknown): TrainingSession {
  const row = record(raw, 'session')
  return {
    id: integer(row.session_id, 'session_id'),
    programId: integer(row.program_id, 'program_id'),
    planId: nullableInteger(row.plan_id, 'plan_id'),
    courseId: nullableInteger(row.course_id, 'course_id'),
    startsOn: text(row.starts_on, 'starts_on'),
    endsOn: text(row.ends_on, 'ends_on'),
    startsAt: nullableText(row.starts_at, 'starts_at'),
    endsAt: nullableText(row.ends_at, 'ends_at'),
    capacity: integer(row.capacity, 'capacity'),
    location: nullableText(row.location, 'location'),
    status: text(row.status, 'status'),
  }
}

export function normaliseEnrollment(raw: unknown): TrainingEnrollment {
  const row = record(raw, 'enrollment')
  const result = nullableEnum(row.completion_result, COMPLETION_RESULTS, 'completion_result')

  return {
    id: integer(row.enrollment_id, 'enrollment_id'),
    sessionId: integer(row.session_id, 'session_id'),
    employmentId: integer(row.employment_id, 'employment_id'),
    status: enumValue(row.status, ENROLLMENT_STATUSES, 'status'),
    attended: row.attended === true,
    completionResult: result,
    score: nullableInteger(row.score, 'score'),
    completedAt: nullableText(row.completed_at, 'completed_at'),
  }
}

export function normaliseCheckin(raw: unknown): TrainingCheckin {
  const row = record(raw, 'checkin')
  return {
    id: integer(row.checkin_id, 'checkin_id'),
    sessionId: integer(row.session_id, 'session_id'),
    employmentId: integer(row.employment_id, 'employment_id'),
    checkedInAt: nullableText(row.checked_in_at, 'checked_in_at'),
    checkedOutAt: nullableText(row.checked_out_at, 'checked_out_at'),
    studyMinutes: integer(row.study_minutes, 'study_minutes'),
    recordedAt: nullableText(row.recorded_at, 'recorded_at'),
  }
}

export function normaliseExam(raw: unknown): TrainingExam {
  const row = record(raw, 'exam')
  return {
    id: integer(row.exam_id, 'exam_id'),
    courseId: nullableInteger(row.course_id, 'course_id'),
    sessionId: nullableInteger(row.session_id, 'session_id'),
    code: text(row.code, 'code'),
    title: text(row.title, 'title'),
    version: integer(row.version, 'version'),
    passingScore: integer(row.passing_score, 'passing_score'),
    maxScore: integer(row.max_score, 'max_score'),
    status: enumValue(row.status, EXAM_STATUSES, 'status'),
    publishedAt: nullableText(row.published_at, 'published_at'),
    recordedAt: nullableText(row.recorded_at, 'recorded_at'),
  }
}

export function normaliseFeedback(raw: unknown): TrainingFeedback {
  const row = record(raw, 'feedback')
  const questionnaire = row.questionnaire
  return {
    id: integer(row.training_feedback_id, 'training_feedback_id'),
    enrollmentId: integer(row.enrollment_id, 'enrollment_id'),
    sessionId: integer(row.session_id, 'session_id'),
    employmentId: integer(row.employment_id, 'employment_id'),
    rating: nullableInteger(row.rating, 'rating'),
    comments: text(row.comments, 'comments'),
    questionnaire: isRecord(questionnaire) ? questionnaire : null,
    status: 'RECORDED',
    recordedAt: nullableText(row.recorded_at, 'recorded_at'),
  }
}

export function normaliseRecord(raw: unknown): TrainingRecord {
  const row = record(raw, 'record')
  return {
    enrollmentId: integer(row.enrollment_id, 'enrollment_id'),
    employmentId: integer(row.employment_id, 'employment_id'),
    sessionId: integer(row.session_id, 'session_id'),
    courseCode: nullableText(row.course_code, 'course_code'),
    courseTitle: nullableText(row.course_title, 'course_title'),
    sessionStartsOn: nullableText(row.session_starts_on, 'session_starts_on'),
    status: enumValue(row.status, ENROLLMENT_STATUSES, 'status'),
    attended: row.attended === true,
    completionResult: nullableEnum(row.completion_result, COMPLETION_RESULTS, 'completion_result'),
    score: nullableInteger(row.score, 'score'),
    completedAt: nullableText(row.completed_at, 'completed_at'),
    checkinCount: integer(row.checkin_count, 'checkin_count'),
    studyMinutes: integer(row.study_minutes, 'study_minutes'),
    feedbackCount: integer(row.feedback_count, 'feedback_count'),
    latestExamVersion: nullableInteger(row.latest_exam_version, 'latest_exam_version'),
  }
}

export function normaliseReport(raw: unknown): TrainingReport {
  const row = record(raw, 'report')
  return {
    planId: integer(row.plan_id, 'plan_id'),
    code: text(row.code, 'code'),
    title: text(row.title, 'title'),
    year: integer(row.year, 'year'),
    budgetCents: integer(row.budget_cents, 'budget_cents'),
    trainingCostCents: integer(row.training_cost_cents, 'training_cost_cents'),
    currency: text(row.currency, 'currency'),
    courseCount: integer(row.course_count, 'course_count'),
    sessionCount: integer(row.session_count, 'session_count'),
    enrolledCount: integer(row.enrolled_count, 'enrolled_count'),
    completedCount: integer(row.completed_count, 'completed_count'),
    completionRateBps: integer(row.completion_rate_bps, 'completion_rate_bps'),
    examPassCount: integer(row.exam_pass_count, 'exam_pass_count'),
    examPassRateBps: integer(row.exam_pass_rate_bps, 'exam_pass_rate_bps'),
    studyMinutesTotal: integer(row.study_minutes_total, 'study_minutes_total'),
    feedbackCount: integer(row.feedback_count, 'feedback_count'),
    averageFeedbackRatingBps: integer(row.average_feedback_rating_bps, 'average_feedback_rating_bps'),
  }
}

function normaliseEmployee(raw: unknown): EmployeeOption | null {
  if (!isRecord(raw)) return null
  const id = raw.employment_id
  if (typeof id !== 'number' || !Number.isSafeInteger(id) || id <= 0) return null
  const name =
    typeof raw.person_name === 'string' && raw.person_name !== ''
      ? raw.person_name
      : typeof raw.display_name === 'string' && raw.display_name !== ''
        ? raw.display_name
        : `员工 #${id}`
  return {
    employmentId: id,
    name,
    employeeNo: typeof raw.employee_no === 'string' ? raw.employee_no : '',
    status: typeof raw.status === 'string' ? raw.status : '',
  }
}

export function businessDate(): string {
  return todayBusinessDate()
}

export async function listPlans(asOf = businessDate()): Promise<TrainingPlan[]> {
  const raw = await resourceGet<unknown>('/v1/training/plans', { as_of: asOf })
  return items(raw, 'plans').map(normalisePlan)
}

export async function listCourses(planId?: number | null, asOf = businessDate()): Promise<TrainingCourse[]> {
  const raw = await resourceGet<unknown>('/v1/training/courses', {
    as_of: asOf,
    ...(planId !== null && planId !== undefined ? { plan_id: planId } : {}),
  })
  return items(raw, 'courses')
    .map(normaliseCourse)
    .filter((course) => planId === null || planId === undefined || course.planId === planId)
}

export async function listPrograms(asOf = businessDate()): Promise<TrainingProgram[]> {
  const raw = await resourceGet<unknown>('/v1/training/programs', { as_of: asOf })
  return items(raw, 'programs').map(normaliseProgram)
}

export async function listSessions(
  programId?: number | null,
  status?: string,
  asOf = businessDate(),
): Promise<TrainingSession[]> {
  const raw = await resourceGet<unknown>('/v1/training/sessions', {
    as_of: asOf,
    ...(programId !== null && programId !== undefined ? { program_id: programId } : {}),
    ...(status !== undefined && status !== '' ? { status } : {}),
  })
  const rows = items(raw, 'sessions').map(normaliseSession)
  return programId === null || programId === undefined
    ? rows
    : rows.filter((session) => session.programId === programId)
}

export async function listEnrollments(
  sessionId?: number | null,
  employmentId?: number | null,
): Promise<TrainingEnrollment[]> {
  const raw = await resourceGet<unknown>('/v1/training/enrollments', {
    ...(sessionId !== null && sessionId !== undefined ? { session_id: sessionId } : {}),
    ...(employmentId !== null && employmentId !== undefined ? { employment_id: employmentId } : {}),
  })
  return items(raw, 'enrollments')
    .map(normaliseEnrollment)
    .filter((row) => sessionId === null || sessionId === undefined || row.sessionId === sessionId)
}

export async function listEmployees(): Promise<EmployeeOption[]> {
  const raw = await resourceGet<unknown>('/v1/employees', {
    as_of: businessDate(),
    page: 1,
    page_size: 100,
    include_archived: false,
  })
  return items(raw, 'employees')
    .map(normaliseEmployee)
    .filter((employee): employee is EmployeeOption => employee !== null)
}

export async function listCheckins(sessionId: number, asOf = businessDate()): Promise<TrainingCheckin[]> {
  const raw = await resourceGet<unknown>(`/v1/training/sessions/${sessionId}/checkins`, { as_of: asOf })
  return items(raw, 'checkins')
    .map(normaliseCheckin)
    .filter((row) => row.sessionId === sessionId)
}

export async function listExams(
  sessionId?: number | null,
  asOf = businessDate(),
): Promise<TrainingExam[]> {
  const raw = await resourceGet<unknown>('/v1/training/exams', {
    as_of: asOf,
    ...(sessionId !== null && sessionId !== undefined ? { session_id: sessionId } : {}),
  })
  return items(raw, 'exams')
    .map(normaliseExam)
    .filter((row) => sessionId === null || sessionId === undefined || row.sessionId === sessionId)
}

export async function listFeedback(
  enrollmentId?: number | null,
  asOf = businessDate(),
): Promise<TrainingFeedback[]> {
  const raw = await resourceGet<unknown>('/v1/training/feedback', {
    as_of: asOf,
    ...(enrollmentId !== null && enrollmentId !== undefined ? { enrollment_id: enrollmentId } : {}),
  })
  return items(raw, 'feedback')
    .map(normaliseFeedback)
    .filter((row) => enrollmentId === null || enrollmentId === undefined || row.enrollmentId === enrollmentId)
}

export async function listRecords(
  employmentId?: number | null,
  asOf = businessDate(),
): Promise<TrainingRecord[]> {
  const raw = await resourceGet<unknown>('/v1/training/records', {
    as_of: asOf,
    ...(employmentId !== null && employmentId !== undefined ? { employment_id: employmentId } : {}),
  })
  return items(raw, 'records')
    .map(normaliseRecord)
    .filter((row) => employmentId === null || employmentId === undefined || row.employmentId === employmentId)
}

export const RECORD_EXPORT_FIELDS = [
  'employment_id',
  'session_id',
  'enrollment_id',
  'course_code',
  'course_title',
  'status',
  'completion_result',
  'score',
  'study_minutes',
  'checkin_count',
  'feedback_count',
  'latest_exam_version',
] as const

export type RecordExportField = (typeof RECORD_EXPORT_FIELDS)[number]

export async function exportTrainingRecords(
  fields: readonly RecordExportField[],
  employmentId?: number | null,
  asOf = businessDate(),
): Promise<Array<Record<string, unknown>>> {
  if (fields.length === 0) {
    throw new ApiError('VALIDATION_FAILED', '请至少选择一个导出字段。', { status: 422 })
  }

  const raw = await resourceGet<unknown>('/v1/training/records/export', {
    as_of: asOf,
    fields: [...fields],
    ...(employmentId !== null && employmentId !== undefined ? { employment_id: employmentId } : {}),
  })
  const data = record(raw, 'record_export')
  return Array.isArray(data.items) ? data.items.filter(isRecord) : []
}

export async function listReports(
  planId?: number | null,
  asOf = businessDate(),
): Promise<TrainingReport[]> {
  const raw = await resourceGet<unknown>('/v1/training/reports', {
    as_of: asOf,
    ...(planId !== null && planId !== undefined ? { plan_id: planId } : {}),
  })
  return items(raw, 'reports')
    .map(normaliseReport)
    .filter((row) => planId === null || planId === undefined || row.planId === planId)
}

export async function createPlan(draft: PlanDraft): Promise<TrainingPlan> {
  const raw = await resourcePost<unknown>(
    '/v1/training/plans',
    {
      code: draft.code,
      title: draft.title,
      year: draft.year,
      budget_cents: draft.budgetCents,
      currency: draft.currency,
    },
    { idempotencyKey: newTrainingIdempotencyKey('plan') },
  )
  return normalisePlan(raw)
}

export async function createCourse(draft: CourseDraft): Promise<TrainingCourse> {
  const raw = await resourcePost<unknown>(
    '/v1/training/courses',
    {
      ...(draft.planId !== null ? { plan_id: draft.planId } : {}),
      code: draft.code,
      title: draft.title,
      instructor: draft.instructor === '' ? null : draft.instructor,
      delivery_mode: draft.deliveryMode,
      duration_minutes: draft.durationMinutes,
      cost_cents: draft.costCents,
      currency: draft.currency,
      materials: draft.materials === '' ? null : draft.materials,
    },
    { idempotencyKey: newTrainingIdempotencyKey('course') },
  )
  return normaliseCourse(raw)
}

export async function updateCourse(courseId: number, patch: CoursePatch): Promise<TrainingCourse> {
  const raw = await resourcePatch<unknown>(
    `/v1/training/courses/${courseId}`,
    {
      ...(patch.title !== undefined ? { title: patch.title } : {}),
      ...(patch.instructor !== undefined ? { instructor: patch.instructor } : {}),
      ...(patch.durationMinutes !== undefined ? { duration_minutes: patch.durationMinutes } : {}),
      ...(patch.materials !== undefined ? { materials: patch.materials } : {}),
      ...(patch.status !== undefined ? { status: patch.status } : {}),
    },
    { idempotencyKey: newTrainingIdempotencyKey('course-update') },
  )
  return normaliseCourse(raw)
}

export async function createProgram(draft: ProgramDraft): Promise<CreatedProgram> {
  const raw = await resourcePost<unknown>(
    '/v1/training/programs',
    {
      code: draft.code,
      title: draft.title,
      default_capacity: draft.defaultCapacity,
    },
    { idempotencyKey: newTrainingIdempotencyKey('program') },
  )
  const row = record(raw, 'program')
  return {
    id: integer(row.program_id, 'program_id'),
    code: text(row.code, 'code'),
    title: text(row.title, 'title'),
    status: enumValue(row.status, PROGRAM_STATUSES, 'status'),
    defaultCapacity: integer(row.default_capacity ?? 0, 'default_capacity'),
  }
}

export async function updateProgram(programId: number, patch: ProgramPatch): Promise<CreatedProgram> {
  const raw = await resourcePatch<unknown>(
    `/v1/training/programs/${programId}`,
    {
      ...(patch.title !== undefined ? { title: patch.title } : {}),
      ...(patch.defaultCapacity !== undefined ? { default_capacity: patch.defaultCapacity } : {}),
      ...(patch.status !== undefined ? { status: patch.status } : {}),
    },
    { idempotencyKey: newTrainingIdempotencyKey('program-update') },
  )
  const row = record(raw, 'program')
  return {
    id: integer(row.program_id, 'program_id'),
    code: text(row.code, 'code'),
    title: text(row.title, 'title'),
    status: enumValue(row.status, PROGRAM_STATUSES, 'status'),
    defaultCapacity: integer(row.default_capacity ?? 0, 'default_capacity'),
  }
}

export async function createSession(draft: SessionDraft): Promise<TrainingSession> {
  const raw = await resourcePost<unknown>(
    '/v1/training/sessions',
    {
      program_id: draft.programId,
      ...(draft.planId !== null ? { plan_id: draft.planId } : {}),
      ...(draft.courseId !== null ? { course_id: draft.courseId } : {}),
      starts_on: draft.startsOn,
      ends_on: draft.endsOn,
      starts_at: draft.startsAt,
      ends_at: draft.endsAt,
      capacity: draft.capacity,
      location: draft.location === '' ? null : draft.location,
    },
    { idempotencyKey: newTrainingIdempotencyKey('session') },
  )
  return normaliseSession(raw)
}

export async function updateSession(sessionId: number, patch: SessionPatch): Promise<TrainingSession> {
  const raw = await resourcePatch<unknown>(
    `/v1/training/sessions/${sessionId}`,
    {
      ...(patch.programId !== undefined ? { program_id: patch.programId } : {}),
      ...(patch.planId !== undefined ? { plan_id: patch.planId } : {}),
      ...(patch.courseId !== undefined ? { course_id: patch.courseId } : {}),
      ...(patch.startsOn !== undefined ? { starts_on: patch.startsOn } : {}),
      ...(patch.endsOn !== undefined ? { ends_on: patch.endsOn } : {}),
      ...(patch.startsAt !== undefined ? { starts_at: patch.startsAt } : {}),
      ...(patch.endsAt !== undefined ? { ends_at: patch.endsAt } : {}),
      ...(patch.capacity !== undefined ? { capacity: patch.capacity } : {}),
      ...(patch.location !== undefined ? { location: patch.location } : {}),
      ...(patch.status !== undefined ? { status: patch.status } : {}),
    },
    { idempotencyKey: newTrainingIdempotencyKey('session-update') },
  )
  return normaliseSession(raw)
}

export async function enroll(sessionId: number, employmentId: number): Promise<TrainingEnrollment> {
  const raw = await resourcePost<unknown>(
    '/v1/training/enrollments',
    { session_id: sessionId, employment_id: employmentId },
    { idempotencyKey: newTrainingIdempotencyKey('enroll') },
  )
  return normaliseEnrollment(raw)
}

export async function cancelEnrollment(enrollmentId: number, reason: string): Promise<TrainingEnrollment> {
  const raw = await resourcePost<unknown>(
    `/v1/training/enrollments/${enrollmentId}/cancel`,
    { reason: reason === '' ? null : reason },
    { idempotencyKey: newTrainingIdempotencyKey('cancel-enrollment') },
  )
  return normaliseEnrollment(raw)
}

export type CompletionInput = {
  completionResult: CompletionResult
  attended: boolean
  score: number | null
  completedOn: string
}

export async function completeEnrollment(
  enrollmentId: number,
  input: CompletionInput,
): Promise<TrainingEnrollment> {
  const body: Record<string, unknown> = {
    completion_result: input.completionResult,
    attended: input.attended,
    completed_on: input.completedOn,
  }
  if (input.score !== null) body.score = input.score

  const raw = await resourcePost<unknown>(
    `/v1/training/enrollments/${enrollmentId}/complete`,
    body,
    { idempotencyKey: newTrainingIdempotencyKey('complete') },
  )
  return normaliseEnrollment(raw)
}

export async function checkIn(
  sessionId: number,
  employmentId: number,
  checkedInAt: string | null,
  checkedOutAt: string | null,
): Promise<TrainingCheckin> {
  const raw = await resourcePost<unknown>(
    `/v1/training/sessions/${sessionId}/checkins`,
    {
      employment_id: employmentId,
      ...(checkedInAt !== null ? { checked_in_at: checkedInAt } : {}),
      ...(checkedOutAt !== null ? { checked_out_at: checkedOutAt } : {}),
    },
    { idempotencyKey: newTrainingIdempotencyKey('checkin') },
  )
  return normaliseCheckin(raw)
}

export async function createExam(draft: ExamDraft): Promise<TrainingExam> {
  const raw = await resourcePost<unknown>(
    '/v1/training/exams',
    {
      ...(draft.courseId !== null ? { course_id: draft.courseId } : {}),
      ...(draft.sessionId !== null ? { session_id: draft.sessionId } : {}),
      code: draft.code,
      title: draft.title,
      passing_score: draft.passingScore,
      max_score: draft.maxScore,
      publish: draft.publish,
    },
    { idempotencyKey: newTrainingIdempotencyKey('exam') },
  )
  return normaliseExam(raw)
}

export async function publishExam(examId: number): Promise<TrainingExam> {
  const raw = await resourcePost<unknown>(
    `/v1/training/exams/${examId}/publish`,
    {},
    { idempotencyKey: newTrainingIdempotencyKey('exam-publish') },
  )
  return normaliseExam(raw)
}

export async function createFeedback(draft: FeedbackDraft): Promise<TrainingFeedback> {
  const raw = await resourcePost<unknown>(
    '/v1/training/feedback',
    {
      enrollment_id: draft.enrollmentId,
      ...(draft.rating !== null ? { rating: draft.rating } : {}),
      comments: draft.comments,
    },
    { idempotencyKey: newTrainingIdempotencyKey('feedback') },
  )
  return normaliseFeedback(raw)
}

export function newTrainingIdempotencyKey(prefix: string): string {
  const suffix =
    typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
      ? crypto.randomUUID()
      : `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`
  return `training-${prefix}-${suffix}`.slice(0, 128)
}

/* ------------------------------------------------------------------ */
/* Render-only Chinese copy. Values never feed back into a domain model. */
/* ------------------------------------------------------------------ */

export const DELIVERY_MODE_LABELS: Record<DeliveryMode, string> = {
  ONSITE: '线下',
  ONLINE: '线上',
  HYBRID: '混合',
}

export const PROGRAM_STATUS_LABELS: Record<ProgramStatus, string> = {
  ACTIVE: '启用中',
  ARCHIVED: '已归档',
}

export const ENROLLMENT_STATUS_LABELS: Record<EnrollmentStatus, string> = {
  ENROLLED: '已报名',
  ATTENDED: '已签到',
  COMPLETED: '已完成',
  CANCELLED: '已取消',
}

export const SESSION_STATUS_LABELS: Record<string, string> = {
  SCHEDULED: '已排期',
  CANCELLED: '已取消',
  COMPLETED: '已结束',
}

export const COMPLETION_RESULT_LABELS: Record<CompletionResult, string> = {
  PASS: '通过',
  FAIL: '未通过',
  ATTENDED: '仅出席',
}

export const EXAM_STATUS_LABELS: Record<ExamStatus, string> = {
  DRAFT: '草稿',
  PUBLISHED: '已发布',
}

export function deliveryModeLabel(mode: DeliveryMode | null): string {
  return mode === null ? '未设置' : DELIVERY_MODE_LABELS[mode]
}

export function programStatusLabel(status: ProgramStatus): string {
  return PROGRAM_STATUS_LABELS[status]
}

export function enrollmentStatusLabel(status: EnrollmentStatus): string {
  return ENROLLMENT_STATUS_LABELS[status]
}

export function sessionStatusLabel(status: string): string {
  return SESSION_STATUS_LABELS[status] ?? '其他状态'
}

export function examStatusLabel(status: ExamStatus): string {
  return EXAM_STATUS_LABELS[status]
}

export function isActiveEnrollment(status: EnrollmentStatus): boolean {
  return status === 'ENROLLED' || status === 'ATTENDED'
}

export function enrollmentActionReason(status: EnrollmentStatus): string {
  return isActiveEnrollment(status) ? '' : `当前报名状态为${enrollmentStatusLabel(status)}，不能重复登记完成。`
}

/**
 * Stable Chinese explanation for a failed training command. Raw backend
 * reasons and provider English are never shown to the operator.
 */
export function trainingActionErrorCopy(error: unknown): string {
  if (error instanceof ApiError) {
    switch (error.serverReason) {
      case 'TRAINING_CAPACITY_REACHED': {
        const capacity = error.details.capacity
        return typeof capacity === 'number'
          ? `该场次名额已满（容量 ${capacity} 人），无法继续报名。请选择其他场次或联系培训管理员扩容。`
          : '该场次名额已满，无法继续报名。请选择其他场次或联系培训管理员扩容。'
      }
      case 'TRAINING_ENROLLMENT_NOT_ACTIVE':
        return '仅处于“已报名”或“已签到”状态的报名可以登记完成；已取消或已完成的报名不会重复写入。'
      case 'TRAINING_ENROLLMENT_NOT_CANCELLABLE':
        return '仅处于“已报名”或“已签到”状态的报名可以取消；已完成或已取消的报名不会重复写入。'
      case 'TRAINING_ENROLLMENT_REQUIRED':
        return '该员工尚未报名此场次，不能登记签到或完成。'
      case 'TRAINING_PROGRAM_DUPLICATE':
        return '培训项目编码已存在且内容不同，请更换编码或读取已有项目后再修改。'
      case 'TRAINING_CAPACITY_BELOW_ENROLLED':
        return '新容量低于当前有效报名人数，请先取消报名或保留足够名额。'
      case 'TRAINING_PROGRAM_HAS_SESSIONS':
        return '培训项目仍有已排期场次，不能归档；请先结束或取消这些场次。'
      case 'TRAINING_SESSION_TIME_INVALID':
      case 'TRAINING_SESSION_DATE_INVALID':
        return '场次结束时间必须晚于开始时间，且结束日期不得早于开始日期。'
      case 'TRAINING_SESSION_CONFLICT':
        return '该课程讲师在所选时间已有重叠场次，请调整时间或讲师。'
      case 'TRAINING_SESSION_HAS_ACTIVE_ENROLLMENTS':
        return '场次仍有有效报名，不能完成或取消；请先处理报名。'
      case 'TRAINING_EXAM_SESSION_MISMATCH':
        return '场次不属于该课程，考试不能关联到不匹配的场次。'
      case 'TRAINING_EXAM_SESSION_IMMUTABLE':
        return '草稿考试的场次关联创建后不可更换；请发布当前版本或创建新编码。'
      case 'EXAM_ALREADY_PUBLISHED':
        return '该考试版本已发布并冻结；如需修改，请以同一编码保存下一版本。'
      case 'CAPABILITY_DENIED':
        return '当前账号没有执行该培训操作的权限。'
      case 'IDEMPOTENCY_PAYLOAD_MISMATCH':
        return '同一幂等键已用于不同的提交内容，请刷新后重新提交。'
    }

    if (error.code === 'FORBIDDEN') return '当前账号没有执行该培训操作的权限。'
    if (error.code === 'VALIDATION_FAILED') {
      const fields = Object.values(error.fieldErrors).flat()
      if (fields.length > 0) return `提交内容未通过校验：${fields.join('；')}`
      return '提交内容未通过校验，请检查必填项与格式。'
    }
    if (error.code === 'NOT_FOUND') return '未找到该培训记录，可能已被撤回或不在当前数据范围内。'
  }

  return error instanceof Error && error.message !== '' ? error.message : '操作未完成，请稍后重试。'
}
