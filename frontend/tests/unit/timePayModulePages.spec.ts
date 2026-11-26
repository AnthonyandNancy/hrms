import { existsSync, readFileSync, readdirSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import ElementPlus from 'element-plus'
import type { Component } from 'vue'
import { PAGE_REGISTRY } from '@/router/registry'
import { timePayPageLoader } from '@/router/pageComponents'
import { PAGE_COMPONENTS } from '@/modules/timepay/pages'
import AttendanceCalendarPage from '@/modules/timepay/pages/AttendanceCalendarPage.vue'
import LeaveRequestsPage from '@/modules/timepay/pages/LeaveRequestsPage.vue'
import PayrollRunsPage from '@/modules/timepay/pages/PayrollRunsPage.vue'
import TravelPoliciesPage from '@/modules/timepay/pages/TravelPoliciesPage.vue'

const resourceGet = vi.fn()

vi.mock('@/api/resourceClient', () => ({
  resourceGet: (...args: unknown[]) => resourceGet(...args),
  resourcePost: vi.fn(),
}))

const timePayModuleDir = resolve(
  dirname(fileURLToPath(import.meta.url)),
  '../../src/modules/timepay',
)
const pagesDir = resolve(timePayModuleDir, 'pages')
const centralPagePath = resolve(
  dirname(fileURLToPath(import.meta.url)),
  '../../src/views/timepay/TimePayrollPage.vue',
)

function moduleFiles(directory = timePayModuleDir): string[] {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const absolute = resolve(directory, entry.name)
    if (entry.isDirectory()) return moduleFiles(absolute)
    return entry.isFile() && /\.(?:ts|vue)$/.test(entry.name) ? [absolute] : []
  })
}

// The payroll workspace and run detail moved to their own module; timepay
// keeps the remaining attendance/leave/overtime/travel and payroll catalogue keys.
const timePayKeys = PAGE_REGISTRY.filter((page) =>
  ['attendance/', 'leave/', 'overtime/', 'travel/', 'payroll/'].some((prefix) =>
    page.componentKey.startsWith(prefix),
  ) && page.componentKey in PAGE_COMPONENTS,
)
  .map((page) => page.componentKey)
  .sort()

async function mountPage(component: Component, path: string) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path, name: 'timepay-page', component: { template: '<div />' } },
      { path: '/login', name: 'login', component: { template: '<div />' } },
      { path: '/forbidden', name: 'forbidden', component: { template: '<div />' } },
    ],
  })
  await router.push(path)
  await router.isReady()

  return mount(component, {
    global: { plugins: [pinia, router, ElementPlus] },
  })
}

beforeEach(() => {
  resourceGet.mockReset()
})

