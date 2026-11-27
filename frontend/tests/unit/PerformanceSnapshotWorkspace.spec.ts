import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import ElementPlus from 'element-plus'
import { useSessionStore } from '@/stores/session'

const resource = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
}))

vi.mock('@/api/resourceClient', () => ({
  resourceGet: (...args: unknown[]) => resource.get(...args),
  resourcePost: (...args: unknown[]) => resource.post(...args),
}))

import PerformanceWorkspacePage from '@/modules/talent/performance/pages/PerformanceWorkspacePage.vue'
import PerformanceCycleDetailPage from '@/modules/talent/performance/pages/PerformanceCycleDetailPage.vue'

const CAPABILITIES = [
  'performance.cycle.read',
  'performance.cycle.manage',
  'performance.review.manage',
  'performance.review.submit',
  'performance.result.publish',
  'performance.calibration.manage',
  'performance.appeal.manage',
  'performance.report.read',
]

function cycle(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    cycle_id: 9,
    name: '2026 年度绩效周期',
    period_from: '2026-01-01',
    period_to: '2026-12-31',
    status: 'PUBLISHED',
    participant_count: 2,
    submitted_count: 2,
    draft_count: 0,
    progress: 100,
    published_at: '2026-11-27T08:00:00Z',
    published_by: 10,
    ...overrides,
  }
}

function review(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    review_id: 31,
    cycle_id: 9,
    employment_id: 21,
    manager_employment_id: 20,
    status: 'SUBMITTED',
    self_rating: '85',
    manager_rating: '90',
    calibrated_rating: '88',
    final_rating: '88',
    snapshot: true,
    published_at: '2026-11-27T08:00:00Z',
    self_assessment: '合成自评',
    manager_assessment: '合成主管评价',
    submitted_at: '2026-11-27T08:00:00Z',
    goals: [],
    ...overrides,
  }
}

function page(items: unknown[], overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return { items, page: 1, page_size: 20, total: items.length, ...overrides }
}

function projection(items: unknown[], drilldown: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    items,
    total: items.length,
    page: 1,
    page_size: items.length,
    as_of: '2026-11-27',
    freshness: 'LIVE',
    source_hash: 'synthetic',
    partial: false,
    drilldown,
    snapshot: drilldown.snapshot === true,
    pending: drilldown.pending === true,
  }
}

async function mountWorkspace() {
  const pinia = createPinia()
  setActivePinia(pinia)
  const session = useSessionStore()
  session.capabilities = CAPABILITIES
  session.status = 'authenticated'
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/performance', component: { template: '<div />' } },
      { path: '/performance/cycles/:id', component: { template: '<div />' } },
    ],
  })
  await router.push('/performance')
  await router.isReady()
  return mount(PerformanceWorkspacePage, { global: { plugins: [pinia, router, ElementPlus] } })
}

async function mountDetail(tab = 'overview') {
  const pinia = createPinia()
  setActivePinia(pinia)
  const session = useSessionStore()
  session.capabilities = CAPABILITIES
  session.status = 'authenticated'
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/performance', component: { template: '<div />' } },
      { path: '/performance/cycles/:id', component: { template: '<div />' } },
    ],
  })
  await router.push(`/performance/cycles/9?tab=${tab}`)
  await router.isReady()
  return mount(PerformanceCycleDetailPage, { global: { plugins: [pinia, router, ElementPlus] } })
}

