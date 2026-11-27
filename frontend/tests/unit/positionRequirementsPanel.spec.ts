import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '@/api/errors'

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

vi.mock('element-plus', async (importOriginal) => {
  const actual = await importOriginal<typeof import('element-plus')>()
  return {
    ...actual,
    ElMessage: { success: vi.fn(), error: vi.fn() },
  }
})

import PositionRequirementsPanel from '@/modules/people/positions/components/PositionRequirementsPanel.vue'

function page(items: unknown[]) {
  return {
    items,
    page: 1,
    page_size: 100,
    total: items.length,
    partial: false,
    partial_reason: null,
  }
}

function version(id: number, versionNo: number, requirements: Record<string, unknown>) {
  return {
    id,
    position_id: 4,
    version: versionNo,
    requirements_json: requirements,
    effective_from: '2026-11-01',
    recorded_at: '2026-11-01T00:00:00Z',
  }
}

function mountPanel(canManage = true) {
  return mount(PositionRequirementsPanel, {
    props: { positionId: 4, asOf: '2026-11-27', canManage },
  })
}

beforeEach(() => {
  resource.get.mockReset()
  resource.post.mockReset()
  resource.patch.mockReset()
  resource.put.mockReset()
  resource.delete.mockReset()
  resource.get.mockResolvedValue(
    page([version(2, 2, { skills: 'PHP', qualifications: 'PMP' })]),
  )
})

describe('PositionRequirementsPanel append-only versions', () => {
  it('submits only changed rows, keeping the displayed history append-only', async () => {
    resource.post.mockResolvedValue(version(3, 3, { skills: 'TypeScript' }))
    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.get('[data-testid="position-requirements-latest"]').text()).toContain('第 2 版')
    expect(wrapper.get('[data-testid="position-requirements"]').text()).toContain('PMP')

    resource.get.mockResolvedValue(
      page([
        version(3, 3, { skills: 'TypeScript' }),
        version(2, 2, { skills: 'PHP', qualifications: 'PMP' }),
      ]),
    )

    await wrapper.get('[data-testid="requirement-input-skills"]').setValue('TypeScript')
    expect(wrapper.get('[data-testid="requirements-changed-count"]').text()).toContain('1')
    await wrapper.get('[data-testid="position-requirements-save"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledTimes(1)
    expect(resource.post).toHaveBeenCalledWith(
      '/v1/positions/4/requirements',
      {
        requirements: { skills: 'TypeScript' },
        effective_from: '2026-11-27',
      },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    const text = wrapper.get('[data-testid="position-requirements"]').text()
    expect(text).toContain('第 3 版')
    expect(text).toContain('第 2 版')
    expect(text).toContain('TypeScript')
    expect(text).toContain('PMP')
  })

  it('does not issue a write when no row changed', async () => {
    const wrapper = mountPanel()
    await flushPromises()

    await wrapper.get('[data-testid="position-requirements-save"]').trigger('click')
    await flushPromises()

    expect(resource.post).not.toHaveBeenCalled()
    expect(wrapper.get('[data-testid="position-requirements-error-save"]').text()).toContain(
      '没有变更',
    )
  })

  it('keeps the operator input when the version write fails', async () => {
    resource.post.mockRejectedValue(
      new ApiError('CONFLICT', 'Conflict', {
        status: 409,
        requestId: 'req-qualification-conflict',
      }),
    )
    const wrapper = mountPanel()
    await flushPromises()

    await wrapper.get('[data-testid="requirement-input-skills"]').setValue('Kubernetes')
    await wrapper.get('[data-testid="position-requirements-save"]').trigger('click')
    await flushPromises()

    expect(
      (wrapper.get('[data-testid="requirement-input-skills"]').element as HTMLInputElement).value,
    ).toBe('Kubernetes')
    expect(wrapper.get('[data-testid="position-requirements-error-save"]').text()).toContain(
      'req-qualification-conflict',
    )
    expect(wrapper.get('[data-testid="position-requirements"]').text()).toContain('第 2 版')
  })

  it('shows no qualification history and no write control without the manage capability', async () => {
    const wrapper = mountPanel(false)
    await flushPromises()

    expect(resource.get).not.toHaveBeenCalled()
    expect(wrapper.get('[data-testid="position-requirements-gap"]').text()).toContain(
      '无权查看或维护任职资格版本',
    )
    expect(wrapper.find('[data-testid="position-requirements-save"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="requirement-input-skills"]').exists()).toBe(false)
  })
})
