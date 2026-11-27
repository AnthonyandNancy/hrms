import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { PLATFORM_PAGE_CONFIGS, platformPageConfig } from '@/modules/platform/catalog'
import { PAGE_COMPONENTS } from '@/modules/platform/pages'
import { PAGE_REGISTRY } from '@/router/registry'

const ROOT = resolve(__dirname, '../..')

function platformRegistryKeys(): string[] {
  return PAGE_REGISTRY.filter((page) => page.layer === 'platform')
    .map((page) => page.componentKey)
    .sort()
}

function registryComponentKeys(): Set<string> {
  return new Set(PAGE_REGISTRY.map((page) => page.componentKey))
}

/** Read a page file relative to the frontend root, failing loudly if absent. */
function readPage(relativePath: string): string {
  return readFileSync(resolve(ROOT, relativePath), 'utf8')
}

function dispositionRows(): Array<{ entry: string; status: string; owner: string; pageFile: string }> {
  const source = readFileSync(resolve(ROOT, '../docs/audit/2026-11-26-platform-module-disposition.md'), 'utf8')
  const rows: Array<{ entry: string; status: string; owner: string; pageFile: string }> = []
  for (const line of source.split(/\r?\n/)) {
    if (!line.startsWith('| `')) continue
    const cells = line
      .split('|')
      .map((cell) => cell.trim())
      .filter((cell) => cell !== '')
    if (cells.length < 4) continue
    const entry = cells[0]?.replaceAll('`', '') ?? ''
    const pageFile = cells[1]?.replaceAll('`', '') ?? ''
    const status = cells[2] ?? ''
    const owner = cells[3] ?? ''
    if (entry === '' || pageFile === '') continue
    rows.push({ entry, pageFile, status, owner })
  }
  return rows
}

describe('platform module catalog', () => {
  it('is a unique, non-empty window over live registry keys', () => {
    const keys = PLATFORM_PAGE_CONFIGS.map((page) => page.key)
    expect(keys.length).toBeGreaterThan(0)
    expect(new Set(keys).size).toBe(keys.length)
    const known = registryComponentKeys()
    for (const key of keys) {
      expect(known.has(key), `${key} is not a registry componentKey`).toBe(true)
    }
    const platformOwned = keys.filter(
      (key) => PLATFORM_PAGE_CONFIGS.some((config) => config.key === key && config.module === 'platform'),
    )
    expect(platformOwned.sort()).toEqual(
      platformRegistryKeys().filter((key) => key !== 'workflow/cases/by-id'),
    )
  })

  it('does not resurrect entries whose page is gone from the registry', () => {
    const keys = new Set(PLATFORM_PAGE_CONFIGS.map((page) => page.key))
    // Volume 3 removed the notice editor/archive form routes (ADR-0035) and
    // moved the three reporting hubs into @/modules/reporting.
    for (const retired of [
      'notices/editor',
      'notices/archive',
      'platform-workspace',
      'dashboard/index',
      'reports/index',
      'notices/index',
    ]) {
      expect(keys.has(retired), retired).toBe(false)
    }
    expect(platformPageConfig('notices/editor')).toBeNull()
  })

  it('resolves every platform registry key to an existing page file', async () => {
    const platformKeys = platformRegistryKeys()
    expect(platformKeys).toHaveLength(15)
    for (const key of platformKeys) {
      const loader = PAGE_COMPONENTS[key as keyof typeof PAGE_COMPONENTS]
      expect(loader, `${key} has no platform page loader`).toBeTypeOf('function')
      const module = await loader()
      expect(module.default, `${key} loader returned no component`).toBeTruthy()
    }
    // The hidden workflow detail page is router-owned and has no settings config.
    expect(platformPageConfig('workflow/cases/by-id')).toBeNull()
  })

  it('has exactly one config per remaining platform-owned page', () => {
    const platformOwned = platformRegistryKeys().filter(
      (key) => PLATFORM_PAGE_CONFIGS.some((config) => config.key === key && config.module === 'platform'),
    )
    expect(platformOwned).toHaveLength(14)
  })

  it('declares only real page modes and never a mode the shared shell cannot render', () => {
    const shell = readPage('src/modules/platform/components/PlatformWorkspace.vue')
    const shellModes = new Set(
      [...shell.matchAll(/mode(?:\.value)?\s*===\s*'([^']+)'/g)].map((match) => match[1]),
    )
    for (const config of PLATFORM_PAGE_CONFIGS) {
      expect(shellModes.has(config.mode), `${config.key} -> ${config.mode}`).toBe(true)
    }
  })

  it('marks reporting pages with their final module and keeps platform configs in platform', () => {
    const owners = new Map(PLATFORM_PAGE_CONFIGS.map((config) => [config.key, config.module]))
    expect(owners.get('dashboard/departments')).toBe('reporting')
    expect(owners.get('reports/payroll')).toBe('reporting')
    expect(owners.get('notices/approval')).toBe('reporting')
    expect(owners.get('system/system-users')).toBe('platform')
    expect(owners.get('workflow')).toBe('platform')
    expect(owners.get('self-service')).toBe('platform')
    expect(owners.get('benefits')).toBe('platform')
  })

  it('lists exactly the platform-owned registry entries in the disposition document', () => {
    const documented = dispositionRows()
      .filter((row) => row.owner === 'platform')
      .map((row) => row.entry)
      .sort()
    expect(documented).toEqual(platformRegistryKeys())

    // Every catalogued platform-owned entry must be one of the 14 config-bearing
    // platform pages; the hidden workflow detail page intentionally has none.
    const catalogued = PLATFORM_PAGE_CONFIGS
      .filter((config) => config.module === 'platform')
      .map((config) => config.key)
      .sort()
    expect(catalogued).toEqual(platformRegistryKeys().filter((key) => key !== 'workflow/cases/by-id'))
  })

  it('documents every registry platform entry with an existing page file and a real status', () => {
    const rows = dispositionRows()
    const documented = new Set(rows.map((row) => row.entry))
    for (const key of platformRegistryKeys()) {
      expect(documented.has(key), key).toBe(true)
    }
    // 15 platform entries + 20 reporting-family keys that still route through
    // platform wrappers or moved hubs.
    expect(rows).toHaveLength(35)
    for (const row of rows) {
      expect(row.pageFile, `${row.entry} page file`).toMatch(/^frontend\/src\/modules\//)
      expect(readPage(row.pageFile.replace(/^frontend\//, '')).length, row.pageFile).toBeGreaterThan(0)
      expect(['dedicated', 'generic']).toContain(row.status)
      expect(['platform', 'reporting']).toContain(row.owner)
    }
  })

  it('has a platform README that states the boundary and the reporting exception', () => {
    const readme = readPage('src/modules/platform/README.md')
    expect(readme).toContain('IAM')
    expect(readme).toContain('modules/reporting')
    expect(readme).toContain('PlatformWorkspace')
  })
})
