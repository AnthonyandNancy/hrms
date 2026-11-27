import { ApiError } from '@/api/errors'
import { resourceGet, resourcePost } from '@/api/resourceClient'
import { nextIdempotencyKey } from '@/modules/people/peopleRead'
import { todayBusinessDate } from '@/utils/format'

/**
 * Employment-contract workspace API boundary.
 *
 * Live routes (verified with `artisan route:list --path=contracts`):
 *   GET  /v1/employments/contracts
 *   GET  /v1/employments/contracts/expiring
 *   GET  /v1/employments/{employmentId}/contracts
 *   POST /v1/employments/{employmentId}/contracts
 *   GET  /v1/employments/{employmentId}/contracts/{id}
 *   GET  /v1/employment-contracts/{id}/amendments
 *   POST /v1/employment-contracts/{id}/amendments
 *   POST /v1/employment-contracts/{id}/terminate
 *
 * There is no `GET/PATCH /v1/contracts/{id}` route. The workspace therefore
 * reads the ledger projection and appends edits through the lifecycle command.
 */

export type ContractType = 'FIXED_TERM' | 'OPEN_ENDED' | 'INTERNSHIP' | 'CONSULTANT'
export type ContractDisplayStatus =
  | 'DRAFT'
  | 'EFFECTIVE'
  | 'EXPIRING'
  | 'EXPIRED'
  | 'TERMINATED'
  | 'RENEWED'
  | 'ARCHIVED'
  | 'UNKNOWN'

export type Contract = {
  id: number
  employment_id: number
  employee_no: string | null
  employee_name: string | null
  contract_no: string
  contract_type: string
  start_date: string
  end_date: string | null
  signed_on: string | null
  status: string
  version: number
  previous_contract_id: number | null
  renewed_by_id: number | null
  days_to_expiry: number | null
  terms_summary: string | null
  display_status: ContractDisplayStatus
  display_status_label: string
  renewal_count: number
  probation_end_date: string | null
  work_location: string | null
  signer: string | null
}

export type ContractPage = {
  items: Contract[]
  total: number
  page: number
  page_size: number
  partial: boolean
  partial_reason: string | null
  as_of: string
}

export type EmployeeOption = {
  employment_id: number
  employee_no: string
  person_name: string
}

export type ContractVersion = {
  id: number
  contract_id: number
  employment_id: number
  change_type: string
  change_type_label: string
  terms_version: number
  document_version: number
  effective_from: string
  effective_to: string
  reason: string | null
  snapshot: Record<string, unknown>
  recorded_at: string | null
  signer: string | null
  status_label: string
}

export type ContractVersionPage = {
  items: ContractVersion[]
  total: number
  partial: boolean
  partial_reason: string | null
}

export type ContractAttachment = {
  id: number
  employment_id: number
  category: string
  version: number
  original_name: string
  mime: string
  size_bytes: number | null
  signed_at: string | null
  expires_at: string | null
  retention_class: string
  legal_hold: boolean
}

export type ContractAttachmentPage = {
  items: ContractAttachment[]
  total: number
  partial: boolean
  partial_reason: string | null
}

export type ContractFormInput = {
  employment_id: number
  contract_no: string
  contract_type: ContractType
  start_date: string
  end_date: string | null
  probation_end_date: string | null
  work_location: string | null
  signed_on: string | null
  terms_summary: string | null
  status: 'DRAFT' | 'ACTIVE'
  reason: string | null
  renewal_of_contract_id: number | null
}

export type ContractAmendmentInput = {
  contract_type?: ContractType
  end_date?: string | null
  signed_on?: string | null
  terms_summary?: string | null
}

export const CONTRACT_TYPES: ReadonlyArray<{ value: ContractType; label: string }> = [
  { value: 'FIXED_TERM', label: '固定期限' },
  { value: 'OPEN_ENDED', label: '无固定期限' },
  { value: 'INTERNSHIP', label: '实习' },
  { value: 'CONSULTANT', label: '劳务' },
]

