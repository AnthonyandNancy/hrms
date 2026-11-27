import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import ElementPlus from 'element-plus'
import { useSessionStore } from '@/stores/session'

const resource = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  patch: vi.fn(),
  delete: vi.fn(),
}))

vi.mock('@/api/resourceClient', () => ({
  resourceGet: resource.get,
  resourcePost: resource.post,
  resourcePatch: resource.patch,
  resourceDelete: resource.delete,
}))

import EmployeeChangeList from '@/modules/people/employees/components/EmployeeChangeList.vue'

const SUBMITTED = {
  id: 91,
  employment_id: 1,
  status: 'SUBMITTED',
  reason: '更正合成教育信息',
  diff: { change_type: 'PROFILE_CORRECTION' },
  requested_by: 10,
  submitted_at: '2026-11-20T01:00:00Z',
  decided_at: null,
  decided_by: null,
  case_id: 88,
}

async function mountList(capabilities: string[]) {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().$patch({ status: 'authenticated', capabilities })
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/workflow/cases/:id', name: 'workflow-case-detail', component: { template: '<div />' } },
      { path: '/', component: { template: '<div />' } },
    ],
  })
  await router.push('/')
  await router.isReady()
  const wrapper = mount(EmployeeChangeList, {
    props: { employmentId: 1 },
    global: { plugins: [pinia, router, ElementPlus] },
  })
  await flushPromises()
  return { wrapper, router }
}

beforeEach(() => {
  Object.values(resource).forEach((fn) => fn.mockReset())
  resource.get.mockResolvedValue({ items: [SUBMITTED], total: 1 })
  resource.post.mockResolvedValue({ id: 91, status: 'SUBMITTED', case_id: 88 })
})

describe('employee change workflow deep link', () => {
  it('links a submitted change to the real workflow case route', async () => {
    const { wrapper, router } = await mountList([
      'people.employee.read',
      'people.employee.manage',
      'workflow.case.read',
    ])

    await wrapper.findAll('button').find((button) => button.text().trim() === '查看')!.trigger('click')
    await flushPromises()

    const link = wrapper.get('[data-testid="change-case-link"]')
    expect(link.attributes('href')).toBe('/workflow/cases/88')
    expect(link.text()).toContain('#88')

    await link.trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.path).toBe('/workflow/cases/88')
  })

  it('hides the case link for callers without workflow.case.read', async () => {
    const { wrapper } = await mountList(['people.employee.read', 'people.employee.manage'])

    await wrapper.findAll('button').find((button) => button.text().trim() === '查看')!.trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="change-case-link"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="change-case-no-access"]').text()).toContain('#88')
  })

  it('submits a draft with an idempotency key and reloads the persisted case reference', async () => {
    const draft = { ...SUBMITTED, id: 92, status: 'DRAFT', case_id: null, submitted_at: null }
    let reads = 0
    resource.get.mockImplementation(() => {
      reads += 1
      // The component may load once on mount and once after submit; serve the
      // persisted row from the second read onward regardless of timing.
      return Promise.resolve(reads === 1 ? { items: [draft], total: 1 } : { items: [{ ...SUBMITTED, id: 92 }], total: 1 })
    })

    const { wrapper } = await mountList([
      'people.employee.read',
      'people.employee.manage',
      'workflow.case.read',
    ])

    await wrapper.get('[data-testid="change-submit-92"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/employees/1/change_requests/92/submit',
      {},
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(resource.get).toHaveBeenCalledTimes(2)
    // Reload keeps the submitted row selected; the detail panel must expose the
    // persisted case number immediately without a second user click.
    expect(wrapper.get('[data-testid="change-case-link"]').attributes('href')).toBe('/workflow/cases/88')
  })
})
