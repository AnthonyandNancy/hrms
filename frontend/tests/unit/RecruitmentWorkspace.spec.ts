import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import ElementPlus from 'element-plus'
import type { Component } from 'vue'
import { ApiError } from '@/api/errors'
import { useSessionStore } from '@/stores/session'

const resource = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  patch: vi.fn(),
}))

vi.mock('@/api/resourceClient', () => ({
  resourceGet: (...args: unknown[]) => resource.get(...args),
  resourcePost: (...args: unknown[]) => resource.post(...args),
  resourcePatch: (...args: unknown[]) => resource.patch(...args),
}))

import RequisitionWorkspacePage from '@/modules/talent/recruiting/pages/RequisitionWorkspacePage.vue'
import CandidateWorkspacePage from '@/modules/talent/recruiting/pages/CandidateWorkspacePage.vue'
import CandidateDetailPage from '@/modules/talent/recruiting/pages/CandidateDetailPage.vue'

const ORG_UNITS = [
  { id: 3, parent_id: null, code: 'RD', name: '研发部', type: 'DEPARTMENT' },
  { id: 4, parent_id: null, code: 'HR', name: '人力资源部', type: 'DEPARTMENT' },
]

function listEnvelope(items: unknown[], overrides: Record<string, unknown> = {}) {
  return { items, page: 1, page_size: 100, total: items.length, ...overrides }
}

function requisition(overrides: Record<string, unknown> = {}) {
  return {
    requisition_id: 11,
    requisition_no: 'REQ-2026-0011',
    title: '合成工程师',
    org_unit_id: 3,
    org_unit_name: '研发部',
    status: 'APPROVED',
    headcount: 2,
    budget_minor: 1234567,
    budget_currency: 'CNY',
    target_start_date: '2027-01-04',
    opened_at: '2026-11-20T02:00:00Z',
    case_id: 9,
    ...overrides,
  }
}

function candidate(overrides: Record<string, unknown> = {}) {
  return {
    candidate_id: 21,
    display_name: '合成候选人',
    status: 'NEW',
    has_email: true,
    has_mobile: true,
    source: 'REFERRAL',
    requisition_id: 11,
    offer_id: null,
    offer_status: null,
    base_salary_minor: null,
    salary_currency: null,
    offer_start_date: null,
    next_interview_at: null,
    converted_employment_id: null,
    converted_employee_no: null,
    recorded_at: '2026-11-18T02:00:00Z',
    ...overrides,
  }
}

const INTERVIEW = {
  interview_id: 51,
  candidate_id: 21,
  requisition_id: 11,
  scheduled_at: '2026-11-25T01:00:00Z',
  duration_minutes: 60,
  interviewer: '合成面试官',
  mode: 'VIDEO',
  status: 'COMPLETED',
  recommendation: 'YES',
  feedback: '合成面试反馈，技术基础扎实。',
  feedback_restricted: false,
}

const OFFER = {
  offer_id: 61,
  candidate_id: 21,
  requisition_id: 11,
  version: 1,
  status: 'ACCEPTED',
  base_salary_minor: 2500000,
  salary_currency: 'CNY',
  start_date: '2026-12-01',
  accepted_at: '2026-11-26T02:00:00Z',
}

const CAPABILITIES = [
  'recruitment.requisition.read',
  'recruitment.requisition.manage',
  'recruitment.candidate.read',
  'recruitment.candidate.manage',
  'recruitment.candidate.convert',
  'recruitment.evaluation.manage',
  'recruitment.offer.manage',
]

function piniaWith(capabilities: string[]) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const session = useSessionStore()
  session.capabilities = capabilities
  session.status = 'authenticated'
  return pinia
}

