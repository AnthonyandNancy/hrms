import { ApiError } from '@/api/errors'
import { resourceGet } from '@/api/resourceClient'
import { isRecord, readPeopleList, rowId } from './peopleRead'
import type { PeopleListResult, PeoplePageConfig, PeopleRow } from './types'

export type PeopleReadState = {
  rows: PeopleRow[]
  total: number
  partial: boolean
  primaryId: number | null
  partialNote: string
}

function emptyState(primaryId: number | null = null): PeopleReadState {
  return { rows: [], total: 0, partial: false, primaryId, partialNote: '' }
}

function toReadState(result: PeopleListResult, primaryId: number | null = null): PeopleReadState {
  return { rows: result.rows, total: result.total, partial: result.partial, primaryId, partialNote: '' }
}

function routeId(value: unknown): number | null {
  if (typeof value !== 'string' || !/^\d+$/.test(value)) return null
  const parsed = Number(value)
  return Number.isSafeInteger(parsed) && parsed > 0 ? parsed : null
}

function single(value: unknown): PeopleReadState {
  return isRecord(value)
    ? { rows: [value], total: 1, partial: false, primaryId: null, partialNote: '' }
    : emptyState()
}

function serviceError(error: unknown, context: string): never {
  if (error instanceof ApiError) throw error
  if (error instanceof Error) throw new Error(`${context}: ${error.message}`)
  throw new Error(context)
}

async function employeePage(pageSize = 20): Promise<PeopleReadState> {
  return toReadState(await readPeopleList('/v1/employees', {
    as_of: new Date().toISOString().slice(0, 10),
    page: 1,
    page_size: pageSize,
  }))
}

async function importsForEmployees(): Promise<PeopleReadState> {
  const employees = await employeePage(30)
  const rows: PeopleRow[] = []
  let total = 0

  for (const employee of employees.rows) {
    const id = Number(employee.employment_id ?? 0)
    if (id <= 0) continue
    const imports = await readPeopleList(`/v1/employees/${id}/imports`)
    for (const batch of imports.rows) {
      rows.push({
        ...batch,
        employee_name: employee.person_name ?? `员工 #${id}`,
        employee_no: employee.employee_no ?? '',
        employment_id: id,
      })
    }
    total += imports.total
  }

  return {
    rows,
    total,
    partial: employees.total > employees.rows.length,
    primaryId: employees.rows[0] ? Number(employees.rows[0].employment_id ?? 0) || null : null,
    partialNote: employees.total > employees.rows.length ? '仅加载前 30 名员工的导入批次。' : '',
  }
}

/**
 * Position sub-resource pages are catalogue views: the position list is the
 * page subject and each position's requirements/incumbents are read from its
 * dedicated endpoint. No row is synthesised from employee data.
 */
async function positionsFor(asOf: string): Promise<PeopleListResult> {
  return readPeopleList('/v1/positions', { as_of: asOf })
}

async function positionRequirements(asOf: string): Promise<PeopleReadState> {
  const positions = await positionsFor(asOf)
  const rows: PeopleRow[] = []
  let total = 0
  let partial = positions.partial

  for (const position of positions.rows.slice(0, 20)) {
    const positionId = Number(position.id ?? 0)
    if (positionId <= 0) continue
    const requirements = await readPeopleList(`/v1/positions/${positionId}/requirements`, { as_of: asOf, page: 1, page_size: 100 })
    total += requirements.total
    partial = partial || requirements.partial
    for (const row of requirements.rows) {
      rows.push({
        ...row,
        position_id: positionId,
        position_code: position.code ?? '',
        position_title: position.title ?? '',
        requirements_summary: requirementsSummary(row.requirements_json),
      })
    }
  }

  return { rows, total, partial, primaryId: null, partialNote: '' }
}

function requirementsSummary(value: unknown): string {
  if (!isRecord(value)) return '—'
  const entries = Object.entries(value)
  if (entries.length === 0) return '—'
  return entries
    .map(([key, item]) => `${key}: ${typeof item === 'object' && item !== null ? JSON.stringify(item) : String(item)}`)
    .join('；')
}

async function positionStaff(asOf: string): Promise<PeopleReadState> {
  const positions = await positionsFor(asOf)
  const rows: PeopleRow[] = []
  let total = 0
  let partial = positions.partial

  for (const position of positions.rows.slice(0, 20)) {
    const positionId = Number(position.id ?? 0)
    if (positionId <= 0) continue
    const staff = await readPeopleList(`/v1/positions/${positionId}/staff`, { as_of: asOf })
    total += staff.total
    partial = partial || staff.partial
    rows.push(...staff.rows)
  }

  return { rows, total, partial, primaryId: null, partialNote: '' }
}

