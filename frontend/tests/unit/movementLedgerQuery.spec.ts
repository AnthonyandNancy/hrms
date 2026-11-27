import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

const source = readFileSync('src/modules/people/lifecycle/api.ts', 'utf8')
const workspace = readFileSync('src/modules/people/lifecycle/pages/MovementWorkspacePage.vue', 'utf8')

describe('movement ledger query contract', () => {
  it('uses one server-side ledger request instead of five parallel case reads', () => {
    expect(source).toContain('listMovementLedger')
    expect(source).toContain("'/v1/lifecycle/cases'")
    expect(source).not.toContain('Promise.allSettled(\n    WORKFLOW_CASE_TYPES')
    expect(source).not.toContain('listLifecycleMovementCases')
    expect(source).not.toContain('WORKFLOW_CASE_TYPES')
    expect(workspace).not.toContain('listMovementCases')
    expect(workspace).not.toContain('listSecondments')
  })

  it('sends every filter and the page window to the server', () => {
    for (const token of [
      'kind',
      'status',
      'employment_id',
      'effective_from',
      'effective_to',
      'q',
      'page',
      'page_size',
      'as_of',
    ]) {
      expect(source).toContain(token)
    }
    expect(source).not.toContain('.slice(start')
    expect(workspace).not.toContain('filteredRows')
    expect(workspace).not.toContain('pagedRows')
  })

  it('persists tab, filters and calendar view in the route query', () => {
    expect(workspace).toContain("type MovementView = 'ledger' | 'calendar'")
    expect(workspace).toContain('route.query.tab')
    expect(workspace).toContain("query.view = 'calendar'")
    expect(workspace).toContain('allowedMovementKinds')
    expect(workspace).toContain('data-testid="movement-calendar"')
  })

  it('renders all three calendar lanes from the server row lane', () => {
    expect(source).toContain("export type CalendarLane = 'EFFECTIVE' | 'SCHEDULED' | 'PENDING_APPROVAL'")
    expect(workspace).toContain('已生效')
    expect(workspace).toContain('待生效')
    expect(workspace).toContain('待审批')
  })
})
