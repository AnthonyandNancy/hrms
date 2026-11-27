import { beforeEach, describe, expect, it, vi } from 'vitest'

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

import {
  getPosition,
  listPositionStaff,
  listPositions,
  type PositionStaff,
} from '@/modules/people/positions/api'

function page(items: unknown[], overrides: Record<string, unknown> = {}) {
  return {
    items,
    page: 1,
    page_size: 20,
    total: items.length,
    partial: false,
    partial_reason: null,
    ...overrides,
  }
}

const ROW = {
  id: 4,
  org_unit_id: 10,
  code: 'ENG-001',
  title: '合成工程师',
  grade: 'P5',
  headcount_limit: 2,
  occupancy: 1,
  vacancies: 1,
  status: 'ACTIVE',
  valid_from: '2026-01-01',
  valid_to: '9999-12-31',
}

beforeEach(() => {
  resource.get.mockReset()
  resource.post.mockReset()
  resource.patch.mockReset()
  resource.put.mockReset()
  resource.delete.mockReset()
})

describe('position server query API', () => {
  it('sends every filter, page and sort to the server and trusts server paging', async () => {
    resource.get.mockResolvedValue(page([ROW], { page: 2, page_size: 1, total: 7 }))

    const result = await listPositions({
      asOf: '2026-11-27',
      q: ' 工程师 ',
      orgUnitId: 10,
      status: 'ACTIVE',
      page: 2,
      pageSize: 1,
      sort: 'title',
    })

    expect(resource.get).toHaveBeenCalledWith('/v1/positions', {
      as_of: '2026-11-27',
      q: '工程师',
      org_unit_id: 10,
      status: 'ACTIVE',
      page: 2,
      page_size: 1,
      sort: 'title',
    })
    expect(result.items).toHaveLength(1)
    expect(result.total).toBe(7)
    expect(result.page).toBe(2)
    expect(result.page_size).toBe(1)
  })

  it('omits blank optional filters and always sends explicit pagination defaults', async () => {
    resource.get.mockResolvedValue(page([ROW]))

    await listPositions({ asOf: '2026-11-27', q: '   ', orgUnitId: null, status: null })

    const params = resource.get.mock.calls[0]?.[1] as Record<string, unknown>
    expect(params).toEqual({
      as_of: '2026-11-27',
      page: 1,
      page_size: 20,
      sort: 'code',
    })
  })

  it('never re-filters or slices a returned page client-side', async () => {
    resource.get.mockResolvedValue(page([{ ...ROW, status: 'DISABLED', org_unit_id: 99 }], { total: 80, page: 3 }))

    const result = await listPositions({
      asOf: '2026-11-27',
      status: 'ACTIVE',
      orgUnitId: 10,
      page: 3,
      pageSize: 2,
    })

    expect(result.items).toHaveLength(1)
    expect(result.items[0]?.status).toBe('DISABLED')
    expect(result.items[0]?.org_unit_id).toBe(99)
    expect(result.total).toBe(80)
    expect(result.page).toBe(3)
  })

  it('reads one position detail by id and as-of date', async () => {
    resource.get.mockResolvedValue(ROW)

    const row = await getPosition(4, '2026-11-27')

    expect(resource.get).toHaveBeenCalledWith('/v1/positions/4', { as_of: '2026-11-27' })
    expect(row?.code).toBe('ENG-001')
  })

  it('reads incumbents for the selected position only through the assignment projection', async () => {
    const staff: PositionStaff = {
      assignment_id: 91,
      employment_id: 7,
      employee_no: 'E1007',
      person_name: '合成员工',
      org_unit_id: 10,
      position_id: 4,
      position_code: 'ENG-001',
      position_title: '合成工程师',
      assignment_type: 'PRIMARY',
      is_primary: true,
      valid_from: '2026-01-01',
      valid_to: '9999-12-31',
    }
    resource.get.mockResolvedValue(page([staff]))

    const result = await listPositionStaff(4, '2026-11-27')

    expect(resource.get).toHaveBeenCalledTimes(1)
    expect(resource.get).toHaveBeenCalledWith('/v1/positions/4/staff', { as_of: '2026-11-27' })
    expect(result.items[0]?.assignment_id).toBe(91)
    expect(result.items[0]?.is_primary).toBe(true)
  })
})
