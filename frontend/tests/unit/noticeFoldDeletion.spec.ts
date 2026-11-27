import { readdirSync, readFileSync, statSync } from 'node:fs'
import { dirname, join, relative, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { PAGE_REGISTRY } from '@/router/registry'
import { PAGE_COMPONENTS } from '@/modules/platform/pages'
import { PAGE_COMPONENTS as REPORTING_PAGE_COMPONENTS } from '@/modules/reporting/pages'

const FRONTEND_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../..')

const FOLD_PATHS = [
  '/notices/approval',
  '/notices/audience',
  '/notices/reads',
  '/notices/templates',
] as const

const FOLD_COMPONENT_KEYS = [
  'notices/approval',
  'notices/audience',
  'notices/reads',
  'notices/templates',
] as const

const FORBIDDEN_PAGE_FILES = [
  'NoticeApprovalPage.vue',
  'NoticeAudiencePage.vue',
  'NoticeReadsPage.vue',
  'NoticeTemplatesPage.vue',
] as const

function filesUnder(directory: string): string[] {
  const results: string[] = []
  for (const entry of readdirSync(directory)) {
    const path = join(directory, entry)
    const stats = statSync(path)
    if (stats.isDirectory()) {
      results.push(...filesUnder(path))
    } else {
      results.push(path)
    }
  }
  return results
}

describe('notice fold deletion', () => {
  it('removes folded notice routes and keeps notifications independent', () => {
    const paths = PAGE_REGISTRY.map((page) => page.path)
    for (const path of FOLD_PATHS) expect(paths).not.toContain(path)
    expect(paths).toContain('/notifications')
    expect(paths).toContain('/notices')
    expect(paths.filter((path) => path.startsWith('/notices/'))).toEqual([])
  })

  it('removes the four folded component keys and Vue files, with no redirects', () => {
    const platformKeys = Object.keys(PAGE_COMPONENTS)
    const reportingKeys = Object.keys(REPORTING_PAGE_COMPONENTS)
    for (const key of FOLD_COMPONENT_KEYS) {
      expect(platformKeys).not.toContain(key)
      expect(reportingKeys).not.toContain(key)
    }

    const platformPagesDirectory = resolve(FRONTEND_ROOT, 'src/modules/platform/pages')
    const names = filesUnder(platformPagesDirectory).map((path) => path.split(/[\\/]/).pop() ?? '')
    for (const file of FORBIDDEN_PAGE_FILES) expect(names).not.toContain(file)

    const routerSource = readFileSync(resolve(FRONTEND_ROOT, 'src/router/registry.ts'), 'utf8')
    for (const path of FOLD_PATHS) {
      expect(routerSource).not.toContain(`path: '${path}'`)
    }
    expect(routerSource).not.toContain('redirect')
  })

  it('leaves no stale fold references in frontend sources', () => {
    const sourceRoot = resolve(FRONTEND_ROOT, 'src')
    const pattern = /NoticeApprovalPage|NoticeAudiencePage|NoticeReadsPage|NoticeTemplatesPage|['"`]\/notices\/(approval|audience|reads|templates)(?=['"`/?])/
    const offenders = filesUnder(sourceRoot)
      .filter((path) => /\.(ts|vue)$/.test(path))
      .filter((path) => pattern.test(readFileSync(path, 'utf8')))
      .map((path) => relative(sourceRoot, path))

    expect(offenders).toEqual([])
  })
})
