import { flushPromises, mount } from '@vue/test-utils'
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

import CandidateDetailPage from '@/modules/talent/recruiting/pages/CandidateDetailPage.vue'

function envelope(items: unknown[] = []) {
  return { items, page: 1, page_size: Math.max(1, items.length), total: items.length }
}

const CANDIDATE = {
  candidate_id: 21,
  display_name: '合成候选人',
  status: 'OFFER_ACCEPTED',
  has_email: true,
  has_mobile: false,
  source: 'REFERRAL',
  application_id: 31,
  application_stage: 'OFFER_ACCEPTED',
  requisition_id: 11,
  onboarding_case_id: 71,
  conversion_status: 'PENDING_ONBOARDING',
  offer_id: 61,
  offer_status: 'ACCEPTED',
  base_salary_minor: 2_500_000,
  salary_currency: 'CNY',
  offer_start_date: '2026-12-01',
  next_interview_at: null,
  converted_employment_id: null,
  converted_employee_no: null,
  recorded_at: '2026-11-18T02:00:00Z',
}

const OFFER = {
  offer_id: 61,
  candidate_id: 21,
  requisition_id: 11,
  version: 1,
  status: 'ACCEPTED',
  base_salary_minor: 2_500_000,
  salary_currency: 'CNY',
  start_date: '2026-12-01',
  case_id: 81,
  onboarding_case_id: 71,
  accepted_at: '2026-11-26T02:00:00Z',
}

const ALL_CAPABILITIES = [
  'recruitment.candidate.read',
  'recruitment.candidate.manage',
  'recruitment.candidate.convert',
  'recruitment.interview.manage',
  'recruitment.evaluation.manage',
  'recruitment.offer.manage',
  'recruitment.offer.accept',
  'recruitment.communication.manage',
  'recruitment.talent-pool.manage',
]

function mockLoads() {
  resource.get.mockImplementation((path: string) => {
    if (path === '/v1/recruitment/candidates') return Promise.resolve(envelope([CANDIDATE]))
    if (path === '/v1/recruitment/candidates/21/interviews') return Promise.resolve(envelope([]))
    if (path === '/v1/recruitment/offers') return Promise.resolve(envelope([OFFER]))
    if (path === '/v1/org-units/tree') return Promise.resolve(envelope([{ id: 3, code: 'RD', name: '研发部' }]))
    if (path === '/v1/recruitment/candidates/21/applications') return Promise.resolve(envelope([]))
    if (path === '/v1/recruitment/candidates/21/evaluations') return Promise.resolve(envelope([]))
    if (path === '/v1/recruitment/candidates/21/communications') return Promise.resolve(envelope([]))
    if (path === '/v1/recruitment/candidates/21/talent-pool') return Promise.resolve(envelope([]))
    if (path === '/v1/recruitment/requisitions') return Promise.resolve(envelope([]))
    return Promise.reject(new Error(`Unexpected GET ${path}`))
  })
}

async function mountDetail(capabilities: string[] = ALL_CAPABILITIES, path = '/recruitment/candidates/21') {
  const pinia = createPinia()
  setActivePinia(pinia)
  const session = useSessionStore()
  session.capabilities = capabilities
  session.status = 'authenticated'
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/recruitment/candidates/:id', name: 'candidate-detail', component: { template: '<div />' } },
      { path: '/recruitment/candidates', name: 'candidates', component: { template: '<div />' } },
      { path: '/hr/employees/:id', name: 'employee-detail', component: { template: '<div />' } },
      { path: '/workflow/cases/:id', name: 'case-detail', component: { template: '<div />' } },
    ],
  })
  await router.push(path)
  await router.isReady()

  return mount(CandidateDetailPage as Component, {
    global: { plugins: [pinia, router, ElementPlus] },
  })
}


describe('M16 candidate detail eight tabs', () => {
  beforeEach(() => {
    resource.get.mockReset()
    resource.post.mockReset()
    resource.patch.mockReset()
    mockLoads()
  })

  it('renders capability-gated tabs for applications, interviews, evaluations, offer, communication, talent pool and conversion', async () => {
    const wrapper = await mountDetail()
    await flushPromises()

    const labels = wrapper.findAll('.el-tabs__item').map((tab) => tab.text())
    expect(labels).toEqual(expect.arrayContaining([
      '概览',
      '应聘申请',
      '面试安排',
      '面试评价',
      'Offer',
      '沟通记录',
      '人才库',
      '录用转入职',
    ]))
    expect(labels).toHaveLength(8)
  })

  it('hides sensitive tabs when their capabilities are absent', async () => {
    const wrapper = await mountDetail(['recruitment.candidate.read'])
    await flushPromises()

    const labels = wrapper.findAll('.el-tabs__item').map((tab) => tab.text())
    expect(labels).toEqual(['概览', '应聘申请', '面试安排'])
    expect(wrapper.text()).toContain('Offer 信息需要 recruitment.offer.manage')
    expect(resource.get).not.toHaveBeenCalledWith('/v1/recruitment/offers', expect.anything())
  })

  it('shows the pending onboarding case instead of inventing an employee after conversion', async () => {
    resource.post.mockResolvedValue({
      conversion_id: null,
      candidate_id: 21,
      employment_id: null,
      person_id: null,
      offer_id: 61,
      org_unit_id: 3,
      employee_no: 'CONV-2026-0001',
      status: 'PENDING_ONBOARDING',
      onboarding_case_id: 71,
      converted_at: null,
      business_replayed: false,
    })

    const wrapper = await mountDetail(ALL_CAPABILITIES, '/recruitment/candidates/21?tab=conversion')
    await flushPromises()

    await wrapper.findAllComponents({ name: 'ElSelect' })[0]!.vm.$emit('update:modelValue', '3')
    await wrapper.findAllComponents({ name: 'ElInput' })[0]!.vm.$emit('update:modelValue', 'CONV-2026-0001')
    await wrapper.findAllComponents({ name: 'ElInput' })[1]!.vm.$emit('update:modelValue', '2026-12-01')
    await flushPromises()
    await wrapper.get('[data-testid="conversion-submit"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/recruitment/candidates/21/convert',
      { org_unit_id: 3, employee_no: 'CONV-2026-0001', hire_date: '2026-12-01' },
      { idempotencyKey: expect.any(String) },
    )
    expect(wrapper.get('[data-testid="conversion-onboarding-link"]').attributes('href')).toBe('/workflow/cases/71')
    expect(wrapper.text()).toContain('员工尚未创建')
  })

  it('keeps the conversion input on duplicate employee number and shows the request id', async () => {
    resource.post.mockRejectedValue(
      new ApiError('CONFLICT', 'Employee number must be unique.', {
        status: 409,
        requestId: 'req-convert-duplicate',
        details: { reason: 'EMPLOYEE_NO_DUPLICATE' },
      }),
    )

    const wrapper = await mountDetail(ALL_CAPABILITIES, '/recruitment/candidates/21?tab=conversion')
    await flushPromises()

    await wrapper.findAllComponents({ name: 'ElSelect' })[0]!.vm.$emit('update:modelValue', '3')
    await wrapper.findAllComponents({ name: 'ElInput' })[0]!.vm.$emit('update:modelValue', 'CONV-2026-0001')
    await flushPromises()
    await wrapper.get('[data-testid="conversion-submit"]').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('员工编号已存在')
    expect(wrapper.text()).toContain('req-convert-duplicate')
    expect(wrapper.findAllComponents({ name: 'ElInput' })[0]!.props('modelValue')).toBe('CONV-2026-0001')
  })
})
