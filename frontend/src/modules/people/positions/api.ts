import { resourceGet, resourcePatch, resourcePost } from '@/api/resourceClient'
import { nextIdempotencyKey } from '@/modules/people/peopleRead'

/**
 * Position workspace API boundary.
 *
 * `GET /v1/positions` is the single catalogue query. Filtering, sorting and
 * pagination are server-side; the page never re-filters a returned page.
 */

export type PositionStatus = 'ACTIVE' | 'DISABLED' | 'UNKNOWN'

export type PositionSort = 'code' | 'code_desc' | 'title' | 'level'

export type Position = {
  id: number
  org_unit_id: number | null
  code: string
  title: string
  grade: string | null
  headcount_limit: number | null
  occupancy: number | null
  vacancies: number | null
  status: PositionStatus
  valid_from: string
  valid_to: string
}

export type PositionPage = {
  items: Position[]
  total: number
  page: number
  page_size: number
  partial: boolean
  partial_reason: string | null
}

export type PositionQuery = {
  asOf: string
  q?: string
  orgUnitId?: number | null
  status?: 'ACTIVE' | 'DISABLED' | null
  page?: number
  pageSize?: number
  sort?: PositionSort
}

export type OrgUnitOption = {
  id: number
  code: string
  name: string
}

export type PositionGrade = {
  id: number
  code: string
  name: string
  level: number
  active: boolean
}

export type PositionGradeInput = {
  code: string
  name: string
  level: number
  active: boolean
}

export type PositionStaff = {
  assignment_id: number
  employment_id: number
  employee_no: string
  person_name: string
  org_unit_id: number | null
  position_id: number | null
  position_code: string
  position_title: string
  assignment_type: string
  is_primary: boolean
  valid_from: string
  valid_to: string
}

export type PositionStaffPage = {
  items: PositionStaff[]
  total: number
  partial: boolean
  partial_reason: string | null
}

export type PositionRequirementVersion = {
  id: number
  position_id: number
  version: number
  requirements: Record<string, unknown>
  effective_from: string
  recorded_at: string | null
}

export type PositionRequirementPage = {
  items: PositionRequirementVersion[]
  total: number
  partial: boolean
  partial_reason: string | null
}

export type PositionCreateInput = {
  org_unit_id: number | null
  code: string
  title: string
  grade: string | null
  headcount_limit: number | null
  valid_from: string | null
  valid_to: string | null
}

export type PositionUpdateInput = {
  title: string
  grade: string | null
  headcount_limit: number | null
  status: 'ACTIVE' | 'DISABLED'
}

export type PositionStatusInput = {
  status: 'ACTIVE' | 'DISABLED'
}

