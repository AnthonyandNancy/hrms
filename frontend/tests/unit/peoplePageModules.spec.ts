import { readFileSync, readdirSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter, type RouteRecordRaw } from 'vue-router'
import ElementPlus from 'element-plus'
import type { Component } from 'vue'
import { PAGE_REGISTRY } from '@/router/registry'
import { matureView } from '@/router/pageComponents'
import { PAGE_COMPONENTS } from '@/modules/people/pages'
import { useSessionStore } from '@/stores/session'
import PeopleWorkspace from '@/modules/people/components/PeopleWorkspace.vue'

const resource = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  patch: vi.fn(),
}))

vi.mock('@/api/resourceClient', () => ({
  resourceGet: resource.get,
  resourcePost: resource.post,
  resourcePatch: resource.patch,
}))

type PageModule = { default: Component }
type LoaderMap = Record<string, (() => Promise<PageModule>) | undefined>

const loaders = PAGE_COMPONENTS as LoaderMap
const peoplePages = PAGE_REGISTRY.filter((page) => page.componentKey.startsWith('hr/'))
const peopleKeys = peoplePages.map((page) => page.componentKey)
const unitDir = dirname(fileURLToPath(import.meta.url))
const pagesDir = resolve(unitDir, '../../src/modules/people/pages')
const employeesDir = resolve(unitDir, '../../src/modules/people/employees/pages')
const workspacePath = resolve(unitDir, '../../src/modules/people/components/PeopleWorkspace.vue')
const routerSourcePath = resolve(unitDir, '../../src/router/pageComponents.ts')

