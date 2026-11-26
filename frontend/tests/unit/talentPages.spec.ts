import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
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

import { PAGE_COMPONENTS } from '@/modules/talent/pages'
import { TALENT_COMPONENT_KEYS, TALENT_PAGE_CONFIGS, talentComponentFor } from '@/views/talent'

const wave4Keys = PAGE_REGISTRY.filter((page) => page.wave === 'Wave 4').map((page) => page.componentKey)

function listResponse(items: unknown[] = [], partial = false) {
  return { items, page: 1, page_size: 20, total: items.length, ...(partial ? { partial: true } : {}) }
}

async function talentPageComponent(key: string): Promise<Component> {
  const loader = PAGE_COMPONENTS[key as keyof typeof PAGE_COMPONENTS]
  if (loader === undefined) throw new Error(`No talent page component registered for ${key}`)
  const module = await loader()
  return module.default as Component
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

  const component = await talentPageComponent(key)
  return mount(component, {
    global: { plugins: [pinia, router, ElementPlus] },
  })
}

describe('Wave 4 talent domain components', () => {
  beforeEach(() => {
    resource.get.mockReset()
    resource.post.mockReset()
  })

  it('registers all 35 registry component keys and returns a component for each', () => {
    // ADR-0035: requisition create moved into the requests workspace drawer.
    expect(TALENT_COMPONENT_KEYS).toHaveLength(35)
    expect(new Set(TALENT_COMPONENT_KEYS).size).toBe(35)
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
    // Element Plus select: emit the model update the component listens for.
    const openingChannel = wrapper.getComponent('[data-testid="opening-channel"]') as VueWrapper
    openingChannel.vm.$emit('update:modelValue', 'EXTERNAL')
    openingChannel.vm.$emit('change', 'EXTERNAL')
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

  it('converts an accepted candidate through the real idempotent endpoint', async () => {
    resource.get.mockResolvedValue(listResponse([
      {
        offer_id: 44,
        candidate_id: 31,
        requisition_id: 8,
        version: 1,
        status: 'ACCEPTED',
        base_salary_minor: 2000000,
        salary_currency: 'CNY',
        start_date: '2026-12-01',
      },
    ]))
    resource.post.mockResolvedValue({
      conversion_id: 9,
      candidate_id: 31,
      employment_id: 501,
      person_id: 61,
      offer_id: 44,
      org_unit_id: 8,
      employee_no: 'CONV-UI-1',
      status: 'COMPLETED',
      converted_at: '2026-11-25T01:00:00Z',
      business_replayed: false,
    })

    const wrapper = await mountWithRouter('recruitment/candidates/convert', '/recruitment/candidates/convert')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/recruitment/offers', expect.any(Object))
    expect(wrapper.find('[data-testid="backend-pending"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="offer-accept-submit"]').exists()).toBe(false)

    await wrapper.get('[data-testid="convert-candidate-id"]').setValue('31')
    await wrapper.get('[data-testid="convert-org-unit-id"]').setValue('8')
    await wrapper.get('[data-testid="convert-employee-no"]').setValue('CONV-UI-1')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/recruitment/candidates/31/convert',
      {
        org_unit_id: 8,
        employee_no: 'CONV-UI-1',
        hire_date: null,
      },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.text()).toContain('任职 501')
  })

  it('declares live funnel read paths with no remaining K9 backend gaps', () => {
    expect(TALENT_PAGE_CONFIGS).toHaveLength(35)
    for (const page of TALENT_PAGE_CONFIGS) {
      expect(page.readPath, page.key).toMatch(/^\/v1\//)
      expect(page.pending, page.key).toBeUndefined()
    }
    expect(TALENT_PAGE_CONFIGS.find((page) => page.key === 'performance/reviews')?.readPath).toBe(
      '/v1/performance/reviews',
    )
    expect(TALENT_PAGE_CONFIGS.find((page) => page.key === 'training/sessions')?.readPath).toBe(
      '/v1/training/sessions',
    )

    const trainingLivePaths = new Map([
      ['training/index', '/v1/training/plans'],
      ['training/courses', '/v1/training/courses'],
      ['training/checkins', '/v1/training/checkins'],
      ['training/exams', '/v1/training/exams'],
      ['training/feedback', '/v1/training/feedback'],
      ['training/records', '/v1/training/records'],
      ['training/reports', '/v1/training/reports'],
    ])
    for (const [key, readPath] of trainingLivePaths) {
      const page = TALENT_PAGE_CONFIGS.find((candidate) => candidate.key === key)
      expect(page?.readPath, key).toBe(readPath)
      expect(page?.pending, key).toBeUndefined()
    }

    const recruitmentLivePaths = new Map([
      ['recruitment/requests/approval', '/v1/recruitment/requisitions'],
      ['recruitment/requests/board', '/v1/recruitment/board'],
      ['recruitment/requests/budget', '/v1/recruitment/budget'],
      ['recruitment/requests/reports', '/v1/recruitment/reports'],
      ['recruitment/candidates/imports', '/v1/recruitment/candidates'],
      ['recruitment/candidates/pipeline', '/v1/recruitment/board'],
      ['recruitment/candidates/evaluations', '/v1/recruitment/candidates/{id}/evaluations'],
      ['recruitment/candidates/communications', '/v1/recruitment/candidates/{id}/communications'],
      ['recruitment/candidates/talent-pool', '/v1/recruitment/candidates/{id}/talent-pool'],
      ['recruitment/candidates/convert', '/v1/recruitment/offers'],
    ])
    for (const [key, readPath] of recruitmentLivePaths) {
      const page = TALENT_PAGE_CONFIGS.find((candidate) => candidate.key === key)
      expect(page?.readPath, key).toBe(readPath)
      expect(page?.pending, key).toBeUndefined()
    }

    const performancePaths = new Map([
      ['performance/indicators', '/v1/performance/indicators'],
      ['performance/goals', '/v1/performance/goals'],
      ['performance/feedback', '/v1/performance/feedback'],
      ['performance/calibration', '/v1/performance/calibration'],
      ['performance/results', '/v1/performance/results'],
      ['performance/reports', '/v1/performance/reports'],
    ])
    for (const [key, readPath] of performancePaths) {
      expect(TALENT_PAGE_CONFIGS.find((page) => page.key === key)?.readPath, key).toBe(readPath)
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

    const component = await talentPageComponent('performance/index')
    mount(component, { global: { plugins: [pinia, router, ElementPlus] } })
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

  it('shows a per-row candidate import report instead of dropping rejected rows', async () => {
    resource.get.mockResolvedValue(listResponse())
    resource.post.mockResolvedValue({
      import_id: 71,
      requisition_id: null,
      source: 'CSV',
      row_count: 2,
      accepted_count: 1,
      rejected_count: 1,
      rows: [
        { row_number: 1, status: 'ACCEPTED', reason: null, errors: {}, candidate_id: 301 },
        {
          row_number: 2,
          status: 'REJECTED',
          reason: 'ROW_VALIDATION_FAILED',
          errors: { email: ['The email must be a valid email address.'] },
          candidate_id: null,
        },
      ],
      recorded_at: '2026-11-25T01:00:00Z',
    })

    const wrapper = await mountWithRouter('recruitment/candidates/imports', '/recruitment/candidates/imports')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/recruitment/candidates', expect.any(Object))
    await wrapper.get('[data-testid="candidate-import-rows"]').setValue(
      'Synthetic Imported, imported@example.test\nSynthetic Invalid, not-an-email',
    )
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/recruitment/candidates/imports',
      expect.objectContaining({
        source: 'CSV',
        rows: [
          { display_name: 'Synthetic Imported', email: 'imported@example.test' },
          { display_name: 'Synthetic Invalid', email: 'not-an-email' },
        ],
      }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.get('[data-testid="candidate-import-report"]').text()).toContain('ROW_VALIDATION_FAILED')
    expect(wrapper.text()).toContain('拒绝 1 行')
  })

  it('approves a requisition through the real workflow decision endpoint', async () => {
    resource.get.mockResolvedValue(listResponse([
      { requisition_id: 8, requisition_no: 'REQ-008', title: 'Synthetic engineer', status: 'PENDING_APPROVAL', headcount: 2, case_id: 10 },
    ]))
    resource.post.mockResolvedValue({
      case_id: 10,
      application_status: 'APPROVED',
      effect_status: 'APPLIED',
      effects: [],
    })

    const wrapper = await mountWithRouter('recruitment/requests/approval', '/recruitment/requests/approval')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/recruitment/requisitions', expect.any(Object))
    await wrapper.get('[data-testid="requisition-approval-case-id"]').setValue('10')
    await wrapper.get('[data-testid="requisition-approval-comment"]').setValue('Synthetic approval')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/cases/10/decisions',
      { action: 'APPROVE', comment: 'Synthetic approval' },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.text()).toContain('Case 10 已批准')
  })

  it('loads funnel board and budget projections from their live endpoints', async () => {

    const boardRow = {
      requisition_id: 8,
      requisition_no: 'REQ-BOARD-1',
      title: 'Synthetic funnel role',
      status: 'APPROVED',
      org_unit_id: 3,
      headcount: 2,
      openings_count: 1,
      candidate_count: 4,
      interview_count: 3,
      offer_count: 1,
      accepted_offers: 1,
      hired_count: 1,
      vacancy_count: 1,
      fill_rate_bps: 5000,
    }

    resource.get.mockResolvedValueOnce(listResponse([boardRow]))
    const board = await mountWithRouter('recruitment/requests/board', '/recruitment/requests/board')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/recruitment/board', expect.any(Object))
    expect(board.text()).toContain('REQ-BOARD-1')
    expect(board.text()).toContain('5000')

    resource.get.mockResolvedValueOnce(listResponse([
      {
        requisition_id: 8,
        requisition_no: 'REQ-BOARD-1',
        title: 'Synthetic funnel role',
        status: 'APPROVED',
        headcount: 2,
        budget_minor: 5000000,
        budget_currency: 'CNY',
        committed_offer_minor: 2000000,
        budget_variance_minor: 3000000,
        filled_headcount: 1,
        vacancy_count: 1,
      },
    ]))
    const budget = await mountWithRouter('recruitment/requests/budget', '/recruitment/requests/budget')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/recruitment/budget', expect.any(Object))
    expect(budget.text()).toContain('3000000')
    expect(budget.text()).toContain('整数分')
  })

  it('loads candidate evaluations for a scoped candidate without candidate PII leakage', async () => {
    resource.get.mockResolvedValue(listResponse([
      {
        evaluation_id: 91,
        candidate_id: 31,
        interview_id: 12,
        evaluator_user_id: 10,
        rating: 4,
        recommendation: 'YES',
        strengths: 'Synthetic strengths',
        concerns: null,
        comments: 'Synthetic comments',
        status: 'RECORDED',
        recorded_at: '2026-11-25T01:00:00Z',
      },
    ]))

    const wrapper = await mountWithRouter(
      'recruitment/candidates/evaluations',
      '/recruitment/candidates/evaluations?candidate_id=31',
    )
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/recruitment/candidates/31/evaluations', {
      candidate_id: 31,
    })
    expect(wrapper.text()).toContain('Synthetic strengths')
    expect(wrapper.text()).toContain('YES')
    expect(wrapper.text()).not.toContain('@')
  })

  it('loads performance indicators from the live endpoint and creates one with an idempotency key', async () => {
    resource.get.mockResolvedValue(listResponse([
      { indicator_id: 71, code: 'IND-QUALITY', name: 'Synthetic quality', category: 'DELIVERY', data_source: 'Ledger', active: true },
    ]))
    resource.post.mockResolvedValue({
      indicator_id: 72,
      code: 'IND-SPEED',
      name: 'Synthetic speed',
      category: null,
      data_source: null,
      description: null,
      active: true,
      recorded_at: '2026-11-25T01:00:00Z',
    })

    const wrapper = await mountWithRouter('performance/indicators', '/performance/indicators')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/performance/indicators', expect.any(Object))
    expect(wrapper.text()).toContain('IND-QUALITY')

    await wrapper.get('[data-testid="indicator-code"]').setValue('IND-SPEED')
    await wrapper.get('[data-testid="indicator-name"]').setValue('Synthetic speed')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/performance/indicators',
      expect.objectContaining({ code: 'IND-SPEED', name: 'Synthetic speed', active: true }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.text()).toContain('指标“IND-SPEED”已创建')
  })

  it('shows REVIEWS_NOT_SUBMITTED when publishing an incomplete cycle without hiding the request id', async () => {
    resource.get.mockResolvedValue(listResponse([
      { review_id: 88, cycle_id: 5, employment_id: 12, cycle_status: 'ACTIVE', status: 'DRAFT', self_rating: '4', manager_rating: null, calibrated_rating: '4.5' },
    ]))
    resource.post.mockRejectedValue({
      code: 'CONFLICT',
      status: 409,
      request_id: 'req-publish-incomplete',
      data: { reason: 'REVIEWS_NOT_SUBMITTED', unsubmitted_reviews: 1 },
    } as unknown as Error)

    const wrapper = await mountWithRouter('performance/results', '/performance/results')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/performance/results', expect.any(Object))
    await wrapper.get('[data-testid="publish-cycle-id"]').setValue('5')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/performance/cycles/5/publish',
      {},
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.text()).toContain('REVIEWS_NOT_SUBMITTED')
    expect(wrapper.text()).toContain('req-publish-incomplete')
  })

  it('posts calibration without asking the client to alter self or manager ratings', async () => {
    resource.get.mockResolvedValue(listResponse())
    resource.post.mockResolvedValue({
      calibration_id: 3,
      review_id: 66,
      cycle_id: 5,
      previous_rating: null,
      calibrated_rating: '3.5',
      self_rating: '4',
      manager_rating: '4',
      reason: 'Synthetic calibration',
    })

    const wrapper = await mountWithRouter('performance/calibration', '/performance/calibration')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/performance/calibration', expect.any(Object))
    await wrapper.get('[data-testid="calibration-review-id"]').setValue('66')
    await wrapper.get('[data-testid="calibration-rating"]').setValue('3.5')
    await wrapper.get('[data-testid="calibration-reason"]').setValue('Synthetic calibration')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/performance/reviews/66/calibration',
      { calibrated_rating: '3.5', reason: 'Synthetic calibration' },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.text()).toContain('自评与主管评分保持不变')
  })
  it('loads training plans from the live plan ledger and creates one with integer-cents budget', async () => {
    resource.get.mockResolvedValue(listResponse([
      { plan_id: 141, code: 'PLAN-2027', title: 'Synthetic annual plan', year: 2027, budget_cents: 1250000, currency: 'CNY', status: 'ACTIVE' },
    ]))
    resource.post.mockResolvedValue({
      plan_id: 142,
      code: 'PLAN-2028',
      title: 'Synthetic 2028 plan',
      year: 2028,
      budget_cents: 900000,
      currency: 'CNY',
      status: 'ACTIVE',
      recorded_at: '2026-11-25T01:00:00Z',
    })

    const wrapper = await mountWithRouter('training/index', '/training')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/training/plans', expect.any(Object))
    expect(wrapper.text()).toContain('Synthetic annual plan')
    expect(wrapper.text()).toContain('1250000')

    await wrapper.get('[data-testid="plan-code"]').setValue('PLAN-2028')
    await wrapper.get('[data-testid="plan-title"]').setValue('Synthetic 2028 plan')
    await wrapper.get('[data-testid="plan-year"]').setValue('2028')
    await wrapper.get('[data-testid="plan-budget-cents"]').setValue('900000')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(resource.post).toHaveBeenCalledWith(
      '/v1/training/plans',
      expect.objectContaining({
        code: 'PLAN-2028',
        title: 'Synthetic 2028 plan',
        year: 2028,
        budget_cents: 900000,
      }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.text()).toContain('培训计划“Synthetic 2028 plan”已创建')
    expect(wrapper.text()).toContain('预算与费用均以整数分提交')
  })

  it('loads the live course catalogue and creates a course linked to a plan', async () => {
    resource.get.mockResolvedValue(listResponse([
      { course_id: 51, plan_id: 141, code: 'COURSE-SAFE', title: 'Synthetic safety course', instructor: 'Synthetic Instructor', delivery_mode: 'ONSITE', duration_minutes: 180, cost_cents: 50000, status: 'ACTIVE' },
    ]))
    resource.post.mockResolvedValue({
      course_id: 52,
      plan_id: 141,
      code: 'COURSE-NEW',
      title: 'Synthetic new course',
      instructor: 'Synthetic Instructor',
      delivery_mode: 'ONLINE',
      duration_minutes: 90,
      cost_cents: 25000,
      currency: 'CNY',
      materials: null,
      status: 'ACTIVE',
      recorded_at: '2026-11-25T01:00:00Z',
    })

    const wrapper = await mountWithRouter('training/courses', '/training/courses')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/training/courses', expect.any(Object))
    expect(wrapper.text()).toContain('Synthetic safety course')

    await wrapper.get('[data-testid="course-plan-id"]').setValue('141')
    await wrapper.get('[data-testid="course-code"]').setValue('COURSE-NEW')
    await wrapper.get('[data-testid="course-title"]').setValue('Synthetic new course')
    await wrapper.get('[data-testid="course-instructor"]').setValue('Synthetic Instructor')
    const deliveryMode = wrapper.getComponent('[data-testid="course-delivery-mode"]') as VueWrapper
    deliveryMode.vm.$emit('update:modelValue', 'ONLINE')
    deliveryMode.vm.$emit('change', 'ONLINE')
    await wrapper.get('[data-testid="course-duration-minutes"]').setValue('90')
    await wrapper.get('[data-testid="course-cost-cents"]').setValue('25000')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/training/courses',
      expect.objectContaining({
        plan_id: 141,
        code: 'COURSE-NEW',
        title: 'Synthetic new course',
        delivery_mode: 'ONLINE',
        duration_minutes: 90,
        cost_cents: 25000,
      }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.text()).toContain('课程“Synthetic new course”已创建')
  })

  it('loads check-ins with derived study minutes and records a check-in for a session', async () => {
    resource.get.mockResolvedValue(listResponse([
      { checkin_id: 61, session_id: 91, employment_id: 45, checked_in_at: '2026-12-01T09:00:00Z', checked_out_at: '2026-12-01T11:30:00Z', study_minutes: 150 },
    ]))
    resource.post.mockResolvedValue({
      checkin_id: 61,
      session_id: 91,
      employment_id: 45,
      checked_in_at: '2026-12-01T09:00:00Z',
      checked_out_at: '2026-12-01T11:30:00Z',
      study_minutes: 150,
      recorded_at: '2026-11-25T01:00:00Z',
    })

    const wrapper = await mountWithRouter('training/checkins', '/training/checkins')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/training/checkins', expect.any(Object))
    expect(wrapper.text()).toContain('150')
    expect(wrapper.text()).toContain('学时由签到/签退时间派生')
    expect(wrapper.get('[data-testid="lifecycle-summary"]').text()).toContain('学时合计（分钟）')
    expect(wrapper.get('[data-testid="lifecycle-summary"]').text()).toContain('150')

    await wrapper.get('[data-testid="checkin-session-id"]').setValue('91')
    await wrapper.get('[data-testid="checkin-employment-id"]').setValue('45')
    await wrapper.get('[data-testid="checkin-in-time"]').setValue('2026-12-01T09:00')
    await wrapper.get('[data-testid="checkin-out-time"]').setValue('2026-12-01T11:30')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/training/sessions/91/checkins',
      expect.objectContaining({
        employment_id: 45,
        checked_in_at: '2026-12-01T09:00',
        checked_out_at: '2026-12-01T11:30',
      }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.text()).toContain('签到已记录')
  })

  it('creates and publishes versioned training exams through the real endpoints', async () => {
    resource.get.mockResolvedValue(listResponse([
      { exam_id: 71, course_id: 51, code: 'EXAM-SAFE', title: 'Synthetic safety exam', version: 1, passing_score: 60, max_score: 100, status: 'PUBLISHED' },
    ]))
    resource.post.mockResolvedValueOnce({
      exam_id: 72,
      course_id: 51,
      code: 'EXAM-SAFE',
      title: 'Synthetic safety exam v2',
      version: 2,
      passing_score: 70,
      max_score: 100,
      status: 'DRAFT',
      published_at: null,
      recorded_at: '2026-11-25T01:00:00Z',
    })
    resource.post.mockResolvedValueOnce({
      exam_id: 72,
      course_id: 51,
      code: 'EXAM-SAFE',
      title: 'Synthetic safety exam v2',
      version: 2,
      passing_score: 70,
      max_score: 100,
      status: 'PUBLISHED',
      published_at: '2026-11-25T02:00:00Z',
      recorded_at: '2026-11-25T01:00:00Z',
    })

    const wrapper = await mountWithRouter('training/exams', '/training/exams')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/training/exams', expect.any(Object))
    expect(wrapper.text()).toContain('PUBLISHED')

    await wrapper.get('[data-testid="exam-course-id"]').setValue('51')
    await wrapper.get('[data-testid="exam-code"]').setValue('EXAM-SAFE')
    await wrapper.get('[data-testid="exam-title"]').setValue('Synthetic safety exam v2')
    await wrapper.get('[data-testid="exam-passing-score"]').setValue('70')
    await wrapper.get('[data-testid="exam-max-score"]').setValue('100')
    const forms = wrapper.findAll('form')
    const examForm = forms[0]
    const publishForm = forms[1]
    if (examForm === undefined || publishForm === undefined) throw new Error('Expected both exam forms')
    await examForm.trigger('submit')
    await flushPromises()

    expect(resource.post).toHaveBeenNthCalledWith(
      1,
      '/v1/training/exams',
      expect.objectContaining({ course_id: 51, code: 'EXAM-SAFE', title: 'Synthetic safety exam v2', passing_score: 70, max_score: 100 }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )

    await wrapper.get('[data-testid="exam-publish-id"]').setValue('72')
    await publishForm.trigger('submit')
    await flushPromises()

    expect(resource.post).toHaveBeenNthCalledWith(
      2,
      '/v1/training/exams/72/publish',
      {},
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.text()).toContain('考试版本 2 已发布并冻结')
  })

  it('appends training feedback per enrollment without rewriting prior rows', async () => {
    resource.get.mockResolvedValue(listResponse([
      { training_feedback_id: 81, enrollment_id: 77, session_id: 91, employment_id: 45, rating: 5, comments: 'Synthetic prior feedback', status: 'RECORDED' },
    ]))
    resource.post.mockResolvedValue({
      training_feedback_id: 82,
      enrollment_id: 77,
      session_id: 91,
      employment_id: 45,
      rating: 4,
      comments: 'Synthetic appended feedback',
      questionnaire: null,
      status: 'RECORDED',
      recorded_at: '2026-11-25T01:00:00Z',
    })

    const wrapper = await mountWithRouter('training/feedback', '/training/feedback')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/training/feedback', expect.any(Object))
    expect(wrapper.text()).toContain('Synthetic prior feedback')

    await wrapper.get('[data-testid="training-feedback-enrollment-id"]').setValue('77')
    await wrapper.get('[data-testid="training-feedback-rating"]').setValue('4')
    await wrapper.get('[data-testid="training-feedback-comments"]').setValue('Synthetic appended feedback')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/training/feedback',
      expect.objectContaining({ enrollment_id: 77, rating: 4, comments: 'Synthetic appended feedback' }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.text()).toContain('反馈已追加')
    expect(wrapper.text()).toContain('反馈仅追加，不覆盖历史行')
  })

  it('loads personal training records and aggregate reports from live projections', async () => {
    resource.get.mockResolvedValueOnce(listResponse([
      { enrollment_id: 77, employment_id: 45, session_id: 91, course_code: 'COURSE-SAFE', course_title: 'Synthetic safety course', status: 'COMPLETED', completion_result: 'PASS', score: 88, study_minutes: 150, checkin_count: 1, feedback_count: 1, latest_exam_version: 1 },
    ]))
    const records = await mountWithRouter('training/records', '/training/records')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/training/records', expect.any(Object))
    expect(records.text()).toContain('COURSE-SAFE')
    expect(records.text()).toContain('150')
    expect(records.text()).toContain('学时由签到/签退时间派生')

    resource.get.mockResolvedValueOnce(listResponse([
      { plan_id: 141, code: 'PLAN-2027', title: 'Synthetic annual plan', year: 2027, budget_cents: 1250000, training_cost_cents: 50000, currency: 'CNY', course_count: 1, session_count: 1, enrolled_count: 1, completed_count: 1, completion_rate_bps: 10000, exam_pass_count: 1, exam_pass_rate_bps: 10000, study_minutes_total: 90, feedback_count: 1, average_feedback_rating_bps: 10000 },
    ]))
    const reports = await mountWithRouter('training/reports', '/training/reports')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/training/reports', expect.any(Object))
    expect(reports.text()).toContain('10000')
    expect(reports.text()).toContain('报表比率以基点表示')
  })

})
