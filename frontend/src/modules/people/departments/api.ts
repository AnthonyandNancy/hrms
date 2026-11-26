import { ApiError } from '@/api/errors'
import { resourceGet, resourcePatch, resourcePost } from '@/api/resourceClient'
import { nextIdempotencyKey } from '@/modules/people/peopleRead'

export type DepartmentType =
  | 'COMPANY'
  | 'DIVISION'
  | 'DEPARTMENT'
  | 'TEAM'
  | 'COST_CENTER'
  | 'SHARED_SERVICE'

export type DepartmentStatus = 'ACTIVE' | 'INACTIVE'

export type Department = {
  id: number
  parent_id: number | null
  code: string
  name: string
  type: DepartmentType
  status: DepartmentStatus
  headcount: number | null
  headcount_limit: number | null
  valid_from: string
  valid_to: string
  manager_employment_id?: number | null
  manager_name?: string | null
}

export type OrgTreeNode = {
  id: number
  parent_id: number | null
  code: string
  name: string
  type: DepartmentType
}

export type DepartmentMember = {
  employment_id: number
  assignment_id: number
  assignment_type: string
  is_primary: boolean
  valid_from: string
  valid_to: string
}

export type DepartmentCreateInput = {
  parent_id: number | null
  code: string
  name: string
  type: DepartmentType
  valid_from: string
  valid_to: string | null
  manager_employment_id: number | null
}

export type DepartmentUpdateInput = DepartmentCreateInput

export type DepartmentMemberInput = {
  employment_id: number
  effective_from: string
  reason: string
}

export type DepartmentPage = {
  items: Department[]
  total: number
  partial: boolean
  partialReason: string | null
}

export type OrgTreePage = {
  items: OrgTreeNode[]
  total: number
  partial: boolean
}

export type MemberPage = {
  items: DepartmentMember[]
  total: number
  partial: boolean
  partialReason: string | null
}

export type DepartmentMoveInput = {
  parent_id: number | null
  effective_from: string
}

export const DEPARTMENT_TYPES: ReadonlyArray<{ value: DepartmentType; label: string }> = [
  { value: 'COMPANY', label: '公司' },
  { value: 'DIVISION', label: '事业部' },
  { value: 'DEPARTMENT', label: '部门' },
  { value: 'TEAM', label: '团队' },
  { value: 'COST_CENTER', label: '成本中心' },
  { value: 'SHARED_SERVICE', label: '共享服务' },
]

export const DEPARTMENT_STATUSES: Readonly<Record<DepartmentStatus, string>> = {
  ACTIVE: '启用',
  INACTIVE: '停用',
}

export const ASSIGNMENT_TYPES: Readonly<Record<string, string>> = {
  PRIMARY: '主任职',
  SECONDMENT: '借调',
  MATRIX: '矩阵任职',
  PROJECT: '项目任职',
}

export function departmentTypeLabel(value: string): string {
  return DEPARTMENT_TYPES.find((type) => type.value === value)?.label ?? '未知类型'
}

export function departmentStatusLabel(value: string): string {
  return value === 'ACTIVE' || value === 'INACTIVE' ? DEPARTMENT_STATUSES[value] : '未知状态'
}

export function assignmentTypeLabel(value: string): string {
  return ASSIGNMENT_TYPES[value] ?? '未知任职'
}

export type DepartmentNode = Omit<Department, 'status'> & {
  status: DepartmentStatus | null
  children: DepartmentNode[]
}

type UnknownRecord = Record<string, unknown>

