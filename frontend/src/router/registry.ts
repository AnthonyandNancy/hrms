/**
 * V1.1 controlled page registry.
 *
 * This file is the single frontend source of truth for menu, route,
 * capability, required API operations, UI states and E2E coverage. The
 * authoritative inventory is docs/audit/2026-11-25-v11-page-inventory.md,
 * generated from HRMS-V1.1实施书.md. scripts/audit_route_coverage.mjs compares
 * this registry against that inventory; missing or extra pages fail CI.
 *
 * Count contract: 134 business pages + 15 platform entries + 6 base pages
 * = 155 entries. Form routes are actions inside their workspace, never pages
 * (ADR-0035), so create/edit/archive URLs are intentionally absent.
 */

import type {
  ClusterCode,
  PageActionSpec,
  PageKind,
  PageLayer,
  PageMenu,
  PageSpec,
  PageState,
  PageWave,
} from '@/shared/navigation/pageModel'
import { PAGE_ACTIONS } from '@/generated/pageActions'

export type {
  ClusterCode,
  PageActionSpec,
  PageKind,
  PageLayer,
  PageMenu,
  PageSpec,
  PageState,
  PageWave,
}

export type MenuSpec = NonNullable<PageMenu>

export const REQUIRED_STATES: PageState[] = ['loading', 'empty', 'error', 'forbidden', 'partial']

export const REQUIRED_NEGATIVE_TESTS: PageSpec['negativeTests'] = [
  'unauthenticated',
  'no_capability',
  'cross_anchor',
  'field_or_export',
]

