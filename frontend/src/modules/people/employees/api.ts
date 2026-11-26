import { resourceDelete, resourceGet, resourcePost } from '@/api/resourceClient'
import { nextIdempotencyKey } from '@/modules/people/peopleRead'

/**
 * Employee directory API boundary.
 *
 * The server owns scoping, filtering, pagination and the canonical ordering.
 * These functions only normalise the wire projection for the workspace; they
 * never synthesise rows or widen a caller's scope.
 */
export type EmployeeRow = {
  employment_id: number
  person_id: number | null
  employee_no: string
  person_name: string
  status: string
  employment_type: string
  hire_date: string | null
  valid_from: string | null
  archived_at: string | null
  org_unit_id: number | null
  position_id: number | null
  department_name: string | null
  position_name: string | null
}

export type EmployeeListQuery = {
  as_of: string
  org_unit_id?: number
  q?: string
  page: number
  page_size: number
  include_archived: boolean
}

export type EmployeeListResult = {
  items: EmployeeRow[]
  page: number
  page_size: number
  total: number
  partial: boolean
  partial_reason: string | null
}

export type OrgUnitOption = {
  id: number
  name: string
  code: string
}

export type PositionOption = {
  id: number
  title: string
  code: string
}

export type OnboardPayload = {
  person: {
    given_name: string
    family_name: string
    email?: string | null
    mobile?: string | null
    national_id?: string | null
    id_type?: 'CN_ID' | 'PASSPORT' | 'OTHER' | null
    birth_date?: string | null
  }
  employment: {
    employee_no: string
    hire_date: string
    employment_type: string | null
  }
  assignment: {
    org_unit_id: number
    position_id: number | null
    valid_from: string | null
  }
  terms: {
    salary_minor: number
    currency: string
    work_location: string | null
    work_schedule: string | null
    effective_from: string | null
  }
}

export type OnboardedEmployee = {
  person_id: number
  employment_id: number
  employee_no: string
  assignment_id: number
  terms_id: number
  replayed: boolean
}

type RecordValue = Record<string, unknown>

function isRecord(value: unknown): value is RecordValue {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function numberOrNull(value: unknown): number | null {
  if (typeof value === 'number' && Number.isFinite(value)) return value
  if (typeof value === 'string' && /^\d+$/.test(value)) return Number(value)
  return null
}

function stringOrNull(value: unknown): string | null {
  return typeof value === 'string' && value !== '' ? value : null
}

function normaliseEmployee(row: RecordValue): EmployeeRow {
  const assignment = isRecord(row.current_assignment) ? row.current_assignment : {}
  const employmentId = numberOrNull(row.employment_id)
  if (employmentId === null || employmentId <= 0) {
    throw new TypeError('员工列表接口返回了缺少 employment_id 的记录。')
  }

  return {
    employment_id: employmentId,
    person_id: numberOrNull(row.person_id),
    employee_no: stringOrNull(row.employee_no) ?? `员工 #${employmentId}`,
    person_name: stringOrNull(row.person_name) ?? '未命名员工',
    status: stringOrNull(row.status) ?? '',
    employment_type: stringOrNull(row.employment_type) ?? '',
    hire_date: stringOrNull(row.hire_date),
    valid_from: stringOrNull(row.valid_from),
    archived_at: stringOrNull(row.archived_at),
    org_unit_id: numberOrNull(assignment.org_unit_id),
    position_id: numberOrNull(assignment.position_id),
    department_name:
      stringOrNull(row.department_name) ??
      stringOrNull(row.org_unit_name) ??
      stringOrNull(assignment.org_unit_name),
    position_name:
      stringOrNull(row.position_name) ??
      stringOrNull(row.position_title) ??
      stringOrNull(assignment.position_name),
  }
}

function readItems(value: unknown): RecordValue[] {
  if (Array.isArray(value)) return value.filter(isRecord)
  if (isRecord(value) && Array.isArray(value.items)) return value.items.filter(isRecord)
  return []
}

export async function listEmployees(query: EmployeeListQuery): Promise<EmployeeListResult> {
  const raw = await resourceGet<unknown>('/v1/employees', {
    as_of: query.as_of,
    org_unit_id: query.org_unit_id,
    q: query.q?.trim() === '' ? undefined : query.q?.trim(),
    page: query.page,
    page_size: query.page_size,
    include_archived: query.include_archived,
  })

  if (!isRecord(raw)) {
    return { items: [], page: query.page, page_size: query.page_size, total: 0, partial: false, partial_reason: null }
  }

  const items = Array.isArray(raw.items) ? raw.items.filter(isRecord).map(normaliseEmployee) : []
  const total = typeof raw.total === 'number' && Number.isFinite(raw.total) ? raw.total : items.length

  return {
    items,
    page: numberOrNull(raw.page) ?? query.page,
    page_size: numberOrNull(raw.page_size) ?? query.page_size,
    total,
    partial: raw.partial === true,
    partial_reason: stringOrNull(raw.partial_reason),
  }
}

export async function listOrgUnits(asOf: string): Promise<OrgUnitOption[]> {
  const raw = await resourceGet<unknown>('/v1/departments', { as_of: asOf })
  return readItems(raw)
    .map((row) => {
      const id = numberOrNull(row.id)
      if (id === null) return null
      return {
        id,
        name: stringOrNull(row.name) ?? `部门 #${id}`,
        code: stringOrNull(row.code) ?? '',
      }
    })
    .filter((row): row is OrgUnitOption => row !== null)
}

export async function listPositions(asOf: string): Promise<PositionOption[]> {
  const raw = await resourceGet<unknown>('/v1/positions', { as_of: asOf })
  return readItems(raw)
    .map((row) => {
      const id = numberOrNull(row.id)
      if (id === null) return null
      return {
        id,
        title: stringOrNull(row.title) ?? `岗位 #${id}`,
        code: stringOrNull(row.code) ?? '',
      }
    })
    .filter((row): row is PositionOption => row !== null)
}

export type ArchiveResult = {
  employment_id: number
  archived_at: string | null
  archive_reason?: string | null
}

export async function archiveEmployee(
  employmentId: number,
  reason: string,
): Promise<ArchiveResult> {
  // The DELETE command requires an audit reason. The shared delete helper
  // carries no JSON body, so the reason travels as a validated query input on
  // the same operation.
  return resourceDelete<ArchiveResult>(
    `/v1/employees/${employmentId}?reason=${encodeURIComponent(reason)}`,
    { idempotencyKey: nextIdempotencyKey('employee-archive') },
  )
}

export async function restoreEmployee(employmentId: number): Promise<ArchiveResult> {
  return resourcePost<ArchiveResult>(
    `/v1/employees/${employmentId}/restore`,
    {},
    { idempotencyKey: nextIdempotencyKey('employee-restore') },
  )
}

export async function onboardEmployee(
  payload: OnboardPayload,
  idempotencyKey: string,
): Promise<OnboardedEmployee> {
  const raw = await resourcePost<unknown>('/v1/employees/onboard', payload, { idempotencyKey })
  if (!isRecord(raw)) throw new TypeError('员工入职接口未返回结果。')

  const employmentId = numberOrNull(raw.employment_id)
  if (employmentId === null) throw new TypeError('员工入职接口未返回员工关系编号。')

  return {
    person_id: numberOrNull(raw.person_id) ?? 0,
    employment_id: employmentId,
    employee_no: stringOrNull(raw.employee_no) ?? '',
    assignment_id: numberOrNull(raw.assignment_id) ?? 0,
    terms_id: numberOrNull(raw.terms_id) ?? 0,
    replayed: raw.replayed === true,
  }
}

