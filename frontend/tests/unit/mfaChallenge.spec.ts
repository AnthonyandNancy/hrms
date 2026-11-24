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

describe('HTTP adapter MFA challenge', () => {
  beforeEach(() => {
    get.mockReset()
    post.mockReset()
  })

  it('maps a 202 login to MFA_REQUIRED and completes via the challenge endpoint', async () => {
    post.mockImplementation((url: string, body?: unknown) => {
      if (url === '/auth/login') {
        return Promise.resolve({
          data: {
            code: 'ACCEPTED',
            data: {
              status: 'MFA_REQUIRED',
              challenge_id: 'challenge-token-value',
              masked_destination: 'TOTP authenticator',
              expires_at: null,
            },
          },
        })
      }
      if (url === '/auth/mfa/challenge') {
        expect(body).toMatchObject({ challenge_id: 'challenge-token-value', factor_type: 'TOTP' })

        return Promise.resolve({
          data: {
            code: 'OK',
            data: {
              user: { id: 1, username: 'installer' },
              csrf_token: 'csrf-value',
              session: { id: 's1', mfa_verified: true, idle_expires_at: '2026-11-24T10:00:00Z', absolute_expires_at: '2026-11-24T17:00:00Z' },
            },
          },
        })
      }

      return Promise.resolve({ data: { code: 'OK', data: { items: [] } } })
    })
    get.mockImplementation((url: string) => {
      if (url === '/me') {
        return Promise.resolve({ data: { code: 'OK', data: { id: 1, tenant_id: 1, username: 'installer', is_privileged: true, auth_version: 2, mfa_verified: true } } })
      }
      if (url === '/me/capabilities') {
        return Promise.resolve({ data: { code: 'OK', data: { capabilities: [], wildcard: true, mfa_required: true, mfa_verified: true } } })
      }

      return Promise.resolve({ data: { code: 'OK', data: { items: [] } } })
    })

    const { createHttpAdapter } = await import('@/api/http')
    const auth = createHttpAdapter().auth

    const login = await auth.login({ username: 'installer', password: 'Install!2026Synthetic' })
    expect(login).toEqual({
      status: 'MFA_REQUIRED',
      challengeId: 'challenge-token-value',
      maskedDestination: 'TOTP authenticator',
    })

    const session = await auth.verifyTotp({ challengeId: login.status === 'MFA_REQUIRED' ? login.challengeId : '', code: '123456' })
    expect(session.authenticated).toBe(true)
    expect(post).toHaveBeenCalledWith('/auth/mfa/challenge', expect.objectContaining({ challenge_id: 'challenge-token-value' }))
  })
})