export const PAGE_REGISTRY: PageSpec[] = [
  {
    path: '/login',
    name: 'login',
    componentKey: 'base/LoginView',
    kind: 'base',
    capability: 'public',
    menu: null,
    layer: 'base',
    wave: 'base',
    requiredApis: ['POST /v1/auth/login'],
    actions: [],
    states: [...REQUIRED_STATES],
    e2e: ['base-login'],
    negativeTests: [...REQUIRED_NEGATIVE_TESTS],
  },
  {
    path: '/workspace',
    name: 'workspace',
    componentKey: 'base/WorkspaceView',
    kind: 'base',
    capability: 'iam.session.manage',
    menu: null,
    layer: 'base',
    wave: 'base',
    requiredApis: ['GET /v1/me/workspace'],
    actions: [],
    states: [...REQUIRED_STATES],
    e2e: ['base-workspace'],
    negativeTests: [...REQUIRED_NEGATIVE_TESTS],
  },
  {
    path: '/forbidden',
    name: 'forbidden',
    componentKey: 'base/ForbiddenView',
    kind: 'base',
    capability: 'public',
    menu: null,
    layer: 'base',
    wave: 'base',
    requiredApis: [],
    actions: [],
    states: [...REQUIRED_STATES],
    e2e: ['base-forbidden'],
    negativeTests: [...REQUIRED_NEGATIVE_TESTS],
  },
  {
    path: '/not-found',
    name: 'not-found',
    componentKey: 'base/NotFoundView',
    kind: 'base',
    capability: 'public',
    menu: null,
    layer: 'base',
    wave: 'base',
    requiredApis: [],
    actions: [],
    states: [...REQUIRED_STATES],
    e2e: ['base-not-found'],
    negativeTests: [...REQUIRED_NEGATIVE_TESTS],
  },
  // GENERATED BUSINESS + PLATFORM ENTRIES (do not hand-edit; see
  // scripts/docs/generate_registry_entries.py).
  {path: '/dashboard', name: 'dashboard', componentKey: 'dashboard/index', kind: 'workspace', capability: 'reporting.dashboard.read', menu: { group: '仪表盘', label: '首页看板', order: 1, icon: 'DataAnalysis' }, layer: 'business', wave: 'Wave 5', requiredApis: ['GET /v1/reporting/dashboard'], actions: [], states: [...REQUIRED_STATES], e2e: ['1-dashboard'], negativeTests: [...REQUIRED_NEGATIVE_TESTS],},
  {path: '/reports', name: 'reports', componentKey: 'reports/index', kind: 'workspace', primaryObject: 'report', capability: 'reporting.export', menu: { group: '报表中心', label: '报表目录', order: 7, icon: 'Document' }, layer: 'business', wave: 'Wave 5', requiredApis: ['GET /v1/reporting/definitions'], actions: [], states: [...REQUIRED_STATES], e2e: ['7-reports'], negativeTests: [...REQUIRED_NEGATIVE_TESTS],},
  {path: '/hr/employees', name: 'hr-employees', componentKey: 'hr/employees/index', kind: 'workspace', primaryObject: 'employee', capability: 'people.employee.read', menu: { group: '员工档案', label: '员工列表', order: 16, icon: 'User' }, layer: 'business', wave: 'Wave 2', requiredApis: ['GET /v1/employees', 'POST /v1/employments', 'POST /v1/employments/{employmentId}/contracts'], actions: actionsForPage('hr-employees'), states: [...REQUIRED_STATES], e2e: ['16-hr-employees'], negativeTests: [...REQUIRED_NEGATIVE_TESTS],},
  {path: '/hr/employees/:id', name: 'employee-detail', componentKey: 'hr/employees/by-id', kind: 'detail', primaryObject: 'employee', detailOf: 'hr-employees', capability: 'people.employee.read', menu: null, layer: 'business', wave: 'Wave 2', requiredApis: ['GET /v1/employees', 'POST /v1/employments/{employment_id}/assignments', 'DELETE /v1/employees/{employment_id}', 'POST /v1/employees/{employment_id}/restore', 'GET /v1/employees/{employment_id}', 'POST /v1/employees/{employmentId}/change_requests/{id}/submit', 'DELETE /v1/employees/{employmentId}/education/{id}', 'DELETE /v1/employees/{employmentId}/experiences/{id}', 'DELETE /v1/employees/{employmentId}/contacts/{id}', 'DELETE /v1/employees/{employmentId}/certificates/{id}'], actions: actionsForPage('employee-detail'), states: [...REQUIRED_STATES], e2e: ['18-employee-detail'], negativeTests: [...REQUIRED_NEGATIVE_TESTS],},
  {path: '/hr/departments', name: 'hr-departments', componentKey: 'hr/departments/index', kind: 'workspace', primaryObject: 'department', capability: 'org.unit.read', menu: { group: '部门管理', label: '组织架构树', order: 27, icon: 'Share' }, layer: 'business', wave: 'Wave 2', requiredApis: ['GET /v1/org-units/tree', 'POST /v1/departments', 'PATCH /v1/departments/{id}', 'DELETE /v1/departments/{id}/members/{employmentId}'], actions: actionsForPage('hr-departments'), states: [...REQUIRED_STATES], e2e: ['27-hr-departments'], negativeTests: [...REQUIRED_NEGATIVE_TESTS],},
  {path: '/hr/positions', name: 'hr-positions', componentKey: 'hr/positions/index', kind: 'workspace', primaryObject: 'position', capability: 'org.position.read', menu: { group: '岗位管理', label: '岗位目录', order: 33, icon: 'Briefcase' }, layer: 'business', wave: 'Wave 2', requiredApis: ['GET /v1/positions', 'POST /v1/positions', 'PATCH /v1/positions/{id}', 'POST /v1/positions/grades', 'POST /v1/positions/{id}/requirements'], actions: actionsForPage('hr-positions'), states: [...REQUIRED_STATES], e2e: ['33-hr-positions'], negativeTests: [...REQUIRED_NEGATIVE_TESTS],},
  {path: '/hr/movements', name: 'hr-movements', componentKey: 'hr/movements/index', kind: 'workspace', primaryObject: 'movement', capability: 'lifecycle.case.read', menu: { group: '员工异动', label: '异动列表', order: 39, icon: 'Refresh' }, layer: 'business', wave: 'Wave 2', requiredApis: ['GET /v1/cases', 'PATCH /v1/onboarding-tasks/{id}', 'POST /v1/lifecycle/onboarding', 'POST /v1/lifecycle/probation-confirmations', 'POST /v1/lifecycle/transfers', 'POST /v1/lifecycle/salary-changes', 'POST /v1/lifecycle/offboardings', 'POST /v1/lifecycle/secondments'], actions: actionsForPage('hr-movements'), states: [...REQUIRED_STATES], e2e: ['39-hr-movements'], negativeTests: [...REQUIRED_NEGATIVE_TESTS],},
  {path: '/hr/movements/:id', name: 'hr-movements-by-id', componentKey: 'hr/movements/by-id', kind: 'detail', primaryObject: 'movement', detailOf: 'hr-movements', capability: 'lifecycle.case.read', menu: null, layer: 'business', wave: 'Wave 2', requiredApis: ['GET /v1/cases', 'GET /v1/lifecycle/cases/{caseId}'], actions: [], states: [...REQUIRED_STATES], e2e: ['46-hr-movements-by-id'], negativeTests: [...REQUIRED_NEGATIVE_TESTS],},
  {path: '/hr/contracts', name: 'hr-contracts', componentKey: 'hr/contracts/index', kind: 'workspace', primaryObject: 'contract', capability: 'people.contract.read', menu: { group: '劳动合同', label: '合同台账', order: 48, icon: 'Files' }, layer: 'business', wave: 'Wave 2', requiredApis: ['GET /v1/employments/contracts/expiring', 'POST /v1/employments/{employmentId}/contracts', 'GET /v1/employments/{employmentId}/contracts'], actions: actionsForPage('hr-contracts'), states: [...REQUIRED_STATES], e2e: ['48-hr-contracts'], negativeTests: [...REQUIRED_NEGATIVE_TESTS],},
  {path: '/hr/contracts/:id', name: 'hr-contracts-by-id', componentKey: 'hr/contracts/by-id', kind: 'detail', primaryObject: 'contract', detailOf: 'hr-contracts', capability: 'people.contract.read', menu: null, layer: 'business', wave: 'Wave 2', requiredApis: ['GET /v1/employments/contracts/expiring', 'GET /v1/employments/{employmentId}/contracts/{id}', 'POST /v1/employment-contracts/{id}/amendments', 'POST /v1/employment-contracts/{id}/terminate', 'POST /v1/employment-contracts/{id}/activate'], actions: actionsForPage('hr-contracts-by-id'), states: [...REQUIRED_STATES], e2e: ['50-hr-contracts-by-id'], negativeTests: [...REQUIRED_NEGATIVE_TESTS],},
  {path: '/attendance', name: 'attendance', componentKey: 'attendance/index', kind: 'workspace', capability: 'time.attendance.read', menu: { group: '考勤打卡', label: '我的打卡', order: 55, icon: 'Clock' }, layer: 'business', wave: 'Wave 3', requiredApis: ['GET /v1/time/attendance', 'GET /v1/time/attendance/records', 'GET /v1/time/punches', 'GET /v1/time/attendance/records/export', 'POST /v1/time/shifts', 'PATCH /v1/time/shifts/{id}', 'POST /v1/time/shifts/{id}/disable', 'POST /v1/time/attendance/rules', 'POST /v1/time/shift-schedules', 'POST /v1/time/attendance/closings', 'POST /v1/time/attendance/closings/{id}/reopen', 'POST /v1/time/attendance/recalculate', 'POST /v1/time/attendance/corrections'], actions: actionsForPage('attendance'), states: [...REQUIRED_STATES], e2e: ['55-attendance'], negativeTests: [...REQUIRED_NEGATIVE_TESTS],},
  {path: '/leave', name: 'leave', componentKey: 'leave/index', kind: 'workspace', capability: 'time.leave.read', menu: { group: '请假管理', label: '请假申请', order: 65, icon: 'Calendar' }, layer: 'business', wave: 'Wave 3', requiredApis: ['GET /v1/time/leave/requests', 'GET /v1/time/leave/balances', 'GET /v1/time/leave/types', 'POST /v1/time/leave/types', 'PATCH /v1/time/leave/types/{id}', 'GET /v1/time/leave/policies', 'POST /v1/time/leave/policies', 'POST /v1/time/leave/policies/{id}/retire', 'POST /v1/time/leave/requests', 'POST /v1/time/leave/requests/on-behalf', 'POST /v1/time/leave/requests/{caseId}/cancel', 'POST /v1/time/leave/requests/{caseId}/revoke', 'GET /v1/time/leave/reports/summary'], actions: actionsForPage('leave'), states: [...REQUIRED_STATES], e2e: ['65-leave'], negativeTests: [...REQUIRED_NEGATIVE_TESTS],},
  {cluster: 'K5', path: '/overtime', name: 'overtime', componentKey: 'overtime/index', kind: 'workspace', capability: 'time.overtime.read', menu: { group: '加班管理', label: '加班申请', order: 73, icon: 'Timer' }, layer: 'business', wave: 'Wave 3', requiredApis: ['GET /v1/time/overtime/records', 'GET /v1/time/overtime/policies', 'POST /v1/time/overtime/policies', 'POST /v1/time/overtime/policies/{id}/retire', 'GET /v1/time/overtime/requests', 'POST /v1/time/overtime/requests', 'POST /v1/time/overtime/requests/{id}/confirm', 'GET /v1/time/overtime/timeoff-balances', 'GET /v1/time/overtime/settlements', 'POST /v1/overtime/records/{recordId}/settlements'], actions: actionsForPage('overtime'), states: [...REQUIRED_STATES], e2e: ['73-overtime'], negativeTests: [...REQUIRED_NEGATIVE_TESTS],},
  {path: '/travel', name: 'travel', componentKey: 'travel/index', kind: 'workspace', capability: 'time.travel.read', menu: { group: '出差管理', label: '出差申请', order: 79, icon: 'Van' }, layer: 'business', wave: 'Wave 3', requiredApis: ['GET /v1/travel/requests', 'POST /v1/travel/requests', 'POST /v1/travel/requests/{id}/change', 'POST /v1/travel/requests/{id}/approve', 'POST /v1/travel/requests/{id}/cancel', 'GET /v1/travel/policies', 'POST /v1/travel/policies', 'GET /v1/travel/expenses', 'POST /v1/travel/expenses', 'GET /v1/travel/reports/summary'], actions: actionsForPage('travel'), states: [...REQUIRED_STATES], e2e: ['79-travel'], negativeTests: [...REQUIRED_NEGATIVE_TESTS],},
  {path: '/payroll', name: 'payroll', componentKey: 'payroll/workspace', kind: 'workspace', capability: 'payroll.run.read', menu: { group: '薪资管理', label: '薪资概览', order: 86, icon: 'Money' }, layer: 'business', wave: 'Wave 3', requiredApis: ['GET /v1/payroll/runs', 'GET /v1/payroll/runs/{id}', 'GET /v1/payroll/runs/{id}/employees', 'POST /v1/payroll/runs', 'POST /v1/payroll/runs/{id}/review', 'POST /v1/payroll/runs/{id}/publish', 'GET /v1/payroll/runs/{id}/exports/bank', 'POST /v1/payroll/items/{id}/versions', 'POST /v1/payroll/items/{id}/retire', 'GET /v1/payroll/reports/summary', 'GET /v1/payroll/payslips', 'POST /v1/payroll/employee-plans', 'POST /v1/payroll/items', 'POST /v1/payroll/schemes'], actions: actionsForPage('payroll'), states: [...REQUIRED_STATES], e2e: ['86-payroll'], negativeTests: [...REQUIRED_NEGATIVE_TESTS],},
  {path: '/payroll/runs/:id', name: 'payroll-run-detail', componentKey: 'payroll/runs/by-id', kind: 'detail', primaryObject: 'payroll-run', detailOf: 'payroll', capability: 'payroll.run.read', menu: null, layer: 'business', wave: 'Wave 3', requiredApis: ['GET /v1/payroll/runs/{id}', 'GET /v1/payroll/runs/{id}/employees', 'GET /v1/payroll/runs/{id}/exports/bank', 'GET /v1/payroll/runs/{id}/exports/tax', 'POST /v1/payroll/runs/{id}/lock-inputs', 'POST /v1/payroll/runs/{id}/calculate', 'POST /v1/payroll/runs/{id}/review', 'POST /v1/payroll/runs/{id}/approve', 'POST /v1/payroll/runs/{id}/publish', 'POST /v1/payroll/runs/{id}/cancel', 'POST /v1/payroll/runs/{id}/reopen', 'POST /v1/payroll/runs/{id}/corrections', 'POST /v1/payroll/runs/{id}/mark-paid', 'POST /v1/payroll/runs/{id}/close', 'POST /v1/payroll/runs/{id}/members'], actions: actionsForPage('payroll-run-detail'), states: [...REQUIRED_STATES], e2e: ['payroll-run-detail'], negativeTests: [...REQUIRED_NEGATIVE_TESTS]},
  {path: '/performance', name: 'performance', componentKey: 'performance/index', kind: 'workspace', capability: 'performance.cycle.read', menu: { group: '绩效考核', label: '绩效周期', order: 98, icon: 'TrendCharts' }, layer: 'business', wave: 'Wave 4', requiredApis: ['GET /v1/performance/cycles', 'POST /v1/performance/cycles', 'POST /v1/performance/cycles/{id}/publish', 'POST /v1/performance/reviews/{id}/appeals', 'GET /v1/performance/bonus-mappings', 'POST /v1/performance/bonus-mappings', 'POST /v1/performance/bonus-mappings/{id}/approve'], actions: actionsForPage('performance'), states: [...REQUIRED_STATES], e2e: ['98-performance'], negativeTests: [...REQUIRED_NEGATIVE_TESTS],},
  {cluster: 'K8', path: '/performance/cycles/:id', name: 'performance-cycle-detail', componentKey: 'performance/cycles/by-id', kind: 'detail', primaryObject: 'performance-cycle', detailOf: 'performance', capability: 'performance.cycle.read', menu: null, layer: 'business', wave: 'Wave 4', requiredApis: ['GET /v1/performance/cycles'], actions: [], states: [...REQUIRED_STATES], e2e: ['performance-cycle-detail'], negativeTests: [...REQUIRED_NEGATIVE_TESTS],},
  {path: '/recruitment/requests', name: 'recruitment-requests', componentKey: 'recruitment/requests/index', kind: 'workspace', primaryObject: 'requisition', capability: 'recruitment.requisition.read', menu: { group: '招聘需求', label: '招聘需求列表', order: 108, icon: 'Search' }, layer: 'business', wave: 'Wave 4', requiredApis: ['GET /v1/recruitment/requisitions', 'POST /v1/recruitment/requisitions', 'PATCH /v1/recruitment/requisitions/{id}', 'POST /v1/recruitment/requisitions/{id}/close', 'GET /v1/recruitment/board', 'GET /v1/recruitment/budget', 'GET /v1/recruitment/reports'], actions: actionsForPage('recruitment-requests'), states: [...REQUIRED_STATES], e2e: ['108-recruitment-requests'], negativeTests: [...REQUIRED_NEGATIVE_TESTS],},
  {path: '/recruitment/candidates', name: 'recruitment-candidates', componentKey: 'recruitment/candidates/index', kind: 'workspace', primaryObject: 'candidate', capability: 'recruitment.candidate.read', menu: { group: '候选人', label: '候选人列表', order: 115, icon: 'UserFilled' }, layer: 'business', wave: 'Wave 4', requiredApis: ['GET /v1/recruitment/candidates', 'PATCH /v1/recruitment/candidates/{id}/stage', 'POST /v1/recruitment/candidates/imports', 'POST /v1/recruitment/offers', 'POST /v1/recruitment/offers/{id}/accept', 'POST /v1/recruitment/candidates/{id}/convert', 'POST /v1/recruitment/candidates/{id}/talent-pool', 'POST /v1/recruitment/candidates/{id}/applications'], actions: actionsForPage('recruitment-candidates'), states: [...REQUIRED_STATES], e2e: ['115-recruitment-candidates'], negativeTests: [...REQUIRED_NEGATIVE_TESTS],},
  {path: '/recruitment/candidates/:id', name: 'recruitment-candidates-by-id', componentKey: 'recruitment/candidates/by-id', kind: 'detail', primaryObject: 'candidate', detailOf: 'recruitment-candidates', capability: 'recruitment.candidate.read', menu: null, layer: 'business', wave: 'Wave 4', requiredApis: ['GET /v1/recruitment/candidates'], actions: [], states: [...REQUIRED_STATES], e2e: ['116-recruitment-candidates-by-id'], negativeTests: [...REQUIRED_NEGATIVE_TESTS],},
  {cluster: 'K10', path: '/training', name: 'training', componentKey: 'training/index', kind: 'workspace', capability: 'training.program.read', menu: { group: '培训管理', label: '培训计划', order: 125, icon: 'Reading' }, layer: 'business', wave: 'Wave 4', requiredApis: ['GET /v1/training/programs', 'POST /v1/training/programs', 'PATCH /v1/training/programs/{id}', 'PATCH /v1/training/courses/{id}', 'PATCH /v1/training/sessions/{id}', 'POST /v1/training/enrollments/{id}/cancel', 'POST /v1/training/exams/{id}/attempts'], actions: actionsForPage('training'), states: [...REQUIRED_STATES], e2e: ['125-training'], negativeTests: [...REQUIRED_NEGATIVE_TESTS],},
  {path: '/notices', name: 'notices', componentKey: 'notices/index', kind: 'workspace', primaryObject: 'notice', capability: 'engagement.notice.read', menu: { group: '公告通知', label: '公告列表', order: 134, icon: 'Bell' }, layer: 'business', wave: 'Wave 5', requiredApis: ['GET /v1/engagement/notices', 'POST /v1/engagement/notices', 'POST /v1/engagement/notices/{notice}/archive', 'POST /v1/engagement/notices/{notice}/restore', 'POST /v1/engagement/notices/audience-preview', 'POST /v1/engagement/notices/{notice}/submit', 'POST /v1/engagement/notices/{notice}/decision', 'POST /v1/engagement/notices/{notice}/publish', 'GET /v1/engagement/notices/{notice}/reads', 'POST /v1/engagement/notice-templates', 'POST /v1/engagement/notices/{notice}/remind'], actions: actionsForPage('notices'), states: [...REQUIRED_STATES], e2e: ['134-notices'], negativeTests: [...REQUIRED_NEGATIVE_TESTS],},
  {path: '/account', name: 'account', componentKey: 'account', kind: 'governance', capability: 'iam.session.manage', menu: { group: '系统管理', label: '登录与账号安全', order: 101, icon: 'Lock' }, layer: 'platform', wave: 'Wave 5', requiredApis: ['GET /v1/me', 'GET /v1/auth/mfa/status', 'GET /v1/auth/sessions', 'POST /v1/auth/password', 'POST /v1/auth/logout-all', 'DELETE /v1/auth/sessions/{id}', 'POST /v1/auth/mfa/totp/enroll', 'POST /v1/auth/mfa/totp/confirm', 'POST /v1/auth/mfa/totp/disable'], actions: actionsForPage('account'), states: [...REQUIRED_STATES], e2e: ['platform-03B-1'], negativeTests: [...REQUIRED_NEGATIVE_TESTS],},
  {path: '/system/users', name: 'system-users', componentKey: 'system/system-users', kind: 'governance', primaryObject: 'user', capability: 'iam.user.read', menu: { group: '系统管理', label: '用户管理', order: 102, icon: 'Lock' }, layer: 'platform', wave: 'Wave 5', requiredApis: ['GET /v1/me/permissions', 'POST /v1/users', 'PATCH /v1/users/{id}', 'GET /v1/users/{id}/bindings', 'GET /v1/users/{id}/permissions-preview', 'POST /v1/users/{id}/roles', 'GET /v1/users'], actions: actionsForPage('system-users'), states: [...REQUIRED_STATES], e2e: ['platform-03B-2'], negativeTests: [...REQUIRED_NEGATIVE_TESTS],},
  {cluster: 'K11', path: '/system/roles', name: 'system-roles', componentKey: 'system/system-roles', kind: 'governance', primaryObject: 'role', capability: 'iam.role.manage', menu: { group: '系统管理', label: '角色管理', order: 103, icon: 'Lock' }, layer: 'platform', wave: 'Wave 5', requiredApis: ['GET /v1/me/permissions', 'GET /v1/roles', 'GET /v1/roles/{id}/pages', 'GET /v1/capabilities', 'POST /v1/roles', 'POST /v1/roles/{id}/copy', 'PUT /v1/roles/{id}/authorization', 'PATCH /v1/roles/{id}', 'DELETE /v1/roles/{id}'], actions: actionsForPage('system-roles'), states: [...REQUIRED_STATES], e2e: ['platform-03B-3'], negativeTests: [...REQUIRED_NEGATIVE_TESTS],},
  {cluster: 'K11', path: '/system/pages', name: 'system-pages', componentKey: 'system/system-pages', kind: 'governance', primaryObject: 'page', capability: 'iam.permission.read', menu: { group: '系统管理', label: '页面目录', order: 104, icon: 'Lock' }, layer: 'platform', wave: 'Wave 5', requiredApis: ['GET /v1/pages/catalog', 'GET /v1/system/menus', 'POST /v1/system/menus', 'PATCH /v1/system/menus/{page_code}', 'DELETE /v1/system/menus/{page_code}', 'GET /v1/capabilities'], actions: actionsForPage('system-pages'), states: [...REQUIRED_STATES], e2e: ['platform-03B-4'], negativeTests: [...REQUIRED_NEGATIVE_TESTS],},
  {path: '/workflow', name: 'workflow', componentKey: 'workflow', kind: 'governance', capability: 'workflow.case.read', menu: { group: '系统管理', label: '审批流程中心', order: 108, icon: 'Lock' }, layer: 'platform', wave: 'Wave 5', requiredApis: ['GET /v1/me/permissions', 'GET /v1/cases', 'GET /v1/tasks/my', 'GET /v1/workflow/templates', 'POST /v1/workflow/templates', 'POST /v1/cases/{caseId}/decisions', 'POST /v1/cases/{id}/withdraw', 'POST /v1/effects/{id}/retry'], actions: actionsForPage('workflow'), states: [...REQUIRED_STATES], e2e: ['platform-03B-8'], negativeTests: [...REQUIRED_NEGATIVE_TESTS],},
  {path: '/workflow/cases/:id', name: 'workflow-case-detail', componentKey: 'workflow/cases/by-id', primaryObject: 'case', kind: 'detail', capability: 'workflow.case.read', menu: null, detailOf: 'workflow', layer: 'platform', wave: 'Wave 5', requiredApis: ['GET /v1/cases/{id}', 'GET /v1/cases/{id}/timeline'], actions: [], states: [...REQUIRED_STATES], e2e: ['platform-03B-8'], negativeTests: [...REQUIRED_NEGATIVE_TESTS],},
  {cluster: 'K11', path: '/benefits', name: 'benefits', componentKey: 'benefits', kind: 'governance', primaryObject: 'benefit', capability: 'benefit.read', menu: { group: '系统管理', label: '社保公积金与福利', order: 110, icon: 'Lock' }, layer: 'platform', wave: 'Wave 5', requiredApis: ['GET /v1/benefits/plans', 'GET /v1/benefits/enrollments', 'GET /v1/benefits/transactions', 'POST /v1/benefits/plans', 'POST /v1/benefits/enrollments', 'POST /v1/benefits/transactions', 'POST /v1/benefits/transactions/{id}/reverse'], actions: actionsForPage('benefits'), states: [...REQUIRED_STATES], e2e: ['platform-03B-10'], negativeTests: [...REQUIRED_NEGATIVE_TESTS],},
  {cluster: 'K11', path: '/system/settings', name: 'system-settings', componentKey: 'system/system-settings', kind: 'governance', primaryObject: 'setting', capability: 'system.settings.manage', menu: { group: '系统管理', label: '公司设置', order: 112, icon: 'Lock' }, layer: 'platform', wave: 'Wave 5', requiredApis: ['GET /v1/system/company-profile', 'PATCH /v1/system/company-profile', 'GET /v1/platform/features', 'PUT /v1/platform/features/{flag}', 'GET /v1/system/dictionaries', 'PATCH /v1/system/dictionaries/{id}', 'GET /v1/system/parameters', 'PUT /v1/system/parameters', 'GET /v1/time/calendars', 'POST /v1/system/calendars/publish', 'GET /v1/employments/{employment_id}/document-files'], actions: actionsForPage('system-settings'), states: [...REQUIRED_STATES], e2e: ['platform-03B-12'], negativeTests: [...REQUIRED_NEGATIVE_TESTS],},
  {cluster: 'K11', path: '/notifications', name: 'notifications', componentKey: 'notifications', kind: 'governance', primaryObject: 'notification', capability: 'notification.read', menu: { group: '系统管理', label: '消息与通知中心', order: 113, icon: 'Lock' }, layer: 'platform', wave: 'Wave 5', requiredApis: ['GET /v1/me/permissions', 'GET /v1/engagement/notifications', 'POST /v1/notifications/deliveries/{id}/retry'], actions: actionsForPage('notifications'), states: [...REQUIRED_STATES], e2e: ['platform-03B-13'], negativeTests: [...REQUIRED_NEGATIVE_TESTS],},
  {cluster: 'K11', path: '/system/audit', name: 'system-audit', componentKey: 'system/system-audit', kind: 'governance', primaryObject: 'audit', capability: 'iam.audit.read', menu: { group: '系统管理', label: '日志审计与运维', order: 114, icon: 'Lock' }, layer: 'platform', wave: 'Wave 5', requiredApis: ['GET /v1/audit/events', 'POST /v1/audit/events/export', 'GET /v1/health/ready'], actions: actionsForPage('system-audit'), states: [...REQUIRED_STATES], e2e: ['platform-03B-14'], negativeTests: [...REQUIRED_NEGATIVE_TESTS],},
]

export function pageByName(name: string): PageSpec | undefined {
  return PAGE_REGISTRY.find((page) => page.name === name)
}

export function pagesInCluster(cluster: ClusterCode): PageSpec[] {
  return PAGE_REGISTRY.filter((page) => page.cluster === cluster)
}

export function pagesForCapability(capability: string): PageSpec[] {
  return PAGE_REGISTRY.filter((page) => page.capability === capability)
}

// Stable names from the frozen V2.1 page-model contract. The historical
// helpers above stay exported so existing consumers keep working unchanged.
export const findPage = pageByName
export const pagesByCluster = pagesInCluster
export const pagesByCapability = pagesForCapability

/**
 * Frozen action accessor (ADR-0036). Actions come only from the generated
 * manifest; PAGE_REGISTRY must never carry a second hand-written copy.
 */
export function actionsForPage(pageCode: string) {
  return PAGE_ACTIONS[pageCode] ?? []
}
