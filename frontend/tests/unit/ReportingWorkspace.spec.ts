import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import ElementPlus from 'element-plus'
import { createMemoryHistory, createRouter } from 'vue-router'
import { ApiError } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import type {
  DashboardSnapshot,
  ExportJob,
  MetricCardMetric,
  Notice,
  NoticeListResult,
  OrgUnitOption,
  ReportDefinition,
  ReportResult,
} from '@/modules/reporting/api'
import MetricCard from '@/modules/reporting/components/MetricCard.vue'
import ExportPanel from '@/modules/reporting/components/ExportPanel.vue'
import NoticeComposer from '@/modules/reporting/components/NoticeComposer.vue'
import DashboardPage from '@/modules/reporting/pages/DashboardPage.vue'
import ReportsPage from '@/modules/reporting/pages/ReportsPage.vue'
import NoticesPage from '@/modules/reporting/pages/NoticesPage.vue'

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

function useCapabilities(capabilities: string[]): void {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().$patch({ status: 'authenticated', capabilities })
}

async function mountWithSession(component: object, capabilities: string[], options: Record<string, unknown> = {}) {
  useCapabilities(capabilities)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: { template: '<div />' } },
      { path: '/dashboard', name: 'dashboard', component: { template: '<div />' } },
      { path: '/hr/employees', name: 'employees', component: { template: '<div />' } },
      { path: '/hr/contracts', name: 'contracts', component: { template: '<div />' } },
      { path: '/notices', name: 'notices', component: { template: '<div />' } },
      { path: '/workflow', name: 'workflow', component: { template: '<div />' } },
      { path: '/payroll', name: 'payroll', component: { template: '<div />' } },
      { path: '/leave', name: 'leave', component: { template: '<div />' } },
      { path: '/recruitment/candidates', name: 'candidates', component: { template: '<div />' } },
      { path: '/:pathMatch(.*)*', name: 'catchall', component: { template: '<div />' } },
    ],
  })
  await router.push('/dashboard')
  await router.isReady()
  return mount(component, {
    global: { plugins: [ElementPlus, router] },
    ...options,
  })
}

function metric(overrides: Partial<MetricCardMetric> = {}): MetricCardMetric {
  return {
    code: 'headcount',
    label: '在职人数',
    value: 1234,
    unit: 'PERSON',
    unitLabel: '人',
    status: 'AVAILABLE',
    reason: null,
    scope: 'AUTHORIZED',
    scopeLabel: '按当前授权范围',
    period: 'AS_OF',
    predicate: '统计日有效任职且在当前授权组织范围内',
    asOf: '2026-11-25',
    drilldown: { route: '/hr/employees', query: { as_of: '2026-11-25' } },
    trend: null,
    sourceHash: 'a'.repeat(64),
    computedAt: '2026-11-25T06:00:00Z',
    preview: [],
    ...overrides,
  }
}

function dashboard(): DashboardSnapshot {
  return {
    asOf: '2026-11-25',
    freshness: '2026-11-25T06:05:00Z',
    freshnessState: 'LIVE',
    metrics: [
      metric(),
      metric({
        code: 'payroll_cost_by_entity',
        label: '薪酬成本',
        value: 0,
        unit: 'CNY_CENT',
        unitLabel: '元',
        status: 'UNCONFIGURED',
        reason: '薪酬成本投影尚未配置：仅统计已发布工资批次的雇主成本，当前不提供估算值。',
        drilldown: { route: '/payroll', query: { as_of: '2026-11-25' } },
      }),
    ],
    sections: {
      departments: [{ label: '合成制造部', count: 1 }],
      workforce: [{ key: 'ACTIVE', label: '在职', count: 1 }],
      recruitment: [],
    },
    pendingApprovals: [],
    recentNotices: [],
    contractAlerts: [],
  }
}