async function mountAt(
  component: Component,
  path: string,
  capabilities: string[] = CAPABILITIES,
  flush = true,
): Promise<VueWrapper> {
  const pinia = piniaWith(capabilities)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/recruitment/requests', name: 'requisitions', component: { template: '<div />' } },
      { path: '/recruitment/candidates', name: 'candidates', component: { template: '<div />' } },
      { path: '/recruitment/candidates/:id', name: 'candidate-detail', component: { template: '<div />' } },
      { path: '/hr/employees/:id', name: 'employee-detail', component: { template: '<div />' } },
    ],
  })
  await router.push(path)
  await router.isReady()

  const wrapper = mount(component, {
    global: { plugins: [pinia, router, ElementPlus] },
  })
  if (flush) {
    await flushPromises()
    await flushPromises()
  }
  return wrapper
}

function mockRequisitionLoads(rows: () => unknown[]) {
  resource.get.mockImplementation((path: string) => {
    if (path === '/v1/recruitment/requisitions') return Promise.resolve(listEnvelope(rows()))
    if (path === '/v1/org-units/tree') return Promise.resolve(listEnvelope(ORG_UNITS))
    return Promise.reject(new Error(`Unexpected GET ${path}`))
  })
}

beforeEach(() => {
  resource.get.mockReset()
  resource.post.mockReset()
  resource.patch.mockReset()
  resource.post.mockResolvedValue({})
  resource.patch.mockResolvedValue({})
})

