import { spawnSync } from 'node:child_process'
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { afterEach, describe, expect, it } from 'vitest'
import {
  DEFAULT_SHARED_ALLOWLIST,
  auditPageComponents,
  discoverPageComponentManifest,
  parseRegistryComponentKeys,
} from '../../../scripts/audit_page_components_lib.mjs'

const frontendDir = resolve(dirname(fileURLToPath(import.meta.url)), '../..')
const repoRoot = resolve(frontendDir, '..')
const script = resolve(repoRoot, 'scripts/audit_page_components.mjs')
const accountSecurityView = 'frontend/src/views/AccountSecurityView.vue'
const temporaryDirectories: string[] = []

function tempDirectory(): string {
  const directory = mkdtempSync(resolve(tmpdir(), 'hrms-page-components-'))
  temporaryDirectories.push(directory)

  return directory
}

function auditFixture(overrides: Record<string, unknown> = {}) {
  return auditPageComponents({
    registryKeys: ['a', 'b'],
    manifest: { a: './pages/A.vue', b: './pages/B.vue' },
    existingFiles: new Set(['./pages/A.vue', './pages/B.vue']),
    sharedAllowlist: [],
    ...overrides,
  })
}

afterEach(() => {
  for (const directory of temporaryDirectories.splice(0)) {
    rmSync(directory, { recursive: true, force: true })
  }
})

describe('page component uniqueness audit', () => {
  it('passes when every registry key has its own existing .vue component', () => {
    const result = auditFixture()

    expect(result.ok).toBe(true)
    expect(result.failures).toEqual([])
    expect(result.missingMappings).toEqual([])
    expect(result.missingFiles).toEqual([])
  })

  it('fails when a registry key has no page component mapping', () => {
    const result = auditFixture({ manifest: { a: './pages/A.vue' } })

    expect(result.ok).toBe(false)
    expect(result.failures.join('\n')).toContain('b: no page component mapping')
    expect(result.missingMappings).toEqual(['b'])
  })

  it('fails when the mapped component file does not exist', () => {
    const result = auditFixture({ existingFiles: new Set(['./pages/A.vue']) })

    expect(result.ok).toBe(false)
    expect(result.failures.join('\n')).toContain('b: mapped file does not exist')
    expect(result.missingFiles).toEqual(['b'])
  })

  it('fails when a registry key maps to a non-Vue file', () => {
    const result = auditFixture({
      manifest: { a: './pages/A.ts', b: './pages/B.vue' },
      existingFiles: new Set(['./pages/A.ts', './pages/B.vue']),
    })

    expect(result.ok).toBe(false)
    expect(result.failures.join('\n')).toContain('must be a .vue file')
  })

  it('fails when the registry declares a duplicate component key', () => {
    const result = auditFixture({ registryKeys: ['a', 'a'] })

    expect(result.ok).toBe(false)
    expect(result.failures.join('\n')).toContain('duplicate registry componentKeys: a')
  })

  it('fails when the registry no longer has the pinned component count', () => {
    const result = auditFixture({ expectedRegistryCount: 160 })

    expect(result.ok).toBe(false)
    expect(result.failures.join('\n')).toContain('registry componentKeys=2, expected 160')
  })

  it('fails when a manifest key is not declared by the registry', () => {
    const result = auditFixture({
      manifest: {
        a: './pages/A.vue',
        b: './pages/B.vue',
        stale: './pages/B.vue',
      },
    })

    expect(result.ok).toBe(false)
    expect(result.failures.join('\n')).toContain('stale: manifest key is not in the registry')
    expect(result.staleManifestKeys).toEqual(['stale'])
  })

  it('fails when two non-allowlisted page codes share a component', () => {
    const result = auditFixture({
      manifest: {
        a: './pages/A.vue',
        b: './pages/A.vue',
      },
      existingFiles: new Set(['./pages/A.vue']),
    })

    expect(result.ok).toBe(false)
    expect(result.failures.join('\n')).toContain('shared component used by a, b')
  })

  it('allows only the documented AccountSecurityView base-page pair', () => {
    const result = auditPageComponents({
      registryKeys: ['base/AccountMfaView', 'base/AccountSessionsView'],
      manifest: {
        'base/AccountMfaView': accountSecurityView,
        'base/AccountSessionsView': accountSecurityView,
      },
      existingFiles: new Set([accountSecurityView]),
      sharedAllowlist: DEFAULT_SHARED_ALLOWLIST,
      expectedRegistryCount: 2,
    })

    expect(result.ok).toBe(true)
    expect(result.allowedSharedGroups).toHaveLength(1)
    expect(DEFAULT_SHARED_ALLOWLIST[0]?.file).toBe(accountSecurityView)
    expect(DEFAULT_SHARED_ALLOWLIST[0]?.reason).toMatch(/MFA/)
  })

  it('does not let a third page join the allowlisted shared component', () => {
    const result = auditPageComponents({
      registryKeys: [
        'base/AccountMfaView',
        'base/AccountSessionsView',
        'base/AccountExtraView',
      ],
      manifest: {
        'base/AccountMfaView': accountSecurityView,
        'base/AccountSessionsView': accountSecurityView,
        'base/AccountExtraView': accountSecurityView,
      },
      existingFiles: new Set([accountSecurityView]),
      sharedAllowlist: DEFAULT_SHARED_ALLOWLIST,
      expectedRegistryCount: 3,
    })

    expect(result.ok).toBe(false)
    expect(result.failures.join('\n')).toContain(
      'shared component used by base/AccountMfaView',
    )
  })

  it('fails closed when a shared allowlist entry has no documented reason', () => {
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

    expect(result.ok).toBe(false)
    expect(result.failures.join('\n')).toContain('must document a reason')
  })

  it('does not let the allowlisted pair share a different component file', () => {
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

    expect(result.ok).toBe(false)
    expect(result.failures.join('\n')).toContain('shared component used by base/AccountMfaView')
  })
})

