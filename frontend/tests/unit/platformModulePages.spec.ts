import { readFileSync, readdirSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter, type RouteRecordRaw } from 'vue-router'
import { useSessionStore } from '@/stores/session'
import ElementPlus from 'element-plus'
import { PAGE_REGISTRY } from '@/router/registry'
import { PAGE_COMPONENTS } from '@/modules/platform/pages'
import { platformPageConfig } from '@/modules/platform/catalog'
import SystemUsersPage from '@/modules/platform/pages/SystemUsersPage.vue'
// Volume 3 Task 16: notices now live in the reporting module.
import NoticesPage from '@/modules/reporting/pages/NoticesPage.vue'

const resource = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  patch: vi.fn(),
  put: vi.fn(),
  delete: vi.fn(),
}))

vi.mock('@/api/resourceClient', () => ({
  resourceGet: resource.get,
  resourcePost: resource.post,
  resourcePatch: resource.patch,
  resourcePut: resource.put,
  resourceDelete: resource.delete,
}))

const pagesDir = resolve(dirname(fileURLToPath(import.meta.url)), '../../src/modules/platform/pages')

function isPlatformComponentKey(key: string): boolean {
  return (
    ['dashboard/', 'reports/', 'notices/'].some((prefix) => key.startsWith(prefix)) ||
    ['account', 'system/', 'workflow', 'self-service', 'benefits', 'notifications'].some(
      (prefix) => key === prefix || key.startsWith(prefix),
    )
  )
}

const platformRegistryKeys = PAGE_REGISTRY.map((page) => page.componentKey)
  .filter(isPlatformComponentKey)
  .sort()

// Pages that have a purpose-built module implementation instead of the shared
// config-driven workspace. They must not regress into the generic shell.
const DEDICATED_PAGES = new Set([
  // Volume 4 Task 2: account security is its own purpose-built workspace.
  'account',
  // Volume 4 Task 5: benefits is a purpose-built plan/rate workspace.
  'benefits',
  'system/system-users',
  'system/system-roles',
  // Volume 2 Task 8 replaced the generic workspace with purpose-built
  // governance screens; they must not regress into the shared shell.
  'system/system-menus',
  'system/system-permissions',
  'system/system-data-scopes',
  'system/system-field-policies',
  // Volume 3 Task 17 replaced the remaining Platform config shells with
  // purpose-built workflow, calendar, settings, notification and audit pages.
  'workflow',
  'workflow/cases/by-id',
  'system/system-calendars',
  'system/system-settings',
  'notifications',
  'system/system-audit',
])

// Volume 3 Task 16: dashboard, reports and notices are their own module now and
// are resolved by the router from @/modules/reporting/pages.
const MOVED_TO_REPORTING = new Set(['dashboard/index', 'reports/index', 'notices/index'])

function listResponse(items: unknown[] = []) {
  return { items, page: 1, page_size: 20, total: items.length }
}

async function mountPage(component: object, path: string, capabilities: string[] = []) {
  const pinia = createPinia()
  setActivePinia(pinia)

  // Dedicated workspaces gate themselves on the session capability set, so the
  // test seeds exactly the capabilities the case is about.
  const session = useSessionStore()
  session.capabilities = capabilities
  const routes: RouteRecordRaw[] = [
    { path, name: `page-${path.replaceAll('/', '-')}`, component: { template: '<div />' } },
    { path: '/forbidden', name: 'forbidden', component: { template: '<div />' } },
    { path: '/login', name: 'login', component: { template: '<div />' } },
    { path: '/:pathMatch(.*)*', name: 'catchall', component: { template: '<div />' } },
  ]
  const router = createRouter({ history: createMemoryHistory(), routes })
  await router.push(path)
  await router.isReady()
  const wrapper = mount(component, {
    global: { plugins: [pinia, router, ElementPlus] },
  })
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  resource.get.mockReset()
  resource.post.mockReset()
  resource.patch.mockReset()
  resource.put.mockReset()
  resource.delete.mockReset()
})

