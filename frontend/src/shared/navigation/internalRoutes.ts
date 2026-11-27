/**
 * Stable internal navigation targets.
 *
 * Every value must be a path owned by PAGE_REGISTRY. Components use these
 * constants instead of hand-written strings so a registry move cannot leave a
 * dead link in a page that the router guard cannot test statically.
 */
export const INTERNAL_ROUTES = {
  workspace: '/workspace',
  account: '/account',
  employees: '/hr/employees',
  payroll: '/payroll',
  attendance: '/attendance',
  leave: '/leave',
  overtime: '/overtime',
  travel: '/travel',
  notices: '/notices',
  training: '/training',
  workflow: '/workflow',
} as const

export type InternalRouteKey = keyof typeof INTERNAL_ROUTES

/** Detail routes are functions because the id is part of the registered path. */
export const INTERNAL_DETAIL_ROUTES = {
  employee: (id: number) => `/hr/employees/${id}`,
  payrollRun: (id: number) => `/payroll/runs/${id}`,
  workflowCase: (id: number) => `/workflow/cases/${id}`,
} as const