export const CONTRACT_STATUS_LABELS: Readonly<Record<ContractDisplayStatus, string>> = {
  DRAFT: '草稿',
  EFFECTIVE: '生效中',
  EXPIRING: '即将到期',
  EXPIRED: '已到期',
  TERMINATED: '已终止',
  RENEWED: '已续签',
  ARCHIVED: '已归档',
  UNKNOWN: '未知状态',
}

export const CONTRACT_STATUS_TONES: Readonly<Record<ContractDisplayStatus, string>> = {
  DRAFT: 'muted',
  EFFECTIVE: 'success',
  EXPIRING: 'warning',
  EXPIRED: 'danger',
  TERMINATED: 'muted',
  RENEWED: 'info',
  ARCHIVED: 'neutral',
  UNKNOWN: 'neutral',
}

const CHANGE_TYPE_LABELS: Readonly<Record<string, string>> = {
  CREATION: '签订',
  RENEWAL: '续签',
  AMENDMENT: '变更',
  TERMINATION: '终止',
}

type UnknownRecord = Record<string, unknown>

function isRecord(value: unknown): value is UnknownRecord {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function text(value: unknown, fallback = ''): string {
  return typeof value === 'string' ? value : fallback
}

function nullableText(value: unknown): string | null {
  return typeof value === 'string' && value !== '' ? value : null
}

function integer(value: unknown): number | null {
  if (typeof value === 'number' && Number.isSafeInteger(value)) return value
  if (typeof value === 'string' && /^\d+$/.test(value)) return Number(value)
  return null
}

function sortedRows(value: unknown): UnknownRecord[] {
  if (Array.isArray(value)) return value.filter(isRecord)
  if (isRecord(value) && Array.isArray(value.items)) return value.items.filter(isRecord)
  return []
}

function meta(value: unknown): { total: number; partial: boolean; partialReason: string | null; asOf: string | null } {
  if (!isRecord(value)) {
    return { total: 0, partial: false, partialReason: null, asOf: null }
  }
  return {
    total: integer(value.total) ?? sortedRows(value).length,
    partial: value.partial === true,
    partialReason: nullableText(value.partial_reason),
    asOf: nullableText(value.as_of),
  }
}

export function contractTypeLabel(value: string): string {
  return CONTRACT_TYPES.find((type) => type.value === value)?.label ?? '未知类型'
}

export function changeTypeLabel(value: string): string {
  return CHANGE_TYPE_LABELS[value] ?? '未知变更'
}

export function contractStatusLabel(value: ContractDisplayStatus): string {
  return CONTRACT_STATUS_LABELS[value]
}

/**
 * ACTIVE is the server lifecycle state; "expiring"/"expired" are the
 * presentation states required by the workspace. The server's days_to_expiry
 * is preferred because it is calculated from the requested business date.
 */
export function contractDisplayStatus(
  row: Pick<Contract, 'status' | 'end_date' | 'days_to_expiry'>,
  asOf: string,
): ContractDisplayStatus {
  const serverStatus = row.status.toUpperCase()
  if (serverStatus === 'DRAFT') return 'DRAFT'
  if (serverStatus === 'TERMINATED') return 'TERMINATED'
  if (serverStatus === 'RENEWED') return 'RENEWED'
  if (serverStatus === 'ARCHIVED') return 'ARCHIVED'

  if (serverStatus === 'ACTIVE' || serverStatus === 'EXPIRING') {
    const days = row.days_to_expiry ?? daysBetween(asOf, row.end_date)
    if (days !== null && days < 0) return 'EXPIRED'
    if (row.end_date === null) return 'EFFECTIVE'
    if (serverStatus === 'EXPIRING') return 'EXPIRING'
    if (days !== null && days <= 60) return 'EXPIRING'
    return 'EFFECTIVE'
  }

  return 'UNKNOWN'
}

function daysBetween(asOf: string, endDate: string | null): number | null {
  if (endDate === null || !/^\d{4}-\d{2}-\d{2}$/.test(asOf) || !/^\d{4}-\d{2}-\d{2}$/.test(endDate)) {
    return null
  }
  const start = Date.parse(`${asOf}T00:00:00Z`)
  const end = Date.parse(`${endDate}T00:00:00Z`)
  if (Number.isNaN(start) || Number.isNaN(end)) return null
  return Math.round((end - start) / 86_400_000)
}

function snapshotText(snapshot: UnknownRecord, keys: readonly string[]): string | null {
  for (const key of keys) {
    const value = snapshot[key]
    if (typeof value === 'string' && value !== '') return value
    if (isRecord(value) && typeof value.display === 'string' && value.display !== '') {
      return value.display
    }
  }
  return null
}

export function buildContractRenewalCounts(rows: readonly Contract[]): Map<number, number> {
  const byId = new Map(rows.map((row) => [row.id, row]))
  const counts = new Map<number, number>()

  for (const row of rows) {
    let cursor = row.previous_contract_id
    const visited = new Set<number>()
    let count = 0
    while (cursor !== null && !visited.has(cursor)) {
      visited.add(cursor)
      const previous = byId.get(cursor)
      count += 1
      cursor = previous?.previous_contract_id ?? null
    }
    counts.set(row.id, Math.max(count, Math.max(0, row.version - 1)))
  }

  return counts
}

function normalizeContract(row: UnknownRecord, asOf: string, renewalCount: number): Contract | null {
  const id = integer(row.id)
  const employmentId = integer(row.employment_id)
  if (id === null || id <= 0 || employmentId === null || employmentId <= 0) return null

  const status = text(row.status, 'UNKNOWN')
  const endDate = nullableText(row.end_date)
  const days = integer(row.days_to_expiry)
  const base = {
    id,
    employment_id: employmentId,
    employee_no: nullableText(row.employee_no),
    employee_name: nullableText(row.employee_name),
    contract_no: text(row.contract_no),
    contract_type: text(row.contract_type),
    start_date: text(row.start_date),
    end_date: endDate,
    signed_on: nullableText(row.signed_on),
    status,
    version: integer(row.version) ?? 1,
    previous_contract_id: integer(row.previous_contract_id),
    renewed_by_id: integer(row.renewed_by_id),
    days_to_expiry: days,
    terms_summary: nullableText(row.terms_summary),
    probation_end_date: nullableText(row.probation_end_date),
    work_location: nullableText(row.work_location),
    signer: nullableText(row.signer),
    display_status: contractDisplayStatus({ status, end_date: endDate, days_to_expiry: days }, asOf),
  } satisfies Omit<Contract, 'display_status_label' | 'renewal_count'>

  return {
    ...base,
    display_status_label: CONTRACT_STATUS_LABELS[base.display_status],
    renewal_count: renewalCount,
  }
}

export async function listContracts(
  asOf = todayBusinessDate(),
  page = 1,
  pageSize = 100,
): Promise<ContractPage> {
  const raw = await resourceGet<unknown>('/v1/employments/contracts', {
    as_of: asOf,
    page,
    page_size: pageSize,
  })
  const rows = sortedRows(raw)
  const pageMeta = meta(raw)
  const normalized = rows
    .map((row) => normalizeContract(row, pageMeta.asOf ?? asOf, 0))
    .filter((row): row is Contract => row !== null)
  const counts = buildContractRenewalCounts(normalized)

  const items = normalized.map((row) => ({
    ...row,
    renewal_count: counts.get(row.id) ?? 0,
  }))

  return {
    items,
    total: pageMeta.total,
    page,
    page_size: pageSize,
    partial: pageMeta.partial,
    partial_reason: pageMeta.partialReason,
    as_of: pageMeta.asOf ?? asOf,
  }
}

export async function findContract(id: number, asOf = todayBusinessDate()): Promise<Contract | null> {
  const page = await listContracts(asOf, 1, 100)
  return page.items.find((contract) => contract.id === id) ?? null
}

/**
 * Read one contract through the real employment-scoped route. The workspace
 * carries the employment id in the detail URL because there is no global
 * `/v1/contracts/{id}` read; the ledger fallback remains for old deep links.
 */
export async function getContract(employmentId: number, id: number, asOf = todayBusinessDate()): Promise<Contract | null> {
  const raw = await resourceGet<unknown>(`/v1/employments/${employmentId}/contracts/${id}`)
  if (!isRecord(raw)) return null
  const row = normalizeContract(raw, asOf, 0)
  return row
}

export async function listEmployeeOptions(asOf = todayBusinessDate()): Promise<EmployeeOption[]> {
  // The employee projection is governed by people.employee.read, which a
  // contract-only reader may not hold. A failed lookup must not block the
  // ledger, so the page degrades to manual employment-id entry.
  const raw = await resourceGet<unknown>('/v1/employees', {
    as_of: asOf,
    page: 1,
    page_size: 100,
  })

  return sortedRows(raw)
    .map((row): EmployeeOption | null => {
      const employmentId = integer(row.employment_id)
      if (employmentId === null || employmentId <= 0) return null
      return {
        employment_id: employmentId,
        employee_no: text(row.employee_no, `员工 #${employmentId}`),
        person_name: text(row.person_name, `员工 #${employmentId}`),
      }
    })
    .filter((row): row is EmployeeOption => row !== null)
}

export async function createContract(employmentId: number, input: ContractFormInput): Promise<void> {
  const body: Record<string, unknown> = {
    contract_no: input.contract_no.trim(),
    contract_type: input.contract_type,
    start_date: input.start_date,
    end_date: input.end_date,
    probation_end_date: input.probation_end_date,
    work_location: input.work_location?.trim() || null,
    signed_on: input.signed_on,
    status: input.status,
    terms_summary: input.terms_summary?.trim() || null,
  }
  if (input.renewal_of_contract_id !== null) {
    body.renewal_of_contract_id = input.renewal_of_contract_id
    body.reason = input.reason?.trim() || null
  }

  await resourcePost(`/v1/employments/${employmentId}/contracts`, body, {
    idempotencyKey: nextIdempotencyKey(input.renewal_of_contract_id === null ? 'contract-create' : 'contract-renew'),
  })
}

export async function amendContract(
  contractId: number,
  effectiveDate: string,
  reason: string,
  changes: ContractAmendmentInput,
): Promise<void> {
  await resourcePost(
    `/v1/employment-contracts/${contractId}/amendments`,
    {
      effective_date: effectiveDate,
      reason: reason.trim(),
      changes: {
        ...(changes.contract_type === undefined ? {} : { contract_type: changes.contract_type }),
        ...(changes.end_date === undefined ? {} : { end_date: changes.end_date }),
        ...(changes.signed_on === undefined ? {} : { signed_on: changes.signed_on }),
        ...(changes.terms_summary === undefined ? {} : { terms_summary: changes.terms_summary }),
      },
    },
    { idempotencyKey: nextIdempotencyKey('contract-amend') },
  )
}

export async function terminateContract(
  contractId: number,
  effectiveDate: string,
  reason: string,
): Promise<void> {
  await resourcePost(
    `/v1/employment-contracts/${contractId}/terminate`,
    { effective_date: effectiveDate, reason: reason.trim() },
    { idempotencyKey: nextIdempotencyKey('contract-terminate') },
  )
}

export async function listContractVersions(contractId: number): Promise<ContractVersionPage> {
  const raw = await resourceGet<unknown>(`/v1/employment-contracts/${contractId}/amendments`, {
    page: 1,
    page_size: 100,
  })
  const rows = sortedRows(raw)
  const pageMeta = meta(raw)

  const items = rows
    .map((row): ContractVersion | null => {
      const id = integer(row.id)
      const rowContractId = integer(row.contract_id)
      const employmentId = integer(row.employment_id)
      if (id === null || rowContractId === null || employmentId === null) return null
      const snapshot = isRecord(row.snapshot) ? row.snapshot : {}
      const changeType = text(row.change_type, 'UNKNOWN')
      const snapshotStatus = nullableText(snapshot.status)
      return {
        id,
        contract_id: rowContractId,
        employment_id: employmentId,
        change_type: changeType,
        change_type_label: changeTypeLabel(changeType),
        terms_version: integer(row.terms_version) ?? 0,
        document_version: integer(row.document_version) ?? 0,
        effective_from: text(row.effective_from),
        effective_to: text(row.effective_to),
        reason: nullableText(row.reason),
        snapshot,
        recorded_at: nullableText(row.recorded_at),
        signer: snapshotText(snapshot, ['signer', 'signed_by_name', 'signed_by', 'recorded_by_name']),
        status_label: snapshotStatus !== null
        ? CONTRACT_STATUS_LABELS[statusFor(snapshotStatus)]
        : versionStatusLabel(changeType),
      }
    })
    .filter((row): row is ContractVersion => row !== null)
    .sort((left, right) =>
      left.effective_from.localeCompare(right.effective_from)
      || left.document_version - right.document_version
      || left.id - right.id,
    )

  return {
    items,
    total: pageMeta.total,
    partial: pageMeta.partial,
    partial_reason: pageMeta.partialReason,
  }
}

function versionStatusLabel(changeType: string): string {
  switch (changeType) {
    case 'CREATION':
      return '已签订'
    case 'RENEWAL':
      return '已续签'
    case 'AMENDMENT':
      return '已变更'
    case 'TERMINATION':
      return '已终止'
    default:
      return '已记录'
  }
}

function statusFor(value: string): ContractDisplayStatus {
  switch (value) {
    case 'DRAFT':
    case 'EFFECTIVE':
    case 'EXPIRING':
    case 'EXPIRED':
    case 'TERMINATED':
    case 'RENEWED':
    case 'ARCHIVED':
      return value
    case 'ACTIVE':
      return 'EFFECTIVE'
    default:
      return 'UNKNOWN'
  }
}

export async function listContractAttachments(employmentId: number): Promise<ContractAttachmentPage> {
  const raw = await resourceGet<unknown>(`/v1/employments/${employmentId}/document-files`, {
    page: 1,
    page_size: 100,
  })
  const rows = sortedRows(raw)
  const pageMeta = meta(raw)

  const items = rows
    .filter((row) => text(row.category) === 'CONTRACT')
    .map((row): ContractAttachment | null => {
      const id = integer(row.id)
      if (id === null) return null
      return {
        id,
        employment_id: integer(row.employment_id) ?? employmentId,
        category: text(row.category, 'CONTRACT'),
        version: integer(row.version) ?? 1,
        original_name: text(row.original_name, `合同附件 #${id}`),
        mime: text(row.mime, 'application/octet-stream'),
        size_bytes: integer(row.size_bytes),
        signed_at: nullableText(row.signed_at),
        expires_at: nullableText(row.expires_at),
        retention_class: text(row.retention_class),
        legal_hold: row.legal_hold === true,
      }
    })
    .filter((row): row is ContractAttachment => row !== null)

  return {
    items,
    total: pageMeta.total,
    partial: pageMeta.partial,
    partial_reason: pageMeta.partialReason,
  }
}

export async function signedDownloadUrl(documentId: number): Promise<string | null> {
  const raw = await resourceGet<unknown>(`/v1/documents/${documentId}/signed-download`)
  return isRecord(raw) ? nullableText(raw.url) : null
}

export function isForbidden(error: unknown): boolean {
  return error instanceof ApiError && (error.status === 403 || error.code === 'FORBIDDEN')
}

export function isNotFound(error: unknown): boolean {
  return error instanceof ApiError && (error.status === 404 || error.code === 'NOT_FOUND')
}