function definition(overrides: Partial<ReportDefinition> = {}): ReportDefinition {
  return {
    id: 1,
    code: 'WORKFORCE_HEADCOUNT',
    name: 'Workforce headcount',
    description: 'Active employments by organization as of the business date.',
    asOfSupported: true,
    drilldownRoute: '/dashboard/metric/headcount',
    fields: [
      { key: 'employee', label: 'Employee', profile: 'P1_INTERNAL', exportable: true },
      { key: 'employee_no', label: 'Employee no.', profile: 'P0_PUBLIC', exportable: true },
      { key: 'org_path', label: 'Organization', profile: 'P0_PUBLIC', exportable: true },
      { key: 'salary', label: 'Restricted salary detail', profile: 'P3_RESTRICTED', exportable: false },
    ],
    filters: [
      { key: 'org_unit_id', label: 'Organization', type: 'ORG', options: [] },
    ],
    ...overrides,
  }
}

function definitionProjection(items: ReportDefinition[], overrides: Record<string, unknown> = {}) {
  return {
    items,
    page: 1,
    page_size: 20,
    total: items.length,
    as_of: '2026-11-25',
    freshness: 'LIVE',
    source_hash: 'b'.repeat(64),
    partial: false,
    partial_reason: null,
    ...overrides,
  }
}

function reportResult(overrides: Partial<ReportResult> = {}): ReportResult {
  return {
    runId: 9,
    definitionCode: 'WORKFORCE_HEADCOUNT',
    title: 'Workforce headcount',
    columns: [
      { key: 'employee_no', label: '员工编号', profile: 'P0_PUBLIC' },
      { key: 'org_path', label: '组织', profile: 'P0_PUBLIC' },
      { key: 'status', label: '状态', profile: 'P0_PUBLIC' },
    ],
    rows: [
      { employee_no: 'SYNTH-001', org_path: '合成制造部', status: 'ACTIVE' },
      { employee_no: 'SYNTH-002', org_path: '合成研发部', status: 'PROBATION' },
    ],
    asOf: '2026-10-31',
    freshness: 'MATERIALIZED',
    sourceHash: 'c'.repeat(64),
    total: 2,
    partial: false,
    partialReason: null,
    generatedAt: '2026-11-25T06:10:00Z',
    ...overrides,
  }
}

function exportJob(overrides: Partial<ExportJob> = {}): ExportJob {
  return {
    id: '17',
    reportCode: 'WORKFORCE_HEADCOUNT',
    reportName: 'Workforce headcount',
    status: 'COMPLETED',
    purpose: 'BUSINESS_OPERATION',
    rowCount: 2,
    createdAt: '2026-11-25T06:10:00Z',
    expiresAt: '2026-11-26T06:10:00Z',
    downloadCount: 0,
    maxDownloads: 5,
    watermark: '101 · 2026-11-25T06:10:00Z · BUSINESS_OPERATION',
    fileName: 'workforce_headcount-17.csv',
    downloadUrl: null,
    downloadToken: 'tok123',
    fields: ['employee_no', 'org_path'],
    sourceHash: 'd'.repeat(64),
    ...overrides,
  }
}

function notice(overrides: Partial<Notice> = {}): Notice {
  return {
    id: 42,
    title: '十一月薪酬发放安排',
    summary: '本月薪酬将在 11 月 28 日发放。',
    body: ['本月薪酬将在 11 月 28 日发放。', '如有疑问请联系人力资源部。'],
    status: 'PUBLISHED',
    audience: 'ALL',
    audienceLabel: '全体员工',
    orgUnitIds: [],
    publishedAt: '2026-11-24T02:00:00Z',
    authorName: null,
    requiresAck: true,
    acknowledged: false,
    acknowledgementCount: 3,
    audienceCount: 20,
    ...overrides,
  }
}

const ORG_UNITS: OrgUnitOption[] = [
  { id: 1, parentId: null, name: '澄明科技', label: '澄明科技' },
  { id: 12, parentId: 1, name: '人力资源部', label: '　人力资源部' },
]

beforeEach(() => {
  resource.get.mockReset()
  resource.post.mockReset()
  resource.patch.mockReset()
  resource.put.mockReset()
  resource.delete.mockReset()
})

