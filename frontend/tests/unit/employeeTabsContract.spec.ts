import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * Static contract check for the frozen employee-detail tab set. These are
 * assertions about the source because the regression was a second table
 * rendered outside EmployeeTabs, which is easier to pin precisely here.
 */
const root = resolve(__dirname, '../../src/modules/people/employees')

function source(path: string): string {
  return readFileSync(resolve(root, path), 'utf8')
}

describe('employee detail tab contract', () => {
  it('declares exactly the eight detail tabs with the legacy aliases', () => {
    const page = source('pages/EmployeeDetailPage.vue')

    for (const tab of [
      'overview',
      'employment',
      'contracts',
      'credentials',
      'education',
      'experience',
      'contacts',
      'changes',
    ]) {
      expect(page).toContain(`'${tab}'`)
    }

    expect(page).toContain("files: 'credentials'")
    expect(page).toContain("history: 'employment'")
    expect(page).toContain("'work-history': 'experience'")
  })

  it('renders each profile writer exactly once and inside the tab workspace', () => {
    const page = source('pages/EmployeeDetailPage.vue')

    expect(page).toContain('<EmployeeProfileSection')
    expect(page.match(/<EmployeeProfileSection/g)?.length ?? 0).toBe(4)
    expect(page.match(/<EmployeeFileList/g)?.length ?? 0).toBe(1)
    expect(page.match(/<EmployeeChangeList/g)?.length ?? 0).toBe(1)

    // A duplicate editable table appeared whenever the resources were rendered
    // in a catch-all workspace next to EmployeeTabs.
    expect(page).toContain("activeTab === 'education'")
    expect(page).toContain("activeTab === 'experience'")
    expect(page).toContain("activeTab === 'contacts'")
    expect(page).toContain("activeTab === 'credentials'")
    expect(page).toContain("activeTab === 'changes'")
  })

  it('keeps EmployeeTabs summary-only for the non-writer tabs', () => {
    const tabs = source('components/EmployeeTabs.vue')

    expect(tabs).not.toContain('EmployeeProfileSection')
    expect(tabs).not.toContain('EmployeeFileList')
    expect(tabs).not.toContain('EmployeeChangeList')
    expect(tabs).toContain("history: 'employment'")
    expect(tabs).toContain("'work-history': 'experience'")
    expect(tabs).toContain("files: 'credentials'")
    expect(tabs).toContain("'employment'")
    expect(tabs).toContain("'contracts'")
    expect(tabs).toContain("'credentials'")
  })
})
