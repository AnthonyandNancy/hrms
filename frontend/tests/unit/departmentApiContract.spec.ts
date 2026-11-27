import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

const api = readFileSync('src/modules/people/departments/api.ts', 'utf8')

describe('department API contract', () => {
  it('keeps basic edits separate from the controlled move payload', () => {
    const start = api.indexOf('export async function updateDepartment')
    const end = api.indexOf('export async function addDepartmentMember')
    const basic = api.slice(start, end)
    expect(basic).toContain('manager_employment_id')
    expect(basic).toContain('resourcePatch')
    expect(basic).not.toContain('new_parent_id')
    expect(basic).not.toContain('parent_id: input.parent_id')
  })

  it('sends the move parent under the server field name new_parent_id', () => {
    const start = api.indexOf('export async function moveDepartment')
    const end = api.indexOf('export async function removeDepartmentMember')
    const move = api.slice(start, end)
    expect(move).toContain('new_parent_id: input.parent_id')
    expect(move).toContain('effective_from: input.effective_from')
    expect(move).toContain('input.idempotencyKey')
  })

  it('fetches staffing by department and as-of without writing staffed', () => {
    const start = api.indexOf('export async function fetchDepartmentStaffing')
    const end = api.indexOf('export async function publishDepartmentHeadcount')
    const staffing = api.slice(start, end)
    expect(staffing).toContain("resourceGet('/v1/departments/staffing'")
    expect(staffing).toContain('org_unit_id: departmentId')
    expect(staffing).toContain('as_of: asOf')
    expect(staffing).not.toContain('resourcePatch')
  })
})