describe('performance snapshot workspace', () => {
  beforeEach(() => {
    resource.get.mockReset()
    resource.post.mockReset()
  })

  it('links every cycle to the stable detail deep link instead of a query parameter', async () => {
    resource.get.mockResolvedValue(page([cycle()]))
    const wrapper = await mountWorkspace()
    await flushPromises()

    const link = wrapper.get('[data-testid="performance-open-cycle"]')
    expect(link.attributes('href')).toBe('/performance/cycles/9')
    expect(link.text()).toContain('查看详情')
    expect(wrapper.find('[data-testid="performance-detail"]').exists()).toBe(false)
  })

  it('shows a published snapshot result and never labels it pending', async () => {
    resource.get.mockImplementation((path: unknown) => {
      if (path === '/v1/performance/cycles') return Promise.resolve(page([cycle()]))
      if (path === '/v1/performance/reviews') return Promise.resolve(page([review()]))
      if (path === '/v1/performance/results') {
        return Promise.resolve(projection([
          {
            review_id: 31,
            cycle_id: 9,
            employment_id: 21,
            cycle_status: 'PUBLISHED',
            status: 'PUBLISHED',
            self_rating: '85',
            manager_rating: '90',
            calibrated_rating: '88',
            final_rating: '88',
            snapshot: true,
            published_at: '2026-11-27T08:00:00Z',
            published_by: 10,
          },
        ], { snapshot: true, pending: false }))
      }
      return Promise.resolve(page([]))
    })

    const wrapper = await mountDetail('results')
    await flushPromises()

    expect(wrapper.get('[data-testid="performance-result-published"]').text()).toContain('已发布')
    expect(wrapper.find('[data-testid="performance-result-pending"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="performance-result-31"]').text()).toContain('发布快照')
  })

  it('labels a draft projection pending and offers no snapshot appeal action', async () => {
    resource.get.mockImplementation((path: unknown) => {
      if (path === '/v1/performance/cycles') return Promise.resolve(page([cycle({ status: 'ACTIVE', published_at: null, published_by: null })]))
      if (path === '/v1/performance/reviews') return Promise.resolve(page([review({ status: 'DRAFT', snapshot: false, final_rating: null })]))
      if (path === '/v1/performance/results') {
        return Promise.resolve(projection([
          {
            review_id: 31,
            cycle_id: 9,
            employment_id: 21,
            cycle_status: 'ACTIVE',
            status: 'DRAFT',
            self_rating: '85',
            manager_rating: null,
            calibrated_rating: null,
            final_rating: null,
            snapshot: false,
            published_at: null,
            published_by: null,
          },
        ], { snapshot: false, pending: true }))
      }
      return Promise.resolve(page([]))
    })

    const wrapper = await mountDetail('results')
    await flushPromises()

    expect(wrapper.get('[data-testid="performance-result-pending"]').text()).toContain('尚未发布')
    expect(wrapper.find('[data-testid="performance-result-appeal-31"]').exists()).toBe(false)
  })

  it('renders all ten detail tabs and loads only the selected tab projection', async () => {
    resource.get.mockImplementation((path: unknown) => {
      if (path === '/v1/performance/cycles') return Promise.resolve(page([cycle()]))
      if (path === '/v1/performance/reviews') return Promise.resolve(page([review()]))
      if (path === '/v1/performance/indicators') {
        return Promise.resolve({ items: [{ indicator_id: 5, code: 'IND-1', name: '合成指标', category: '交付', data_source: '合成台账', active: true }], total: 1 })
      }
      return Promise.resolve(page([]))
    })

    const wrapper = await mountDetail('indicators')
    await flushPromises()

    const tabs = ['overview', 'templates', 'indicators', 'goals', 'reviews', 'feedback', 'calibration', 'results', 'appeals', 'reports']
    for (const tab of tabs) {
      expect(wrapper.find(`[data-testid="performance-tab-${tab}"]`).exists()).toBe(true)
    }
    expect(wrapper.get('[data-testid="performance-tab-panel-indicators"]').text()).toContain('合成指标')
    expect(resource.get).toHaveBeenCalledWith('/v1/performance/indicators')
    expect(resource.get).not.toHaveBeenCalledWith('/v1/performance/templates')
  })

  it('does not count draft reviews as published in the report tab', async () => {
    resource.get.mockImplementation((path: unknown) => {
      if (path === '/v1/performance/cycles') return Promise.resolve(page([cycle({ status: 'ACTIVE', published_at: null, published_by: null })]))
      if (path === '/v1/performance/reviews') return Promise.resolve(page([review({ snapshot: false })]))
      if (path === '/v1/performance/reports') {
        return Promise.resolve(projection([
          {
            cycle_id: 9,
            name: '2026 年度绩效周期',
            status: 'ACTIVE',
            review_count: 2,
            submitted_reviews: 1,
            draft_reviews: 1,
            calibrated_reviews: 0,
            published_reviews: 0,
            completion_rate: 0,
            snapshot: false,
          },
        ]))
      }
      return Promise.resolve(page([]))
    })

    const wrapper = await mountDetail('reports')
    await flushPromises()

    const text = wrapper.get('[data-testid="performance-tab-panel-reports"]').text()
    expect(text).toContain('已发布快照0')
    expect(text).toContain('发布完成率0%')
  })
})
