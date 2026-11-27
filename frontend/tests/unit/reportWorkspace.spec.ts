import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

describe('M03 reports workspace convergence', () => {
  it('keeps exactly one /reports registry entry and removes the eight fold targets', () => {
    const registry = readFileSync('src/router/registry.ts', 'utf8')
    const subroutes = ['headcount', 'attendance', 'payroll', 'hiring', 'talent', 'builder', 'subscriptions', 'exports']

    expect(registry).toContain("path: '/reports'")
    for (const sub of subroutes) {
      expect(registry).not.toContain(`path: '/reports/${sub}'`)
    }
  })

  it('removes the legacy platform report page references from runtime source', () => {
    const files = [
      'src/modules/platform/pages/index.ts',
      'src/router/registry.ts',
    ]
    const legacy = [
      'ReportHeadcountPage',
      'ReportAttendancePage',
      'ReportPayrollPage',
      'ReportHiringPage',
      'ReportTalentPage',
      'ReportBuilderPage',
      'ReportSubscriptionsPage',
      'ReportExportsPage',
    ]

    for (const file of files) {
      const contents = readFileSync(file, 'utf8')
      for (const name of legacy) {
        expect(contents, `${file} must not reference ${name}`).not.toContain(name)
      }
      for (const sub of ['headcount', 'attendance', 'payroll', 'hiring', 'talent', 'builder', 'subscriptions', 'exports']) {
        expect(contents, `${file} must not reference reports/${sub}`).not.toContain(`reports/${sub}`)
      }
    }
  })

  it('renders a grouped report catalog and keeps the selected report in the query contract', () => {
    const page = readFileSync('src/modules/reporting/pages/ReportsPage.vue', 'utf8')
    const catalog = readFileSync('src/modules/reporting/components/ReportCatalog.vue', 'utf8')
    const api = readFileSync('src/modules/reporting/api.ts', 'utf8')

    expect(page).toContain('<ReportCatalog')
    expect(page).toContain("query.report = code")
    expect(catalog).toContain('reportDomainLabel')
    expect(catalog).toContain('definition.domain')
    // The contract forbids rendering the internal projection field; a source
    // comment explaining that rule is not a runtime field.
    expect(catalog).not.toMatch(/definition\.sourceProjection/)
    expect(catalog).not.toMatch(/definition\.source_projection/)
    expect(api).not.toContain('最小货币单位')
  })

  it('renders error and empty as mutually exclusive report states', () => {
    const page = readFileSync('src/modules/reporting/pages/ReportsPage.vue', 'utf8')

    expect(page).toContain('data-testid="report-error"')
    expect(page).toContain('data-testid="report-empty"')
    expect(page).toContain('v-if="runError"')
    expect(page).toContain('v-else-if="result"')
  })
})
