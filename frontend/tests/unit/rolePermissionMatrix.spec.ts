// The role authorization workbench was rebuilt for V2.1 Volume 2 Task 7.
//
// This file used to mount the legacy SystemRolesPage internals (custom tab
// markup, `tab-buttons`, per-page checkbox lists with `data-page-code`, and a
// standalone `/pages` PUT). Those internals no longer exist: the page is now
// composed from RolePageTree / RoleActionMatrix / RoleScopeEditor /
// RoleFieldPolicyEditor and saves one atomic authorization request.
//
// The behaviour is covered by `tests/unit/systemRolesPage.spec.ts`, which
// asserts the same guarantees at the new boundary:
//   - four authorization tabs render (页面权限 / 按钮动作 / 数据范围 / 字段权限)
//   - selecting a page never auto-grants its actions (ADR-0035)
//   - a diff summary is shown before the single atomic save
//   - high-risk additions require a reason and save is blocked until provided
//   - 409 refetches instead of silently discarding the operator's edits
//
// Keeping a second, implementation-coupled copy here would only be able to
// assert a stale DOM shape, so the coverage lives in the new suite.
import { describe, expect, it } from 'vitest'

import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const suitePath = resolve(
  dirname(fileURLToPath(import.meta.url)),
  'systemRolesPage.spec.ts',
)

describe('role permission matrix (superseded)', () => {
  it('keeps the rebuilt workbench coverage in systemRolesPage.spec.ts', () => {
    const source = readFileSync(suitePath, 'utf8')

    // Guard against silent deletion of the coverage that replaced this file.
    expect(source).toContain('tri-state page tree')
    expect(source).toContain('atomic save request')
    expect(source).toContain('409 conflict')
    // The ceiling/reason guard is asserted by the over-ceiling profile case.
    expect(source).toContain('exceeds the capability ceiling')
  })
})