export type RequirementPayload = {
  requirements: Record<string, string>
  effectiveFrom: string
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

function integer(value: unknown, fallback: number | null = null): number | null {
  if (typeof value === 'number' && Number.isSafeInteger(value)) return value
  if (typeof value === 'string' && /^\d+$/.test(value)) return Number(value)
  return fallback
}

function boolean(value: unknown): boolean {
  return value === true || value === 1 || value === '1'
}

function listItems(value: unknown): UnknownRecord[] {
  if (Array.isArray(value)) return value.filter(isRecord)
  if (isRecord(value) && Array.isArray(value.items)) return value.items.filter(isRecord)
  return []
}

function normalizeStatus(value: unknown): PositionStatus {
  if (value === 'ACTIVE') return 'ACTIVE'
  if (value === 'DISABLED') return 'DISABLED'
  return 'UNKNOWN'
}

function normalizePosition(row: UnknownRecord): Position | null {
  const id = integer(row.id)
  if (id === null || id <= 0) return null

  return {
    id,
    org_unit_id: integer(row.org_unit_id),
    code: text(row.code),
    title: text(row.title),
    grade: nullableText(row.grade),
    headcount_limit: integer(row.headcount_limit),
    occupancy: integer(row.occupancy),
    vacancies: integer(row.vacancies),
    status: normalizeStatus(row.status),
    valid_from: text(row.valid_from),
    valid_to: text(row.valid_to),
  }
}

function normalizeOrgUnit(row: UnknownRecord): OrgUnitOption | null {
  const id = integer(row.id)
  if (id === null || id <= 0) return null
  return {
    id,
    code: text(row.code),
    name: text(row.name, `组织 #${id}`),
  }
}

function normalizeGrade(row: UnknownRecord): PositionGrade | null {
  const id = integer(row.id)
  const code = text(row.code)
  if (id === null || id <= 0 || code === '') return null
  return {
    id,
    code,
    name: text(row.name, code),
    level: integer(row.level, 0) ?? 0,
    active: boolean(row.active),
  }
}

function normalizeStaff(row: UnknownRecord): PositionStaff | null {
  const assignmentId = integer(row.assignment_id)
  const employmentId = integer(row.employment_id)
  if (assignmentId === null || employmentId === null) return null

  return {
    assignment_id: assignmentId,
    employment_id: employmentId,
    employee_no: text(row.employee_no),
    person_name: text(row.person_name),
    org_unit_id: integer(row.org_unit_id),
    position_id: integer(row.position_id),
    position_code: text(row.position_code),
    position_title: text(row.position_title),
    assignment_type: text(row.assignment_type, 'PRIMARY'),
    is_primary: boolean(row.is_primary),
    valid_from: text(row.valid_from),
    valid_to: text(row.valid_to),
  }
}

function normalizeRequirement(row: UnknownRecord): PositionRequirementVersion | null {
  const id = integer(row.id)
  const positionId = integer(row.position_id)
  const version = integer(row.version)
  if (id === null || positionId === null || version === null) return null

  return {
    id,
    position_id: positionId,
    version,
    requirements: isRecord(row.requirements_json) ? { ...row.requirements_json } : {},
    effective_from: text(row.effective_from),
    recorded_at: nullableText(row.recorded_at),
  }
}

function pageEnvelope(data: unknown, items: Position[]): PositionPage {
  return {
    items,
    total: isRecord(data) ? integer(data.total, items.length) ?? items.length : items.length,
    page: isRecord(data) ? integer(data.page, 1) ?? 1 : 1,
    page_size: isRecord(data) ? integer(data.page_size, Math.max(1, items.length)) ?? Math.max(1, items.length) : Math.max(1, items.length),
    partial: isRecord(data) && data.partial === true,
    partial_reason:
      isRecord(data) && typeof data.partial_reason === 'string' ? data.partial_reason : null,
  }
}

export const POSITION_STATUS_LABELS: Readonly<Record<string, string>> = {
  ACTIVE: '启用',
  DISABLED: '停用',
}

export const POSITION_ASSIGNMENT_TYPE_LABELS: Readonly<Record<string, string>> = {
  PRIMARY: '主任职',
  SECONDMENT: '借调',
  MATRIX: '矩阵任职',
  PROJECT: '项目任职',
}

export function positionStatusLabel(value: string): string {
  return POSITION_STATUS_LABELS[value] ?? '未知状态'
}

export function assignmentTypeLabel(value: string): string {
  return POSITION_ASSIGNMENT_TYPE_LABELS[value] ?? value
}

/**
 * Grade codes are catalogue identifiers, not localisable enum values. Render
 * the server-maintained Chinese grade name when the catalogue is readable;
 * otherwise keep the code visible instead of inventing a name.
 */
export function positionGradeLabel(
  grade: string | null,
  grades: readonly PositionGrade[],
): string {
  if (grade === null || grade.trim() === '') return '未设置'
  const match = grades.find((candidate) => candidate.code === grade)
  return match === undefined ? grade : `${match.name}（${grade}）`
}

export function orgUnitName(
  orgUnitId: number | null,
  orgUnits: readonly OrgUnitOption[],
): string {
  if (orgUnitId === null) return '未关联组织'
  return orgUnits.find((unit) => unit.id === orgUnitId)?.name ?? `组织 #${orgUnitId}`
}

/** Server-side catalogue query. No status/org/page filtering happens locally. */
export async function listPositions(query: PositionQuery): Promise<PositionPage> {
  const q = query.q?.trim() ?? ''
  const data: unknown = await resourceGet('/v1/positions', {
    as_of: query.asOf,
    ...(q === '' ? {} : { q }),
    ...(query.orgUnitId === null || query.orgUnitId === undefined
      ? {}
      : { org_unit_id: query.orgUnitId }),
    ...(query.status === null || query.status === undefined ? {} : { status: query.status }),
    page: query.page ?? 1,
    page_size: query.pageSize ?? 20,
    sort: query.sort ?? 'code',
  })
  const items = listItems(data)
    .map(normalizePosition)
    .filter((row): row is Position => row !== null)

  return pageEnvelope(data, items)
}

export async function getPosition(positionId: number, asOf: string): Promise<Position | null> {
  const data: unknown = await resourceGet(`/v1/positions/${positionId}`, { as_of: asOf })
  return isRecord(data) ? normalizePosition(data) : null
}

export async function listOrgUnits(asOf: string): Promise<OrgUnitOption[]> {
  const data: unknown = await resourceGet('/v1/departments', { as_of: asOf })
  return listItems(data)
    .map(normalizeOrgUnit)
    .filter((row): row is OrgUnitOption => row !== null)
    .sort((left, right) => left.code.localeCompare(right.code))
}

export async function listPositionGrades(
  asOf: string,
  options: { active?: boolean; pageSize?: number } = {},
): Promise<PositionGrade[]> {
  const data: unknown = await resourceGet('/v1/positions/grades', {
    as_of: asOf,
    ...(options.active === undefined ? {} : { active: options.active }),
    page: 1,
    page_size: options.pageSize ?? 100,
  })
  return listItems(data)
    .map(normalizeGrade)
    .filter((row): row is PositionGrade => row !== null)
    .sort((left, right) => left.level - right.level || left.code.localeCompare(right.code))
}

export async function upsertPositionGrade(input: PositionGradeInput): Promise<PositionGrade | null> {
  const data: unknown = await resourcePost(
    '/v1/positions/grades',
    {
      code: input.code.trim(),
      name: input.name.trim(),
      level: input.level,
      active: input.active,
    },
    { idempotencyKey: nextIdempotencyKey('position-grade') },
  )
  return isRecord(data) ? normalizeGrade(data) : null
}

export async function createPosition(input: PositionCreateInput): Promise<Position | null> {
  const data: unknown = await resourcePost(
    '/v1/positions',
    {
      org_unit_id: input.org_unit_id,
      code: input.code,
      title: input.title,
      grade: input.grade,
      headcount_limit: input.headcount_limit,
      valid_from: input.valid_from,
      valid_to: input.valid_to,
    },
    { idempotencyKey: nextIdempotencyKey('position-create') },
  )
  return isRecord(data) ? normalizePosition(data) : null
}

export async function updatePosition(
  positionId: number,
  input: PositionUpdateInput,
): Promise<Position | null> {
  const data: unknown = await resourcePatch(
    `/v1/positions/${positionId}`,
    {
      title: input.title,
      grade: input.grade,
      headcount_limit: input.headcount_limit,
      status: input.status,
    },
    { idempotencyKey: nextIdempotencyKey('position-update') },
  )
  return isRecord(data) ? normalizePosition(data) : null
}

/** Dedicated disable/enable action: one status field, one audited command. */
export async function updatePositionStatus(
  positionId: number,
  status: 'ACTIVE' | 'DISABLED',
): Promise<Position | null> {
  const data: unknown = await resourcePatch(
    `/v1/positions/${positionId}`,
    { status },
    { idempotencyKey: nextIdempotencyKey(`position-${status.toLowerCase()}`) },
  )
  return isRecord(data) ? normalizePosition(data) : null
}

export async function listPositionStaff(
  positionId: number,
  asOf: string,
): Promise<PositionStaffPage> {
  const data: unknown = await resourceGet(`/v1/positions/${positionId}/staff`, { as_of: asOf })
  const items = listItems(data)
    .map(normalizeStaff)
    .filter((row): row is PositionStaff => row !== null)

  return {
    items,
    total: isRecord(data) ? integer(data.total, items.length) ?? items.length : items.length,
    partial: isRecord(data) && data.partial === true,
    partial_reason:
      isRecord(data) && typeof data.partial_reason === 'string' ? data.partial_reason : null,
  }
}

export async function listPositionRequirements(
  positionId: number,
  asOf: string,
): Promise<PositionRequirementPage> {
  const data: unknown = await resourceGet(`/v1/positions/${positionId}/requirements`, {
    as_of: asOf,
    page: 1,
    page_size: 100,
  })
  const items = listItems(data)
    .map(normalizeRequirement)
    .filter((row): row is PositionRequirementVersion => row !== null)
    .sort((left, right) => right.version - left.version || right.id - left.id)

  return {
    items,
    total: isRecord(data) ? integer(data.total, items.length) ?? items.length : items.length,
    partial: isRecord(data) && data.partial === true,
    partial_reason:
      isRecord(data) && typeof data.partial_reason === 'string' ? data.partial_reason : null,
  }
}

/**
 * Qualification history is append-only. Saving submits a new version
 * containing only the changed requirement rows. The backend idempotency ledger
 * owns replay semantics for the same Idempotency-Key.
 */
export async function versionPositionRequirements(
  positionId: number,
  payload: RequirementPayload,
  idempotencyKey?: string,
): Promise<PositionRequirementVersion | null> {
  const data: unknown = await resourcePost(
    `/v1/positions/${positionId}/requirements`,
    {
      requirements: payload.requirements,
      effective_from: payload.effectiveFrom,
    },
    { idempotencyKey: idempotencyKey ?? nextIdempotencyKey('position-requirements') },
  )
  return isRecord(data) ? normalizeRequirement(data) : null
}

export const REQUIRED_REQUIREMENT_FIELDS: ReadonlyArray<{ key: string; label: string }> = [
  { key: 'skills', label: '技能要求' },
  { key: 'qualifications', label: '资格要求' },
]

export function requirementFieldLabel(key: string): string {
  return REQUIRED_REQUIREMENT_FIELDS.find((field) => field.key === key)?.label ?? key
}

export function requirementValueText(value: unknown): string {
  if (value === null || value === undefined) return ''
  if (typeof value === 'string') return value
  if (typeof value === 'number' || typeof value === 'boolean') return String(value)
  try {
    return JSON.stringify(value)
  } catch {
    return ''
  }
}

/** Catalogue identity and dates are immutable through the update command. */
export const POSITION_EDIT_READONLY_NOTE =
  '岗位编码、部门与生效日期在创建后不可修改；如需调整这些目录事实，请新建岗位并停用旧岗位。'

export const POSITION_DUPLICATE_REASON = 'POSITION_CODE_DUPLICATE'

export function isForbidden(error: unknown): boolean {
  return (
    typeof error === 'object' &&
    error !== null &&
    'status' in error &&
    (error as { status?: unknown }).status === 403
  )
}
