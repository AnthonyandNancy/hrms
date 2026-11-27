/**
 * Volume 3 Task 16 reporting workspaces.
 *
 * These keys are resolved by the router directly. They are intentionally not
 * re-exported through the platform module: a module must not import another
 * module's page components (the module boundary gate enforces this).
 */
export const PAGE_COMPONENTS = {
  'dashboard/index': () => import('./DashboardPage.vue'),
  'reports/index': () => import('./ReportsPage.vue'),
  'notices/index': () => import('./NoticesPage.vue'),
}
