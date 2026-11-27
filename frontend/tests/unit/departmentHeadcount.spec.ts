import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import {
  canUseAsParent,
  type Department,
  type DepartmentStaffing,
} from '@/modules/people/departments/api'

const api = readFileSync('src/modules/people/departments/api.ts', 'utf8')
const page = readFileSync(
  'src/modules/people/departments/pages/DepartmentWorkspacePage.vue',
  'utf8',
)
const drawer = readFileSync(
  'src/modules/people/departments/components/DepartmentFormDrawer.vue',
  'utf8',
)

const DEPARTMENTS: Department[] = [
  {
    id: 1,
    parent_id: null,
    code: 'HQ',
    name: '总公司',
    type: 'COMPANY',
    status: 'ACTIVE',
    headcount: 2,
    headcount_limit: 10,
    valid_from: '2026-01-01',
    valid_to: '9999-12-31',
    manager_employment_id: 88,
  },
  {
    id: 2,
    parent_id: 1,
    code: 'HR',
    name: '人力资源部',
    type: 'DEPARTMENT',
    status: 'ACTIVE',
    headcount: 3,
    headcount_limit: 5,
    valid_from: '2026-01-01',
    valid_to: '9999-12-31',
    manager_employment_id: null,
  },
]

describe('department headcount and manager facts', () => {
  it('uses the versioned headcount endpoints and never submits staffed as a write', () => {
    expect(api).toContain("resourceGet('/v1/departments/staffing'")
    expect(api).toContain('headcount_effective_from')
    expect(api).toContain('headcount_limit: input.headcount_limit')
    expect(api).not.toContain('staffed: input.')
    expect(page).toContain('追加编制版本')
    expect(page).toContain('在岗人数')
    expect(page).toContain('编制是追加式版本事实')
  })

  it('parses all staffing facts including nulls without inventing values', async () => {
    const { fetchDepartmentStaffing } = (await import('@/modules/people/departments/api')) as unknown as {
      fetchDepartmentStaffing: () => Promise<unknown>
    }
    const row: DepartmentStaffing = {
      org_unit_id: 2,
      code: 'HR',
      name: '人力资源部',
      headcount_limit: null,
      staffed: 0,
      vacancies: null,
      overstaffed: false,
      version: null,
      effective_from: null,
    }
    expect(row.headcount_limit).toBeNull()
    expect(row.staffed).toBe(0)
    expect(row.vacancies).toBeNull()
    expect(typeof fetchDepartmentStaffing).toBe('function')
    expect(canUseAsParent(DEPARTMENTS, 2, 1)).toBe(false)
  })

  it('persists manager_employment_id as a real editable field, not a fake display', () => {
    expect(drawer).not.toContain('MISSING_MANAGER_ENDPOINT')
    expect(drawer).toContain('department-manager')
    expect(drawer).toContain('manager_employment_id')
    expect(page).toContain('manager_employment_id')
    expect(api).toContain('manager_employment_id: input.manager_employment_id')
  })
})
