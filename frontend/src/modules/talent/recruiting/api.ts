import { ApiError } from '@/api/errors'
import { resourceGet, resourcePatch, resourcePost } from '@/api/resourceClient'

/**
 * Recruitment workspace API boundary.
 *
 * The client normalises transport casing only. Money stays an integer count of
 * minor units until a component formats it, and absent server values stay
 * absent instead of becoming zero or a fabricated row.
 */

export type RequisitionStatus =
  | 'DRAFT'
  | 'PENDING_APPROVAL'
  | 'APPROVED'
  | 'REJECTED'
  | 'CLOSED'
  | 'ON_HOLD'

export type Requisition = {
  id: number
  requisitionNo: string
  title: string
  orgUnitId: number
  orgUnitName: string | null
  status: RequisitionStatus
  statusLabel: string
  headcount: number
  budgetMinor: number | null
  budgetCurrency: string | null
  targetStartDate: string | null
  openedAt: string | null
  caseId: number | null
}

export type OrgUnitOption = {
  id: number
  name: string
  code: string
}

export type RequisitionInput = {
  org_unit_id: number
  requisition_no?: string
  title: string
  headcount: number
  budget_minor: number | null
  budget_currency: string | null
  target_start_date: string | null
}

export type RequisitionPatch = {
  org_unit_id?: number
  title?: string
  headcount?: number
  budget_minor?: number | null
  budget_currency?: string | null
  target_start_date?: string | null
}

export type CandidateStatus =
  | 'NEW'
  | 'SCREENING'
  | 'INTERVIEW'
  | 'OFFER'
  | 'OFFER_ACCEPTED'
  | 'CONVERTED'
  | 'HIRED'
  | 'REJECTED'

export type PipelineStage = 'NEW' | 'SCREENING' | 'INTERVIEW' | 'OFFER' | 'HIRED' | 'REJECTED'

export type Candidate = {
  id: number
  displayName: string
  status: CandidateStatus
  statusLabel: string
  stage: PipelineStage
  hasEmail: boolean
  hasMobile: boolean
  source: string | null
  applicationId: number | null
  applicationStage: string | null
  requisitionId: number | null
  onboardingCaseId: number | null
  conversionStatus: string | null
  offerId: number | null
  offerStatus: string | null
  baseSalaryMinor: number | null
  salaryCurrency: string | null
  offerStartDate: string | null
  nextInterviewAt: string | null
  convertedEmploymentId: number | null
  convertedEmployeeNo: string | null
}

export type CandidateFilters = {
  /** Direct id lookup, used by the candidate detail route. */
  candidateId?: number
  requisitionId?: number | null
  status?: string
  search?: string
  talentPool?: boolean
}

export type Interview = {
  id: number
  candidateId: number
  requisitionId: number
  applicationId: number | null
  applicationStage: string | null
  scheduledAt: string | null
  durationMinutes: number
  interviewer: string
  mode: string
  status: string
  recommendation: string | null
  feedback: string | null
  feedbackRestricted: boolean
}

export type OfferStatus =
  | 'DRAFT'
  | 'PENDING_APPROVAL'
  | 'APPROVED'
  | 'REJECTED'
  | 'ACCEPTED'
  | 'DECLINED'
  | 'SUPERSEDED'
  | 'WITHDRAWN'
  | 'UNKNOWN'

export type Offer = {
  id: number
  candidateId: number
  requisitionId: number
  version: number
  status: OfferStatus
  statusLabel: string
  baseSalaryMinor: number
  salaryCurrency: string
  startDate: string
  caseId: number | null
  onboardingCaseId: number | null
  acceptedAt: string | null
}

export type ConversionInput = {
  orgUnitId: number
  employeeNo: string
  hireDate: string | null
}

export type CandidateConversion = {
  conversionId: number | null
  candidateId: number
  employmentId: number | null
  personId: number | null
  offerId: number
  orgUnitId: number
  employeeNo: string
  status: string
  onboardingCaseId: number | null
  convertedAt: string | null
  businessReplayed: boolean
}

export type CandidateApplication = {
  id: number
  candidateId: number
  requisitionId: number
  openingId: number | null
  stage: string
  status: string
  source: string | null
  recordedAt: string | null
  requisitionNo: string | null
  requisitionTitle: string | null
  requisitionStatus: string | null
}

export type InterviewInput = {
  requisitionId: number
  scheduledAt: string
  durationMinutes: number
  interviewer: string
  mode: string
}

export type Evaluation = {
  id: number
  candidateId: number
  interviewId: number | null
  evaluatorUserId: number
  rating: number
  recommendation: string
  strengths: string | null
  concerns: string | null
  comments: string | null
  status: string
  recordedAt: string | null
}

export type Communication = {
  id: number
  candidateId: number
  channel: string
  direction: string
  subject: string | null
  body: string
  consentBasis: string
  sentAt: string | null
  recordedAt: string | null
}

