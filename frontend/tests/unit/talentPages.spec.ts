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

import { TALENT_COMPONENT_KEYS, talentComponentFor } from '@/views/talent'

const wave4Keys = PAGE_REGISTRY.filter((page) => page.wave === 'Wave 4').map((page) => page.componentKey)

function listResponse(items: unknown[] = []) {
  return { items, page: 1, page_size: 20, total: items.length }
}

async function mountWithRouter(key: string, path: string) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path, name: 'talent-page', component: { template: '<div />' } },
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
    ]))

    const wrapper = await mountWithRouter('recruitment/candidates/index', '/recruitment/candidates')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/recruitment/candidates', expect.any(Object))
    expect(wrapper.text()).toContain('Synthetic Candidate')
    expect(wrapper.text()).toContain('NEW')
    expect(wrapper.text()).toContain('已登记')
    expect(wrapper.text()).not.toContain('@')
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
  })
})
