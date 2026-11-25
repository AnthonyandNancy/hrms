import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import ElementPlus from 'element-plus'
import type { Component } from 'vue'
import { ApiError } from '@/api/errors'
import { PAGE_REGISTRY } from '@/router/registry'

const resource = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
}))

vi.mock('@/api/resourceClient', () => ({
  resourceGet: resource.get,
  resourcePost: resource.post,
}))

import { TALENT_COMPONENT_KEYS, TALENT_PAGE_CONFIGS, talentComponentFor } from '@/views/talent'

const wave4Keys = PAGE_REGISTRY.filter((page) => page.wave === 'Wave 4').map((page) => page.componentKey)

function listResponse(items: unknown[] = [], partial = false) {
  return { items, page: 1, page_size: 20, total: items.length, ...(partial ? { partial: true } : {}) }
}

async function mountWithRouter(key: string, path: string) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const routePattern = path.replace(/\/\d+(?=\/|$)/g, '/:id')
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: routePattern, name: 'talent-page', component: { template: '<div />' } },
      { path: '/login', name: 'login', component: { template: '<div />' } },
      { path: '/forbidden', name: 'forbidden', component: { template: '<div />' } },
    ],
  })
  await router.push(path)
  await router.isReady()

  const component = talentComponentFor(key)
  if (component === null) throw new Error(`No talent component registered for ${key}`)
  return mount(component as Component, {
    global: { plugins: [pinia, router, ElementPlus] },
  })
}

