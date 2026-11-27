/**
 * Volume 3 Time workspaces.
 *
 * The router resolves these keys directly. They are not re-exported through the
 * timepay module: a module must not import another module's page components,
 * which the frontend module boundary gate enforces.
 */
export const PAGE_COMPONENTS = {
  'attendance/index': () => import('../attendance/pages/AttendanceWorkspacePage.vue'),
  'leave/index': () => import('../leave/pages/LeaveWorkspacePage.vue'),
  'overtime/index': () => import('../overtime/pages/OvertimeWorkspacePage.vue'),
  'travel/index': () => import('../travel/pages/TravelWorkspacePage.vue'),
}
