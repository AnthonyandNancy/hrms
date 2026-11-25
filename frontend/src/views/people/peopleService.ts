import { ApiError } from '@/api/errors'
import { resourceGet } from '@/api/resourceClient'
import { ENTITY_SCOPE, isRecord, readPeopleList, rowId } from './peopleRead'
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
    ...ENTITY_SCOPE,
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

async function positionStaff(): Promise<PeopleReadState> {
  const [positions, employees] = await Promise.all([
    readPeopleList('/v1/positions', { ...ENTITY_SCOPE }),
    employeePage(100),
  ])
  const byPosition = new Map<number, PeopleRow[]>()

  for (const employee of employees.rows) {
    const assignment = isRecord(employee.current_assignment) ? employee.current_assignment : null
    const positionId = assignment ? Number(assignment.position_id ?? 0) : 0
    if (positionId <= 0) continue
    const list = byPosition.get(positionId) ?? []
    list.push(employee)
    byPosition.set(positionId, list)
  }

  const rows: PeopleRow[] = []
  for (const position of positions.rows) {
    const positionId = Number(position.id ?? 0)
    for (const employee of byPosition.get(positionId) ?? []) {
      rows.push({
        position_code: position.code ?? '',
        position_title: position.title ?? '',
        employee_no: employee.employee_no ?? '',
        person_name: employee.person_name ?? '',
        employment_id: employee.employment_id ?? '',
      })
    }
  }

  return {
    rows,
    total: rows.length,
    partial: true,
    primaryId: null,
    partialNote: '后端没有按岗位查询员工的子接口；本列表从员工首页当前任职匹配，不覆盖未加载员工。',
  }
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
      case 'employee-profile':
        if (id === null) return emptyState()
        return toReadState(await readPeopleList(`/v1/employees/${id}/${config.read.resource}`))
      case 'employee-timeline':
        if (id === null) return emptyState()
        return toReadState(await readPeopleList(`/v1/employments/${id}/timeline`))
      case 'employee-imports':
        return await importsForEmployees()
      case 'org-tree':
        return toReadState(await readPeopleList('/v1/org-units/tree', { ...ENTITY_SCOPE, as_of: asOf }))
      case 'department-list':
        return toReadState(await readPeopleList('/v1/departments', { ...ENTITY_SCOPE, as_of: asOf }))
      case 'department-members': {
        const tree = await readPeopleList('/v1/org-units/tree', { ...ENTITY_SCOPE, as_of: asOf })
        const department = tree.rows[0]
        if (!department) return { ...tree, primaryId: null, partialNote: '' }
        const departmentId = Number(department.id ?? 0)
        if (departmentId <= 0) return { ...tree, primaryId: null, partialNote: '' }
        const members = await readPeopleList(`/v1/departments/${departmentId}/members`, { as_of: asOf })
        return { ...members, primaryId: departmentId, partialNote: '' }
      }
      case 'department-staffing':
        return toReadState(await readPeopleList('/v1/departments/staffing', { ...ENTITY_SCOPE, as_of: asOf }))
      case 'positions':
        return toReadState(await readPeopleList('/v1/positions', { ...ENTITY_SCOPE, as_of: asOf }))
      case 'position-staff':
        return await positionStaff()
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
          ...ENTITY_SCOPE,
          as_of: asOf,
          page: 1,
          page_size: 20,
        }))
      case 'contract-expiring':
        return toReadState(await readPeopleList('/v1/employments/contracts/expiring', {
          ...ENTITY_SCOPE,
          as_of: asOf,
          within_days: config.read.withinDays ?? 60,
        }))
      case 'contract-detail': {
        if (id === null) return emptyState()
        const ledger = await readPeopleList('/v1/employments/contracts', {
          ...ENTITY_SCOPE,
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