// Volume 3 replaced these config-driven wrappers with purpose-built employee
// workspaces. The legacy files stay as one-line re-exports so the registry and
// route integration do not change; they must not regress into PeopleWorkspace.
const DEDICATED_EMPLOYEE_PAGES: ReadonlyMap<string, { target: string; shape: RegExp }> = new Map([
  // Volume 3 Task 7: the movement workspace and its detail page are dedicated
  // implementations; the legacy files are thin re-exports of them.
  [
    'MovementListPage.vue',
    {
      target: '../../lifecycle/pages/MovementWorkspacePage.vue',
      shape: /import\s+MovementWorkspacePage\s+from\s+['"]\.\.\/lifecycle\/pages\/MovementWorkspacePage\.vue['"][\s\S]*<MovementWorkspacePage\s*\/>/,
    },
  ],
  [
    'MovementDetailPage.vue',
    {
      target: '../../lifecycle/pages/MovementDetailPage.vue',
      shape: /import\s+MovementDetailPage\s+from\s+['"]\.\.\/lifecycle\/pages\/MovementDetailPage\.vue['"][\s\S]*<MovementDetailPage\s*\/>/,
    },
  ],
  [
    'EmployeeListPage.vue',
    {
      target: 'EmployeeListPage.vue',
      shape: /import\s+EmployeeListPage\s+from\s+['"]\.\.\/employees\/pages\/EmployeeListPage\.vue['"][\s\S]*<EmployeeListPage\s*\/>/,
    },
  ],
  [
    'DepartmentTreePage.vue',
    {
      target: '../../departments/pages/DepartmentWorkspacePage.vue',
      shape: /import\s+DepartmentWorkspacePage\s+from\s+['"]\.\.\/departments\/pages\/DepartmentWorkspacePage\.vue['"][\s\S]*<DepartmentWorkspacePage\s*\/>/,
    },
  ],
  [
    'PositionCatalogPage.vue',
    {
      target: '../../positions/pages/PositionWorkspacePage.vue',
      shape: /import\s+PositionWorkspacePage\s+from\s+['"]\.\.\/positions\/pages\/PositionWorkspacePage\.vue['"][\s\S]*<PositionWorkspacePage\s*\/>/,
    },
  ],
  [
    'ContractLedgerPage.vue',
    {
      target: '../../contracts/pages/ContractWorkspacePage.vue',
      shape: /import\s+ContractWorkspacePage\s+from\s+['"]\.\.\/contracts\/pages\/ContractWorkspacePage\.vue['"][\s\S]*<ContractWorkspacePage\s*\/>/,
    },
  ],
  [
    'EmployeeDetailPage.vue',
    {
      target: 'EmployeeDetailPage.vue',
      // Either a pure `export { default } from` redirect or a one-component
      // wrapper is acceptable; both have exactly one implementation source.
      shape: /(?:export\s*\{\s*default\s*\}\s*from\s*['"][^'"]*employees\/pages\/EmployeeDetailPage\.vue['"]|import\s+EmployeeDetailPage\s+from\s+['"]\.\.\/employees\/pages\/EmployeeDetailPage\.vue['"][\s\S]*<EmployeeDetailPage\s*\/>)/,
    },
  ],
])

function listResponse(items: unknown[] = []) {
  return { items, page: 1, page_size: 20, total: items.length }
}

function projectionResponse(items: unknown[] = []) {
  return {
    items,
    page: 1,
    page_size: 20,
    total: items.length,
    as_of: '2026-11-25',
    freshness: 'LIVE',
    source_hash: 'a'.repeat(64),
    partial: false,
    partial_reason: null,
    drilldown: null,
  }
}

async function loadPage(key: string): Promise<{ component: Component; module: PageModule }> {
  const loader = loaders[key]
  if (loader === undefined) throw new Error(`No People loader for ${key}`)
  const page = await loader()
  return { component: page.default, module: page }
}

async function mountPage(key: string, path: string) {
  const page = peoplePages.find((candidate) => candidate.componentKey === key)
  if (page === undefined) throw new Error(`No registry page for ${key}`)

  const pinia = createPinia()
  setActivePinia(pinia)
  const session = useSessionStore()
  session.capabilities = ['people.employee.read', 'people.contract.read', 'org.unit.read', 'org.position.read']
  session.status = 'authenticated'
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      {
        path: page.path,
        name: page.name,
        component: { template: '<div />' },
        meta: { registry: page },
      } satisfies RouteRecordRaw,
      { path: '/login', name: 'login', component: { template: '<div />' } },
      { path: '/forbidden', name: 'forbidden', component: { template: '<div />' } },
    ],
  })
  await router.push(path)
  await router.isReady()

  const { component } = await loadPage(key)
  const wrapper = mount(component, {
    global: { plugins: [pinia, router, ElementPlus] },
  })
  return { wrapper, router }
}

beforeEach(() => {
  resource.get.mockReset()
  resource.post.mockReset()
  resource.patch.mockReset()
})

describe('People page module manifest', () => {
  it('has 35 distinct page files and a loader for every People registry key', async () => {
    // ADR-0035: the four People form routes are now workspace actions.
    expect(peopleKeys).toHaveLength(35)
    expect(new Set(peopleKeys).size).toBe(35)

    const pageFiles = readdirSync(pagesDir)
      .filter((name) => name.endsWith('.vue'))
      .sort()
    expect(pageFiles).toHaveLength(35)
    expect(new Set(pageFiles).size).toBe(35)

    const manifestSource = readFileSync(join(pagesDir, 'index.ts'), 'utf8')
    const mappedFiles = [...manifestSource.matchAll(/import\(['"]\.\/([^'"]+\.vue)['"]\)/g)]
      .map((match) => match[1])
    expect(mappedFiles).toHaveLength(35)
    expect(new Set(mappedFiles).size).toBe(35)
    expect(mappedFiles.sort()).toEqual(pageFiles)

    expect(Object.keys(loaders).sort()).toEqual([...peopleKeys].sort())
    for (const key of peopleKeys) {
      expect(matureView(key), key).toBeTypeOf('function')
      expect(loaders[key], key).toBeTypeOf('function')
      const { component, module } = await loadPage(key)
      expect(component, key).toBeTruthy()
      expect(module.default, key).toBe(component)
    }
    expect(matureView('hr/employees/not-real')).toBeNull()
  })

  it('mounts representative employee, department and contract pages with their own config and API', async () => {
    const cases = [
      {
        key: 'hr/employees/index',
        file: 'EmployeeListPage.vue',
        path: '/hr/employees',
        api: '/v1/employees',
        response: () => listResponse([
          { employment_id: 1, person_name: 'Synthetic Employee', employee_no: 'E1001', status: 'ACTIVE' },
        ]),
        text: 'Synthetic Employee',
      },
      {
        key: 'hr/departments/index',
        file: 'DepartmentTreePage.vue',
        path: '/hr/departments',
        api: '/v1/org-units/tree',
        response: () => listResponse([
          { id: 10, code: 'DEP-010', name: 'Synthetic Department', status: 'ACTIVE' },
        ]),
        text: 'Synthetic Department',
      },
      {
        key: 'hr/contracts/index',
        file: 'ContractLedgerPage.vue',
        path: '/hr/contracts',
        api: '/v1/employments/contracts',
        response: () => projectionResponse([
          {
            id: 31,
            employment_id: 1,
            contract_no: 'CON-2026-0031',
            contract_type: 'FIXED_TERM',
            status: 'ACTIVE',
            days_to_expiry: 36,
          },
        ]),
        text: 'CON-2026-0031',
      },
    ] as const

    const observedApis: string[] = []
    const observedConfigs: string[] = []

    for (const testCase of cases) {
      resource.get.mockReset()
      resource.get.mockImplementation((path: string) => {
        observedApis.push(path)
        return Promise.resolve(path === testCase.api ? testCase.response() : listResponse())
      })

      const { wrapper } = await mountPage(testCase.key, testCase.path)
      await flushPromises()

      expect(resource.get, testCase.key).toHaveBeenCalledWith(testCase.api, expect.any(Object))
      expect(wrapper.text(), testCase.key).toContain(testCase.text)

      if (DEDICATED_EMPLOYEE_PAGES.has(testCase.file)) {
        // Dedicated workspaces own their own tables/drawers; the config-driven
        // shell must never come back for them.
        expect(wrapper.findComponent(PeopleWorkspace).exists(), testCase.key).toBe(false)
        observedConfigs.push(testCase.key)
        continue
      }

      const shell = wrapper.findComponent(PeopleWorkspace)
      expect(shell.exists(), testCase.key).toBe(true)
      expect(shell.props('config'), testCase.key).toMatchObject({ key: testCase.key })
      expect(shell.props(), testCase.key).not.toHaveProperty('componentKey')
      expect(shell.props(), testCase.key).not.toHaveProperty('pageCode')

      observedConfigs.push(shell.props('config').key as string)
    }

    // The dedicated employee workspace also loads its department and position
    // lookups for the create drawer. Those are explicitly expected; any other
    // unexpected API remains a failure.
    const expectedApis: string[] = [
      ...cases.map((testCase) => testCase.api as string),
      '/v1/departments',
      '/v1/departments/10/members',
      '/v1/org-units/tree',
      '/v1/positions',
      '/v1/employees',
    ]
    expect(new Set(observedApis)).toEqual(new Set(expectedApis))
    expect(new Set(observedConfigs)).toEqual(new Set(cases.map((testCase) => testCase.key)))
  })

  it('never passes page identity props to PeopleWorkspace', () => {
    const pageFiles = readdirSync(pagesDir)
      .filter((name) => name.endsWith('.vue'))
      .sort()

    for (const file of pageFiles) {
      const source = readFileSync(join(pagesDir, file), 'utf8')
      expect(source, file).not.toMatch(/\bcomponentKey\b|\bpageCode\b/)
      expect(source, file).not.toMatch(/\bcomponent-key\b|\bpage-code\b/i)
      const dedicated = DEDICATED_EMPLOYEE_PAGES.get(file)
      if (dedicated !== undefined) {
        // Thin re-export/redirect: the dedicated page is the only implementation;
        // the legacy file must not keep a second config-driven shell.
        expect(source, file).not.toContain('PeopleWorkspace')
        expect(source, file).not.toContain('peoplePageConfig')
        expect(source, file).toMatch(dedicated.shape)
        expect(readFileSync(join(employeesDir, dedicated.target), 'utf8'), file).not.toBe('')
        continue
      }
      expect(source, file).toMatch(/<PeopleWorkspace\s+:config="config"\s*\/>/)
    }

    const workspaceSource = readFileSync(workspacePath, 'utf8')
    expect(workspaceSource).toMatch(/defineProps<\{[\s\S]*?config:\s*PeoplePageConfig[\s\S]*?\}>\(\)/)
    expect(workspaceSource).not.toMatch(/\bcomponentKey\b|\bpageCode\b/)
    expect(workspaceSource).not.toMatch(/route\.meta\.registry/)
  })

  it('resolves the People domain only through the module manifest', () => {
    const routerSource = readFileSync(routerSourcePath, 'utf8')

    expect(routerSource).toContain("from '@/modules/people/pages'")
    expect(routerSource).not.toContain("from '@/views/people'")
    expect(routerSource).not.toMatch(/['"]hr\/employees\/index['"]\s*:/)
    expect(routerSource).not.toMatch(/['"]hr\/departments\/index['"]\s*:/)
  })
})
