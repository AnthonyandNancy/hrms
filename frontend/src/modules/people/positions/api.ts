import { resourceGet, resourcePatch, resourcePost } from '@/api/resourceClient'
import { nextIdempotencyKey } from '@/modules/people/peopleRead'

/**
 * Position workspace API boundary.
 *
 * The live contract is deliberately narrow:
 *   GET /v1/positions accepts only `as_of` and `q`.
 *   PATCH /v1/positions/{id} accepts only title, grade, headcount_limit and status.
 *
 * Filtering by status / organisation, paging and grade-label resolution are
 * therefore done over the rows the server actually returned. No sort, page or
 * page_size parameter is invented client-side.
 */

export type PositionStatus = 'ACTIVE' | 'DISABLED' | 'UNKNOWN'

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
  partial: boolean
  partial_reason: string | null
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
    active: row.active === true,
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

export const POSITION_STATUS_LABELS: Readonly<Record<string, string>> = {
  ACTIVE: '启用',
  DISABLED: '停用',
}

export function positionStatusLabel(value: string): string {
  return POSITION_STATUS_LABELS[value] ?? '未知状态'
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

/** GET /v1/positions supports as_of and q only. The endpoint returns all rows. */
export async function listPositions(asOf: string, q = ''): Promise<PositionPage> {
  const trimmed = q.trim()
  const data: unknown = await resourceGet('/v1/positions', {
    as_of: asOf,
    ...(trimmed === '' ? {} : { q: trimmed }),
  })
  const items = listItems(data)
    .map(normalizePosition)
    .filter((row): row is Position => row !== null)

  return {
    items,
    total: items.length,
    partial: isRecord(data) && data.partial === true,
    partial_reason:
      isRecord(data) && typeof data.partial_reason === 'string' ? data.partial_reason : null,
  }
}

export async function listOrgUnits(asOf: string): Promise<OrgUnitOption[]> {
  const data: unknown = await resourceGet('/v1/departments', { as_of: asOf })
  return listItems(data)
    .map(normalizeOrgUnit)
    .filter((row): row is OrgUnitOption => row !== null)
    .sort((left, right) => left.code.localeCompare(right.code))
}

export async function listPositionGrades(asOf: string): Promise<PositionGrade[]> {
  const data: unknown = await resourceGet('/v1/positions/grades', {
    as_of: asOf,
    active: true,
    page: 1,
    page_size: 100,
  })
  return listItems(data)
    .map(normalizeGrade)
    .filter((row): row is PositionGrade => row !== null)
    .sort((left, right) => left.level - right.level || left.code.localeCompare(right.code))
}

export async function createPosition(input: PositionCreateInput): Promise<void> {
  await resourcePost(
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
}

export async function updatePosition(
  positionId: number,
  input: PositionUpdateInput,
): Promise<void> {
  await resourcePatch(
    `/v1/positions/${positionId}`,
    {
      title: input.title,
      grade: input.grade,
      headcount_limit: input.headcount_limit,
      status: input.status,
    },
    { idempotencyKey: nextIdempotencyKey('position-update') },
  )
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
    total: items.length,
    partial: isRecord(data) && data.partial === true,
    partial_reason:
      isRecord(data) && typeof data.partial_reason === 'string' ? data.partial_reason : null,
  }
}

/**
 * Qualification history is append-only. The live API has no per-version
 * PATCH, so saving submits a new version containing the changed requirement
 * rows and then reloads the projection.
 */
export async function versionPositionRequirements(
  positionId: number,
  payload: RequirementPayload,
): Promise<void> {
  await resourcePost(
    `/v1/positions/${positionId}/requirements`,
    {
      requirements: payload.requirements,
      effective_from: payload.effectiveFrom,
    },
    { idempotencyKey: nextIdempotencyKey('position-requirements') },
  )
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

/**
 * The live PATCH contract has no fields for these catalogue columns. They are
 * shown read-only so the operator is never led to believe they were saved.
 */
export const POSITION_EDIT_READONLY_NOTE =
  '岗位编码、组织、生效/失效日期不在 PATCH /v1/positions/{id} 的可写字段中，本抽屉仅展示只读值；' +
  '如需调整这些目录字段，需要后端新增带生效日期的版本命令。'

export const POSITION_LIST_CAPABILITY_NOTE =
  'GET /v1/positions 仅支持 as_of 与 q，未提供 status、org_unit_id、page、page_size 或 sort 查询参数；' +
  '本页在服务端 as_of/q 结果上做状态与组织筛选及分页。'

export const POSITION_DUPLICATE_REASON = 'POSITION_CODE_DUPLICATE'

export function isForbidden(error: unknown): boolean {
  return (
    typeof error === 'object' &&
    error !== null &&
    'status' in error &&
    (error as { status?: unknown }).status === 403
  )
}
