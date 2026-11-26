import assert from 'node:assert/strict'
import { test } from 'node:test'
import { auditUserCopy } from '../audit_user_copy.mjs'

test('rejects technical copy and English enums in user-facing templates', () => {
  const result = auditUserCopy({
    files: [
      { path: 'frontend/src/modules/platform/pages/SystemUsersPage.vue', source: '<p>角色来自 GET /v1/roles；状态 ACTIVE</p>' },
    ],
  })
  assert.equal(result.ok, false)
  assert.match(result.failures.join('\n'), /GET \/v1\//)
  assert.match(result.failures.join('\n'), /ACTIVE/)
})
