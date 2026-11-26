import type { Component } from 'vue'
import { PAGE_COMPONENTS as TIMEPAY_PAGES } from '@/modules/timepay/pages'

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
  'dashboard/index': () => import('@/views/DashboardView.vue'),
  'reports/index': () => import('@/views/ReportsView.vue'),
  'hr/employees/index': () => import('@/views/EmployeeDirectoryView.vue'),
  'hr/departments/index': () => import('@/views/OrgTreeView.vue'),
  'notices/index': () => import('@/views/NoticesView.vue'),
  'system/system-users': () => import('@/views/AdminView.vue'),
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
  {
    test: (key) => key.startsWith('hr/'),
    load: (key) => () => import('@/views/people').then((module) => module.peopleComponentFor(key) as Component),
  },
  {
    test: (key) => ['performance/', 'recruitment/'].some((prefix) => key.startsWith(prefix)) || key.startsWith('training/'),
    load: (key) => () => import('@/views/talent').then((module) => module.talentComponentFor(key) as Component),
  },
  {
    test: (key) =>
      ['dashboard/', 'reports/', 'notices/'].some((prefix) => key.startsWith(prefix)) ||
      ['account', 'system/', 'workflow', 'self-service', 'benefits', 'notifications'].some((prefix) => key === prefix || key.startsWith(prefix)),
    load: (key) => () => import('@/views/platform').then((module) => module.platformComponentFor(key) as Component),
  },
]

function isTimePayKey(key: string): boolean {
  return ['attendance/', 'leave/', 'overtime/', 'travel/', 'payroll/'].some((prefix) =>
    key.startsWith(prefix),
  )
}

export function timePayPageLoader(key: string): Loader | null {
  return TIMEPAY_PAGES[key as keyof typeof TIMEPAY_PAGES] ?? null
}

export function matureView(componentKey: string): Loader | null {
  const base = BASE_VIEWS[componentKey]
  if (base !== undefined) return base

  if (isTimePayKey(componentKey)) {
    return timePayPageLoader(componentKey)
  }

  const domain = DOMAIN_LOADERS.find((candidate) => candidate.test(componentKey))
  return domain === undefined ? null : domain.load(componentKey)
}
