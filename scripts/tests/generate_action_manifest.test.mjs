import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
  buildActionManifest,
  validateActionManifest,
  validateCapabilityGaps,
  validateWorkbenchCoverage,
} from '../generate_action_manifest.mjs'

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

test('accepts an action whose declared service class and method exist', () => {
  const serviceIndex = new Map([['RolePageService', { methods: new Set(['replace']) }]])
  const manifest = buildActionManifest({
    pages: [{ name: 'system-roles', path: '/system/roles', requiredApis: ['PUT /v1/roles/{id}/pages'] }],
    catalog: [{ code: 'iam.role.manage', anchorTypes: ['COMPANY'], fieldProfileMax: 'P2_SENSITIVE' }],
    openapi: { paths: { '/v1/roles/{id}/pages': { put: { 'x-capability': 'iam.role.manage', 'x-action-label': '保存角色页面权限' } } } },
    actions: [{
      pageCode: 'system-roles',
      key: 'system.roles.save.pages',
      label: '保存页面授权',
      capability: 'iam.role.manage',
      api: 'PUT /v1/roles/{id}/pages',
      ui: 'primary',
      confirm: 'none',
      services: ['RolePageService::replace'],
    }],
    serviceIndex,
  })
  assert.deepEqual(manifest.pages['system-roles'][0].services, ['RolePageService::replace'])
})

test('rejects an action whose declared service class is absent', () => {
  assert.throws(
    () => buildActionManifest({
      pages: [{ name: 'system-roles', path: '/system/roles', requiredApis: ['PUT /v1/roles/{id}/pages'] }],
      catalog: [{ code: 'iam.role.manage', anchorTypes: ['COMPANY'], fieldProfileMax: 'P2_SENSITIVE' }],
      openapi: { paths: { '/v1/roles/{id}/pages': { put: { 'x-capability': 'iam.role.manage', 'x-action-label': '保存角色页面权限' } } } },
      actions: [{
        pageCode: 'system-roles',
        key: 'system.roles.save.pages',
        label: '保存页面授权',
        capability: 'iam.role.manage',
        api: 'PUT /v1/roles/{id}/pages',
        ui: 'primary',
        confirm: 'none',
        services: ['MissingPageService::save'],
      }],
      serviceIndex: new Map([['RolePageService', { methods: new Set(['replace']) }]]),
    }),
    /unknown service class MissingPageService/,
  )
})

test('rejects an action whose declared service method is absent', () => {
  assert.throws(
    () => buildActionManifest({
      pages: [{ name: 'system-roles', path: '/system/roles', requiredApis: ['PUT /v1/roles/{id}/pages'] }],
      catalog: [{ code: 'iam.role.manage', anchorTypes: ['COMPANY'], fieldProfileMax: 'P2_SENSITIVE' }],
      openapi: { paths: { '/v1/roles/{id}/pages': { put: { 'x-capability': 'iam.role.manage', 'x-action-label': '保存角色页面权限' } } } },
      actions: [{
        pageCode: 'system-roles',
        key: 'system.roles.save.pages',
        label: '保存页面授权',
        capability: 'iam.role.manage',
        api: 'PUT /v1/roles/{id}/pages',
        ui: 'primary',
        confirm: 'none',
        services: ['RolePageService::save'],
      }],
      serviceIndex: new Map([['RolePageService', { methods: new Set(['replace']) }]]),
    }),
    /RolePageService has no method save/,
  )
})

test('buildServiceIndex reads class names and public methods from PHP files', async () => {
  const { mkdtempSync, mkdirSync, writeFileSync, rmSync } = await import('node:fs')
  const { tmpdir } = await import('node:os')
  const { join } = await import('node:path')
  const root = mkdtempSync(join(tmpdir(), 'page-action-services-'))
  try {
    mkdirSync(join(root, 'Services'), { recursive: true })
    writeFileSync(join(root, 'Services', 'RolePageService.php'), `<?php
namespace App\Modules\IAM\Services;
final class RolePageService
{
    public function replace(string $roleCode, array $pages): array { return []; }
    private function hidden(): void {}
}
`, 'utf8')
    const { buildServiceIndex } = await import('../generate_action_manifest.mjs')
    const index = buildServiceIndex(root)
    assert.ok(index.get('RolePageService')?.methods.has('replace'))
    assert.equal(index.get('RolePageService')?.methods.has('hidden'), false)
    assert.ok([...index.keys()].some((key) => key.endsWith('RolePageService') && key.includes('Services') && index.get(key)?.methods.has('replace')))
  } finally {
    rmSync(root, { recursive: true, force: true })
  }
})