export type TalentPoolTag = {
  id: number
  candidateId: number
  tag: string
  reason: string | null
  retentionUntil: string | null
  status: string
  recordedAt: string | null
}

export type RequisitionOpening = {
  id: number
  requisitionId: number
  channel: string
  status: string
  publishedAt: string | null
}

export type RecruitmentBoardRow = {
  requisitionId: number
  requisitionNo: string
  title: string
  status: string
  headcount: number
  openingsCount: number
  candidateCount: number
  interviewCount: number
  offerCount: number
  acceptedOffers: number
  hiredCount: number
  vacancyCount: number
  fillRateBps: number
}

export type RecruitmentBudgetRow = {
  requisitionId: number
  requisitionNo: string
  title: string
  status: string
  headcount: number
  budgetMinor: number | null
  budgetCurrency: string | null
  committedOfferMinor: number
  budgetVarianceMinor: number | null
  filledHeadcount: number
  vacancyCount: number
}

export type RecruitmentReportRow = {
  requisitionId: number
  requisitionNo: string
  title: string
  status: string
  headcount: number
  openingsCount: number
  candidateCount: number
  interviewCount: number
  offerCount: number
  acceptedOffers: number
  hiredCount: number
  fillRateBps: number
  timeToHireDays: number | null
}

export type CandidateImportRow = {
  rowNumber: number
  status: string
  reason: string | null
  errors: Record<string, string[]>
  candidateId: number | null
}

export type CandidateImportResult = {
  importId: number
  requisitionId: number | null
  source: string
  rowCount: number
  acceptedCount: number
  rejectedCount: number
  rows: CandidateImportRow[]
  recordedAt: string | null
}

export type ListResult<T> = {
  items: T[]
  page: number
  pageSize: number
  total: number
}

type Raw = Record<string, unknown>

const REQUISITION_STATUS_LABELS: Readonly<Record<RequisitionStatus, string>> = {
  DRAFT: '草稿',
  PENDING_APPROVAL: '招聘中',
  APPROVED: '招聘中',
  REJECTED: '已关闭',
  CLOSED: '已关闭',
  ON_HOLD: '已暂停',
}

const CANDIDATE_STATUS_LABELS: Readonly<Record<string, string>> = {
  NEW: '新简历',
  SCREENING: '筛选',
  INTERVIEW: '面试',
  OFFER: 'offer',
  OFFER_ACCEPTED: '已接受 Offer',
  CONVERTED: '已入职',
  HIRED: '已入职',
  REJECTED: '已淘汰',
  ACTIVE: '有效',
  EXPIRED: '已过期',
  REVOKED: '已撤销',
}

const OFFER_STATUS_LABELS: Readonly<Record<OfferStatus, string>> = {
  DRAFT: '草稿',
  PENDING_APPROVAL: '审批中',
  APPROVED: '已批准',
  REJECTED: '已拒绝',
  ACCEPTED: '已接受',
  DECLINED: '已拒绝',
  SUPERSEDED: '已取代',
  WITHDRAWN: '已撤回',
  UNKNOWN: '未知状态',
}

export const PIPELINE_STAGES: ReadonlyArray<{ key: PipelineStage; label: string }> = [
  { key: 'NEW', label: '新简历' },
  { key: 'SCREENING', label: '筛选' },
  { key: 'INTERVIEW', label: '面试' },
  { key: 'OFFER', label: 'offer' },
  { key: 'HIRED', label: '已入职' },
  { key: 'REJECTED', label: '已淘汰' },
]

/** Stages a recruiter may set explicitly. Hired is produced by conversion. */
export const MOVABLE_STAGES: ReadonlyArray<{ key: string; label: string }> = [
  { key: 'NEW', label: '新简历' },
  { key: 'SCREENING', label: '筛选' },
  { key: 'INTERVIEW', label: '面试' },
  { key: 'OFFER', label: 'offer' },
  { key: 'OFFER_ACCEPTED', label: '已接受 Offer' },
  { key: 'REJECTED', label: '已淘汰' },
]

export const REQUISITION_STATUS_OPTIONS: ReadonlyArray<{ value: RequisitionStatus | ''; label: string }> = [
  { value: '', label: '全部状态' },
  { value: 'DRAFT', label: '草稿' },
  { value: 'PENDING_APPROVAL', label: '招聘中' },
  { value: 'ON_HOLD', label: '已暂停' },
  { value: 'CLOSED', label: '已关闭' },
]

export const OPENING_CHANNELS: ReadonlyArray<{ value: string; label: string }> = [
  { value: 'EXTERNAL', label: '外部渠道' },
  { value: 'INTERNAL', label: '内部渠道' },
  { value: 'REFERRAL', label: '内部推荐' },
  { value: 'AGENCY', label: '猎头渠道' },
]

