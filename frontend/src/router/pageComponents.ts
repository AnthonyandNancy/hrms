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
  'performance/index': () => import('@/views/talent').then((module) => module.talentComponentFor('performance/index') as Component),
  'performance/templates': () => import('@/views/talent').then((module) => module.talentComponentFor('performance/templates') as Component),
  'performance/indicators': () => import('@/views/talent').then((module) => module.talentComponentFor('performance/indicators') as Component),
  'performance/goals': () => import('@/views/talent').then((module) => module.talentComponentFor('performance/goals') as Component),
  'performance/reviews': () => import('@/views/talent').then((module) => module.talentComponentFor('performance/reviews') as Component),
  'performance/feedback': () => import('@/views/talent').then((module) => module.talentComponentFor('performance/feedback') as Component),
  'performance/calibration': () => import('@/views/talent').then((module) => module.talentComponentFor('performance/calibration') as Component),
  'performance/results': () => import('@/views/talent').then((module) => module.talentComponentFor('performance/results') as Component),
  'performance/appeals': () => import('@/views/talent').then((module) => module.talentComponentFor('performance/appeals') as Component),
  'performance/reports': () => import('@/views/talent').then((module) => module.talentComponentFor('performance/reports') as Component),
  'recruitment/requests/index': () => import('@/views/talent').then((module) => module.talentComponentFor('recruitment/requests/index') as Component),
  'recruitment/requests/create': () => import('@/views/talent').then((module) => module.talentComponentFor('recruitment/requests/create') as Component),
  'recruitment/requests/approval': () => import('@/views/talent').then((module) => module.talentComponentFor('recruitment/requests/approval') as Component),
  'recruitment/requests/openings': () => import('@/views/talent').then((module) => module.talentComponentFor('recruitment/requests/openings') as Component),
  'recruitment/requests/board': () => import('@/views/talent').then((module) => module.talentComponentFor('recruitment/requests/board') as Component),
  'recruitment/requests/budget': () => import('@/views/talent').then((module) => module.talentComponentFor('recruitment/requests/budget') as Component),
  'recruitment/requests/reports': () => import('@/views/talent').then((module) => module.talentComponentFor('recruitment/requests/reports') as Component),
  'recruitment/candidates/index': () => import('@/views/talent').then((module) => module.talentComponentFor('recruitment/candidates/index') as Component),
  'recruitment/candidates/by-id': () => import('@/views/talent').then((module) => module.talentComponentFor('recruitment/candidates/by-id') as Component),
  'recruitment/candidates/imports': () => import('@/views/talent').then((module) => module.talentComponentFor('recruitment/candidates/imports') as Component),
  'recruitment/candidates/pipeline': () => import('@/views/talent').then((module) => module.talentComponentFor('recruitment/candidates/pipeline') as Component),
  'recruitment/candidates/interviews': () => import('@/views/talent').then((module) => module.talentComponentFor('recruitment/candidates/interviews') as Component),
  'recruitment/candidates/evaluations': () => import('@/views/talent').then((module) => module.talentComponentFor('recruitment/candidates/evaluations') as Component),
  'recruitment/candidates/offers': () => import('@/views/talent').then((module) => module.talentComponentFor('recruitment/candidates/offers') as Component),
  'recruitment/candidates/communications': () => import('@/views/talent').then((module) => module.talentComponentFor('recruitment/candidates/communications') as Component),
  'recruitment/candidates/talent-pool': () => import('@/views/talent').then((module) => module.talentComponentFor('recruitment/candidates/talent-pool') as Component),
  'recruitment/candidates/convert': () => import('@/views/talent').then((module) => module.talentComponentFor('recruitment/candidates/convert') as Component),
  'training/index': () => import('@/views/talent').then((module) => module.talentComponentFor('training/index') as Component),
  'training/courses': () => import('@/views/talent').then((module) => module.talentComponentFor('training/courses') as Component),
  'training/sessions': () => import('@/views/talent').then((module) => module.talentComponentFor('training/sessions') as Component),
  'training/enrollments': () => import('@/views/talent').then((module) => module.talentComponentFor('training/enrollments') as Component),
  'training/checkins': () => import('@/views/talent').then((module) => module.talentComponentFor('training/checkins') as Component),
  'training/exams': () => import('@/views/talent').then((module) => module.talentComponentFor('training/exams') as Component),
  'training/feedback': () => import('@/views/talent').then((module) => module.talentComponentFor('training/feedback') as Component),
  'training/records': () => import('@/views/talent').then((module) => module.talentComponentFor('training/records') as Component),
  'training/reports': () => import('@/views/talent').then((module) => module.talentComponentFor('training/reports') as Component),
}
export function matureView(componentKey: string): (() => Promise<Component>) | null {
  return MATURE_VIEWS[componentKey] ?? null
}