describe('requisition workspace', () => {
  it('renders live requisition rows with Chinese status, org unit, headcount, opened date and render-only money', async () => {
    mockRequisitionLoads(() => [requisition()])

    const wrapper = await mountAt(RequisitionWorkspacePage, '/recruitment/requests')

    expect(resource.get).toHaveBeenCalledWith(
      '/v1/recruitment/requisitions',
      expect.objectContaining({ page: 1, page_size: 50 }),
    )
    const text = wrapper.text()
    expect(text).toContain('招聘需求')
    expect(text).toContain('合成工程师')
    expect(text).toContain('研发部')
    expect(text).toContain('招聘中')
    expect(text).toContain('2')
    expect(text).toContain('2026-11-20')
    expect(text).toContain('¥12,345.67')
    expect(wrapper.findAll('tbody tr')).toHaveLength(1)
  })

  it('creates a requisition through the drawer using integer minor units and an idempotency key', async () => {
    const rows: unknown[] = []
    mockRequisitionLoads(() => rows)
    resource.post.mockImplementation((path: string) => {
      if (path === '/v1/recruitment/requisitions') {
        const created = requisition({
          requisition_id: 12,
          requisition_no: 'REQ-2026-0012',
          title: '合成测试工程师',
          status: 'PENDING_APPROVAL',
          headcount: 3,
          budget_minor: 2500050,
        })
        rows.push(created)
        return Promise.resolve(created)
      }
      return Promise.reject(new Error(`Unexpected POST ${path}`))
    })

    const wrapper = await mountAt(RequisitionWorkspacePage, '/recruitment/requests')
    await wrapper.get('[data-testid="requisition-create"]').trigger('click')

    expect(wrapper.find('[data-testid="requisition-drawer"]').exists()).toBe(true)
    await wrapper.get('[data-testid="requisition-no"]').setValue('REQ-2026-0012')
    await wrapper.get('[data-testid="requisition-title"]').setValue('合成测试工程师')
    await wrapper.get('[data-testid="requisition-org-unit"]').setValue('3')
    await wrapper.get('[data-testid="requisition-headcount"]').setValue('3')
    await wrapper.get('[data-testid="requisition-budget-minor"]').setValue('2500050')
    await wrapper.get('[data-testid="requisition-drawer"] form').trigger('submit')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/recruitment/requisitions',
      expect.objectContaining({
        requisition_no: 'REQ-2026-0012',
        title: '合成测试工程师',
        org_unit_id: 3,
        headcount: 3,
        budget_minor: 2500050,
      }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.text()).toContain('合成测试工程师')
    expect(wrapper.text()).toContain('招聘中')
    expect(wrapper.text()).toContain('¥25,000.50')
  })

  it('edits an existing requisition and requires confirmation before closing it', async () => {
    mockRequisitionLoads(() => [requisition()])
    resource.patch.mockImplementation((path: string, body: Record<string, unknown>) => {
      if (path === '/v1/recruitment/requisitions/11') {
        return Promise.resolve(requisition({ title: String(body.title), status: 'APPROVED' }))
      }
      return Promise.reject(new Error(`Unexpected PATCH ${path}`))
    })
    resource.post.mockImplementation((path: string) => {
      if (path === '/v1/recruitment/requisitions/11/close') {
        return Promise.resolve(requisition({ status: 'CLOSED', replayed: false }))
      }
      return Promise.reject(new Error(`Unexpected POST ${path}`))
    })

    const wrapper = await mountAt(RequisitionWorkspacePage, '/recruitment/requests')

    await wrapper.get('[data-testid="requisition-edit-11"]').trigger('click')
    await wrapper.get('[data-testid="requisition-title"]').setValue('高级合成工程师')
    await wrapper.get('[data-testid="requisition-drawer"] form').trigger('submit')
    await flushPromises()

    expect(resource.patch).toHaveBeenCalledWith(
      '/v1/recruitment/requisitions/11',
      expect.objectContaining({ title: '高级合成工程师' }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.text()).toContain('高级合成工程师')

    await wrapper.get('[data-testid="requisition-close-11"]').trigger('click')
    const closeDialog = wrapper.get('[data-testid="requisition-close-dialog"]')
    expect(closeDialog.text()).toContain('高级合成工程师')
    expect(closeDialog.text()).toContain('关闭后不能继续新增候选人')
    expect(resource.post).not.toHaveBeenCalledWith(
      '/v1/recruitment/requisitions/11/close',
      expect.anything(),
      expect.anything(),
    )

    await wrapper.get('[data-testid="requisition-close-confirm"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/recruitment/requisitions/11/close',
      expect.objectContaining({ reason: expect.any(String) }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.text()).toContain('已关闭')
  })

  it('shows forbidden without fetching, an honest empty state, an error state and a partial org lookup state', async () => {
    const forbidden = await mountAt(RequisitionWorkspacePage, '/recruitment/requests', [])
    expect(forbidden.text()).toContain('无权查看招聘需求')
    expect(resource.get).not.toHaveBeenCalled()

    resource.get.mockResolvedValue(listEnvelope([]))
    const empty = await mountAt(RequisitionWorkspacePage, '/recruitment/requests')
    expect(empty.text()).toContain('还没有招聘需求')
    expect(empty.findAll('tbody tr')).toHaveLength(0)

    resource.get.mockRejectedValue(new ApiError('INTERNAL', '招聘服务暂时不可用', { status: 500, requestId: 'req-rec-error' }))
    const error = await mountAt(RequisitionWorkspacePage, '/recruitment/requests')
    expect(error.text()).toContain('招聘服务暂时不可用')
    expect(error.text()).toContain('req-rec-error')

    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/recruitment/requisitions') return Promise.resolve(listEnvelope([requisition()]))
      if (path === '/v1/org-units/tree') return Promise.reject(new ApiError('FORBIDDEN', '无组织树权限', { status: 403 }))
      return Promise.reject(new Error(`Unexpected GET ${path}`))
    })
    const partial = await mountAt(RequisitionWorkspacePage, '/recruitment/requests')
    expect(partial.text()).toContain('部分数据')
    expect(partial.text()).toContain('组织单元目录')
    expect(partial.text()).toContain('合成工程师')
  })

  it('renders the loading state until the requisition read resolves', async () => {
    resource.get.mockReturnValue(new Promise(() => undefined))
    const wrapper = await mountAt(RequisitionWorkspacePage, '/recruitment/requests', CAPABILITIES, false)
    await flushPromises()
    expect(wrapper.text()).toContain('正在加载招聘需求')
    wrapper.unmount()
  })
})