export function requisitionStatusLabel(value: string): string {
  return REQUISITION_STATUS_LABELS[value as RequisitionStatus] ?? '未知状态'
}

export function candidateStatusLabel(value: string): string {
  return CANDIDATE_STATUS_LABELS[value] ?? '未知状态'
}

export function openingStatusLabel(value: string): string {
  return ({ DRAFT: '草稿', OPEN: '开放中', PUBLISHED: '已发布', CLOSED: '已关闭', FILLED: '已招满', CANCELLED: '已取消' } as Record<string, string>)[value] ?? '未知状态'
}

export function offerStatusLabel(value: string): string {
  return OFFER_STATUS_LABELS[value as OfferStatus] ?? '未知状态'
}

export function stageForStatus(value: string): PipelineStage {
  switch (value) {
    case 'SCREENING':
      return 'SCREENING'
    case 'INTERVIEW':
      return 'INTERVIEW'
    case 'OFFER':
    case 'OFFER_ACCEPTED':
      return 'OFFER'
    case 'CONVERTED':
    case 'HIRED':
      return 'HIRED'
    case 'REJECTED':
      return 'REJECTED'
    default:
      return 'NEW'
  }
}

export function stageLabel(value: string): string {
  return PIPELINE_STAGES.find((stage) => stage.key === value)?.label ?? '未知阶段'
}

export function nextIdempotencyKey(prefix: string): string {
  const random = globalThis.crypto?.randomUUID?.() ?? Math.random().toString(36).slice(2)
  return `recruitment-${prefix}-${random}`
}

