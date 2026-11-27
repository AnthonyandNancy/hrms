import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import ElementPlus from 'element-plus'
import { createMemoryHistory, createRouter } from 'vue-router'
import { ApiError } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import type { ReportDefinition, ReportResult } from '@/modules/reporting/api'
import { reportCellText } from '@/modules/reporting/api'
import ReportsPage from '@/modules/reporting/pages/ReportsPage.vue'

const resource = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  patch: vi.fn(),
  put: vi.fn(),
  delete: vi.fn(),
}))

vi.mock('@/api/resourceClient', () => ({
  resourceGet: resource.get,
  resourcePost: resource.post,
  resourcePatch: resource.patch,
  resourcePut: resource.put,
  resourceDelete: resource.delete,
}))

function definition(overrides: Partial<ReportDefinition> = {}): ReportDefinition {
  return {
    id: 1,
    code: 'PAYROLL_COST',
    name: '工资成本报表',
    domain: 'PAYROLL',
    description: '统计工资批次的应发、实发与雇主成本。',
    asOfSupported: true,
    drilldownRoute: null,
    fields: [
      { key: 'employee_no', label: '工号', profile: 'P0_PUBLIC', exportable: true },
      { key: 'gross_cents', label: '应发工资（元）', profile: 'P2_SENSITIVE', exportable: true },
    ],
    filters: [],
    ...overrides,
  }
}

function result(overrides: Partial<ReportResult> = {}): ReportResult {
  return {
    runId: 1,
    definitionCode: 'PAYROLL_COST',
    title: '工资成本报表',
    columns: [
      { key: 'employee_no', label: '工号', profile: 'P0_PUBLIC' },
      { key: 'gross_cents', label: '应发工资（元）', profile: 'P2_SENSITIVE' },
    ],
    rows: [{ employee_no: 'SYNTH-001', gross_cents: 123456 }],
    asOf: '2026-11-27',
    freshness: 'MATERIALIZED',
    sourceHash: 'a'.repeat(64),
    total: 1,
    partial: false,
    partialReason: null,
    generatedAt: '2026-11-27T06:00:00Z',
    ...overrides,
  }
}

async function mountPage(capabilities = ['reporting.report.read']) {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().$patch({ status: 'authenticated', capabilities })
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/reports', component: { template: '<div />' } },
      { path: '/dashboard', component: { template: '<div />' } },
    ],
  })
  await router.push('/reports?report=PAYROLL_COST')
  await router.isReady()
  return mount(ReportsPage, {
    global: { plugins: [ElementPlus, router] },
  })
}

beforeEach(() => {
  vi.clearAllMocks()
  resource.get.mockImplementation((path: string) => {
    if (path === '/v1/reporting/definitions') {
      return Promise.resolve({
        items: [definition()],
        page: 1,
        page_size: 20,
        total: 1,
        as_of: '2026-11-27',
        freshness: 'LIVE',
        source_hash: 'b'.repeat(64),
        partial: false,
        partial_reason: null,
      })
    }
    if (path === '/v1/org-units/tree') return Promise.resolve({ items: [] })
    return Promise.reject(new Error(`Unexpected path ${path}`))
  })
})

describe('report query states', () => {
  it('shows an error state, not zero rows, when the run fails', async () => {
    resource.post.mockRejectedValue(new ApiError('SERVICE_UNAVAILABLE', '投影暂不可用', { status: 503 }))

    const wrapper = await mountPage()
    await flushPromises()

    await wrapper.find('[data-testid="report-run"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="report-error"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="report-empty"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="report-result"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('共 0 行')
  })

  it('does not convert a malformed result without rows into an empty table', async () => {
    resource.post.mockResolvedValue({ title: '工资成本报表', totalRows: 0 })

    const wrapper = await mountPage()
    await flushPromises()
    await wrapper.find('[data-testid="report-run"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="report-error"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="report-empty"]').exists()).toBe(false)
  })

  it('shows an honest empty result only after a successful zero-row response', async () => {
    resource.post.mockResolvedValue(result({ rows: [], total: 0 }))

    const wrapper = await mountPage()
    await flushPromises()
    await wrapper.find('[data-testid="report-run"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="report-error"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="report-empty"]').text()).toContain('当前报表没有数据')
  })

  it('shows partial separately from both empty and error', async () => {
    resource.post.mockResolvedValue(result({
      rows: [],
      total: 0,
      partial: true,
      partialReason: '组织投影仍在物化，结果可能不完整。',
    }))

    const wrapper = await mountPage()
    await flushPromises()
    await wrapper.find('[data-testid="report-run"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="report-partial"]').text()).toContain('组织投影仍在物化')
    expect(wrapper.find('[data-testid="report-empty"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="report-error"]').exists()).toBe(false)
  })

  it('formats integer cents as yuan and never exposes a minor-unit label', async () => {
    expect(reportCellText(123456, 'gross_cents')).toContain('¥')
    expect(reportCellText(123456, 'gross_cents')).toContain('1,234.56')
    expect(reportCellText(123456, 'gross_cents')).not.toContain('123456')
    expect(reportCellText(123456, 'gross_cents')).not.toContain('最小货币单位')

    resource.post.mockResolvedValue(result())
    const wrapper = await mountPage()
    await flushPromises()
    await wrapper.find('[data-testid="report-run"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="report-result"]').text()).toContain('1,234.56')
    expect(wrapper.text()).not.toContain('最小货币单位')
  })

  it('restores the selected report from ?report= on refresh', async () => {
    const wrapper = await mountPage()
    await flushPromises()

    const button = wrapper.find('[data-testid="report-definition-PAYROLL_COST"]')
    expect(button.attributes('aria-current')).toBe('page')
  })
})