describe('TimePay module page split', () => {
  it('maps all 42 TimePay registry keys to distinct, loadable .vue pages', async () => {
    expect(timePayKeys).toHaveLength(42)
    expect(Object.keys(PAGE_COMPONENTS).sort()).toEqual(timePayKeys)

    const pageFiles = readdirSync(pagesDir)
      .filter((name) => name.endsWith('.vue'))
      .sort()
    expect(pageFiles).toHaveLength(43)
    // PayrollOverviewPage.vue is retained on disk but no longer referenced.

    const source = readFileSync(resolve(pagesDir, 'index.ts'), 'utf8')
    const importedFiles = [...source.matchAll(/\(\)\s*=>\s*import\('\.\/([^']+\.vue)'\)/g)]
      .map((match) => match[1])
      .filter((name): name is string => name !== undefined)
    expect(importedFiles).toHaveLength(42)
    expect(new Set(importedFiles).size).toBe(42)
    for (const fileName of importedFiles) {
      expect(existsSync(resolve(pagesDir, fileName)), fileName).toBe(true)
    }

    const loaded = await Promise.all(
      Object.entries(PAGE_COMPONENTS).map(async ([key, loader]) => {
        expect(timePayPageLoader(key), key).not.toBeNull()
        return loader()
      }),
    )
    expect(loaded.every((module) => module.default !== undefined)).toBe(true)
    expect(new Set(loaded.map((module) => module.default)).size).toBe(42)
  })

  it('keeps page identity out of every TimePay page component', () => {
    const pageSources = [
      ...readdirSync(pagesDir)
        .filter((name) => name.endsWith('.vue'))
        .map((name) => ({ name, source: readFileSync(resolve(pagesDir, name), 'utf8') })),
      { name: 'TimePayrollPage.vue', source: readFileSync(centralPagePath, 'utf8') },
    ]

    for (const page of pageSources) {
      expect(page.source, page.name).not.toMatch(/\bcomponentKey\b/)
      expect(page.source, page.name).not.toMatch(/\bpageCode\b/)
    }
  })

  it('confines legacy view imports to the explicit TimePay bridge', () => {
    const bridgePath = resolve(timePayModuleDir, 'legacyBridge.ts')
    const importingFiles = moduleFiles().filter((file) => {
      const source = readFileSync(file, 'utf8')
      return /(?:from\s+|import\s*\()['"]@\/views\/timepay(?:\/|['"])/.test(source)
    })

    expect(importingFiles).toEqual([bridgePath])

    // Pages are one of two shapes and nothing else:
    //   - bridge pages use the shared legacyBridge definition, or
    //   - dedicated workspaces compose a module-level page (Volume 3).
    // Neither may reach into @/views directly, and a dedicated page must not
    // silently fall back to the generic bridge.
    // Volume 3 workspaces are resolved by the router from
    // @/modules/time/workspaces, so no timepay page may import another module's
    // page components; the bridge pages stay on the shared definition.
    for (const fileName of readdirSync(pagesDir).filter((name) => name.endsWith('.vue'))) {
      const source = readFileSync(resolve(pagesDir, fileName), 'utf8')
      expect(source, fileName).not.toMatch(/@\/views(?:\/|['"])/)
      expect(source, fileName).not.toMatch(/@\/modules\/time\//)
      expect(source, fileName).toContain("from '../legacyBridge'")
    }
  })

  it('renders the attendance calendar with its own definition and endpoint', async () => {
    resourceGet.mockResolvedValue({ items: [], total: 0 })

    const wrapper = await mountPage(AttendanceCalendarPage, '/attendance/calendar')
    await flushPromises()

    expect(resourceGet).toHaveBeenCalledWith(
      '/time/attendance',
      expect.objectContaining({ month: expect.any(String) }),
    )
    expect(wrapper.get('[data-page="attendance-calendar"]').text()).toContain('考勤日历')
  })

  it('renders leave requests with its own definition and endpoint', async () => {
    resourceGet.mockResolvedValue({ items: [], total: 0 })

    const wrapper = await mountPage(LeaveRequestsPage, '/leave/requests')
    await flushPromises()

    expect(resourceGet).toHaveBeenCalledWith('/time/leave/requests', expect.any(Object))
    expect(wrapper.get('[data-page="leave-requests"]').text()).toContain('请假台账')
  })

  it('renders travel policies with its own definition and endpoint', async () => {
    resourceGet.mockResolvedValue({ items: [], total: 0 })

    const wrapper = await mountPage(TravelPoliciesPage, '/travel/policies')
    await flushPromises()

    expect(resourceGet).toHaveBeenCalledWith(
      '/travel/policies',
      expect.objectContaining({ page: 1, page_size: 20 }),
    )
    expect(wrapper.get('[data-page="travel-policies"]').text()).toContain('差旅政策')
  })

  it('renders payroll runs with its own definition and endpoint', async () => {
    resourceGet.mockResolvedValue({ items: [], total: 0 })

    const wrapper = await mountPage(PayrollRunsPage, '/payroll/batches')
    await flushPromises()

    expect(resourceGet).toHaveBeenCalledWith(
      '/payroll/runs',
      expect.objectContaining({ page: 1, page_size: 20 }),
    )
    expect(wrapper.get('[data-page="payroll-runs"]').text()).toContain('工资核算批次')
  })
})