function isRecord(value: unknown): value is Raw {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function contractError(field: string): never {
  throw new ApiError('INTERNAL', `服务端返回的招聘数据不符合契约：${field}。`, { status: 502 })
}

function text(value: unknown, field: string): string {
  if (typeof value === 'string' && value !== '') return value
  contractError(field)
}

function nullableText(value: unknown): string | null {
  return typeof value === 'string' && value !== '' ? value : null
}

function integer(value: unknown, field: string): number {
  if (typeof value === 'number' && Number.isSafeInteger(value)) return value
  contractError(field)
}

function nullableInteger(value: unknown): number | null {
  if (value === null || value === undefined) return null
  if (typeof value === 'number' && Number.isSafeInteger(value)) return value
  return null
}

function rowsOf(value: unknown): Raw[] {
  if (Array.isArray(value)) return value.filter(isRecord)
  if (isRecord(value) && Array.isArray(value.items)) return value.items.filter(isRecord)
  return []
}

function listMeta(value: unknown, count: number): { page: number; pageSize: number; total: number } {
  if (!isRecord(value)) return { page: 1, pageSize: Math.max(1, count), total: count }
  return {
    page: integer(value.page ?? 1, 'page'),
    pageSize: integer(value.page_size ?? Math.max(1, count), 'page_size'),
    total: integer(value.total ?? count, 'total'),
  }
}

export function normaliseRequisition(raw: unknown): Requisition {
  const row = isRecord(raw) ? raw : contractError('requisition')
  const status = text(row.status, 'status') as RequisitionStatus

  return {
    id: integer(row.requisition_id, 'requisition_id'),
    requisitionNo: text(row.requisition_no, 'requisition_no'),
    title: text(row.title, 'title'),
    orgUnitId: integer(row.org_unit_id, 'org_unit_id'),
    orgUnitName: nullableText(row.org_unit_name),
    status,
    statusLabel: requisitionStatusLabel(status),
    headcount: integer(row.headcount, 'headcount'),
    budgetMinor: nullableInteger(row.budget_minor),
    budgetCurrency: nullableText(row.budget_currency),
    targetStartDate: nullableText(row.target_start_date),
    openedAt: nullableText(row.opened_at),
    caseId: nullableInteger(row.case_id),
  }
}

export async function listRequisitions(
  filters: { status?: string; search?: string } = {},
  page = 1,
  pageSize = 50,
): Promise<ListResult<Requisition>> {
  const raw = await resourceGet<unknown>('/v1/recruitment/requisitions', {
    status: filters.status || undefined,
    search: filters.search || undefined,
    page,
    page_size: pageSize,
  })
  const items = rowsOf(raw).map(normaliseRequisition)
  return { items, ...listMeta(raw, items.length) }
}

export async function listOrgUnits(): Promise<OrgUnitOption[]> {
  const raw = await resourceGet<unknown>('/v1/org-units/tree')
  return rowsOf(raw)
    .map((row): OrgUnitOption | null => {
      const id = nullableInteger(row.id)
      if (id === null || id <= 0) return null
      return {
        id,
        name: typeof row.name === 'string' && row.name !== '' ? row.name : `组织单元 #${id}`,
        code: typeof row.code === 'string' ? row.code : '',
      }
    })
    .filter((row): row is OrgUnitOption => row !== null)
}

export async function createRequisition(input: RequisitionInput): Promise<Requisition> {
  const body: Record<string, unknown> = {
    org_unit_id: input.org_unit_id,
    requisition_no: input.requisition_no?.trim(),
    title: input.title.trim(),
    headcount: input.headcount,
  }
  if (input.budget_minor !== null) body.budget_minor = input.budget_minor
  if (input.budget_currency !== null && input.budget_currency.trim() !== '') {
    body.budget_currency = input.budget_currency.trim().toUpperCase()
  }
  if (input.target_start_date !== null && input.target_start_date !== '') {
    body.target_start_date = input.target_start_date
  }

  const raw = await resourcePost<unknown>('/v1/recruitment/requisitions', body, {
    idempotencyKey: nextIdempotencyKey('requisition-create'),
  })
  return normaliseRequisition(raw)
}

export async function updateRequisition(id: number, patch: RequisitionPatch): Promise<Requisition> {
  const body: Record<string, unknown> = {}
  if (patch.org_unit_id !== undefined) body.org_unit_id = patch.org_unit_id
  if (patch.title !== undefined) body.title = patch.title.trim()
  if (patch.headcount !== undefined) body.headcount = patch.headcount
  if (patch.budget_minor !== undefined) body.budget_minor = patch.budget_minor
  if (patch.budget_currency !== undefined) body.budget_currency = patch.budget_currency
  if (patch.target_start_date !== undefined) body.target_start_date = patch.target_start_date

  const raw = await resourcePatch<unknown>(`/v1/recruitment/requisitions/${id}`, body, {
    idempotencyKey: nextIdempotencyKey(`requisition-update-${id}`),
  })
  return normaliseRequisition(raw)
}

export async function closeRequisition(id: number, reason: string): Promise<Requisition> {
  const raw = await resourcePost<unknown>(
    `/v1/recruitment/requisitions/${id}/close`,
    { reason: reason.trim() },
    { idempotencyKey: nextIdempotencyKey(`requisition-close-${id}`) },
  )
  return normaliseRequisition(raw)
}

export function normaliseCandidate(raw: unknown): Candidate {
  const row = isRecord(raw) ? raw : contractError('candidate')
  const status = text(row.status, 'status')

  return {
    id: integer(row.candidate_id, 'candidate_id'),
    displayName: text(row.display_name, 'display_name'),
    status: status as CandidateStatus,
    statusLabel: candidateStatusLabel(status),
    stage: stageForStatus(status),
    hasEmail: row.has_email === true,
    hasMobile: row.has_mobile === true,
    source: nullableText(row.source),
    applicationId: nullableInteger(row.application_id),
    applicationStage: nullableText(row.application_stage),
    requisitionId: nullableInteger(row.requisition_id),
    onboardingCaseId: nullableInteger(row.onboarding_case_id),
    conversionStatus: nullableText(row.conversion_status),
    offerId: nullableInteger(row.offer_id),
    offerStatus: nullableText(row.offer_status),
    baseSalaryMinor: nullableInteger(row.base_salary_minor),
    salaryCurrency: nullableText(row.salary_currency),
    offerStartDate: nullableText(row.offer_start_date),
    nextInterviewAt: nullableText(row.next_interview_at),
    convertedEmploymentId: nullableInteger(row.converted_employment_id),
    convertedEmployeeNo: nullableText(row.converted_employee_no),
  }
}

export async function listCandidates(
  filters: CandidateFilters = {},
  page = 1,
  pageSize = 100,
): Promise<ListResult<Candidate>> {
  const raw = await resourceGet<unknown>('/v1/recruitment/candidates', {
    requisition_id: filters.requisitionId ?? undefined,
    candidate_id: filters.candidateId ?? undefined,
    status: filters.status || undefined,
    search: filters.search || undefined,
    talent_pool: filters.talentPool ? true : undefined,
    page,
    page_size: pageSize,
  })
  const items = rowsOf(raw).map(normaliseCandidate)
  return { items, ...listMeta(raw, items.length) }
}

export async function moveCandidateStage(
  candidateId: number,
  status: string,
  reason: string | null = null,
  applicationId: number | null = null,
): Promise<{ candidateId: number; status: string; previousStatus: string; replayed: boolean; applicationId: number | null }> {
  const raw = await resourcePatch<unknown>(
    `/v1/recruitment/candidates/${candidateId}/stage`,
    {
      status,
      ...(applicationId !== null ? { application_id: applicationId } : {}),
      ...(reason === null || reason.trim() === '' ? {} : { reason: reason.trim() }),
    },
    { idempotencyKey: nextIdempotencyKey(`candidate-stage-${candidateId}`) },
  )
  const row = isRecord(raw) ? raw : contractError('candidate_stage')
  return {
    candidateId: integer(row.candidate_id, 'candidate_id'),
    status: text(row.status, 'status'),
    previousStatus: text(row.previous_status, 'previous_status'),
    replayed: row.replayed === true,
    applicationId: nullableInteger(row.application_id),
  }
}

export function normaliseInterview(raw: unknown): Interview {
  const row = isRecord(raw) ? raw : contractError('interview')

  return {
    id: integer(row.interview_id, 'interview_id'),
    candidateId: integer(row.candidate_id, 'candidate_id'),
    requisitionId: integer(row.requisition_id, 'requisition_id'),
    applicationId: nullableInteger(row.application_id),
    applicationStage: nullableText(row.application_stage),
    scheduledAt: nullableText(row.scheduled_at),
    durationMinutes: integer(row.duration_minutes, 'duration_minutes'),
    interviewer: text(row.interviewer, 'interviewer'),
    mode: text(row.mode, 'mode'),
    status: text(row.status, 'status'),
    recommendation: nullableText(row.recommendation),
    feedback: nullableText(row.feedback),
    feedbackRestricted: row.feedback_restricted === true,
  }
}

export async function listInterviews(candidateId: number): Promise<Interview[]> {
  const raw = await resourceGet<unknown>(`/v1/recruitment/candidates/${candidateId}/interviews`)
  return rowsOf(raw).map(normaliseInterview)
}

export function normaliseOffer(raw: unknown): Offer {
  const row = isRecord(raw) ? raw : contractError('offer')
  const status = text(row.status, 'status') as OfferStatus

  return {
    id: integer(row.offer_id, 'offer_id'),
    candidateId: integer(row.candidate_id, 'candidate_id'),
    requisitionId: integer(row.requisition_id, 'requisition_id'),
    version: integer(row.version, 'version'),
    status,
    statusLabel: offerStatusLabel(status),
    baseSalaryMinor: integer(row.base_salary_minor, 'base_salary_minor'),
    salaryCurrency: text(row.salary_currency, 'salary_currency'),
    startDate: text(row.start_date, 'start_date'),
    caseId: nullableInteger(row.case_id),
    onboardingCaseId: nullableInteger(row.onboarding_case_id),
    acceptedAt: nullableText(row.accepted_at),
  }
}

export async function listOffers(candidateId: number): Promise<Offer[]> {
  const raw = await resourceGet<unknown>('/v1/recruitment/offers', {
    candidate_id: candidateId,
    page: 1,
    page_size: 20,
  })
  return rowsOf(raw).map(normaliseOffer)
}

export async function convertCandidate(
  candidateId: number,
  input: ConversionInput,
): Promise<CandidateConversion> {
  /**
   * Single-company contract: the conversion body has exactly the target org
   * unit, employee number and optional hire date. Tenant and legal-entity
   * fields were removed in Volume 2 and must never be sent by the client.
   */
  const body: Record<string, unknown> = {
    org_unit_id: input.orgUnitId,
    employee_no: input.employeeNo.trim(),
  }
  if (input.hireDate !== null && input.hireDate !== '') {
    body.hire_date = input.hireDate
  }

  const raw = await resourcePost<unknown>(
    `/v1/recruitment/candidates/${candidateId}/convert`,
    body,
    { idempotencyKey: nextIdempotencyKey(`candidate-convert-${candidateId}`) },
  )
  const row = isRecord(raw) ? raw : contractError('candidate_conversion')

  return {
    conversionId: nullableInteger(row.conversion_id),
    candidateId: integer(row.candidate_id, 'candidate_id'),
    employmentId: nullableInteger(row.employment_id),
    personId: nullableInteger(row.person_id),
    offerId: integer(row.offer_id, 'offer_id'),
    orgUnitId: integer(row.org_unit_id, 'org_unit_id'),
    employeeNo: text(row.employee_no, 'employee_no'),
    status: text(row.status, 'status'),
    onboardingCaseId: nullableInteger(row.onboarding_case_id),
    convertedAt: nullableText(row.converted_at),
    businessReplayed: row.business_replayed === true,
  }
}

export function normaliseCandidateApplication(raw: unknown): CandidateApplication {
  const row = isRecord(raw) ? raw : contractError('candidate_application')

  return {
    id: integer(row.application_id, 'application_id'),
    candidateId: integer(row.candidate_id, 'candidate_id'),
    requisitionId: integer(row.requisition_id, 'requisition_id'),
    openingId: nullableInteger(row.opening_id),
    stage: text(row.stage, 'stage'),
    status: text(row.status, 'status'),
    source: nullableText(row.source),
    recordedAt: nullableText(row.recorded_at),
    requisitionNo: nullableText(row.requisition_no),
    requisitionTitle: nullableText(row.requisition_title),
    requisitionStatus: nullableText(row.requisition_status),
  }
}

export async function listApplications(candidateId: number): Promise<CandidateApplication[]> {
  const raw = await resourceGet<unknown>(`/v1/recruitment/candidates/${candidateId}/applications`)
  return rowsOf(raw).map(normaliseCandidateApplication)
}

export async function createApplication(
  candidateId: number,
  input: { requisitionId: number; openingId?: number | null; source?: string | null },
): Promise<CandidateApplication> {
  const raw = await resourcePost<unknown>(
    `/v1/recruitment/candidates/${candidateId}/applications`,
    {
      requisition_id: input.requisitionId,
      ...(input.openingId ? { opening_id: input.openingId } : {}),
      ...(input.source ? { source: input.source } : {}),
    },
    { idempotencyKey: nextIdempotencyKey(`candidate-application-${candidateId}`) },
  )

  return normaliseCandidateApplication(raw)
}

export async function createInterview(candidateId: number, input: InterviewInput): Promise<Interview> {
  const raw = await resourcePost<unknown>(
    `/v1/recruitment/candidates/${candidateId}/interviews`,
    {
      requisition_id: input.requisitionId,
      scheduled_at: input.scheduledAt,
      duration_minutes: input.durationMinutes,
      interviewer: input.interviewer,
      mode: input.mode,
    },
    { idempotencyKey: nextIdempotencyKey(`candidate-interview-${candidateId}`) },
  )

  return normaliseInterview(raw)
}

export function normaliseEvaluation(raw: unknown): Evaluation {
  const row = isRecord(raw) ? raw : contractError('candidate_evaluation')

  return {
    id: integer(row.evaluation_id, 'evaluation_id'),
    candidateId: integer(row.candidate_id, 'candidate_id'),
    interviewId: nullableInteger(row.interview_id),
    evaluatorUserId: integer(row.evaluator_user_id, 'evaluator_user_id'),
    rating: integer(row.rating, 'rating'),
    recommendation: text(row.recommendation, 'recommendation'),
    strengths: nullableText(row.strengths),
    concerns: nullableText(row.concerns),
    comments: nullableText(row.comments),
    status: text(row.status, 'status'),
    recordedAt: nullableText(row.recorded_at),
  }
}

export async function listEvaluations(candidateId: number): Promise<Evaluation[]> {
  const raw = await resourceGet<unknown>(`/v1/recruitment/candidates/${candidateId}/evaluations`)
  return rowsOf(raw).map(normaliseEvaluation)
}

export async function createEvaluation(
  candidateId: number,
  input: {
    interviewId: number | null
    rating: number
    recommendation: string
    strengths?: string | null
    concerns?: string | null
    comments?: string | null
  },
): Promise<Evaluation> {
  const raw = await resourcePost<unknown>(
    `/v1/recruitment/candidates/${candidateId}/evaluations`,
    {
      ...(input.interviewId !== null ? { interview_id: input.interviewId } : {}),
      rating: input.rating,
      recommendation: input.recommendation,
      strengths: input.strengths ?? null,
      concerns: input.concerns ?? null,
      comments: input.comments ?? null,
    },
    { idempotencyKey: nextIdempotencyKey(`candidate-evaluation-${candidateId}`) },
  )

  return normaliseEvaluation(raw)
}

export function normaliseCommunication(raw: unknown): Communication {
  const row = isRecord(raw) ? raw : contractError('candidate_communication')

  return {
    id: integer(row.communication_id, 'communication_id'),
    candidateId: integer(row.candidate_id, 'candidate_id'),
    channel: text(row.channel, 'channel'),
    direction: text(row.direction, 'direction'),
    subject: nullableText(row.subject),
    body: text(row.body, 'body'),
    consentBasis: text(row.consent_basis, 'consent_basis'),
    sentAt: nullableText(row.sent_at),
    recordedAt: nullableText(row.recorded_at),
  }
}

export async function listCommunications(candidateId: number): Promise<Communication[]> {
  const raw = await resourceGet<unknown>(`/v1/recruitment/candidates/${candidateId}/communications`)
  return rowsOf(raw).map(normaliseCommunication)
}

export async function createCommunication(
  candidateId: number,
  input: {
    channel: string
    direction: string
    subject: string | null
    body: string
    consentBasis: string
    sentAt?: string | null
  },
): Promise<Communication> {
  const raw = await resourcePost<unknown>(
    `/v1/recruitment/candidates/${candidateId}/communications`,
    {
      channel: input.channel,
      direction: input.direction,
      subject: input.subject,
      body: input.body,
      consent_basis: input.consentBasis,
      ...(input.sentAt ? { sent_at: input.sentAt } : {}),
    },
    { idempotencyKey: nextIdempotencyKey(`candidate-communication-${candidateId}`) },
  )

  return normaliseCommunication(raw)
}

export function normaliseTalentPoolTag(raw: unknown): TalentPoolTag {
  const row = isRecord(raw) ? raw : contractError('talent_pool_tag')

  return {
    id: integer(row.tag_id, 'tag_id'),
    candidateId: integer(row.candidate_id, 'candidate_id'),
    tag: text(row.tag, 'tag'),
    reason: nullableText(row.reason),
    retentionUntil: nullableText(row.retention_until),
    status: text(row.status, 'status'),
    recordedAt: nullableText(row.recorded_at),
  }
}

export async function listTalentPoolTags(candidateId: number): Promise<TalentPoolTag[]> {
  const raw = await resourceGet<unknown>(`/v1/recruitment/candidates/${candidateId}/talent-pool`)
  return rowsOf(raw).map(normaliseTalentPoolTag)
}

export async function createTalentPoolTag(
  candidateId: number,
  input: { tag: string; reason: string | null; retentionUntil: string | null },
): Promise<TalentPoolTag> {
  const raw = await resourcePost<unknown>(
    `/v1/recruitment/candidates/${candidateId}/talent-pool`,
    {
      tag: input.tag,
      reason: input.reason,
      retention_until: input.retentionUntil,
    },
    { idempotencyKey: nextIdempotencyKey(`candidate-talent-pool-${candidateId}`) },
  )

  return normaliseTalentPoolTag(raw)
}

export async function createOffer(input: {
  candidateId: number
  requisitionId: number
  baseSalaryMinor: number
  salaryCurrency: string
  startDate: string
}): Promise<Offer> {
  const raw = await resourcePost<unknown>(
    '/v1/recruitment/offers',
    {
      candidate_id: input.candidateId,
      requisition_id: input.requisitionId,
      base_salary_minor: input.baseSalaryMinor,
      salary_currency: input.salaryCurrency,
      start_date: input.startDate,
    },
    { idempotencyKey: nextIdempotencyKey(`offer-create-${input.candidateId}`) },
  )
  const row = isRecord(raw) ? raw : contractError('offer')

  return {
    id: integer(row.offer_id, 'offer_id'),
    candidateId: input.candidateId,
    requisitionId: input.requisitionId,
    version: integer(row.version, 'version'),
    status: text(row.status, 'status') as OfferStatus,
    statusLabel: offerStatusLabel(text(row.status, 'status')),
    baseSalaryMinor: input.baseSalaryMinor,
    salaryCurrency: input.salaryCurrency,
    startDate: input.startDate,
    caseId: nullableInteger(row.case_id),
    onboardingCaseId: nullableInteger(row.onboarding_case_id),
    acceptedAt: null,
  }
}

export async function acceptOffer(
  offerId: number,
  input: { orgUnitId: number; employeeNo: string; hireDate: string | null },
): Promise<{ offerId: number; offerStatus: string; onboardingCaseId: number | null; replayed: boolean }> {
  const raw = await resourcePost<unknown>(
    `/v1/recruitment/offers/${offerId}/accept`,
    {
      org_unit_id: input.orgUnitId,
      employee_no: input.employeeNo.trim(),
      ...(input.hireDate ? { hire_date: input.hireDate } : {}),
    },
    { idempotencyKey: nextIdempotencyKey(`offer-accept-${offerId}`) },
  )
  const row = isRecord(raw) ? raw : contractError('offer_accept')

  return {
    offerId: integer(row.offer_id, 'offer_id'),
    offerStatus: text(row.offer_status ?? row.status, 'offer_status'),
    onboardingCaseId: nullableInteger(row.onboarding_case_id),
    replayed: row.replayed === true,
  }
}

export function normaliseOpening(raw: unknown): RequisitionOpening {
  const row = isRecord(raw) ? raw : contractError('requisition_opening')

  return {
    id: integer(row.opening_id, 'opening_id'),
    requisitionId: integer(row.requisition_id, 'requisition_id'),
    channel: text(row.channel, 'channel'),
    status: text(row.status, 'status'),
    publishedAt: nullableText(row.published_at),
  }
}

export async function listRequisitionOpenings(requisitionId: number): Promise<RequisitionOpening[]> {
  const raw = await resourceGet<unknown>(`/v1/recruitment/requisitions/${requisitionId}/openings`)
  return rowsOf(raw).map(normaliseOpening)
}

export async function publishRequisitionOpening(requisitionId: number, channel: string): Promise<RequisitionOpening> {
  const raw = await resourcePost<unknown>(
    `/v1/recruitment/requisitions/${requisitionId}/openings`,
    { channel },
    { idempotencyKey: nextIdempotencyKey(`requisition-opening-${requisitionId}`) },
  )

  return normaliseOpening(raw)
}

export async function listRecruitmentBoard(): Promise<RecruitmentBoardRow[]> {
  const raw = await resourceGet<unknown>('/v1/recruitment/board')
  return rowsOf(raw).map((row) => ({
    requisitionId: integer(row.requisition_id, 'requisition_id'),
    requisitionNo: text(row.requisition_no, 'requisition_no'),
    title: text(row.title, 'title'),
    status: text(row.status, 'status'),
    headcount: integer(row.headcount, 'headcount'),
    openingsCount: integer(row.openings_count, 'openings_count'),
    candidateCount: integer(row.candidate_count, 'candidate_count'),
    interviewCount: integer(row.interview_count, 'interview_count'),
    offerCount: integer(row.offer_count, 'offer_count'),
    acceptedOffers: integer(row.accepted_offers, 'accepted_offers'),
    hiredCount: integer(row.hired_count, 'hired_count'),
    vacancyCount: integer(row.vacancy_count, 'vacancy_count'),
    fillRateBps: integer(row.fill_rate_bps, 'fill_rate_bps'),
  }))
}

export async function listRecruitmentBudget(): Promise<RecruitmentBudgetRow[]> {
  const raw = await resourceGet<unknown>('/v1/recruitment/budget')
  return rowsOf(raw).map((row) => ({
    requisitionId: integer(row.requisition_id, 'requisition_id'),
    requisitionNo: text(row.requisition_no, 'requisition_no'),
    title: text(row.title, 'title'),
    status: text(row.status, 'status'),
    headcount: integer(row.headcount, 'headcount'),
    budgetMinor: nullableInteger(row.budget_minor),
    budgetCurrency: nullableText(row.budget_currency),
    committedOfferMinor: integer(row.committed_offer_minor, 'committed_offer_minor'),
    budgetVarianceMinor: nullableInteger(row.budget_variance_minor),
    filledHeadcount: integer(row.filled_headcount, 'filled_headcount'),
    vacancyCount: integer(row.vacancy_count, 'vacancy_count'),
  }))
}

export async function listRecruitmentReports(): Promise<RecruitmentReportRow[]> {
  const raw = await resourceGet<unknown>('/v1/recruitment/reports')
  return rowsOf(raw).map((row) => ({
    requisitionId: integer(row.requisition_id, 'requisition_id'),
    requisitionNo: text(row.requisition_no, 'requisition_no'),
    title: text(row.title, 'title'),
    status: text(row.status, 'status'),
    headcount: integer(row.headcount, 'headcount'),
    openingsCount: integer(row.openings_count, 'openings_count'),
    candidateCount: integer(row.candidate_count, 'candidate_count'),
    interviewCount: integer(row.interview_count, 'interview_count'),
    offerCount: integer(row.offer_count, 'offer_count'),
    acceptedOffers: integer(row.accepted_offers, 'accepted_offers'),
    hiredCount: integer(row.hired_count, 'hired_count'),
    fillRateBps: integer(row.fill_rate_bps, 'fill_rate_bps'),
    timeToHireDays: nullableInteger(row.time_to_hire_days),
  }))
}

export async function importCandidates(input: {
  requisitionId: number | null
  source: string
  rows: Array<{ display_name: string; email?: string; mobile?: string; source?: string }>
}): Promise<CandidateImportResult> {
  const raw = await resourcePost<unknown>(
    '/v1/recruitment/candidates/imports',
    {
      requisition_id: input.requisitionId,
      source: input.source,
      rows: input.rows,
    },
    { idempotencyKey: nextIdempotencyKey('candidate-import') },
  )
  const row = isRecord(raw) ? raw : contractError('candidate_import')
  const reports = Array.isArray(row.rows) ? row.rows.filter(isRecord) : []

  return {
    importId: integer(row.import_id, 'import_id'),
    requisitionId: nullableInteger(row.requisition_id),
    source: text(row.source, 'source'),
    rowCount: integer(row.row_count, 'row_count'),
    acceptedCount: integer(row.accepted_count, 'accepted_count'),
    rejectedCount: integer(row.rejected_count, 'rejected_count'),
    rows: reports.map((report) => ({
      rowNumber: integer(report.row_number, 'row_number'),
      status: text(report.status, 'status'),
      reason: nullableText(report.reason),
      errors: isRecord(report.errors)
        ? Object.fromEntries(
            Object.entries(report.errors).map(([field, messages]) => [
              field,
              Array.isArray(messages) ? messages.filter((message): message is string => typeof message === 'string') : [],
            ]),
          )
        : {},
      candidateId: nullableInteger(report.candidate_id),
    })),
    recordedAt: nullableText(row.recorded_at),
  }
}

export function isForbidden(error: unknown): boolean {
  return error instanceof ApiError && error.code === 'FORBIDDEN'
}

export function serverReason(error: unknown): string | null {
  if (error instanceof ApiError) {
    if (error.serverReason !== null) return error.serverReason
    const reason = error.details.reason
    return typeof reason === 'string' && reason !== '' ? reason : null
  }
  return null
}

export function requestId(error: unknown): string | null {
  return error instanceof ApiError ? error.requestId : null
}
