import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

const page = readFileSync(
  'src/modules/people/departments/pages/DepartmentWorkspacePage.vue',
  'utf8',
)
const api = readFileSync('src/modules/people/departments/api.ts', 'utf8')
const members = readFileSync(
  'src/modules/people/departments/components/DepartmentMembersPanel.vue',
  'utf8',
)

describe('department workspace tabs', () => {
  it('defines the four workspace tabs and restores dept/tab from the route query', () => {
    expect(page).toContain("type DepartmentTab = 'overview' | 'list' | 'members' | 'staffing'")
    expect(page).toContain('route.query.dept')
    expect(page).toContain('route.query.tab')
    expect(page).toContain('router.replace({ query })')
    expect(page).toContain('`department-tab-${tab.value}`')
    expect(page).toContain("value: 'overview'")
    expect(page).toContain("value: 'list'")
    expect(page).toContain("value: 'members'")
    expect(page).toContain("value: 'staffing'")
  })

  it('renders list, members and staffing from one selected department without a second list API', () => {
    expect(page).toContain('fetchDepartmentPage')
    expect(page).toContain('fetchOrgTree')
    expect(page).toContain('fetchDepartmentMembers')
    expect(page).toContain('fetchDepartmentStaffing')
    expect(page).toContain('DepartmentMembersPanel')
    expect(page).toContain("activeTab === 'list'")
    expect(page).toContain("activeTab === 'members'")
    expect(page).toContain("activeTab === 'staffing'")
    expect(api).toContain("resourceGet('/v1/departments/staffing'")
    expect(members).toContain('/hr/employees/${member.employment_id}')
  })

  it('does not render a member-removal blocker and calls the real DELETE command', () => {
    expect(members).not.toContain('MISSING_MEMBER_REMOVAL_ENDPOINT')
    expect(members).toContain('removeDepartmentMember')
    expect(api).toContain('client.delete(`/departments/${departmentId}/members/${employmentId}`')
    expect(api).toContain("'Idempotency-Key'")
  })
})