describe('candidate pipeline', () => {
  function mockCandidateLoads(rows: () => unknown[], candidates: () => unknown[]) {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/recruitment/requisitions') return Promise.resolve(listEnvelope(rows()))
      if (path === '/v1/recruitment/candidates') return Promise.resolve(listEnvelope(candidates()))
      if (path === '/v1/org-units/tree') return Promise.resolve(listEnvelope(ORG_UNITS))
      return Promise.reject(new Error(`Unexpected GET ${path}`))
    })
  }

  it('renders the six real stages, filters by requisition and never invents empty rows', async () => {
    mockCandidateLoads(
      () => [requisition()],
      () => [
        candidate(),
        candidate({ candidate_id: 22, display_name: '筛选候选人', status: 'SCREENING' }),
        candidate({ candidate_id: 23, display_name: '面试候选人', status: 'INTERVIEW' }),
        candidate({ candidate_id: 24, display_name: 'Offer 候选人', status: 'OFFER' }),
        candidate({ candidate_id: 25, display_name: '已入职候选人', status: 'CONVERTED', converted_employment_id: 77, converted_employee_no: 'CONV-0077' }),
        candidate({ candidate_id: 26, display_name: '淘汰候选人', status: 'REJECTED' }),
      ],
    )

    const wrapper = await mountAt(CandidateWorkspacePage, '/recruitment/candidates')

    const text = wrapper.text()
    for (const label of ['新简历', '筛选', '面试', 'offer', '已入职', '已淘汰']) {
      expect(text).toContain(label)
    }
    expect(wrapper.get('[data-testid="pipeline-column-NEW"]').text()).toContain('合成候选人')
    expect(wrapper.get('[data-testid="pipeline-column-SCREENING"]').text()).toContain('筛选候选人')
    expect(wrapper.get('[data-testid="pipeline-column-HIRED"]').text()).toContain('CONV-0077')
    expect(wrapper.find('[data-testid="candidate-requisition-filter"]').exists()).toBe(true)

    const requisitionFilter = wrapper.getComponent('[data-testid="candidate-requisition-filter"]') as VueWrapper
    await requisitionFilter.setValue('11')
    requisitionFilter.vm.$emit('change', '11')
    await flushPromises()
    await flushPromises()
    await flushPromises()

    expect(resource.get).toHaveBeenLastCalledWith(
      '/v1/recruitment/candidates',
      expect.objectContaining({ requisition_id: 11, page: 1, page_size: 100 }),
    )
  })

  it('confirms an explicit stage move before calling the API and keeps a failed move open in Chinese', async () => {
    mockCandidateLoads(() => [requisition()], () => [candidate()])
    resource.patch
      .mockRejectedValueOnce(
        new ApiError('CONFLICT', 'Candidate status changed.', {
          status: 409,
          details: { reason: 'CANDIDATE_STAGE_CONFLICT' },
        }),
      )
      .mockResolvedValueOnce({ candidate_id: 21, status: 'OFFER', previous_status: 'NEW', replayed: false })

    const wrapper = await mountAt(CandidateWorkspacePage, '/recruitment/candidates')

    await wrapper.get('[data-testid="candidate-target-21"]').setValue('OFFER')
    await wrapper.get('[data-testid="candidate-move-21"]').trigger('click')

    const dialog = wrapper.get('[data-testid="candidate-stage-dialog"]')
    expect(dialog.text()).toContain('合成候选人')
    expect(dialog.text()).toContain('offer')
    expect(resource.patch).not.toHaveBeenCalled()

    await wrapper.get('[data-testid="candidate-stage-confirm"]').trigger('click')
    await flushPromises()

    expect(resource.patch).toHaveBeenCalledWith(
      '/v1/recruitment/candidates/21/stage',
      expect.objectContaining({ status: 'OFFER' }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.get('[data-testid="candidate-stage-dialog"]').text()).toContain('候选人阶段已变化')

    await wrapper.get('[data-testid="candidate-stage-confirm"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="candidate-stage-dialog"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="pipeline-column-OFFER"]').text()).toContain('合成候选人')
  })

  it('shows loading, forbidden, empty, error and partial states', async () => {
    resource.get.mockReturnValue(new Promise(() => undefined))
    const loading = await mountAt(CandidateWorkspacePage, '/recruitment/candidates', CAPABILITIES, false)
    await flushPromises()
    expect(loading.text()).toContain('正在加载候选人')
    loading.unmount()

    resource.get.mockReset()
    const forbidden = await mountAt(CandidateWorkspacePage, '/recruitment/candidates', [])
    expect(forbidden.text()).toContain('无权查看候选人')
    expect(resource.get).not.toHaveBeenCalled()

    mockCandidateLoads(() => [], () => [])
    const empty = await mountAt(CandidateWorkspacePage, '/recruitment/candidates')
    expect(empty.text()).toContain('还没有候选人')

    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/recruitment/requisitions') return Promise.reject(new ApiError('FORBIDDEN', '无需求读取权限', { status: 403 }))
      if (path === '/v1/recruitment/candidates') return Promise.resolve(listEnvelope([candidate()]))
      return Promise.reject(new Error(`Unexpected GET ${path}`))
    })
    const partial = await mountAt(CandidateWorkspacePage, '/recruitment/candidates')
    expect(partial.text()).toContain('部分数据')
    expect(partial.text()).toContain('招聘需求目录')
    expect(partial.text()).toContain('合成候选人')

    resource.get.mockRejectedValue(new ApiError('INTERNAL', '候选人服务暂时不可用', { status: 500 }))
    const error = await mountAt(CandidateWorkspacePage, '/recruitment/candidates')
    expect(error.text()).toContain('候选人服务暂时不可用')
  })
})

