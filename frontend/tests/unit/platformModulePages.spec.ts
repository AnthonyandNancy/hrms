import { readFileSync, readdirSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter, type RouteRecordRaw } from 'vue-router'
import ElementPlus from 'element-plus'
import { PAGE_REGISTRY } from '@/router/registry'
import { PAGE_COMPONENTS } from '@/modules/platform/pages'
import { platformPageConfig } from '@/modules/platform/catalog'
import SystemUsersPage from '@/modules/platform/pages/SystemUsersPage.vue'
import NoticeListPage from '@/modules/platform/pages/NoticeListPage.vue'

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
const DEDICATED_PAGES = new Set(['system/system-users', 'system/system-roles'])

function listResponse(items: unknown[] = []) {
  return { items, page: 1, page_size: 20, total: items.length }
}

async function mountPage(component: object, path: string) {
  const pinia = createPinia()
  setActivePinia(pinia)
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
    expect(platformRegistryKeys).toHaveLength(36)
    expect(Object.keys(PAGE_COMPONENTS).sort()).toEqual(platformRegistryKeys)

    const loadedComponents = new Set<object>()
    const manifestSource = readFileSync(join(pagesDir, 'index.ts'), 'utf8')
    const componentFiles = [...manifestSource.matchAll(/'([^']+)':\s*\(\)\s*=>\s*import\('([^']+\.vue)'\)/g)]
      .map((match) => match[2])
    const vueFiles = readdirSync(pagesDir)
      .filter((name) => name.endsWith('.vue'))
      .sort()

    expect(componentFiles).toHaveLength(36)
    expect(new Set(componentFiles).size).toBe(36)
    expect(vueFiles).toHaveLength(36)

    for (const key of platformRegistryKeys) {
      const loader = PAGE_COMPONENTS[key as keyof typeof PAGE_COMPONENTS]
      expect(loader, key).toBeTypeOf('function')
      const module = await loader()
      expect(module.default, key).toBeTruthy()
      loadedComponents.add(module.default)
    }

    expect(loadedComponents.size).toBe(36)
    expect(platformPageConfig('system/system-users')).not.toBeNull()
    expect(platformPageConfig('system/system-users')?.title).toBe('用户管理')
  })

  it('keeps every page config-bound and free of componentKey identity lookups', () => {
    const manifestSource = readFileSync(join(pagesDir, 'index.ts'), 'utf8')
    const mappings = [...manifestSource.matchAll(/'([^']+)':\s*\(\)\s*=>\s*import\('([^']+\.vue)'\)/g)]
    const filenames = new Map(mappings.map((match) => [match[1], match[2]?.replace('./', '')]))

    for (const key of platformRegistryKeys) {
      const source = readFileSync(join(pagesDir, filenames.get(key) ?? ''), 'utf8')
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

    expect(wrapper.get('[data-testid="system-users-page"]').exists()).toBe(true)
    expect(wrapper.get('h1').text()).toBe('用户与角色')
    expect(resource.get).toHaveBeenCalledWith('/v1/users', expect.objectContaining({ page: 1 }))
    expect(resource.get).toHaveBeenCalledWith('/v1/roles')
    expect(wrapper.text()).not.toContain('页面授权树')
    expect(wrapper.text()).not.toContain('日志审计')
  })

  it('passes the notice list config to the workspace and calls only the notice feed', async () => {
    resource.get.mockResolvedValue(listResponse())

    const wrapper = await mountPage(NoticeListPage, '/notices')

    expect(wrapper.get('.platform-workspace').attributes('data-page')).toBe('notices/index')
    expect(wrapper.get('h1').text()).toBe('公告列表')
    expect(resource.get).toHaveBeenCalledWith('/v1/engagement/notices', expect.objectContaining({ filter: 'all' }))
    expect(resource.get).not.toHaveBeenCalledWith('/v1/auth/events', expect.anything())
    expect(wrapper.text()).not.toContain('日志审计')
  })
})