describe('reporting workspace', () => {
  it('renders a metric card with exact value, unit, as-of, freshness and partial reason', async () => {
    const wrapper = await mountWithSession(MetricCard, ['reporting.dashboard.read'], {
      props: {
        metric: metric(),
        asOf: '2026-11-25',
        freshness: 'MATERIALIZED',
        freshnessAt: '2026-11-25T06:00:00Z',
        partialReason: '部分组织尚在计算，人数可能偏低。',
      },
    })

    expect(wrapper.text()).toContain('在职人数')
    expect(wrapper.text()).toContain('1,234')
    expect(wrapper.text()).toContain('人')
    expect(wrapper.text()).toContain('2026年11月25日')
    expect(wrapper.text()).toContain('缓存')
    expect(wrapper.get('[data-testid="metric-partial"]').text()).toContain('部分组织尚在计算')
    expect(wrapper.text()).not.toContain('1,234.0')
    expect(wrapper.text()).toContain('按当前授权范围')
    expect(wrapper.text()).not.toContain('PERSON')

    await wrapper.get('button').trigger('click')
    expect(wrapper.emitted('drill')?.[0]).toEqual([expect.objectContaining({ code: 'headcount' })])
  })

  it('loads the dashboard snapshot and navigates with the server drilldown target', async () => {
    resource.get.mockResolvedValue(dashboard())

    const wrapper = await mountWithSession(DashboardPage, ['reporting.dashboard.read', 'people.employee.read'])
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/reporting/dashboard', { as_of: expect.stringMatching(/^\d{4}-\d{2}-\d{2}$/) })
    expect(wrapper.text()).toContain('在职人数')
    expect(wrapper.text()).toContain('1,234')
    expect(wrapper.get('[data-testid="dashboard-partial"]').text()).toContain('薪酬成本')
    expect(wrapper.text()).not.toContain('Payroll run tables exist')
    expect(wrapper.text()).not.toContain('MINOR_UNITS')
    expect(wrapper.text()).toContain('实时快照')

    await wrapper.get('[data-testid="metric-card-headcount"]').trigger('click')
    await flushPromises()

    const router = wrapper.vm.$router
    expect(router.currentRoute.value.path).toBe('/hr/employees')
    expect(router.currentRoute.value.query).toEqual({ as_of: '2026-11-25' })
    expect(resource.get).toHaveBeenCalledTimes(1)
  })

  it('shows a Chinese forbidden state and makes no dashboard request without its capability', async () => {
    const wrapper = await mountWithSession(DashboardPage, [])
    await flushPromises()

    expect(wrapper.get('[data-testid="dashboard-forbidden"]').text()).toContain('无权查看仪表盘')
    expect(wrapper.text()).not.toContain('reporting.dashboard.read')
    expect(resource.get).not.toHaveBeenCalled()
  })

  it('runs a report with as-of and filters and renders projection metadata', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/reporting/definitions') return Promise.resolve(definitionProjection([definition()]))
      if (path === '/v1/org-units/tree') return Promise.resolve({ items: ORG_UNITS })
      return Promise.reject(new Error(`Unexpected path ${path}`))
    })
    resource.post.mockResolvedValue(reportResult())

    const wrapper = await mountWithSession(ReportsPage, ['reporting.report.read', 'reporting.export'])
    await flushPromises()

    await wrapper.get('[data-testid="report-as-of"]').setValue('2026-10-31')
    await wrapper.get('[data-testid="report-filter-org_unit_id"]').setValue('12')
    await wrapper.get('[data-testid="report-run"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith('/v1/reporting/run', {
      definition_code: 'WORKFORCE_HEADCOUNT',
      filters: { org_unit_id: '12' },
      as_of: '2026-10-31',
    })
    const result = wrapper.get('[data-testid="report-result"]').text()
    expect(result).toContain('2026年10月31日')
    expect(result).toContain('c'.repeat(64))
    expect(result).toContain('2')
    expect(result).toContain('SYNTH-001')
    expect(result).toContain('合成制造部')
    expect(result).toContain('在职')
    expect(result).not.toContain('ACTIVE')
  })

  it('renders honest partial and empty report states without inventing rows', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/reporting/definitions') {
        return Promise.resolve(definitionProjection([definition()], {
          partial: true,
          partial_reason: '部分报表定义暂未同步。',
        }))
      }
      if (path === '/v1/org-units/tree') return Promise.reject(new Error('org tree unavailable'))
      return Promise.reject(new Error(`Unexpected path ${path}`))
    })
    resource.post.mockResolvedValue(reportResult({
      rows: [],
      total: 0,
      partial: true,
      partialReason: '组织投影仍在物化，结果可能不完整。',
    }))

    const wrapper = await mountWithSession(ReportsPage, ['reporting.report.read'])
    await flushPromises()

    expect(wrapper.get('[data-testid="reports-partial"]').text()).toContain('部分报表定义暂未同步')
    await wrapper.get('[data-testid="report-run"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid="report-partial"]').text()).toContain('组织投影仍在物化')
    expect(wrapper.get('[data-testid="report-empty"]').text()).toContain('当前报表没有数据')
    expect(wrapper.findAll('[data-testid="report-row"]')).toHaveLength(0)
  })

  it('shows a Chinese forbidden state and makes no report request without report read', async () => {
    const wrapper = await mountWithSession(ReportsPage, [])
    await flushPromises()

    expect(wrapper.get('[data-testid="reports-forbidden"]').text()).toContain('无权查看报表')
    expect(wrapper.text()).not.toContain('reporting.report.read')
    expect(resource.get).not.toHaveBeenCalled()
  })

  it('maps EXPORT_FIELD_NOT_ALLOWED to readable Chinese without leaking the raw code', async () => {
    resource.post.mockRejectedValue(new ApiError('VALIDATION_FAILED', 'The given data was invalid.', {
      status: 422,
      details: { reason: 'EXPORT_FIELD_NOT_ALLOWED' },
    }))

    const wrapper = await mountWithSession(ExportPanel, ['reporting.export'], {
      props: { definition: definition(), asOf: '2026-10-31' },
    })
    await wrapper.get('[data-testid="export-submit"]').trigger('click')
    await flushPromises()

    const denial = wrapper.get('[data-testid="export-denial"]').text()
    expect(denial).toContain('不在该报表的可导出字段白名单内')
    expect(denial).not.toContain('EXPORT_FIELD_NOT_ALLOWED')
    expect(denial).not.toContain('VALIDATION_FAILED')
  })

  it('maps EXPORT_FIELD_HIDDEN to readable Chinese without leaking the raw code', async () => {
    resource.post.mockRejectedValue(new ApiError('VALIDATION_FAILED', 'The given data was invalid.', {
      status: 422,
      details: { reason: 'EXPORT_FIELD_HIDDEN' },
    }))

    const wrapper = await mountWithSession(ExportPanel, ['reporting.export'], {
      props: { definition: definition(), asOf: '2026-10-31' },
    })
    await wrapper.get('[data-testid="export-submit"]').trigger('click')
    await flushPromises()

    const denial = wrapper.get('[data-testid="export-denial"]').text()
    expect(denial).toContain('受限字段')
    expect(denial).toContain('不可导出')
    expect(denial).not.toContain('EXPORT_FIELD_HIDDEN')
  })

  it('submits reportCode/purpose/asOf with an idempotency key and links a completed download', async () => {
    resource.post.mockResolvedValue(exportJob())

    const wrapper = await mountWithSession(ExportPanel, ['reporting.export'], {
      props: { definition: definition(), asOf: '2026-10-31', filters: { org_unit_id: '12' } },
    })

    // Element Plus renders an el-select as a div in unit tests. Drive its model
    // through the component contract and verify the request payload.
    wrapper.findComponent({ name: 'ElSelect' }).vm.$emit('update:modelValue', 'AUDIT')
    await flushPromises()
    await wrapper.get('[data-testid="export-submit"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/reporting/exports',
      {
        reportCode: 'WORKFORCE_HEADCOUNT',
        purpose: 'AUDIT',
        fields: ['employee', 'employee_no', 'org_path'],
        asOf: '2026-10-31',
        filters: { org_unit_id: '12' },
      },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.text()).toContain('导出任务已创建')
    expect(wrapper.text()).toContain('已完成')
    expect(wrapper.get('[data-testid="export-download"]').attributes('href')).toBe(
      '/v1/reporting/exports/17/download?token=tok123',
    )
  })

  it('loads notice detail and marks a notice acknowledged after the real command', async () => {
    const unread = notice()
    const alreadyAcknowledged = notice({
      id: 43,
      title: '办公区消防演练',
      requiresAck: true,
      acknowledged: true,
      acknowledgementCount: 19,
    })
    const list: NoticeListResult = {
      items: [unread, alreadyAcknowledged],
      total: 2,
      partial: false,
      partialReason: null,
    }
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/engagement/notices') return Promise.resolve(list)
      if (path === '/v1/engagement/notices/42') return Promise.resolve(unread)
      if (path === '/v1/org-units/tree') return Promise.resolve({ items: ORG_UNITS })
      return Promise.reject(new Error(`Unexpected path ${path}`))
    })
    resource.post.mockResolvedValue(notice({ acknowledged: true, acknowledgementCount: 4 }))

    const wrapper = await mountWithSession(NoticesPage, ['engagement.notice.read'])
    await flushPromises()

    expect(wrapper.text()).toContain('十一月薪酬发放安排')
    expect(wrapper.text()).toContain('已确认')
    expect(wrapper.findAll('[data-testid="notice-acknowledged"]')).toHaveLength(1)

    await wrapper.get('[data-testid="notice-detail-42"]').trigger('click')
    await flushPromises()
    expect(resource.get).toHaveBeenCalledWith('/v1/engagement/notices/42')
    expect(wrapper.get('[data-testid="notice-detail"]').text()).toContain('本月薪酬将在 11 月 28 日发放')

    await wrapper.get('[data-testid="notice-acknowledge-42"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/engagement/notices/42/acknowledge',
      {},
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.get('[data-testid="notice-detail-acknowledged"]').text()).toContain('已确认')
    expect(wrapper.findAll('[data-testid="notice-acknowledged"]')).toHaveLength(2)
  })

  it('requires an explicit confirmation before the notice composer publishes', async () => {
    resource.post.mockResolvedValue(notice({
      id: 77,
      title: '新的合成公告',
      audience: 'ORG',
      audienceLabel: '人力资源部',
      orgUnitIds: [12],
    }))

    const wrapper = await mountWithSession(NoticeComposer, ['engagement.notice.manage'], {
      props: { orgUnits: ORG_UNITS },
    })

    await wrapper.get('[data-testid="notice-title"]').setValue('新的合成公告')
    await wrapper.get('[data-testid="notice-body"]').setValue('这是一条至少十个字的合成公告正文。')
    await wrapper.get('[data-testid="notice-audience"]').setValue('ORG')
    await wrapper.get('[data-testid="notice-org-unit"]').setValue('12')
    await wrapper.get('[data-testid="notice-requires-ack"]').setValue(true)
    await wrapper.get('[data-testid="notice-publish"]').trigger('click')
    await flushPromises()

    // The confirmation step may perform one audience-preview POST, but it must
    // not create the notice until the operator confirms explicitly.
    expect(resource.post).not.toHaveBeenCalledWith(
      '/v1/engagement/notices',
      expect.anything(),
      expect.anything(),
    )
    expect(wrapper.get('[data-testid="notice-confirm"]').text()).toContain('确认发布公告')
    expect(wrapper.get('[data-testid="notice-confirm"]').text()).toContain('人力资源部')

    await wrapper.get('[data-testid="notice-confirm-publish"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/engagement/notices',
      {
        title: '新的合成公告',
        body: '这是一条至少十个字的合成公告正文。',
        audience: 'ORG',
        audienceLabel: '人力资源部',
        orgUnitIds: [12],
        requiresAck: true,
        approvalRequired: false,
      },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.text()).toContain('公告已发布')
    expect(wrapper.emitted('created')).toHaveLength(1)
  })

  it('shows a Chinese forbidden state and no fake notices without notice read', async () => {
    const wrapper = await mountWithSession(NoticesPage, [])
    await flushPromises()

    expect(wrapper.get('[data-testid="notices-forbidden"]').text()).toContain('无权查看公告')
    expect(wrapper.findAll('[data-testid="notice-item"]')).toHaveLength(0)
    expect(wrapper.text()).not.toContain('engagement.notice.read')
    expect(resource.get).not.toHaveBeenCalled()
  })
})
