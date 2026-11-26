import assert from 'node:assert/strict'
import { test } from 'node:test'
import { buildActionManifest, validateActionManifest } from '../generate_action_manifest.mjs'

test('generates deterministic actions from registry, catalog and OpenAPI', () => {
  const manifest = buildActionManifest({
    pages: [{ name: 'system-users', path: '/system/users', requiredApis: ['GET /v1/users', 'POST /v1/users'] }],
    catalog: [{ code: 'iam.user.manage', anchorTypes: ['COMPANY'], fieldProfileMax: 'P3_RESTRICTED' }],
    openapi: { paths: { '/v1/users': { post: { 'x-capability': 'iam.user.manage', 'x-action-label': '新增用户' } } } },
    actions: [{ pageCode: 'system-users', key: 'system-users.create', label: '新增用户', capability: 'iam.user.manage', api: 'POST /v1/users', ui: 'primary', confirm: 'none' }],
  })
  assert.deepEqual(manifest.pages['system-users'].map((action) => action.key), ['system-users.create'])
  assert.equal(manifest.pages['system-users'][0].fieldProfileMax, 'P3_RESTRICTED')
})

test('rejects an action whose API is absent from OpenAPI', () => {
  assert.throws(
    () => buildActionManifest({
      pages: [{ name: 'system-users', path: '/system/users', requiredApis: ['POST /v1/users'] }],
      catalog: [{ code: 'iam.user.manage', anchorTypes: ['COMPANY'], fieldProfileMax: 'P3_RESTRICTED' }],
      openapi: { paths: {} },
      actions: [{ pageCode: 'system-users', key: 'system-users.create', label: '新增用户', capability: 'iam.user.manage', api: 'POST /v1/users', ui: 'primary', confirm: 'none' }],
    }),
    /missing OpenAPI operation/,
  )
})

test('validate reports a write API in the registry that has no action', () => {
  const result = validateActionManifest({
    pages: [{ name: 'system-users', requiredApis: ['GET /v1/users', 'POST /v1/users'] }],
    manifest: { version: 1, pages: { 'system-users': [] } },
    openapi: { paths: { '/v1/users': { post: { 'x-capability': 'iam.user.manage' } } } },
    gaps: [],
  })
  assert.equal(result.ok, false)
  assert.match(result.errors.join('\n'), /write API POST \/v1\/users has no action/)
})

test('validate reports a capability-guarded OpenAPI write that has no action', () => {
  const result = validateActionManifest({
    pages: [{ name: 'system-users', requiredApis: ['GET /v1/users'] }],
    manifest: { version: 1, pages: {} },
    openapi: { paths: { '/v1/users': { post: { 'x-capability': 'iam.user.manage' } } } },
    gaps: [],
  })
  assert.equal(result.ok, false)
  assert.match(result.errors.join('\n'), /OpenAPI write POST \/v1\/users has no action/)
})

test('validate honours only explicitly listed gaps', () => {
  const result = validateActionManifest({
    pages: [{ name: 'system-users', requiredApis: ['GET /v1/users'] }],
    manifest: { version: 1, pages: {} },
    openapi: { paths: { '/v1/users': { post: { 'x-capability': 'iam.user.manage' } } } },
    gaps: [{ api: 'POST /v1/users', owner: 'Volume 2 Task 5' }],
  })
  assert.equal(result.ok, true)
})

test('rejects action capability that differs from OpenAPI', () => {
  assert.throws(
    () => buildActionManifest({
      pages: [{ name: 'system-users', path: '/system/users', requiredApis: ['GET /v1/users', 'POST /v1/users'] }],
      catalog: [{ code: 'iam.user.manage', anchorTypes: ['COMPANY'], fieldProfileMax: 'P3_RESTRICTED' }],
      openapi: { paths: { '/v1/users': { post: { 'x-capability': 'iam.user.read' } } } },
      actions: [{ pageCode: 'system-users', key: 'system-users.create', label: '新增用户', capability: 'iam.user.manage', api: 'POST /v1/users', ui: 'primary', confirm: 'none' }],
    }),
    /capability mismatch/,
  )
})