test('capability gap validation requires an exact owner and a one-line reason', () => {
  const result = validateCapabilityGaps({
    capabilityGaps: [{ capability: 'people.employee.export', pages: ['hr-employees'], reason: 'API absent.' }],
    pages: [{ name: 'hr-employees' }],
    catalog: ['people.employee.export'],
    openapi: { paths: {} },
  })
  assert.equal(result.ok, false)
  assert.match(result.errors.join('\n'), /people\.employee\.export has invalid owner/)
})

test('capability gap validation rejects a stale gap once OpenAPI carries the capability', () => {
  const result = validateCapabilityGaps({
    capabilityGaps: [{
      capability: 'people.employee.export',
      pages: ['hr-employees'],
      owner: 'Volume 3 Task 4',
      reason: 'Employee export operation is absent.',
    }],
    pages: [{ name: 'hr-employees' }],
    catalog: ['people.employee.export'],
    openapi: { paths: { '/v1/employees/export': { get: { 'x-capability': 'people.employee.export' } } } },
  })
  assert.equal(result.ok, false)
  assert.match(result.errors.join('\n'), /stale capability gap people\.employee\.export.*GET \/v1\/employees\/export/)
})

test('capability gap validation keeps an owned gap when the API is still missing', () => {
  const result = validateCapabilityGaps({
    capabilityGaps: [{
      capability: 'people.employee.export',
      pages: ['hr-employees'],
      owner: 'Volume 3 Task 4',
      reason: 'Employee export operation is absent.',
    }],
    pages: [{ name: 'hr-employees' }],
    catalog: ['people.employee.export'],
    openapi: { paths: {} },
  })
  assert.equal(result.ok, true, result.errors.join('\n'))
  assert.deepEqual(result.retained.map((gap) => gap.capability), ['people.employee.export'])
  assert.deepEqual(result.resolved, [])
})

test('capability gap validation rejects a capability that is not in CapabilityCatalog', () => {
  const result = validateCapabilityGaps({
    capabilityGaps: [{
      capability: 'people.employee.invent',
      pages: ['hr-employees'],
      owner: 'Volume 3 Task 4',
      reason: 'Invented capability.',
    }],
    pages: [{ name: 'hr-employees' }],
    catalog: ['people.employee.export'],
    openapi: { paths: {} },
  })
  assert.equal(result.ok, false)
  assert.match(result.errors.join('\n'), /gap people\.employee\.invent is not in CapabilityCatalog/)
})

test('capability gap validation rejects a page that does not declare the capability', () => {
  const result = validateCapabilityGaps({
    capabilityGaps: [{
      capability: 'people.employee.export',
      pages: ['hr-employees'],
      owner: 'Volume 3 Task 4',
      reason: 'Export absent.',
    }],
    pages: [{ name: 'hr-employees' }],
    catalog: ['people.employee.export'],
    permissionPages: [{ page_code: 'hr-employees', actions: ['people.employee.read'] }],
    openapi: { paths: {} },
  })
  assert.equal(result.ok, false)
  assert.match(result.errors.join('\n'), /gap people\.employee\.export is not declared by page hr-employees/)
})

test('capability gap validation rejects an unknown page and duplicate capability gap', () => {
  const result = validateCapabilityGaps({
    capabilityGaps: [
      { capability: 'people.employee.export', pages: ['missing-page'], owner: 'Volume 3 Task 4', reason: 'Absent.' },
      { capability: 'people.employee.export', pages: ['hr-employees'], owner: 'Volume 3 Task 4', reason: 'Absent.' },
    ],
    pages: [{ name: 'hr-employees' }],
    catalog: ['people.employee.export'],
    openapi: { paths: {} },
  })
  assert.equal(result.ok, false)
  assert.match(result.errors.join('\n'), /gap people.employee.export references unknown page missing-page/)
  assert.match(result.errors.join('\n'), /duplicate capability gap people\.employee\.export/)
})

test('workbench coverage reports missing registry pages as explicit skips', () => {
  const result = validateWorkbenchCoverage({
    pages: [],
    manifest: { version: 1, pages: {} },
    serviceIndex: new Map(),
  })
  assert.equal(result.ok, true, result.errors.join('\n'))
  assert.equal(result.skipped.length, Object.keys({
    'system-users': 1, 'system-roles': 1, 'system-menus': 1, 'system-permissions': 1, 'system-data-scopes': 1, 'system-field-policies': 1,
  }).length)
  assert.ok(result.skipped.every((skip) => skip.owner.startsWith('Volume 2 Task')))
})

test('workbench coverage accepts a read-only page with no actions and no write APIs', () => {
  const result = validateWorkbenchCoverage({
    pages: [{ name: 'system-permissions', requiredApis: ['GET /v1/capabilities'] }],
    manifest: { version: 1, pages: {} },
    serviceIndex: new Map(),
  })
  assert.equal(result.ok, true, result.errors.join('\n'))
})

