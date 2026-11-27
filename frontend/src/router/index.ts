import { createRouter, createWebHistory, type RouteLocationNormalized, type RouteRecordRaw } from 'vue-router'
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
  // Numeric ids only. String.raw is required: a plain TS string would turn
  // \d into d and Vue Router would treat (d+) as a literal path segment.
  const pathWithIdConstraint = /:[A-Za-z_][A-Za-z0-9_]*/.test(page.path)
    ? page.path.replace(/:([A-Za-z_][A-Za-z0-9_]*)/g, (_match, name: string) => String.raw`${':'}${name}(\d+)`)
    : page.path

  return {
    path: nested ? pathWithIdConstraint.replace(/^\//, '') : pathWithIdConstraint,
    name: page.name,
    component: loader ?? (() => import('@/components/ResourcePage.vue')),
    props: loader ? (page.path.includes(':id') ? true : false) : { page },
    meta: {
      title: page.menu?.label ?? page.name,
      navKey: page.name,
      pageCode: page.name,
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

export async function pageAccessGuard(
  to: RouteLocationNormalized,
  session: ReturnType<typeof useSessionStore>,
): Promise<true | { name?: string; path?: string; query?: Record<string, string | undefined> }> {
  if (session.status === 'idle') {
    await session.bootstrap()
  }
  const isPublic = to.meta.public === true
  if (!isPublic && !session.isAuthenticated) {
    return {
      name: 'login',
      query: { redirect: to.fullPath, expired: session.status === 'expired' ? '1' : undefined },
    }
  }
  if (to.name === 'login' && session.isAuthenticated && to.query.mfa !== '1') {
    return { path: '/workspace' }
  }
  const required = typeof to.meta.capability === 'string' ? to.meta.capability : undefined
  const pageCode = typeof to.meta.pageCode === 'string' ? to.meta.pageCode : undefined

  // Public and terminal pages are never subject to a page grant. /forbidden and
  // /not-found must stay reachable, otherwise a denied page redirects to a page
  // that redirects to itself and the SPA locks up before it can render.
  const isTerminal =
    isPublic || to.name === 'forbidden' || to.name === 'not-found' || to.name === 'not-found-catchall'

  if (!isTerminal && pageCode && !session.canPage(pageCode)) {
    return { name: 'forbidden', query: { from: to.fullPath, reason: 'PAGE_NOT_GRANTED' } }
  }
  if (!isTerminal && required && !session.can(required)) {
    return { name: 'forbidden', query: { from: to.fullPath, reason: 'ACTION_NOT_GRANTED' } }
  }

  return true
}

router.beforeEach((to) => pageAccessGuard(to, useSessionStore()))

router.afterEach((to) => {
  const pageTitle = typeof to.meta.title === 'string' ? to.meta.title : ''
  document.title = pageTitle ? `${pageTitle} · HRMS` : 'HRMS · 人事工作台'
})

export default router