describe('registry and module manifest discovery', () => {
  it('parses double-quoted component keys and ignores comments', () => {
    const parsed = parseRegistryComponentKeys(`
      // componentKey: 'not/a/page'
      export const PAGE_REGISTRY: PageSpec[] = [
        { path: '/a', "componentKey": 'a/page', name: 'a' },
        { path: '/b', componentKey: "b/page", name: 'b' },
      ]
    `)

    expect(parsed.errors).toEqual([])
    expect(parsed.keys).toEqual(['a/page', 'b/page'])
  })

  it('discovers double-quoted module loaders and only base-* base views', () => {
    const root = tempDirectory()
    const pagesDir = resolve(root, 'frontend/src/modules/people/pages')
    const viewsDir = resolve(root, 'frontend/src/views')
    const routerDir = resolve(root, 'frontend/src/router')
    mkdirSync(pagesDir, { recursive: true })
    mkdirSync(viewsDir, { recursive: true })
    mkdirSync(routerDir, { recursive: true })

    writeFileSync(
      resolve(pagesDir, 'index.ts'),
      [
        'export const PAGE_COMPONENTS = {',
        '  "hr/employees/index": () => import("./EmployeeListPage.vue"),',
        '} as const',
      ].join('\n'),
    )
    writeFileSync(resolve(pagesDir, 'EmployeeListPage.vue'), '<template><div /></template>')
    writeFileSync(resolve(viewsDir, 'LoginView.vue'), '<template><div /></template>')
    writeFileSync(resolve(viewsDir, 'AccountSecurityView.vue'), '<template><div /></template>')
    writeFileSync(
      resolve(routerDir, 'pageComponents.ts'),
      [
        'const BASE_VIEWS: Record<string, Loader> = {',
        '  "base/LoginView": () => import("@/views/LoginView.vue"),',
        '  "base/AccountMfaView": () => import("@/views/AccountSecurityView.vue"),',
        '  "dashboard/index": () => import("@/views/DashboardView.vue"),',
        '}',
      ].join('\n'),
    )

    const discovered = discoverPageComponentManifest({ root })

    expect(discovered.errors).toEqual([])
    expect(discovered.manifest['hr/employees/index']).toBe(
      'frontend/src/modules/people/pages/EmployeeListPage.vue',
    )
    expect(discovered.manifest['base/LoginView']).toBe('frontend/src/views/LoginView.vue')
    expect(discovered.manifest['base/AccountMfaView']).toBe(accountSecurityView)
    expect(discovered.manifest['dashboard/index']).toBeUndefined()
    expect(
      discovered.existingFiles.has('frontend/src/modules/people/pages/EmployeeListPage.vue'),
    ).toBe(true)
    expect(discovered.existingFiles.has('frontend/src/views/LoginView.vue')).toBe(true)
  })
})

describe('real repository page component gate', () => {
  it('passes with 161 registry keys mapped to 155 module pages plus 6 base pages', () => {
    const result = spawnSync(process.execPath, [script], {
      cwd: frontendDir,
      encoding: 'utf8',
      env: { ...process.env, NO_COLOR: '1' },
    })

    expect(result.status, `${result.stdout}
${result.stderr}`).toBe(0)

    const report = JSON.parse(result.stdout) as {
      ok: boolean
      total: number
      mapped: number
      moduleMapped: number
      baseMapped: number
      mappedIncludingBase: number
      expected: number
      failures: string[]
      missingMappings: string[]
      missingFiles: string[]
      staleManifestKeys: string[]
    }

    expect(report.ok).toBe(true)
    expect(report.total).toBe(161)
    expect(report.expected).toBe(161)
    expect(report.mapped).toBe(155)
    expect(report.moduleMapped).toBe(155)
    expect(report.baseMapped).toBe(6)
    expect(report.mappedIncludingBase).toBe(161)
    expect(report.failures).toEqual([])
    expect(report.missingMappings).toEqual([])
    expect(report.missingFiles).toEqual([])
    expect(report.staleManifestKeys).toEqual([])
  })
})