async function positionGrades(asOf: string): Promise<PeopleReadState> {
  const grades = await readPeopleList('/v1/positions/grades', { as_of: asOf, page: 1, page_size: 100 })
  const rows = grades.rows.map((row) => ({ ...row, active: row.active === true ? '是' : '否' }))
  return { rows, total: grades.total, partial: grades.partial, primaryId: null, partialNote: '' }
}

async function secondments(asOf: string): Promise<PeopleReadState> {
  const result = await readPeopleList('/v1/lifecycle/secondments', { as_of: asOf, page: 1, page_size: 20 })
  return { rows: result.rows, total: result.total, partial: result.partial, primaryId: null, partialNote: '' }
}

export async function loadPeoplePage(
  config: PeoplePageConfig,
  routeParams: Record<string, unknown>,
): Promise<PeopleReadState> {
  const id = routeId(routeParams.id)
  const asOf = new Date().toISOString().slice(0, 10)

  try {
    switch (config.read.kind) {
      case 'employee-list':
        return await employeePage(20)
      case 'employee-detail':
        if (id === null) return emptyState()
        return single(await resourceGet<unknown>(`/v1/employees/${id}`, { as_of: asOf }))
      case 'employee-documents':
        if (id === null) return emptyState()
        return toReadState(await readPeopleList(`/v1/employments/${id}/document-files`, {
          page: 1,
          page_size: 20,
        }))
      case 'employee-profile':
        if (id === null) return emptyState()
        return toReadState(await readPeopleList(`/v1/employees/${id}/${config.read.resource}`))
      case 'employee-timeline':
        if (id === null) return emptyState()
        return toReadState(await readPeopleList(`/v1/employments/${id}/timeline`))
      case 'employee-imports':
        return await importsForEmployees()
      case 'org-tree':
        return toReadState(await readPeopleList('/v1/org-units/tree', { as_of: asOf }))
      case 'department-list':
        return toReadState(await readPeopleList('/v1/departments', { as_of: asOf }))
      case 'department-members': {
        const tree = await readPeopleList('/v1/org-units/tree', { as_of: asOf })
        const department = tree.rows[0]
        if (!department) return { ...tree, primaryId: null, partialNote: '' }
        const departmentId = Number(department.id ?? 0)
        if (departmentId <= 0) return { ...tree, primaryId: null, partialNote: '' }
        const members = await readPeopleList(`/v1/departments/${departmentId}/members`, { as_of: asOf })
        return { ...members, primaryId: departmentId, partialNote: '' }
      }
      case 'department-staffing':
        return toReadState(await readPeopleList('/v1/departments/staffing', { as_of: asOf }))
      case 'positions':
        return toReadState(await readPeopleList('/v1/positions', { as_of: asOf }))
      case 'position-requirements':
        return await positionRequirements(asOf)
      case 'position-grades':
        return await positionGrades(asOf)
      case 'position-staff':
        return await positionStaff(asOf)
      case 'secondments':
        return await secondments(asOf)
      case 'lifecycle-cases':
        return toReadState(await readPeopleList('/v1/lifecycle/cases', {
          case_type: config.read.caseType,
          page: 1,
          page_size: 20,
        }))
      case 'lifecycle-case-detail':
        if (id === null) return emptyState()
        return single(await resourceGet<unknown>(`/v1/lifecycle/cases/${id}`))
      case 'contracts-ledger':
        return toReadState(await readPeopleList('/v1/employments/contracts', {
          as_of: asOf,
          page: 1,
          page_size: 20,
        }))
      case 'contract-expiring':
        return toReadState(await readPeopleList('/v1/employments/contracts/expiring', {
          as_of: asOf,
          within_days: config.read.withinDays ?? 60,
        }))
      case 'contract-detail': {
        if (id === null) return emptyState()
        const ledger = await readPeopleList('/v1/employments/contracts', {
          as_of: asOf,
          page: 1,
          page_size: 100,
        })
        const ledgerState = toReadState(ledger)
        const match = ledger.rows.find((row) => rowId(row) === String(id))
        return match
          ? { ...ledgerState, rows: [match], total: 1, partialNote: '' }
          : { ...ledgerState, rows: [], total: 0, partial: true, partialNote: '未能在当前合同台账页中定位该合同；记录可能超出前 100 行范围。' }
      }
      case 'contract-templates':
        return toReadState(await readPeopleList('/v1/contract-templates', {
          as_of: asOf,
          status: config.read.status,
          page: 1,
          page_size: 20,
        }))
      case 'contract-amendments':
        if (id === null) return emptyState()
        return toReadState(await readPeopleList(`/v1/employment-contracts/${id}/amendments`, {
          as_of: asOf,
          page: 1,
          page_size: 20,
        }))
    }
  } catch (error) {
    return serviceError(error, `加载${config.title}失败`)
  }
}
