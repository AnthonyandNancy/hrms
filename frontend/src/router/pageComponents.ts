import type { Component } from 'vue'

/**
 * Registry key -> mature business view.
 *
 * Every other registry entry is rendered by ResourcePage, which calls the
 * canonical API declared in the registry and presents loading/empty/error/
 * forbidden/partial states. Keys are added here only when a page has a richer
 * purpose-built implementation.
 */
const MATURE_VIEWS: Record<string, () => Promise<Component>> = {
  'base/LoginView': () => import('@/views/LoginView.vue'),
  'base/WorkspaceView': () => import('@/views/WorkspaceView.vue'),
  'base/ForbiddenView': () => import('@/views/ForbiddenView.vue'),
  'base/NotFoundView': () => import('@/views/NotFoundView.vue'),
  'dashboard/index': () => import('@/views/DashboardView.vue'),
  'reports/index': () => import('@/views/ReportsView.vue'),
  'hr/employees/index': () => import('@/views/EmployeeDirectoryView.vue'),
  'hr/departments/index': () => import('@/views/OrgTreeView.vue'),
  'attendance/index': () => import('@/views/AttendanceView.vue'),
  'leave/index': () => import('@/views/LeaveView.vue'),
  'payroll/batches': () => import('@/views/PayrollRunsView.vue'),
  'payroll/payslips': () => import('@/views/PayslipsView.vue'),
  'notices/index': () => import('@/views/NoticesView.vue'),
  'system/system-users': () => import('@/views/AdminView.vue'),
}
export function matureView(componentKey: string): (() => Promise<Component>) | null {
  return MATURE_VIEWS[componentKey] ?? null
}
