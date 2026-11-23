import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { useSessionStore } from '@/stores/session'

const routes: RouteRecordRaw[] = [
  {
    path: '/login',
    name: 'login',
    component: () => import('@/views/LoginView.vue'),
    meta: { public: true, title: '登录' },
  },
  {
    path: '/',
    component: () => import('@/layouts/AppShell.vue'),
    children: [
      {
        path: '',
        redirect: '/workspace',
      },
      {
        path: 'workspace',
        name: 'workspace',
        component: () => import('@/views/WorkspaceView.vue'),
        meta: { title: '我的工作台', navKey: 'workspace' },
      },
      {
        path: 'employees',
        name: 'employees',
        component: () => import('@/views/EmployeeDirectoryView.vue'),
        meta: { title: '员工目录', capability: 'people.employee.read', navKey: 'directory' },
      },
      {
        path: 'organization',
        name: 'organization',
        component: () => import('@/views/OrgTreeView.vue'),
        meta: { title: '组织架构', capability: 'org.tree.read', navKey: 'organization' },
      },
      {
        path: 'cases',
        name: 'cases',
        component: () => import('@/views/CaseCenterView.vue'),
        meta: { title: '审批中心', capability: 'workflow.case.read', navKey: 'cases' },
      },
      {
        path: 'cases/:id',
        name: 'case-detail',
        component: () => import('@/views/CaseDetailView.vue'),
        meta: { title: '案例详情', capability: 'workflow.case.read', navKey: 'cases' },
        props: true,
      },
      {
        path: 'leave',
        name: 'leave',
        component: () => import('@/views/LeaveView.vue'),
        meta: { title: '假期与余额', capability: 'time.leave.read', navKey: 'leave' },
      },
      {
        path: 'attendance',
        name: 'attendance',
        component: () => import('@/views/AttendanceView.vue'),
        meta: { title: '考勤日历', capability: 'time.attendance.read', navKey: 'attendance' },
      },
      {
        path: 'payroll',
        name: 'payroll',
        component: () => import('@/views/PayrollRunsView.vue'),
        meta: { title: '工资批次', capability: 'payroll.run.read', navKey: 'payroll' },
      },
      {
        path: 'payroll/runs/:id',
        name: 'payroll-run-detail',
        component: () => import('@/views/PayrollRunDetailView.vue'),
        meta: { title: '工资批次详情', capability: 'payroll.run.read', navKey: 'payroll' },
        props: true,
      },
      {
        path: 'payroll/my-payslips',
        name: 'payslips',
        component: () => import('@/views/PayslipsView.vue'),
        meta: { title: '我的工资条', capability: 'payroll.payslip.read.self', navKey: 'payslips' },
      },
      {
        path: 'payroll/my-payslips/:id',
        name: 'payslip-detail',
        component: () => import('@/views/PayslipDetailView.vue'),
        meta: { title: '工资条详情', capability: 'payroll.payslip.read.self', navKey: 'payslips' },
        props: true,
      },
      {
        path: 'admin',
        name: 'admin',
        component: () => import('@/views/AdminView.vue'),
        meta: { title: '权限治理', capability: 'iam.user.read', navKey: 'admin' },
      },
      {
        path: 'notices',
        name: 'notices',
        component: () => import('@/views/NoticesView.vue'),
        meta: { title: '公告与通知', capability: 'engagement.notice.read', navKey: 'notices' },
      },
      {
        path: 'notices/:id',
        name: 'notice-detail',
        component: () => import('@/views/NoticeDetailView.vue'),
        meta: { title: '公告详情', capability: 'engagement.notice.read', navKey: 'notices' },
        props: true,
      },
      {
        path: 'dashboard',
        name: 'dashboard',
        component: () => import('@/views/DashboardView.vue'),
        meta: { title: '人力仪表盘', capability: 'reporting.dashboard.read', navKey: 'dashboard' },
      },
      {
        path: 'dashboard/metric/:metric',
        name: 'metric-drilldown',
        component: () => import('@/views/MetricDrilldownView.vue'),
        meta: { title: '指标下钻', capability: 'reporting.dashboard.read', navKey: 'dashboard' },
        props: true,
      },
      {
        path: 'reports',
        name: 'reports',
        component: () => import('@/views/ReportsView.vue'),
        meta: { title: '报表中心', capability: 'reporting.export', navKey: 'reports' },
      },
      {
        path: 'forbidden',
        name: 'forbidden',
        component: () => import('@/views/ForbiddenView.vue'),
        meta: { title: '无访问权限' },
      },
    ],
  },
  {
    path: '/:pathMatch(.*)*',
    name: 'not-found',
    component: () => import('@/views/NotFoundView.vue'),
    meta: { public: true, title: '页面不存在' },
  },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior() {
    return { top: 0 }
  },
})

router.beforeEach(async (to) => {
  const session = useSessionStore()
  if (session.status === 'idle') {
    await session.bootstrap()
  }
  const isPublic = to.meta.public === true
  if (!isPublic && !session.isAuthenticated) {
    return { name: 'login', query: { redirect: to.fullPath, expired: session.status === 'expired' ? '1' : undefined } }
  }
  if (to.name === 'login' && session.isAuthenticated && to.query.mfa !== '1') {
    return { path: '/workspace' }
  }
  const required = typeof to.meta.capability === 'string' ? to.meta.capability : undefined
  if (required && !session.can(required)) {
    return { name: 'forbidden', query: { from: to.fullPath } }
  }
  return true
})

router.afterEach((to) => {
  const pageTitle = typeof to.meta.title === 'string' ? to.meta.title : ''
  document.title = pageTitle ? `${pageTitle} · HRMS` : 'HRMS · 人事工作台'
})

export default router
