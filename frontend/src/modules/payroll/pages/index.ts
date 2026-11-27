/**
 * Payroll workspace route targets.
 *
 * The new workspace and its run detail own purpose-built components. The
 * remaining legacy payroll catalogue pages stay in the timepay module manifest
 * until they are rebuilt, so no key ever has two loaders.
 */
export const PAGE_COMPONENTS = {
  'payroll/workspace': () => import('./PayrollWorkspacePage.vue'),
  'payroll/runs/by-id': () => import('./PayrollRunDetailPage.vue'),
} as const

export type PayrollPageComponentKey = keyof typeof PAGE_COMPONENTS
