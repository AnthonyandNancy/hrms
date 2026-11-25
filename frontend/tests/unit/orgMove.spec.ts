import { beforeEach, describe, expect, it, vi } from 'vitest'

const get = vi.fn()
const post = vi.fn()

vi.mock('axios', () => {
  const create = vi.fn(() => ({
    get,
    post,
    interceptors: { request: { use: vi.fn() }, response: { use: vi.fn() } },
  }))

  return { default: { create, isAxiosError: () => false }, create, isAxiosError: () => false }
})

describe('organization.moveOrg contract', () => {
  beforeEach(() => {
    get.mockReset()
    post.mockReset()
  })

  it('previews impact with GET and the server parameter names', async () => {
    get.mockResolvedValue({
      data: {
        code: 'OK',
        data: {
          affected_employee_count: 12,
          affected_manager_count: 3,
          payroll_cost_center_count: 0,
          cross_entity_warning: true,
        },
      },
    })

    const { createHttpAdapter } = await import('@/api/http')
    const facts = await createHttpAdapter().organization.previewOrgMove(12, 34, '2026-12-01')

    expect(get).toHaveBeenCalledTimes(1)
    const [url, config] = get.mock.calls[0] as [string, { params: Record<string, unknown> }]
    expect(url).toBe('/org-units/12/impact')
    expect(config.params).toEqual({ new_parent_id: 34, as_of: '2026-12-01' })
    expect(facts).toEqual({
      affectedEmployees: 12,
      affectedManagers: 3,
      affectedPayrollCostCenters: 0,
      crossEntityWarning: true,
    })
  })

  it('submits the move with effective_from instead of effective_date', async () => {
    post.mockResolvedValue({ data: { code: 'OK', data: { moved: true, old_org_unit_id: 12 } } })

    const { createHttpAdapter } = await import('@/api/http')
    const result = await createHttpAdapter().organization.moveOrg(12, 34, '2026-12-01')

    expect(post).toHaveBeenCalledTimes(1)
    const [url, body] = post.mock.calls[0] as [string, Record<string, unknown>]
    expect(url).toBe('/org-units/12/move')
    expect(body).toEqual({ new_parent_id: 34, effective_from: '2026-12-01' })
    expect(body).not.toHaveProperty('effective_date')
    expect(result).toEqual({ moved: true, oldOrgUnitId: 12 })
  })
})