function isRecord(value: unknown): value is UnknownRecord {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function text(value: unknown, fallback = ''): string {
  return typeof value === 'string' ? value : fallback
}

function integer(value: unknown, fallback: number | null = null): number | null {
  if (typeof value === 'number' && Number.isSafeInteger(value)) return value
  if (typeof value === 'string' && /^\d+$/.test(value)) return Number(value)
  return fallback
}

function listItems(value: unknown): UnknownRecord[] {
  if (!isRecord(value) || !Array.isArray(value.items)) return []
  return value.items.filter(isRecord)
}

function normalizeType(value: unknown): DepartmentType {
  return DEPARTMENT_TYPES.some((type) => type.value === value)
    ? (value as DepartmentType)
    : 'DEPARTMENT'
}

function normalizeDepartment(row: UnknownRecord): Department {
  return {
    id: integer(row.id) ?? 0,
    parent_id: integer(row.parent_id),
    code: text(row.code),
    name: text(row.name),
    type: normalizeType(row.type),
    status: row.status === 'INACTIVE' ? 'INACTIVE' : 'ACTIVE',
    headcount: integer(row.headcount),
    headcount_limit: integer(row.headcount_limit),
    valid_from: text(row.valid_from),
    valid_to: text(row.valid_to),
    manager_employment_id: integer(row.manager_employment_id),
    manager_name: typeof row.manager_name === 'string' ? row.manager_name : null,
  }
}

function normalizeTreeRow(row: UnknownRecord): OrgTreeNode | null {
  const id = integer(row.id)
  if (id === null || id <= 0) return null
  return {
    id,
    parent_id: integer(row.parent_id),
    code: text(row.code),
    name: text(row.name),
    type: normalizeType(row.type),
  }
}

function normalizeMember(row: UnknownRecord): DepartmentMember | null {
  const employmentId = integer(row.employment_id)
  const assignmentId = integer(row.assignment_id)
  if (employmentId === null || assignmentId === null) return null
  return {
    employment_id: employmentId,
    assignment_id: assignmentId,
    assignment_type: text(row.assignment_type, 'UNKNOWN'),
    is_primary: row.is_primary === true,
    valid_from: text(row.valid_from),
    valid_to: text(row.valid_to),
  }
}

export function businessDateToday(now = new Date()): string {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Shanghai',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(now)
  const record: Record<string, string> = {}
  for (const part of parts) {
    if (part.type !== 'literal') record[part.type] = part.value
  }
  return `${record.year ?? '0000'}-${record.month ?? '00'}-${record.day ?? '00'}`
}

export async function fetchDepartmentPage(asOf: string): Promise<DepartmentPage> {
  const data: unknown = await resourceGet('/v1/departments', { as_of: asOf })
  const records = listItems(data)
  const items = records
    .map(normalizeDepartment)
    .filter((row) => row.id > 0)
    .sort((left, right) => left.code.localeCompare(right.code))
  const total = isRecord(data) && typeof data.total === 'number' ? data.total : items.length
  return {
    items,
    total,
    partial: isRecord(data) && data.partial === true,
    partialReason:
      isRecord(data) && typeof data.partial_reason === 'string' ? data.partial_reason : null,
  }
}

export async function fetchOrgTree(asOf: string): Promise<OrgTreePage> {
  const data: unknown = await resourceGet('/v1/org-units/tree', { as_of: asOf })
  const items = listItems(data)
    .map(normalizeTreeRow)
    .filter((row): row is OrgTreeNode => row !== null)
    .sort((left, right) => left.code.localeCompare(right.code))
  const total = isRecord(data) && typeof data.total === 'number' ? data.total : items.length
  return {
    items,
    total,
    partial: isRecord(data) && data.partial === true,
  }
}

export async function fetchDepartmentMembers(
  departmentId: number,
  asOf: string,
): Promise<MemberPage> {
  const data: unknown = await resourceGet(`/v1/departments/${departmentId}/members`, { as_of: asOf })
  const items = listItems(data)
    .map(normalizeMember)
    .filter((row): row is DepartmentMember => row !== null)
    .sort((left, right) => left.employment_id - right.employment_id)
  const total = isRecord(data) && typeof data.total === 'number' ? data.total : items.length
  return {
    items,
    total,
    partial: isRecord(data) && data.partial === true,
    partialReason:
      isRecord(data) && typeof data.partial_reason === 'string' ? data.partial_reason : null,
  }
}

/**
 * Department create/edit are department-scoped commands, per the task
 * interface (`POST /v1/departments`, `PATCH /v1/departments/{id}`). The
 * manager is submitted as `manager_employment_id`; if the deployed backend
 * does not declare that field yet, it must return 422 rather than the client
 * persisting an unsaved value locally.
 */
export async function createDepartment(input: DepartmentCreateInput): Promise<void> {
  await resourcePost(
    '/v1/departments',
    {
      parent_id: input.parent_id,
      code: input.code,
      name: input.name,
      type: input.type,
      valid_from: input.valid_from,
      valid_to: input.valid_to,
      manager_employment_id: input.manager_employment_id,
    },
    { idempotencyKey: nextIdempotencyKey('department-create') },
  )
}

export async function updateDepartment(id: number, input: DepartmentUpdateInput): Promise<void> {
  await resourcePatch(
    `/v1/departments/${id}`,
    {
      parent_id: input.parent_id,
      code: input.code,
      name: input.name,
      type: input.type,
      valid_from: input.valid_from,
      valid_to: input.valid_to,
      manager_employment_id: input.manager_employment_id,
    },
    { idempotencyKey: nextIdempotencyKey('department-update') },
  )
}

export async function addDepartmentMember(
  departmentId: number,
  input: DepartmentMemberInput,
): Promise<void> {
  await resourcePost(
    `/v1/departments/${departmentId}/members`,
    {
      employment_id: input.employment_id,
      effective_from: input.effective_from,
      reason: input.reason,
    },
    { idempotencyKey: nextIdempotencyKey('department-member') },
  )
}

export async function moveDepartment(
  departmentId: number,
  input: DepartmentMoveInput,
): Promise<void> {
  await resourcePost(
    `/v1/org-units/${departmentId}/move`,
    {
      new_parent_id: input.parent_id,
      effective_from: input.effective_from,
    },
    { idempotencyKey: nextIdempotencyKey('department-move') },
  )
}

/**
 * The member-removal command named by the workspace requirements has no
 * backend route yet. This constant is rendered as an explicit blocker after
 * the destructive confirmation; no request is invented.
 */
export const MISSING_MEMBER_REMOVAL_ENDPOINT =
  '缺少后端接口：移除部门成员需要按 effective_from 结束任职的 DELETE/PATCH 命令（当前仅支持 POST /v1/departments/{id}/members 调入）。'

export const MISSING_MANAGER_ENDPOINT =
  '后端尚未在部门创建/更新契约与查询投影中声明负责人字段；负责人编号不会凭空保存，需先补齐后端契约。'

export function buildDepartmentTree(
  treeRows: readonly OrgTreeNode[],
  departments: readonly Department[],
): DepartmentNode[] {
  const departmentById = new Map<number, Department>()
  for (const department of departments) departmentById.set(department.id, department)

  const rowById = new Map<number, OrgTreeNode>()
  for (const row of treeRows) rowById.set(row.id, row)

  const ids = new Set<number>([...rowById.keys(), ...departmentById.keys()])
  const nodes = new Map<number, DepartmentNode>()

  for (const id of ids) {
    const row = rowById.get(id)
    const department = departmentById.get(id)
    if (department === undefined && row === undefined) continue
    const base: Omit<DepartmentNode, 'children'> = department ?? {
      id,
      parent_id: row?.parent_id ?? null,
      code: row?.code ?? '',
      name: row?.name ?? '',
      type: row?.type ?? 'DEPARTMENT',
      status: null,
      headcount: null,
      headcount_limit: null,
      valid_from: '',
      valid_to: '',
    }
    nodes.set(id, {
      ...base,
      parent_id: row?.parent_id ?? base.parent_id,
      children: [],
    })
  }

  const roots: DepartmentNode[] = []
  for (const node of nodes.values()) {
    const parent = node.parent_id === null ? undefined : nodes.get(node.parent_id)
    if (parent === undefined) roots.push(node)
    else parent.children.push(node)
  }

  const sort = (list: DepartmentNode[]): void => {
    list.sort((left, right) => left.code.localeCompare(right.code))
    for (const node of list) sort(node.children)
  }
  sort(roots)
  return roots
}

export function flattenDepartments(departments: readonly Department[]): Department[] {
  return [...departments].sort((left, right) => left.code.localeCompare(right.code))
}

/**
 * A node may be used as the new parent only when it is neither the moving
 * department nor one of its descendants. This mirrors the server-side cycle
 * refusal and is the single guard used by the edit and move pickers.
 */
export function canUseAsParent(
  departments: readonly Department[],
  candidateId: number,
  movingId: number | null,
): boolean {
  if (movingId === null) return true
  if (candidateId === movingId) return false

  const parentById = new Map<number, number | null>()
  for (const department of departments) parentById.set(department.id, department.parent_id)

  let cursor: number | null = candidateId
  const visited = new Set<number>()
  while (cursor !== null && !visited.has(cursor)) {
    if (cursor === movingId) return false
    visited.add(cursor)
    cursor = parentById.get(cursor) ?? null
  }
  return true
}

export function isForbidden(error: unknown): boolean {
  return error instanceof ApiError && (error.status === 403 || error.code === 'FORBIDDEN')
}

export function errorMessage(error: unknown): string {
  if (error instanceof Error && error.message !== '') return error.message
  return '请求未完成，请稍后重试。'
}
