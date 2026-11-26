import type { CaseAction } from '@/api/contracts'
import { PAGE_REGISTRY, type MenuSpec } from '@/router/registry'

export type NavigationGroup =
  | 'dashboard'
  | 'reports'
  | 'people'
  | 'organization'
  | 'lifecycle'
  | 'contracts'
  | 'attendance'
  | 'leave'
  | 'overtime'
  | 'travel'
  | 'payroll'
  | 'performance'
  | 'recruitment'
  | 'candidates'
  | 'training'
  | 'engagement'
  | 'system'

export type NavigationItem = {
  key: string
  label: string
  path: string
  icon: string
  expandable: boolean
  capability?: string
  group: NavigationGroup
  badge?: 'approvals' | 'notices'
  children: NavigationChild[]
}

export type NavigationChild = {
  key: string
  label: string
  path: string
  capability: string
  order: number
  icon: string
}

const GROUP_BY_MENU: Record<string, NavigationGroup> = {
  仪表盘: 'dashboard',
  报表中心: 'reports',
  员工档案: 'people',
  部门管理: 'organization',
  岗位管理: 'organization',
  员工异动: 'lifecycle',
  劳动合同: 'contracts',
  考勤打卡: 'attendance',
  请假管理: 'leave',
  加班管理: 'overtime',
  出差管理: 'travel',
  薪资管理: 'payroll',
  绩效考核: 'performance',
  招聘需求: 'recruitment',
  候选人: 'candidates',
  培训管理: 'training',
  公告通知: 'engagement',
  系统管理: 'system',
}

const GROUP_LABEL: Record<NavigationGroup, string> = {
  dashboard: '仪表盘',
  reports: '报表中心',
  people: '员工档案',
  organization: '组织与岗位',
  lifecycle: '员工异动',
  contracts: '劳动合同',
  attendance: '考勤打卡',
  leave: '请假管理',
  overtime: '加班管理',
  travel: '出差管理',
  payroll: '薪资管理',
  performance: '绩效考核',
  recruitment: '招聘需求',
  candidates: '候选人',
  training: '培训管理',
  engagement: '公告通知',
  system: '系统管理',
}

const ICON_BY_GROUP: Record<NavigationGroup, string> = {
  dashboard: 'DataAnalysis',
  reports: 'Document',
  people: 'User',
  organization: 'Share',
  lifecycle: 'Refresh',
  contracts: 'Files',
  attendance: 'Clock',
  leave: 'Calendar',
  overtime: 'Timer',
  travel: 'Van',
  payroll: 'Money',
  performance: 'TrendCharts',
  recruitment: 'Search',
  candidates: 'UserFilled',
  training: 'Reading',
  engagement: 'Bell',
  system: 'Lock',
}

const BADGE_BY_GROUP: Partial<Record<NavigationGroup, 'approvals' | 'notices'>> = {
  lifecycle: 'approvals',
  engagement: 'notices',
}

function menuGroupKey(menu: MenuSpec): NavigationGroup {
  return GROUP_BY_MENU[menu.group] ?? 'system'
}

/**
 * Build the 17 top-level menu groups from the controlled registry.
 *
 * A parent menu is never sufficient to see a child: every child keeps its own
 * capability and is filtered independently. The workspace base entry is the
 * only non-menu item exposed at the top.
 */
export const NAVIGATION: NavigationItem[] = (() => {
  const groups = new Map<NavigationGroup, NavigationChild[]>()
  const groupOrder = new Map<NavigationGroup, number>()

  for (const page of PAGE_REGISTRY) {
    if (page.menu === null) {
      continue
    }
    const group = menuGroupKey(page.menu)
    const children = groups.get(group) ?? []
    children.push({
      key: page.name,
      label: page.menu.label,
      path: page.path,
      capability: page.capability,
      order: page.menu.order,
      icon: page.menu.icon,
    })
    groups.set(group, children)
    groupOrder.set(group, Math.min(groupOrder.get(group) ?? Number.MAX_SAFE_INTEGER, page.menu.order))
  }

  return [
    {
      key: 'workspace',
      label: '我的工作台',
      path: '/workspace',
      icon: 'HomeFilled',
      expandable: false,
      group: 'dashboard',
      children: [],
    },
    ...[...groups.entries()]
      .sort(([a], [b]) => (groupOrder.get(a) ?? 0) - (groupOrder.get(b) ?? 0))
      .map(([group, children]) => {
        const first = children[0]
        return {
          key: group,
          label: GROUP_LABEL[group],
          path: first?.path ?? '/workspace',
          icon: ICON_BY_GROUP[group],
          expandable: children.length > 0,
          group,
          badge: BADGE_BY_GROUP[group],
          children: children.sort((a, b) => a.order - b.order),
        }
      }),
  ]
})()

export { GROUP_LABEL }

export function visibleNavigation(capabilities: readonly string[]): NavigationItem[] {
  const granted = new Set(capabilities)

  return NAVIGATION.map((item) => ({
    ...item,
    children: item.children.filter((child) => granted.has(child.capability)),
  })).filter((item) => item.children.length > 0 || item.key === 'workspace')
}

export function can(capabilities: readonly string[], capability: string | undefined): boolean {
  return !capability || capabilities.includes(capability)
}

const CASE_ACTION_CAPABILITY: Record<CaseAction, string> = {
  APPROVE: 'workflow.case.decide',
  REJECT: 'workflow.case.decide',
  RETURN: 'workflow.case.decide',
  RETRY_EFFECT: 'workflow.case.admin',
}

export function visibleCaseActions(
  capabilities: readonly string[],
  actions: readonly CaseAction[],
): CaseAction[] {
  return actions.filter((action) => capabilities.includes(CASE_ACTION_CAPABILITY[action]))
}

export function firstAllowedPath(capabilities: readonly string[]): string {
  const navigation = visibleNavigation(capabilities)
  return navigation[0]?.path ?? '/workspace'
}
