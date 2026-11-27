import type { Component } from 'vue'
import { PAGE_COMPONENTS as PEOPLE_PAGES } from '@/modules/people/pages'
import { PAGE_COMPONENTS as PLATFORM_PAGES } from '@/modules/platform/pages'
import { PAGE_COMPONENTS as TALENT_PAGES } from '@/modules/talent/pages'
import { PAGE_COMPONENTS as TIMEPAY_PAGES } from '@/modules/timepay/pages'
import { PAGE_COMPONENTS as REPORTING_PAGES } from '@/modules/reporting/pages'
import { PAGE_COMPONENTS as TIME_WORKSPACE_PAGES } from '@/modules/time/workspaces'
import { PAGE_COMPONENTS as PAYROLL_PAGES } from '@/modules/payroll/pages'

/**
 * Registry key -> mature business view loader.
 *
 * Keys are added here only when a page has a purpose-built implementation.
 * Everything else is rendered by ResourcePage, which calls the canonical API
 * declared in the registry and presents loading/empty/error/forbidden/partial
 * states. The release gate (`audit_route_coverage.mjs --require-mature`) must
 * reach 160 before the project is called page-complete.
 */
type Loader = () => Promise<Component>

const BASE_VIEWS: Record<string, Loader> = {
  'base/LoginView': () => import('@/views/LoginView.vue'),
  'base/WorkspaceView': () => import('@/views/WorkspaceView.vue'),
  'base/ForbiddenView': () => import('@/views/ForbiddenView.vue'),
  'base/NotFoundView': () => import('@/views/NotFoundView.vue'),
  'base/AccountMfaView': () => import('@/views/AccountSecurityView.vue'),
  'base/AccountSessionsView': () => import('@/views/AccountSecurityView.vue'),
}

/**
 * Domain loaders are resolved from the domain barrel. A single dynamic import
 * per domain keeps the initial bundle small while still returning a concrete
 * component for every registry key in that domain.
 */
const DOMAIN_LOADERS: ReadonlyArray<{
  test: (key: string) => boolean
  load: (key: string) => Loader
}> = [
]

function isTalentKey(key: string): boolean {
  return ['performance/', 'recruitment/', 'training/'].some((prefix) => key.startsWith(prefix))
}

export function talentPageLoader(key: string): Loader | null {
  const loader = TALENT_PAGES[key as keyof typeof TALENT_PAGES]
  return loader === undefined ? null : () => loader() as Promise<Component>
}

function isPlatformKey(key: string): boolean {
  return (
    ['dashboard/', 'reports/', 'notices/'].some((prefix) => key.startsWith(prefix)) ||
    ['account', 'system/', 'workflow', 'self-service', 'benefits', 'notifications'].some(
      (prefix) => key === prefix || key.startsWith(prefix),
    )
  )
}

export function platformPageLoader(key: string): Loader | null {
  const loader = PLATFORM_PAGES[key as keyof typeof PLATFORM_PAGES]
  return loader === undefined ? null : () => loader() as Promise<Component>
}

function isTimePayKey(key: string): boolean {
  return ['attendance/', 'leave/', 'overtime/', 'travel/', 'payroll/'].some((prefix) =>
    key.startsWith(prefix),
  )
}

export function timePayPageLoader(key: string): Loader | null {
  return TIMEPAY_PAGES[key as keyof typeof TIMEPAY_PAGES] ?? null
}

/**
 * Volume 3 reporting workspaces are their own module; the router resolves them
 * directly instead of routing platform pages across a module boundary.
 */
const REPORTING_KEYS = new Set(['dashboard/index', 'reports/index', 'notices/index'])

export function matureView(componentKey: string): Loader | null {
  const base = BASE_VIEWS[componentKey]
  if (base !== undefined) return base

  if (REPORTING_KEYS.has(componentKey)) {
    const reporting = REPORTING_PAGES[componentKey as keyof typeof REPORTING_PAGES]
    if (reporting !== undefined) return reporting
  }

  const timeWorkspace = TIME_WORKSPACE_PAGES[componentKey as keyof typeof TIME_WORKSPACE_PAGES]
  if (timeWorkspace !== undefined) return timeWorkspace

  const payrollPage = PAYROLL_PAGES[componentKey as keyof typeof PAYROLL_PAGES]
  if (payrollPage !== undefined) return payrollPage

  const peoplePage = PEOPLE_PAGES[componentKey as keyof typeof PEOPLE_PAGES]
  if (peoplePage !== undefined) return peoplePage

  if (componentKey.startsWith('hr/')) return null

  if (isTimePayKey(componentKey)) {
    return timePayPageLoader(componentKey)
  }

  if (isTalentKey(componentKey)) {
    return talentPageLoader(componentKey)
  }

  if (isPlatformKey(componentKey)) {
    return platformPageLoader(componentKey)
  }

  const domain = DOMAIN_LOADERS.find((candidate) => candidate.test(componentKey))
  return domain === undefined ? null : domain.load(componentKey)
}
