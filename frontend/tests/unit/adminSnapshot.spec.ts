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

describe('getAdminSnapshot', () => {
  beforeEach(() => {
    get.mockReset()
    post.mockReset()
  })

  it('returns real users instead of a hard-coded empty page', async () => {
    get.mockImplementation((url: string) => {
      if (url === '/users') {
        return Promise.resolve({ data: { code: 'OK', data: { items: [{ id: 7, username: 'real.user' }], page: 1, page_size: 10, total: 1 } } })
      }
      if (url === '/access-review') {
        return Promise.resolve({ data: { code: 'OK', data: { unused_high_risk_bindings: [], sod_risks: [] } } })
      }
      return Promise.resolve({ data: { code: 'OK', data: { items: [] } } })
    })

    const { createHttpAdapter } = await import('@/api/http')
    const snapshot = await createHttpAdapter().admin.getAdminSnapshot('users')

    expect(snapshot.users.items).toHaveLength(1)
    expect(snapshot.users.items[0]).toMatchObject({ id: 7, name: 'real.user', status: 'ACTIVE' })
    expect(snapshot.users.total).toBe(1)
  })
})
