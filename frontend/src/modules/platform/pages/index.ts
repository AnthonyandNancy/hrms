/** Lazy route targets for every Platform page_code. */
// Volume 3 Task 16 moved dashboard/reports/notices into the reporting module;
// the router resolves those keys from @/modules/reporting/pages instead.
export const PAGE_COMPONENTS = {
  'account': () => import('./AccountSecurityPage.vue'),
  'system/system-users': () => import('./SystemUsersPage.vue'),
  'system/system-roles': () => import('./SystemRolesPage.vue'),
  'system/system-pages': () => import('./SystemPagesPage.vue'),
  'workflow': () => import('./WorkflowCenterPage.vue'),
  'workflow/cases/by-id': () => import('../workflow/pages/WorkflowCaseDetailPage.vue'),
  'benefits': () => import('./BenefitsPage.vue'),
  'system/system-settings': () => import('./SystemSettingsPage.vue'),
  'notifications': () => import('./NotificationsPage.vue'),
  'system/system-audit': () => import('./SystemAuditPage.vue'),
} as const