test('workbench coverage rejects a read-only page that declares a write action', () => {
  const result = validateWorkbenchCoverage({
    pages: [{ name: 'system-permissions', requiredApis: ['GET /v1/capabilities', 'PUT /v1/capabilities'] }],
    manifest: {
      version: 1,
      pages: { 'system-permissions': [{ key: 'system.permissions.save', api: 'PUT /v1/capabilities' }] },
    },
    serviceIndex: new Map(),
  })
  assert.equal(result.ok, false)
  assert.match(result.errors.join('\n'), /system-permissions is read-only but declares 1 action/)
  assert.match(result.errors.join('\n'), /system-permissions is read-only but declares write API PUT \/v1\/capabilities/)
})

test('workbench coverage rejects a writable page with write APIs but no actions', () => {
  const result = validateWorkbenchCoverage({
    pages: [{ name: 'system-menus', requiredApis: ['GET /v1/system/menus', 'POST /v1/system/menus'] }],
    manifest: { version: 1, pages: {} },
    serviceIndex: new Map([['MenuOverrideService', { methods: new Set(['create']) }]]),
  })
  assert.equal(result.ok, false)
  assert.match(result.errors.join('\n'), /system-menus is writable but has no action/)
})

test('workbench coverage skips a writable page whose write APIs have not landed yet', () => {
  const result = validateWorkbenchCoverage({
    pages: [{ name: 'system-data-scopes', requiredApis: ['GET /v1/me/permissions'] }],
    manifest: { version: 1, pages: {} },
    serviceIndex: new Map(),
  })
  assert.equal(result.ok, true, result.errors.join('\n'))
  assert.ok(result.skipped.some((skip) => skip.pageCode === 'system-data-scopes'
    && skip.owner === 'Volume 2 Task 8'
    && /no declared write API/.test(skip.reason)))
})

test('workbench coverage requires every action on a governance page to declare existing services', () => {
  const page = {
    name: 'system-menus',
    requiredApis: ['GET /v1/system/menus', 'POST /v1/system/menus'],
  }
  const manifest = {
    version: 1,
    pages: {
      'system-menus': [{
        key: 'system.menus.create',
        pageCode: 'system-menus',
        capability: 'iam.menu.manage',
        api: 'POST /v1/system/menus',
        services: ['MissingService::create'],
      }],
    },
  }
  const missingService = validateWorkbenchCoverage({
    pages: [page],
    manifest,
    serviceIndex: new Map([['MenuOverrideService', { methods: new Set(['create']) }]]),
  })
  assert.equal(missingService.ok, false)
  assert.match(missingService.errors.join('\n'), /unknown service class MissingService/)

  const missingDeclaration = validateWorkbenchCoverage({
    pages: [page],
    manifest: { version: 1, pages: { 'system-menus': [{ ...manifest.pages['system-menus'][0], services: undefined }] } },
    serviceIndex: new Map([['MenuOverrideService', { methods: new Set(['create']) }]]),
  })
  assert.equal(missingDeclaration.ok, false)
  assert.match(missingDeclaration.errors.join('\n'), /system-menus\/system\.menus\.create: must declare at least one backend service/)

  const valid = validateWorkbenchCoverage({
    pages: [page],
    manifest: { version: 1, pages: { 'system-menus': [{ ...manifest.pages['system-menus'][0], services: ['MenuOverrideService::create'] }] } },
    serviceIndex: new Map([['MenuOverrideService', { methods: new Set(['create']) }]]),
  })
  assert.equal(valid.ok, true, valid.errors.join('\n'))
})

test('allows one real API to be represented by one action on each of two pages', () => {
  const manifest = buildActionManifest({
    pages: [
      { name: 'system-roles', path: '/system/roles', requiredApis: ['PUT /v1/system/data-scopes/{id}'] },
      { name: 'system-data-scopes', path: '/system/data-scopes', requiredApis: ['PUT /v1/system/data-scopes/{id}'] },
    ],
    catalog: [{ code: 'iam.scope.manage', anchorTypes: ['COMPANY'], fieldProfileMax: 'P1_INTERNAL' }],
    openapi: { paths: { '/v1/system/data-scopes/{id}': { put: { 'x-capability': 'iam.scope.manage', 'x-action-label': '保存数据范围' } } } },
    actions: [
      { pageCode: 'system-roles', key: 'system.roles.save.scope', label: '保存数据范围', capability: 'iam.scope.manage', api: 'PUT /v1/system/data-scopes/{id}', ui: 'primary', confirm: 'none' },
      { pageCode: 'system-data-scopes', key: 'system.data.scopes.save', label: '保存数据范围', capability: 'iam.scope.manage', api: 'PUT /v1/system/data-scopes/{id}', ui: 'primary', confirm: 'none' },
    ],
  })
  assert.equal(manifest.pages['system-roles'].length, 1)
  assert.equal(manifest.pages['system-data-scopes'].length, 1)
})