describe('platform module page split', () => {
  it('maps every Platform registry key to exactly one distinct lazy page file', async () => {
    const platformOwnedKeys = platformRegistryKeys.filter((key) => !MOVED_TO_REPORTING.has(key))

    // ADR-0035: notice editor/archive form routes became workspace actions.
    expect(platformOwnedKeys).toHaveLength(32)
    expect(Object.keys(PAGE_COMPONENTS).sort()).toEqual(platformOwnedKeys)

    const loadedComponents = new Set<object>()
    const manifestSource = readFileSync(join(pagesDir, 'index.ts'), 'utf8')
    const componentFiles = [...manifestSource.matchAll(/'([^']+)':\s*\(\)\s*=>\s*import\('([^']+\.vue)'\)/g)]
      .map((match) => match[2])
    const vueFiles = readdirSync(pagesDir)
      .filter((name) => name.endsWith('.vue'))
      .sort()

    expect(componentFiles).toHaveLength(32)
    expect(new Set(componentFiles).size).toBe(32)
    // 34 platform-owned screens plus the three reporting workspaces that moved out
    // (minus the two notice form-route screens that are now workspace actions).
    expect(vueFiles).toHaveLength(34)

    for (const key of platformRegistryKeys) {
      if (MOVED_TO_REPORTING.has(key)) continue
      const loader = PAGE_COMPONENTS[key as keyof typeof PAGE_COMPONENTS]
      expect(loader, key).toBeTypeOf('function')
      const module = await loader()
      expect(module.default, key).toBeTruthy()
      loadedComponents.add(module.default)
    }

    expect(loadedComponents.size).toBe(32)
    expect(platformPageConfig('system/system-users')).not.toBeNull()
    expect(platformPageConfig('system/system-users')?.title).toBe('用户管理')
  })

  it('keeps every page config-bound and free of componentKey identity lookups', () => {
    const manifestSource = readFileSync(join(pagesDir, 'index.ts'), 'utf8')
    const mappings = [...manifestSource.matchAll(/'([^']+)':\s*\(\)\s*=>\s*import\('([^']+\.vue)'\)/g)]
    const filenames = new Map(mappings.map((match) => [match[1], match[2] ?? '']))

    for (const key of platformRegistryKeys) {
      if (MOVED_TO_REPORTING.has(key)) continue
      const source = readFileSync(resolve(pagesDir, filenames.get(key) ?? ''), 'utf8')
      expect(source, key).not.toMatch(/\bcomponentKey\b/)
      if (DEDICATED_PAGES.has(key)) {
        expect(source, key).not.toContain('PlatformWorkspace')
        continue
      }
      expect(source, key).toContain(`platformPageConfig('${key}')`)
      expect(source, key).toContain('<PlatformWorkspace :config="config" />')
    }

    const shellSource = readFileSync(
      resolve(pagesDir, '../components/PlatformWorkspace.vue'),
      'utf8',
    )
    expect(shellSource).not.toMatch(/\bcomponentKey\b/)
    expect(shellSource).not.toContain('route.meta.registry')
    expect(shellSource).not.toContain('useRoute')
    expect(shellSource).toMatch(/defineProps<\{\s+config: PlatformPageConfig\s+\}>/)
  })

  it('renders the dedicated users page and calls the users and roles endpoints', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/users') return Promise.resolve(listResponse())
      if (path === '/v1/roles') return Promise.resolve({ roles: [] })
      return Promise.resolve({})
    })

    const wrapper = await mountPage(SystemUsersPage, '/system/system-users')

    expect(wrapper.find('[data-testid="system-users-page"]').exists()).toBe(true)
    expect(wrapper.get('h1').text()).toBe('用户与角色')
    expect(resource.get).toHaveBeenCalledWith('/v1/users', expect.objectContaining({ page: 1 }))
    expect(resource.get).toHaveBeenCalledWith('/v1/roles')
    expect(wrapper.text()).not.toContain('页面授权树')
    expect(wrapper.text()).not.toContain('日志审计')
  })

  it('passes the notice list config to the workspace and calls only the notice feed', async () => {
    resource.get.mockResolvedValue(listResponse())

    const wrapper = await mountPage(NoticesPage, '/notices', ['engagement.notice.read'])

    // Volume 3 Task 16 replaced the config shell with the notices workspace; the
    // guarantees that matter are unchanged: this page reads only the notice feed.
    expect(wrapper.find('[data-testid="notices-page"]').exists()).toBe(true)
    expect(resource.get).toHaveBeenCalledWith('/v1/engagement/notices', expect.objectContaining({ filter: 'all' }))
    expect(resource.get).not.toHaveBeenCalledWith('/v1/auth/events', expect.anything())
    expect(wrapper.text()).not.toContain('日志审计')
  })
})
