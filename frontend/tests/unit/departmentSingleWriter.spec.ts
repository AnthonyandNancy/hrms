import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

const api = readFileSync('src/modules/people/departments/api.ts', 'utf8')

describe('department single writer frontend contract', () => {
  it('creates and updates only through /v1/departments', () => {
    expect(api).toContain("resourcePost")
    expect(api).toContain("'/v1/departments'")
    expect(api).toContain('`/v1/departments/${id}`')
    expect(api).not.toContain("resourcePost('/v1/org-units'")
    expect(api).not.toContain("'/v1/org-units', {")
  })

  it('keeps move as the only org-unit command and never sends parent_id in basic edit', () => {
    const updateStart = api.indexOf('export async function updateDepartment')
    const updateEnd = api.indexOf('export async function addDepartmentMember')
    const updateBody = api.slice(updateStart, updateEnd)
    expect(updateBody).not.toContain('parent_id: input.parent_id')
    expect(api).toContain("`/v1/org-units/${departmentId}/move`")
  })
})