describe('Wave 4 talent domain components', () => {
  beforeEach(() => {
    resource.get.mockReset()
    resource.post.mockReset()
  })

  it('registers all 36 registry component keys and returns a component for each', () => {
    expect(TALENT_COMPONENT_KEYS).toHaveLength(36)
    expect(new Set(TALENT_COMPONENT_KEYS).size).toBe(36)
    expect([...TALENT_COMPONENT_KEYS].sort()).toEqual([...wave4Keys].sort())
    for (const key of wave4Keys) {
      expect(talentComponentFor(key), key).not.toBeNull()
    }
    expect(talentComponentFor('performance/not-real')).toBeNull()
  })

  it('loads performance templates from the live endpoint and blocks a template whose weights do not total 100', async () => {
    resource.get.mockResolvedValue(listResponse())
    const wrapper = await mountWithRouter('performance/templates', '/performance/templates')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/performance/templates', expect.any(Object))

    await wrapper.get('[data-testid="template-code"]').setValue('PERF-TPL-01')
    await wrapper.get('[data-testid="template-name"]').setValue('季度考核模板')
    await wrapper.get('[data-testid="template-section-1-key"]').setValue('goals')
    await wrapper.get('[data-testid="template-section-1-weight"]').setValue('60')
    await wrapper.get('[data-testid="template-section-2-key"]').setValue('competency')
    await wrapper.get('[data-testid="template-section-2-weight"]').setValue('30')
    await wrapper.get('[data-testid="template-submit"]').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('权重合计必须等于 100')
    expect(resource.post).not.toHaveBeenCalled()
  })

  it('shows server INVALID_WEIGHT_TOTAL on the template form without navigating away', async () => {
    resource.get.mockResolvedValue(listResponse())
    resource.post.mockRejectedValue(
      new ApiError('VALIDATION_FAILED', 'Section weights must total exactly 100.', {
        status: 422,
        requestId: 'req-weight-form',
        fieldErrors: { sections: ['Section weights must total exactly 100.'] },
      }),
    )

    const wrapper = await mountWithRouter('performance/templates', '/performance/templates')
    await flushPromises()

    await wrapper.get('[data-testid="template-code"]').setValue('PERF-TPL-02')
    await wrapper.get('[data-testid="template-name"]').setValue('年度考核模板')
    await wrapper.get('[data-testid="template-section-1-key"]').setValue('results')
    await wrapper.get('[data-testid="template-section-1-weight"]').setValue('50')
    await wrapper.get('[data-testid="template-section-2-key"]').setValue('values')
    await wrapper.get('[data-testid="template-section-2-weight"]').setValue('50')
    await wrapper.get('[data-testid="template-submit"]').trigger('click')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/performance/templates',
      expect.objectContaining({
        code: 'PERF-TPL-02',
        sections: [
          { key: 'results', weight: 50 },
          { key: 'values', weight: 50 },
        ],
      }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.text()).toContain('INVALID_WEIGHT_TOTAL')
    expect(wrapper.text()).toContain('req-weight-form')
  })

  it('keeps REQUISITION_NOT_APPROVED on the openings form when publishing an unapproved requisition', async () => {
    resource.get.mockResolvedValue(listResponse([
      { requisition_id: 8, requisition_no: 'REQ-008', title: 'Synthetic engineer', status: 'PENDING_APPROVAL', headcount: 2, case_id: 10 },
    ]))
    resource.post.mockRejectedValue({
      code: 'CONFLICT',
      status: 409,
      request_id: 'req-opening-conflict',
      data: { reason: 'REQUISITION_NOT_APPROVED', status: 'PENDING_APPROVAL' },
    } as unknown as Error)

    const wrapper = await mountWithRouter('recruitment/requests/openings', '/recruitment/requests/openings')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/recruitment/requisitions', expect.any(Object))
    await wrapper.get('[data-testid="opening-requisition-id"]').setValue('8')
    await wrapper.get('[data-testid="opening-channel"]').setValue('EXTERNAL')
    await wrapper.get('[data-testid="opening-submit"]').trigger('click')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/recruitment/requisitions/8/openings',
      { channel: 'EXTERNAL' },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.text()).toContain('REQUISITION_NOT_APPROVED')
    expect(wrapper.text()).toContain('req-opening-conflict')
  })

  it('loads candidates from the live candidate list endpoint without exposing contact values', async () => {
    resource.get.mockResolvedValue(listResponse([
      { candidate_id: 31, display_name: 'Synthetic Candidate', status: 'NEW', has_email: true },
    ], true))

    const wrapper = await mountWithRouter('recruitment/candidates/index', '/recruitment/candidates')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/recruitment/candidates', expect.any(Object))
    expect(wrapper.text()).toContain('Synthetic Candidate')
    expect(wrapper.text()).toContain('NEW')
    expect(wrapper.text()).toContain('已登记')
    expect(wrapper.text()).not.toContain('@')
    expect(wrapper.get('[data-testid="talent-partial"]').text()).toContain('部分数据')
  })

  it('filters the candidate detail page to the candidate id in the route', async () => {
    resource.get.mockResolvedValue(listResponse([
      { candidate_id: 31, display_name: 'Synthetic Candidate 31', status: 'NEW', has_email: true },
      { candidate_id: 32, display_name: 'Synthetic Candidate 32', status: 'INTERVIEW', has_email: false },
    ]))

    const wrapper = await mountWithRouter('recruitment/candidates/by-id', '/recruitment/candidates/31')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/recruitment/candidates', { candidate_id: 31 })
    expect(wrapper.text()).toContain('Synthetic Candidate 31')
    expect(wrapper.text()).not.toContain('Synthetic Candidate 32')
  })

  it('loads the live Offer ledger now that GET /v1/recruitment/offers exists', async () => {
    resource.get.mockResolvedValue(listResponse([
      {
        offer_id: 44,
        candidate_id: 31,
        requisition_id: 8,
        version: 2,
        status: 'APPROVED',
        base_salary_minor: 2000000,
        salary_currency: 'CNY',
        start_date: '2026-12-01',
      },
    ]))

    const wrapper = await mountWithRouter('recruitment/candidates/offers', '/recruitment/candidates/offers')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/recruitment/offers', expect.any(Object))
    expect(wrapper.text()).toContain('44')
    expect(wrapper.text()).toContain('APPROVED')
    expect(wrapper.text()).not.toContain('Offer 台账读取：后端接口待补')
  })

  it('loads the live performance review ledger now that GET /v1/performance/reviews exists', async () => {
    resource.get.mockResolvedValue(listResponse([
      {
        review_id: 66,
        cycle_id: 5,
        employment_id: 12,
        manager_employment_id: 13,
        status: 'DRAFT',
        self_rating: 'B',
        manager_rating: null,
      },
    ]))

    const wrapper = await mountWithRouter('performance/reviews', '/performance/reviews')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/performance/reviews', expect.any(Object))
    expect(wrapper.text()).toContain('66')
    expect(wrapper.text()).toContain('DRAFT')
  })

  it('loads the live training session ledger now that GET /v1/training/sessions exists', async () => {
    resource.get.mockResolvedValue(listResponse([
      {
        session_id: 91,
        program_id: 3,
        starts_on: '2026-12-01',
        ends_on: '2026-12-01',
        capacity: 20,
        location: 'Synthetic room',
        status: 'SCHEDULED',
      },
    ]))

    const wrapper = await mountWithRouter('training/sessions', '/training/sessions')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/training/sessions', expect.any(Object))
    expect(wrapper.text()).toContain('91')
    expect(wrapper.text()).toContain('Synthetic room')
    expect(wrapper.text()).toContain('SCHEDULED')
  })

  it('creates a training session with real fields and an idempotency key', async () => {
    resource.get.mockResolvedValue(listResponse())
    resource.post.mockResolvedValue({ session_id: 92, program_id: 3, capacity: 20, status: 'SCHEDULED' })

    const wrapper = await mountWithRouter('training/sessions', '/training/sessions')
    await flushPromises()

    await wrapper.get('[data-testid="session-program-id"]').setValue('3')
    await wrapper.get('[data-testid="session-starts-on"]').setValue('2026-12-02')
    await wrapper.get('[data-testid="session-ends-on"]').setValue('2026-12-02')
    await wrapper.get('[data-testid="session-starts-at"]').setValue('2026-12-02T09:00')
    await wrapper.get('[data-testid="session-ends-at"]').setValue('2026-12-02T12:00')
    await wrapper.get('[data-testid="session-capacity"]').setValue('20')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/training/sessions',
      expect.objectContaining({
        program_id: 3,
        starts_on: '2026-12-02',
        ends_on: '2026-12-02',
        capacity: 20,
      }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.text()).toContain('培训场次已创建')
  })

  it('keeps unbacked candidate convert data out of the offer accept workflow', async () => {
    resource.get.mockResolvedValue(listResponse([
      {
        offer_id: 44,
        candidate_id: 31,
        requisition_id: 8,
        version: 1,
        status: 'APPROVED',
        base_salary_minor: 2000000,
        salary_currency: 'CNY',
        start_date: '2026-12-01',
      },
    ]))

    const wrapper = await mountWithRouter('recruitment/candidates/convert', '/recruitment/candidates/convert')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/recruitment/offers', expect.any(Object))
    expect(wrapper.find('[data-testid="backend-pending"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('/v1/recruitment/candidates/{id}/convert')
    expect(wrapper.find('[data-testid="offer-accept-submit"]').exists()).toBe(true)
  })

  it('declares a live GET path and explicit backend gaps for every Wave 4 page config', () => {
    expect(TALENT_PAGE_CONFIGS).toHaveLength(36)
    for (const page of TALENT_PAGE_CONFIGS) {
      expect(page.readPath, page.key).toMatch(/^\/v1\//)
    }
    expect(TALENT_PAGE_CONFIGS.find((page) => page.key === 'performance/reviews')?.readPath).toBe(
      '/v1/performance/reviews',
    )
    expect(TALENT_PAGE_CONFIGS.find((page) => page.key === 'training/sessions')?.readPath).toBe(
      '/v1/training/sessions',
    )

    const plannedGaps = new Map([
      ['performance/indicators', '/v1/performance/indicators'],
      ['performance/goals', '/v1/performance/goals'],
      ['performance/feedback', '/v1/performance/feedback'],
      ['performance/calibration', '/v1/performance/calibration'],
      ['performance/results', '/v1/performance/results'],
      ['recruitment/candidates/imports', '/v1/recruitment/candidates/imports'],
      ['recruitment/candidates/pipeline', '/v1/recruitment/candidates/pipeline'],
      ['recruitment/candidates/communications', '/v1/recruitment/candidates/{id}/communications'],
      ['recruitment/candidates/talent-pool', '/v1/recruitment/candidates/talent-pool'],
      ['recruitment/candidates/convert', '/v1/recruitment/candidates/{id}/convert'],
      ['training/exams', '/v1/training/exams'],
      ['training/records', '/v1/training/records'],
    ])
    for (const [key, plannedPath] of plannedGaps) {
      const page = TALENT_PAGE_CONFIGS.find((candidate) => candidate.key === key)
      expect(page?.pending?.plannedPath, key).toBe(plannedPath)
    }
  })

  it('routes a forbidden page-load failure to the safe forbidden page with its request id', async () => {
    resource.get.mockRejectedValue(
      new ApiError('FORBIDDEN', 'Forbidden.', { status: 403, requestId: 'req-talent-403' }),
    )

    const pinia = createPinia()
    setActivePinia(pinia)
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/performance', name: 'talent-page', component: { template: '<div />' } },
        { path: '/login', name: 'login', component: { template: '<div />' } },
        { path: '/forbidden', name: 'forbidden', component: { template: '<div />' } },
      ],
    })
    await router.push('/performance')
    await router.isReady()

    const component = talentComponentFor('performance/index')
    if (component === null) throw new Error('Missing performance/index component')
    mount(component as Component, { global: { plugins: [pinia, router, ElementPlus] } })
    await flushPromises()
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('forbidden')
    expect(router.currentRoute.value.query.request_id).toBe('req-talent-403')
  })

  it('keeps one enrollment row and the existing server id when duplicate enrollment is submitted', async () => {
    resource.get.mockResolvedValue(listResponse([
      { program_id: 3, code: 'TRN-003', title: 'Synthetic Safety Program', status: 'ACTIVE' },
    ]))
    resource.post.mockResolvedValue({
      enrollment_id: 77,
      session_id: 12,
      employment_id: 45,
      status: 'ENROLLED',
      attended: false,
      completion_result: null,
      score: null,
      completed_at: null,
    })

    const wrapper = await mountWithRouter('training/enrollments', '/training/enrollments')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/training/programs', expect.any(Object))
    await wrapper.get('[data-testid="enrollment-session-id"]').setValue('12')
    await wrapper.get('[data-testid="enrollment-employment-id"]').setValue('45')
    await wrapper.get('[data-testid="enrollment-submit"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-testid="enrollment-submit"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledTimes(2)
    expect(resource.post).toHaveBeenCalledWith(
      '/v1/training/enrollments',
      {
        session_id: 12,
        employment_id: 45,
      },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.findAll('[data-testid^="enrollment-row-"]')).toHaveLength(1)
    expect(wrapper.get('[data-testid="enrollment-row-77"]').text()).toContain('77')
    expect(wrapper.text()).toContain('报名编号 77')
  })

  it('surfaces the request id from a 5xx page-load failure', async () => {
    resource.get.mockRejectedValue(
      new ApiError('INTERNAL', 'Synthetic failure', { status: 500, requestId: 'req-talent-500' }),
    )

    const wrapper = await mountWithRouter('performance/index', '/performance')
    await flushPromises()

    expect(wrapper.text()).toContain('req-talent-500')
    expect(wrapper.get('[data-testid="request-id"]').text()).toContain('req-talent-500')
  })

  it('shows an explicit backend-pending panel with the planned path for unbacked pages', async () => {
    resource.get.mockResolvedValue(listResponse())

    const wrapper = await mountWithRouter('performance/indicators', '/performance/indicators')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/performance/cycles', expect.any(Object))
    expect(wrapper.text()).toContain('后端接口待补')
    expect(wrapper.text()).toContain('/v1/performance/indicators')
    expect(wrapper.get('[data-testid="talent-partial"]').text()).toContain('部分数据')
  })
})