test('rejects two actions for the same API on one page', () => {
  assert.throws(
    () => buildActionManifest({
      pages: [{ name: 'system-users', path: '/system/users', requiredApis: ['POST /v1/users'] }],
      catalog: [{ code: 'iam.user.manage', anchorTypes: ['COMPANY'], fieldProfileMax: 'P2_SENSITIVE' }],
      openapi: { paths: { '/v1/users': { post: { 'x-capability': 'iam.user.manage', 'x-action-label': '新增用户' } } } },
      actions: [
        { pageCode: 'system-users', key: 'system.users.create', label: '新增用户', capability: 'iam.user.manage', api: 'POST /v1/users', ui: 'primary', confirm: 'none' },
        { pageCode: 'system-users', key: 'system.users.create.duplicate', label: '新增用户', capability: 'iam.user.manage', api: 'POST /v1/users', ui: 'primary', confirm: 'none' },
      ],
    }),
    /duplicate action API POST \/v1\/users on page system-users/,
  )
})

test('validate reports two actions for the same API on one page', () => {
  const result = validateActionManifest({
    pages: [{ name: 'system-users', requiredApis: ['POST /v1/users'] }],
    manifest: {
      version: 1,
      pages: {
        'system-users': [
          { key: 'system.users.create', api: 'POST /v1/users', capability: 'iam.user.manage' },
          { key: 'system.users.create.duplicate', api: 'POST /v1/users', capability: 'iam.user.manage' },
        ],
      },
    },
    openapi: { paths: { '/v1/users': { post: { 'x-capability': 'iam.user.manage' } } } },
    gaps: [],
  })
  assert.equal(result.ok, false)
  assert.match(result.errors.join('\n'), /POST \/v1\/users has 2 actions on system-users; exactly one per page is required/)
})

test('a gap cannot exempt a write API that a page now declares', () => {
  const result = validateActionManifest({
    pages: [{ name: 'system-menus', requiredApis: ['GET /v1/system/menus', 'POST /v1/system/menus'] }],
    manifest: { version: 1, pages: {} },
    openapi: { paths: { '/v1/system/menus': { post: { 'x-capability': 'iam.menu.manage' } } } },
    gaps: [{ api: 'POST /v1/system/menus', owner: 'Volume 2 Task 8', reason: 'Was a gap.' }],
  })
  assert.equal(result.ok, false)
  assert.match(result.errors.join('\n'), /system-menus: write API POST \/v1\/system\/menus has no action/)
  assert.match(result.errors.join('\n'), /gap POST \/v1\/system\/menus is declared by page system-menus; it must have an action/)
})

test('validateActionManifest reports service problems for a manifest action', () => {
  const result = validateActionManifest({
    pages: [{ name: 'system-field-policies', requiredApis: ['PUT /v1/system/field-policies/{field_key}'] }],
    manifest: {
      version: 1,
      pages: {
        'system-field-policies': [{
          key: 'system.field.policies.save',
          api: 'PUT /v1/system/field-policies/{field_key}',
          capability: 'iam.field.manage',
          services: ['FieldPolicyService::delete'],
        }],
      },
    },
    openapi: { paths: { '/v1/system/field-policies/{field_key}': { put: { 'x-capability': 'iam.field.manage', 'x-action-label': '保存字段策略' } } } },
    gaps: [],
    serviceIndex: new Map([['FieldPolicyService', { methods: new Set(['update']) }]]),
  })
  assert.equal(result.ok, false)
  assert.match(result.errors.join('\n'), /FieldPolicyService has no method delete/)
})

test('validateActionManifest requires services on the newly added workbench pages', () => {
  const result = validateActionManifest({
    pages: [{ name: 'system-data-scopes', requiredApis: ['PUT /v1/system/data-scopes/{id}'] }],
    manifest: {
      version: 1,
      pages: {
        'system-data-scopes': [{
          key: 'system.data.scopes.save',
          api: 'PUT /v1/system/data-scopes/{id}',
          capability: 'iam.scope.manage',
        }],
      },
    },
    openapi: { paths: { '/v1/system/data-scopes/{id}': { put: { 'x-capability': 'iam.scope.manage', 'x-action-label': '保存数据范围' } } } },
    gaps: [],
    serviceIndex: new Map([['DataScopePolicyService', { methods: new Set(['upsert']) }]]),
  })
  assert.equal(result.ok, false)
  assert.match(result.errors.join('\n'), /system-data-scopes\/system\.data\.scopes\.save: must declare at least one backend service/)
})
