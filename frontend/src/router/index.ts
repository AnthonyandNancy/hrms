import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { useSessionStore } from '@/stores/session'
import { PAGE_REGISTRY, type PageSpec } from '@/router/registry'
import { matureView } from '@/router/pageComponents'

/**
 * Build a route from the controlled registry.
 *
 * Mature views keep their existing component. Every other registered page uses
 * ResourcePage with the page's declared canonical GET operation. That makes
 * coverage executable: a page can no longer exist without a route, capability,
 * menu metadata and an API declaration.
 */
function registryRoute(page: PageSpec, nested = true): RouteRecordRaw {
  const loader = matureView(page.componentKey)

  return {
    path: nested ? page.path.replace(/^\//, '') : page.path,
    name: page.name,
    component: loader ?? (() => import('@/components/ResourcePage.vue')),
    props: loader ? (page.path.includes(':id') ? true : false) : { page },
    meta: {
      title: page.menu?.label ?? page.name,
      capability: page.capability === 'public' ? undefined : page.capability,
      public: page.capability === 'public',
      menu: page.menu,
      registry: page,
    },
  } as RouteRecordRaw
}

const loginPage = PAGE_REGISTRY.find((page) => page.path === '/login')
const notFoundPage = PAGE_REGISTRY.find((page) => page.path === '/not-found')
const shellPages = PAGE_REGISTRY.filter(
  (page) => page.path !== '/login' && page.path !== '/not-found',
)

if (loginPage === undefined || notFoundPage === undefined) {
  throw new Error('The base registry must declare /login and /not-found.')
}

const routes: RouteRecordRaw[] = [
  registryRoute(loginPage, false),
  {
    path: '/',
    component: () => import('@/layouts/AppShell.vue'),
    children: [
      { path: '', redirect: '/workspace' },
      ...shellPages.map((page) => registryRoute(page)),
    ],
  },
  registryRoute(notFoundPage, false),
  {
    path: '/employees',
    redirect: '/hr/employees',
  },
  {
    path: '/organization',
    redirect: '/hr/departments',
  },
  {
    path: '/cases',
    name: 'cases',
    component: () => import('@/views/CaseCenterView.vue'),
    meta: { title: '审批中心', capability: 'workflow.case.read' },
  },
  {
    path: '/cases/:id',
    name: 'case-detail',
    component: () => import('@/views/CaseDetailView.vue'),
    meta: { title: '案例详情', capability: 'workflow.case.read' },
    props: true,
  },
  {
    path: '/admin',
    redirect: '/system/users',
  },
  {
    path: '/:pathMatch(.*)*',
    name: 'not-found-catchall',
    redirect: '/not-found',
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