describe('candidate detail and conversion', () => {
  function mockDetailLoads(overrides: { candidate?: Record<string, unknown>; interviews?: unknown[]; offers?: unknown[] } = {}) {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/recruitment/candidates') {
        return Promise.resolve(listEnvelope([candidate({ status: 'OFFER_ACCEPTED', ...overrides.candidate })]))
      }
      if (path === '/v1/recruitment/candidates/21/interviews') {
        return Promise.resolve(listEnvelope(overrides.interviews ?? [INTERVIEW]))
      }
      if (path === '/v1/recruitment/offers') {
        return Promise.resolve(listEnvelope(overrides.offers ?? [OFFER]))
      }
      if (path === '/v1/org-units/tree') return Promise.resolve(listEnvelope(ORG_UNITS))
      return Promise.reject(new Error(`Unexpected GET ${path}`))
    })
  }

  it('renders the candidate profile, interview records, restricted feedback and render-only offer money', async () => {
    mockDetailLoads()

    const wrapper = await mountAt(CandidateDetailPage, '/recruitment/candidates/21')

    expect(wrapper.text()).toContain('合成候选人')
    expect(wrapper.text()).toContain('已接受 Offer')
    expect(wrapper.text()).toContain('合成面试官')
    expect(wrapper.text()).toContain('合成面试反馈')
    expect(wrapper.text()).toContain('¥25,000.00')
    expect(wrapper.text()).not.toContain('candidate@example.test')
  })

  it('shows the feedback permission boundary when the interview projection is redacted', async () => {
    mockDetailLoads({
      interviews: [{ ...INTERVIEW, feedback: undefined, recommendation: undefined, feedback_restricted: true }],
    })

    const wrapper = await mountAt(CandidateDetailPage, '/recruitment/candidates/21', [
      'recruitment.candidate.read',
      'recruitment.candidate.convert',
      'recruitment.offer.manage',
    ])

    expect(wrapper.text()).toContain('面试反馈仅授权可见')
  })

  it('hides conversion and offer reads when the corresponding capabilities are absent', async () => {
    mockDetailLoads()

    const wrapper = await mountAt(CandidateDetailPage, '/recruitment/candidates/21', ['recruitment.candidate.read'])

    expect(wrapper.find('[data-testid="candidate-convert"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('Offer 信息需要 recruitment.offer.manage')
    expect(resource.get).not.toHaveBeenCalledWith('/v1/recruitment/offers', expect.anything())
  })

  it('shows an honest empty state for an unknown candidate id', async () => {
    mockDetailLoads()
    const wrapper = await mountAt(CandidateDetailPage, '/recruitment/candidates/9999')
    expect(wrapper.text()).toContain('未找到该候选人')
  })
})
