import { describe, expect, it } from 'vitest'
import { PAGE_REGISTRY, actionsForPage } from '@/router/registry'
import { PAGE_ACTIONS } from '@/generated/pageActions'

const WRITE = ['POST', 'PATCH', 'PUT', 'DELETE']

describe('page action manifest', () => {
  it('every non-public write page declares at least one write action', () => {
    for (const page of PAGE_REGISTRY) {
      if (page.capability === 'public') continue
      const writes = page.requiredApis.filter((api) => WRITE.includes(api.split(' ')[0] ?? ''))
      if (writes.length === 0) continue
      expect(page.actions.length, `${page.path} actions`).toBeGreaterThan(0)
    }
  })

  it('declares write actions with a catalogued-style capability and a write verb', () => {
    for (const page of PAGE_REGISTRY) {
      for (const action of page.actions) {
        expect(action.key, `${page.path} action key`).toMatch(/^[a-z][a-z0-9]*(\.[a-z0-9-]+)+$/)
        expect(action.label, `${page.path} ${action.key} label`).not.toBe('')
        expect(action.capability, `${page.path} ${action.key} capability`).toMatch(/^[a-z]+\.[a-z0-9.-]+$/)
        expect(WRITE, `${page.path} ${action.key} api verb`).toContain(action.api.split(' ')[0])
        expect(['primary', 'secondary', 'danger', 'hidden']).toContain(action.ui)
      }
    }
  })

  it('derives every account MFA and session action from the page requiredApis', () => {
    const mfa = PAGE_REGISTRY.find((page) => page.name === 'account-mfa')
    const sessions = PAGE_REGISTRY.find((page) => page.name === 'account-sessions')

    // Order comes from the generated manifest, so assert the set not the order.
    expect(new Set(mfa?.actions.map((action) => action.api))).toEqual(
      new Set(['POST /v1/auth/mfa/totp/enroll', 'POST /v1/auth/mfa/totp/confirm']),
    )
    expect(sessions?.actions.map((action) => action.api)).toEqual(['POST /v1/auth/logout-all'])
    expect(new Set([...mfa!.actions, ...sessions!.actions].map((action) => action.capability))).toEqual(
      new Set(['iam.session.manage']),
    )
  })

  it('serves every page action from the generated manifest, not a second list', () => {
    for (const page of PAGE_REGISTRY) {
      expect(page.actions).toEqual(actionsForPage(page.name))
    }
    expect(Object.keys(PAGE_ACTIONS).length).toBeGreaterThan(0)
  })

  it('keeps action APIs inside the page requiredApis', () => {
    for (const page of PAGE_REGISTRY) {
      for (const action of page.actions) {
        expect(page.requiredApis, `${page.path} ${action.key} api must be declared`).toContain(action.api)
      }
    }
  })
})
