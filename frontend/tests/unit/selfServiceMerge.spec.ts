import { describe, expect, it } from 'vitest'
import { PAGE_REGISTRY } from '@/router/registry'
import { INTERNAL_ROUTES } from '@/shared/navigation/internalRoutes'

const retired = ['/account/mfa', '/account/sessions', '/self-service']

describe('M01 self-service merge', () => {
  it('permanently deletes the legacy account and self-service routes', () => {
    const paths = PAGE_REGISTRY.map((page) => page.path)
    for (const path of retired) expect(paths).not.toContain(path)
  })

  it('keeps account security as one route and one menu entry', () => {
    const accounts = PAGE_REGISTRY.filter((page) => page.path === '/account')
    expect(accounts).toHaveLength(1)
    expect(accounts[0]?.componentKey).toBe('account')
    expect(accounts[0]?.menu?.label).toBe('登录与账号安全')
  })

  it('keeps all self-service entry points on surviving workspaces', () => {
    expect(INTERNAL_ROUTES.account).toBe('/account')
    expect(INTERNAL_ROUTES.workflow).toBe('/workflow')
    expect(INTERNAL_ROUTES.payroll).toBe('/payroll')
    expect(INTERNAL_ROUTES.leave).toBe('/leave')
    expect(INTERNAL_ROUTES.training).toBe('/training')
  })
})
