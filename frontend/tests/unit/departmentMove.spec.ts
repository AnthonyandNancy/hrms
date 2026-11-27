import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

const page = readFileSync(
  'src/modules/people/departments/pages/DepartmentWorkspacePage.vue',
  'utf8',
)
const api = readFileSync('src/modules/people/departments/api.ts', 'utf8')

describe('department move wizard', () => {
  it('previews impact before confirmation and shows cycle/authorization warnings', () => {
    expect(page).toContain('previewDepartmentMove')
    expect(page).toContain('movePreviewing')
    expect(page).toContain('movePreview')
    expect(page).toContain('cycle_refused')
    expect(page).toContain('authorization_warning')
    expect(page).toContain('affected_employee_count')
    expect(page).toContain('affected_manager_count')
    expect(page).toContain('cross_entity_warning')
    expect(api).toContain('previewDepartmentMove')
    expect(api).toContain("`/v1/org-units/${departmentId}/impact`")
  })

  it('reuses the same fixed idempotency key for one confirmed move', () => {
    expect(page).toContain("nextIdempotencyKey('department-move-confirm')")
    expect(page).toContain('idempotencyKey: moveIdempotencyKey.value')
    expect(api).toContain('idempotencyKey: input.idempotencyKey')
    expect(api).toContain('new_parent_id: input.parent_id')
    expect(api).toContain('effective_from: input.effective_from')
  })
})
