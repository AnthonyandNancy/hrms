import assert from 'node:assert/strict'
import { mkdtempSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import test from 'node:test'
import {
  DEFAULT_SHARED_ALLOWLIST,
  auditPageComponents,
  discoverPageComponentManifest,
  parseRegistryComponentKeys,
} from '../audit_page_components_lib.mjs'

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../..')
const accountSecurityView = 'frontend/src/views/AccountSecurityView.vue'

function auditFixture(overrides = {}) {
  return auditPageComponents({
    registryKeys: ['a', 'b'],
    manifest: { a: './pages/A.vue', b: './pages/B.vue' },
    existingFiles: new Set(['./pages/A.vue', './pages/B.vue']),
    sharedAllowlist: [],
    ...overrides,
  })
}

test('fails when two page codes point to the same component', () => {
  const result = auditFixture({
    manifest: {
      a: './pages/EmployeeListPage.vue',
      b: './pages/EmployeeListPage.vue',
    },
    existingFiles: new Set(['./pages/EmployeeListPage.vue']),
  })

  assert.equal(result.ok, false)
  assert.match(result.failures.join('\n'), /shared component/)
})

test('passes when each key has its own file', () => {
  const result = auditFixture()

  assert.equal(result.ok, true)
  assert.deepEqual(result.failures, [])
})

test('fails when a registry key has no component mapping', () => {
  const result = auditFixture({ manifest: { a: './pages/A.vue' } })

  assert.equal(result.ok, false)
  assert.match(result.failures.join('\n'), /b: no page component mapping/)
  assert.deepEqual(result.missingMappings, ['b'])
})

test('fails when a mapped component file does not exist', () => {
  const result = auditFixture({ existingFiles: new Set(['./pages/A.vue']) })

  assert.equal(result.ok, false)
  assert.match(result.failures.join('\n'), /b: mapped file does not exist/)
  assert.deepEqual(result.missingFiles, ['b'])
})

test('fails when a registry key maps to a non-Vue file', () => {
  const result = auditFixture({
    manifest: { a: './pages/A.ts', b: './pages/B.vue' },
    existingFiles: new Set(['./pages/A.ts', './pages/B.vue']),
  })

  assert.equal(result.ok, false)
  assert.match(result.failures.join('\n'), /must be a \.vue file/)
})

test('fails when the registry no longer has the pinned 160 component keys', () => {
  const result = auditFixture({ expectedRegistryCount: 160 })

  assert.equal(result.ok, false)
  assert.match(result.failures.join('\n'), /registry componentKeys=2, expected 160/)
})

test('fails when the registry declares a duplicate component key', () => {
  const result = auditPageComponents({
    registryKeys: ['a', 'a'],
    manifest: { a: './pages/A.vue' },
    existingFiles: new Set(['./pages/A.vue']),
    sharedAllowlist: [],
  })

  assert.equal(result.ok, false)
  assert.match(result.failures.join('\n'), /duplicate registry componentKeys: a/)
})

test('fails when a manifest key is not declared by the registry', () => {
  const result = auditFixture({ manifest: { a: './pages/A.vue', b: './pages/B.vue', stale: './pages/B.vue' } })

  assert.equal(result.ok, false)
  assert.match(result.failures.join('\n'), /stale: manifest key is not in the registry/)
})

test('allows only the documented AccountSecurityView base-page pair', () => {
  const result = auditPageComponents({
    registryKeys: ['base/AccountMfaView', 'base/AccountSessionsView'],
    manifest: {
      'base/AccountMfaView': accountSecurityView,
      'base/AccountSessionsView': accountSecurityView,
    },
    existingFiles: new Set([accountSecurityView]),
    expectedRegistryCount: 2,
  })

  assert.equal(result.ok, true)
  assert.equal(DEFAULT_SHARED_ALLOWLIST.length, 1)
  assert.deepEqual(DEFAULT_SHARED_ALLOWLIST[0].keys, [
    'base/AccountMfaView',
    'base/AccountSessionsView',
  ])
  assert.equal(DEFAULT_SHARED_ALLOWLIST[0].file, accountSecurityView)
  assert.match(DEFAULT_SHARED_ALLOWLIST[0].reason, /MFA/)
  assert.equal(result.allowedSharedGroups.length, 1)
})

test('does not let the allowlisted pair share any other component file', () => {
  const otherFile = 'frontend/src/views/OtherAccountView.vue'
  const result = auditPageComponents({
    registryKeys: ['base/AccountMfaView', 'base/AccountSessionsView'],
    manifest: {
      'base/AccountMfaView': otherFile,
      'base/AccountSessionsView': otherFile,
    },
    existingFiles: new Set([otherFile]),
    sharedAllowlist: DEFAULT_SHARED_ALLOWLIST,
    expectedRegistryCount: 2,
  })

  assert.equal(result.ok, false)
  assert.match(result.failures.join('\n'), /shared component used by base\/AccountMfaView/)
})

test('does not let a third page join the allowlisted shared component', () => {
  const result = auditPageComponents({
    registryKeys: ['base/AccountMfaView', 'base/AccountSessionsView', 'base/AccountExtraView'],
    manifest: {
      'base/AccountMfaView': accountSecurityView,
      'base/AccountSessionsView': accountSecurityView,
      'base/AccountExtraView': accountSecurityView,
    },
    existingFiles: new Set([accountSecurityView]),
    sharedAllowlist: DEFAULT_SHARED_ALLOWLIST,
    expectedRegistryCount: 3,
  })

  assert.equal(result.ok, false)
  assert.match(result.failures.join('\n'), /shared component used by base\/AccountMfaView/)
})

test('fails closed when an allowlisted shared group has no documented reason', () => {
  const result = auditPageComponents({
    registryKeys: ['base/AccountMfaView', 'base/AccountSessionsView'],
    manifest: {
      'base/AccountMfaView': accountSecurityView,
      'base/AccountSessionsView': accountSecurityView,
    },
    existingFiles: new Set([accountSecurityView]),
    sharedAllowlist: [
      {
        keys: ['base/AccountMfaView', 'base/AccountSessionsView'],
        file: accountSecurityView,
        reason: '',
      },
    ],
    expectedRegistryCount: 2,
  })

  assert.equal(result.ok, false)
  assert.match(result.failures.join('\n'), /must document a reason/)
})

test('parses the actual registry component keys and pins 161 unique keys', () => {
  const source = readFileSync(join(repoRoot, 'frontend/src/router/registry.ts'), 'utf8')
  const parsed = parseRegistryComponentKeys(source)

  assert.deepEqual(parsed.errors, [])
  assert.equal(parsed.keys.length, 161)
  assert.equal(new Set(parsed.keys).size, 161)
  assert.ok(parsed.keys.includes('hr/employees/index'))
  assert.ok(parsed.keys.includes('base/AccountMfaView'))
})

test('parses double-quoted registry entries and ignores comments', () => {
  const parsed = parseRegistryComponentKeys(`
    // componentKey: 'not/a/page'
    export const PAGE_REGISTRY: PageSpec[] = [
      { path: '/a', "componentKey": 'a/page', name: 'a' },
      { path: '/b', componentKey: "b/page", name: 'b' },
    ]
  `)

  assert.deepEqual(parsed.errors, [])
  assert.deepEqual(parsed.keys, ['a/page', 'b/page'])
})

test('fails closed when PAGE_REGISTRY has a non-string componentKey', () => {
  const parsed = parseRegistryComponentKeys(`
    export const PAGE_REGISTRY: PageSpec[] = [
      { componentKey: 42 },
    ]
  `)

  assert.equal(parsed.keys.length, 0)
  assert.match(parsed.errors.join('\n'), /componentKey/)
})

test('discovers module manifests and base views as repo-relative component paths', () => {
  const fixtureRoot = mkdtempSync(join(tmpdir(), 'hrms-page-components-'))

  try {
    const pagesDir = join(fixtureRoot, 'frontend/src/modules/people/pages')
    const viewsDir = join(fixtureRoot, 'frontend/src/views')
    const routerDir = join(fixtureRoot, 'frontend/src/router')
    mkdirSync(pagesDir, { recursive: true })
    mkdirSync(viewsDir, { recursive: true })
    mkdirSync(routerDir, { recursive: true })

    writeFileSync(
      join(pagesDir, 'index.ts'),
      [
        'export const PAGE_COMPONENTS = {',
        "  'hr/employees/index': () => import('./EmployeeListPage.vue'),",
        '} as const',
      ].join('\n'),
    )
    writeFileSync(join(pagesDir, 'EmployeeListPage.vue'), '<template><div /></template>')
    writeFileSync(join(viewsDir, 'LoginView.vue'), '<template><div /></template>')
    writeFileSync(
      join(routerDir, 'pageComponents.ts'),
      [
        'const BASE_VIEWS: Record<string, Loader> = {',
        "  'base/LoginView': () => import('@/views/LoginView.vue'),",
        "  'dashboard/index': () => import('@/views/DashboardView.vue'),",
        '}',
      ].join('\n'),
    )

    const discovered = discoverPageComponentManifest({ root: fixtureRoot })

    assert.deepEqual(discovered.errors, [])
    assert.equal(
      discovered.manifest['hr/employees/index'],
      'frontend/src/modules/people/pages/EmployeeListPage.vue',
    )
    assert.equal(discovered.manifest['base/LoginView'], 'frontend/src/views/LoginView.vue')
    assert.equal(discovered.manifest['dashboard/index'], undefined)
    assert.ok(
      discovered.existingFiles.has('frontend/src/modules/people/pages/EmployeeListPage.vue'),
    )
    assert.ok(discovered.existingFiles.has('frontend/src/views/LoginView.vue'))
  } finally {
    rmSync(fixtureRoot, { recursive: true, force: true })
  }
})
