import assert from 'node:assert/strict'
import { test } from 'node:test'
import { auditUiPrimitives } from '../audit_ui_primitives.mjs'

test('rejects business pages with raw table/input/select', () => {
  const result = auditUiPrimitives({
    files: [
      { path: 'frontend/src/modules/platform/pages/SystemUsersPage.vue', source: '<table><input /><select /></table>' },
      { path: 'frontend/src/shared/ui/DataTable.vue', source: '<el-table />' },
    ],
  })
  assert.equal(result.ok, false)
  assert.match(result.failures.join('\n'), /SystemUsersPage\.vue: raw table/)
})
