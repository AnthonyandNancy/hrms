import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import ElementPlus from 'element-plus'
import { createMemoryHistory, createRouter } from 'vue-router'
import { useSessionStore } from '@/stores/session'
import DashboardPage from '@/modules/reporting/pages/DashboardPage.vue'
import DashboardSections from '@/modules/reporting/components/DashboardSections.vue'
import type { DashboardSnapshot, MetricCardMetric } from '@/modules/reporting/api'

const resource = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
}))

vi.mock('@/api/resourceClient', () => ({
  resourceGet: resource.get,
  resourcePost: resource.post,
}))

function metric(overrides: Partial<MetricCardMetric> = {}): MetricCardMetric {
  return {
    code: 'headcount',
    label: '在职人数',
    value: 4,
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

function snapshot(overrides: Partial<DashboardSnapshot> = {}): DashboardSnapshot {
  return {
    asOf: '2026-11-25',
    freshness: '2026-11-25T06:05:00Z',
    freshnessState: 'LIVE',
    metrics: [
      metric(),
      metric({
        code: 'probation',
        label: '试用期人数',
        value: 1,
        unit: 'PERSON',
        unitLabel: '人',
      }),
      metric({
        code: 'contract_expiring',
        label: '即将到期合同',
        value: 2,
        unit: 'CONTRACT',
        unitLabel: '份',
        drilldown: { route: '/hr/contracts', query: { as_of: '2026-11-25' } },
      }),
      metric({
        code: 'pending_approvals',
        label: '待审批事项',
        value: 1,
        unit: 'ITEM',
        unitLabel: '项',
        drilldown: { route: '/workflow', query: { as_of: '2026-11-25' } },
      }),
      metric({
        code: 'leave_liability',
        label: '假期负债',
        value: 840,
        unit: 'MINUTE',
        unitLabel: '分钟',
        drilldown: { route: '/leave', query: { as_of: '2026-11-25' } },
      }),
      metric({
        code: 'payroll_cost_by_entity',
        label: '薪酬成本',
        value: 123456,
        unit: 'CNY_CENT',
        unitLabel: '元',
        drilldown: { route: '/payroll', query: { as_of: '2026-11-25' } },
      }),
      metric({
        code: 'recruitment_funnel',
        label: '招聘漏斗',
        value: 0,
        unit: 'PERSON',
        unitLabel: '人',
        status: 'UNCONFIGURED',
        reason: '招聘漏斗投影尚未配置：需由招聘模块发布真实候选人阶段数量，当前不提供推断值。',
        drilldown: { route: '/recruitment/candidates', query: { as_of: '2026-11-25' } },
      }),
    ],
    sections: {
      departments: [
        { label: '合成制造部', count: 2 },
        { label: '合成研发部', count: 2 },
      ],
      workforce: [
        { key: 'ACTIVE', label: '在职', count: 3 },
        { key: 'PROBATION', label: '试用期', count: 1 },
      ],
      recruitment: [],
    },
    pendingApprovals: [
      {
        id: 8204,
        caseNo: 'CASE-8204',
        title: '合成请假审批',
        status: 'PENDING',
        statusLabel: '待处理',
        ageDays: 2,
        dueAt: '2026-11-26T09:00:00Z',
      },
    ],
    recentNotices: [
      {
        id: 301,
        title: '合成公告',
        publishedAt: '2026-11-24T09:00:00Z',
        requiresAck: true,
        acknowledged: false,
        authorName: null,
      },
    ],
    contractAlerts: [
      {
        employmentId: 2,
        employeeName: '合成员工',
        employeeNo: 'SYNTH-0002',
        orgName: '合成制造部',
        endDate: '2026-12-10',
        daysRemaining: 15,
      },
    ],
    ...overrides,
  }
}

function capabilities(items: string[]): void {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().$patch({ status: 'authenticated', capabilities: items })
}

async function mountDashboard(items: string[] = [
  'reporting.dashboard.read',
  'people.employee.read',
  'people.contract.read',
  'workflow.case.read',
  'time.leave.read',
  'payroll.run.read',
  'recruitment.candidate.read',
]) {
  capabilities(items)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/dashboard', component: { template: '<div />' } },
      { path: '/hr/employees', component: { template: '<div />' } },
      { path: '/hr/contracts', component: { template: '<div />' } },
      { path: '/workflow', component: { template: '<div />' } },
      { path: '/leave', component: { template: '<div />' } },
      { path: '/payroll', component: { template: '<div />' } },
      { path: '/recruitment/candidates', component: { template: '<div />' } },
      { path: '/notices', component: { template: '<div />' } },
    ],
  })

  await router.push('/dashboard')
  await router.isReady()

  return mount(DashboardPage, {
    global: { plugins: [ElementPlus, router] },
  })
}

beforeEach(() => {
  resource.get.mockReset()
  resource.post.mockReset()
})

