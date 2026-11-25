import { describe, expect, it, vi, beforeEach } from 'vitest'

const get = vi.fn()
const post = vi.fn()
const patch = vi.fn()

vi.mock('axios', () => ({
  default: {
    create: () => ({
      get,
      post,
      patch,
      interceptors: { request: { use: vi.fn() } },
    }),
  },
}))

import { resourceGet, resourcePost, resourcePatch } from '@/api/resourceClient'

describe('resource client path normalisation', () => {
  beforeEach(() => {
    get.mockReset().mockResolvedValue({ data: { data: { ok: true } } })
    post.mockReset().mockResolvedValue({ data: { data: { ok: true } } })
    patch.mockReset().mockResolvedValue({ data: { data: { ok: true } } })
  })

  it('strips a leading /v1 so the axios base URL is not duplicated', async () => {
    await resourceGet('/v1/me/permissions')
    expect(get).toHaveBeenCalledWith('/me/permissions', expect.any(Object))
  })

  it('keeps paths that are already relative to the API base', async () => {
    await resourceGet('/time/leave/types')
    expect(get).toHaveBeenCalledWith('/time/leave/types', expect.any(Object))
  })

  it('normalises writes and patches the same way', async () => {
    await resourcePost('/v1/time/leave/requests', {}, { idempotencyKey: 'k1' })
    await resourcePatch('/v1/positions/1', { name: 'x' })
    expect(post).toHaveBeenCalledWith('/time/leave/requests', {}, expect.any(Object))
    expect(patch).toHaveBeenCalledWith('/positions/1', { name: 'x' }, expect.any(Object))
  })
})
