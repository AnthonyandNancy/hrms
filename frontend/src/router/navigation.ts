import type { CaseAction } from '@/api/contracts'

export type NavigationItem = {
  key: string
  label: string
  path: string
  icon: string
  capability?: string
  group: 'work' | 'people' | 'time' | 'pay' | 'insight' | 'admin'
  badge?: 'approvals' | 'notices'
}

export const NAVIGATION: NavigationItem[] = [
  {
    key: 'workspace',
    label: '我的工作台',
    path: '/workspace',
    icon: 'HomeFilled',
    group: 'work',
  },
  {
    key: 'cases',
    label: '审批中心',
    path: '/cases',
    icon: 'Stamp',
    capability: 'workflow.case.view.mine',
    group: 'work',
    badge: 'approvals',
  },
  {
    key: 'directory',
    label: '员工目录',
    path: '/employees',
    icon: 'User',
    capability: 'people.directory.view',
    group: 'people',
  },
  {
    key: 'organization',
    label: '组织架构',
    path: '/organization',
    icon: 'Share',
    capability: 'organization.view',
    group: 'people',
  },
  {
    key: 'leave',
    label: '假期与余额',
    path: '/leave',
    icon: 'Calendar',
    capability: 'time.leave.view',
    group: 'time',
  },
  {
    key: 'attendance',
    label: '考勤日历',
    path: '/attendance',
    icon: 'Clock',
    capability: 'time.attendance.view.mine',
    group: 'time',
  },
  {
    key: 'payroll',
    label: '工资批次',
    path: '/payroll',
    icon: 'Money',
    capability: 'payroll.run.view',
    group: 'pay',
  },
  {
    key: 'payslips',
    label: '我的工资条',
    path: '/payroll/my-payslips',
    icon: 'Wallet',
    capability: 'payroll.payslip.view.mine',
    group: 'pay',
  },
  {
    key: 'dashboard',
    label: '人力仪表盘',
    path: '/dashboard',
    icon: 'DataAnalysis',
    capability: 'reporting.dashboard.view',
    group: 'insight',
  },
  {
    key: 'reports',
    label: '报表中心',
    path: '/reports',
    icon: 'Document',
    capability: 'reporting.report.view',
    group: 'insight',
  },
  {
    key: 'notices',
    label: '公告与通知',
    path: '/notices',
    icon: 'Bell',
    capability: 'engagement.notice.view',
    group: 'work',
    badge: 'notices',
  },
  {
    key: 'admin',
    label: '权限治理',
    path: '/admin',
    icon: 'Lock',
    capability: 'iam.user.view',
    group: 'admin',
  },
]

export const GROUP_LABEL: Record<NavigationItem['group'], string> = {
  work: '协作',
  people: '人员与组织',
  time: '假勤',
  pay: '薪酬',
  insight: '洞察',
  admin: '治理',
}

/** UI-only filtering. The backend is always the authority for each resource. */
export function visibleNavigation(capabilities: readonly string[]): NavigationItem[] {
  const granted = new Set(capabilities)
  return NAVIGATION.filter((item) => !item.capability || granted.has(item.capability))
}

export function can(capabilities: readonly string[], capability: string | undefined): boolean {
  return !capability || capabilities.includes(capability)
}

const CASE_ACTION_CAPABILITY: Record<CaseAction, string> = {
  APPROVE: 'workflow.case.decide',
  REJECT: 'workflow.case.decide',
  RETURN: 'workflow.case.decide',
  RETRY_EFFECT: 'workflow.effect.retry',
}

export function visibleCaseActions(
  capabilities: readonly string[],
  actions: readonly CaseAction[],
): CaseAction[] {
  return actions.filter((action) => capabilities.includes(CASE_ACTION_CAPABILITY[action]))
}

export function firstAllowedPath(capabilities: readonly string[]): string {
  return visibleNavigation(capabilities)[0]?.path ?? '/workspace'
}