describe('dashboard sections', () => {
  it('folds the five old dashboard pages into five inline sections', async () => {
    resource.get.mockResolvedValue(snapshot())
    const wrapper = await mountDashboard()
    await flushPromises()

    expect(wrapper.find('[data-testid="dashboard-section-departments"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="dashboard-section-workforce"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="dashboard-section-changes"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="dashboard-section-tasks"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="dashboard-section-recruitment"]').exists()).toBe(true)
    expect(wrapper.findAll('.dashboard-section')).toHaveLength(6)

    const departments = wrapper.get('[data-testid="dashboard-section-departments"]').text()
    expect(departments).toContain('部门人数分析')
    expect(departments).toContain('合成制造部')
    expect(departments).toContain('2 人')
    expect(departments).not.toContain('org_path')

    const workforce = wrapper.get('[data-testid="dashboard-section-workforce"]').text()
    expect(workforce).toContain('员工构成')
    expect(workforce).toContain('试用期')
    expect(workforce).toContain('1 人')
  })

  it('renders business units and yuan without a technical unit code', async () => {
    resource.get.mockResolvedValue(snapshot())
    const wrapper = await mountDashboard()
    await flushPromises()

    const text = wrapper.text()
    expect(text).toContain('1,234.56')
    expect(text).toContain('元')
    expect(text).toContain('人')
    expect(text).toContain('份')
    expect(text).toContain('项')
    expect(text).toContain('分钟')
    expect(text).not.toContain('MINOR_UNITS')
    expect(text).not.toContain('CNY_CENT')
    expect(text).not.toContain('COUNT')
    expect(text).not.toContain('PERSON')
    expect(text).not.toContain('最小货币单位')
    expect(text).not.toContain('Welcome back')
  })

  it('shows a Chinese unconfigured state instead of a fabricated zero', async () => {
    resource.get.mockResolvedValue(snapshot())
    const wrapper = await mountDashboard()
    await flushPromises()

    const recruitment = wrapper.get('[data-testid="dashboard-section-recruitment"]').text()
    expect(recruitment).toContain('指标尚未配置')
    expect(recruitment).toContain('招聘漏斗投影尚未配置')
    expect(recruitment).not.toContain('0 人')
    expect(wrapper.text()).not.toContain('projection publishes')
  })

  it('uses the server drilldown route and as-of rather than a frontend re-count', async () => {
    resource.get.mockResolvedValue(snapshot())
    const wrapper = await mountDashboard()
    await flushPromises()

    await wrapper.get('[data-testid="metric-card-headcount"]').trigger('click')
    await flushPromises()

    const router = wrapper.vm.$router
    expect(router.currentRoute.value.path).toBe('/hr/employees')
    expect(router.currentRoute.value.query).toEqual({ as_of: '2026-11-25' })
    expect(resource.get).toHaveBeenCalledTimes(1)
  })

  it('renders an unavailable metric reason without calling the drilldown API', async () => {
    const data = snapshot()
    data.metrics = data.metrics.map((item) => item.code === 'headcount'
      ? { ...item, status: 'UNCONFIGURED', reason: '在职人数投影尚未配置。' }
      : item)
    resource.get.mockResolvedValue(data)

    const wrapper = await mountDashboard()
    await flushPromises()

    await wrapper.get('[data-testid="metric-card-headcount"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid="dashboard-departments-unavailable"]').text()).toContain('指标尚未配置')
    expect(wrapper.get('[data-testid="dashboard-selected-unavailable"]').text()).toContain('在职人数投影尚未配置')
    expect(resource.get).toHaveBeenCalledTimes(1)
  })

  it('keeps loading, empty, forbidden and error states distinct', async () => {
    resource.get.mockRejectedValue(new Error('network down'))
    const errorWrapper = await mountDashboard()
    await flushPromises()
    expect(errorWrapper.find('[data-testid="dashboard-error"]').exists()).toBe(true)

    resource.get.mockResolvedValue(snapshot())
    const emptyWrapper = await mountDashboard()
    await flushPromises()
    expect(emptyWrapper.find('[data-testid="dashboard-section-departments"]').exists()).toBe(true)
  })

  it('does not request data without dashboard capability', async () => {
    const wrapper = await mountDashboard([])
    await flushPromises()

    expect(wrapper.get('[data-testid="dashboard-forbidden"]').text()).toContain('无权查看仪表盘')
    expect(resource.get).not.toHaveBeenCalled()
  })
})

describe('DashboardSections', () => {
  it('renders unavailable departments with the server reason, not an empty zero table', () => {
    const data = snapshot()
    const wrapper = mount(DashboardSections, {
      props: {
        sections: { ...(data.sections as NonNullable<typeof data.sections>), departments: [] },
        metrics: data.metrics.map((item) => item.code === 'headcount'
          ? { ...item, status: 'FORBIDDEN', reason: '当前账号无权查看该指标。' }
          : item),
        pendingApprovals: data.pendingApprovals ?? [],
        contractAlerts: data.contractAlerts ?? [],
        recentNotices: data.recentNotices ?? [],
        asOf: data.asOf,
      },
      global: { plugins: [ElementPlus] },
    })

    expect(wrapper.get('[data-testid="dashboard-departments-unavailable"]').text()).toContain('无权查看')
    expect(wrapper.get('[data-testid="dashboard-departments-unavailable"]').text()).toContain('无权查看该指标')
  })
})
