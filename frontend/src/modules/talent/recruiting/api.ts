import { ApiError } from '@/api/errors'
import { resourceGet, resourcePatch, resourcePost } from '@/api/resourceClient'

/**
 * Recruitment workspace API boundary.
 *
 * Live routes used here (verified with `php artisan route:list --path=recruitment`):
 *   GET   /v1/recruitment/requisitions
 *   POST  /v1/recruitment/requisitions
 *   GET   /v1/recruitment/candidates
 *   GET   /v1/recruitment/offers
 *   POST  /v1/recruitment/candidates/{id}/convert
 *   GET   /v1/org-units/tree
 *
 * The workspace-only commands below are implemented in RecruitmentController
 * but their routes belong to the router owner:
 *   PATCH /v1/recruitment/requisitions/{id}
 *   POST  /v1/recruitment/requisitions/{id}/close
 *   PATCH /v1/recruitment/candidates/{id}/stage
 *   GET   /v1/recruitment/candidates/{id}/interviews
 *
 * The client normalises transport casing only. Money stays an integer count
 * of minor units until a component formats it, and absent server values stay
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
  requisitionId: number | null
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
}

export type Interview = {
  id: number
  candidateId: number
  requisitionId: number
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
  acceptedAt: string | null
}

export type ConversionInput = {
  orgUnitId: number
  employeeNo: string
  hireDate: string | null
}

export type CandidateConversion = {
  conversionId: number
  candidateId: number
  employmentId: number
  personId: number
  offerId: number
  orgUnitId: number
  employeeNo: string
  status: string
  convertedAt: string | null
  businessReplayed: boolean
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

export function requisitionStatusLabel(value: string): string {
  return REQUISITION_STATUS_LABELS[value as RequisitionStatus] ?? '未知状态'
}

export function candidateStatusLabel(value: string): string {
  return CANDIDATE_STATUS_LABELS[value] ?? '未知状态'
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
    requisitionId: nullableInteger(row.requisition_id),
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
): Promise<{ candidateId: number; status: string; previousStatus: string; replayed: boolean }> {
  const raw = await resourcePatch<unknown>(
    `/v1/recruitment/candidates/${candidateId}/stage`,
    { status, ...(reason === null || reason.trim() === '' ? {} : { reason: reason.trim() }) },
    { idempotencyKey: nextIdempotencyKey(`candidate-stage-${candidateId}`) },
  )
  const row = isRecord(raw) ? raw : contractError('candidate_stage')
  return {
    candidateId: integer(row.candidate_id, 'candidate_id'),
    status: text(row.status, 'status'),
    previousStatus: text(row.previous_status, 'previous_status'),
    replayed: row.replayed === true,
  }
}

export function normaliseInterview(raw: unknown): Interview {
  const row = isRecord(raw) ? raw : contractError('interview')

  return {
    id: integer(row.interview_id, 'interview_id'),
    candidateId: integer(row.candidate_id, 'candidate_id'),
    requisitionId: integer(row.requisition_id, 'requisition_id'),
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
    conversionId: integer(row.conversion_id, 'conversion_id'),
    candidateId: integer(row.candidate_id, 'candidate_id'),
    employmentId: integer(row.employment_id, 'employment_id'),
    personId: integer(row.person_id, 'person_id'),
    offerId: integer(row.offer_id, 'offer_id'),
    orgUnitId: integer(row.org_unit_id, 'org_unit_id'),
    employeeNo: text(row.employee_no, 'employee_no'),
    status: text(row.status, 'status'),
    convertedAt: nullableText(row.converted_at),
    businessReplayed: row.business_replayed === true,
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
