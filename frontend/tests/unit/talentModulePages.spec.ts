import { existsSync, readFileSync, readdirSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useSessionStore } from '@/stores/session'
import { createMemoryHistory, createRouter } from 'vue-router'
import ElementPlus from 'element-plus'
import type { Component } from 'vue'
import { PAGE_REGISTRY } from '@/router/registry'
import { matureView } from '@/router/pageComponents'

const resource = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
}))

vi.mock('@/api/resourceClient', () => ({
  resourceGet: resource.get,
  resourcePost: resource.post,
}))

import { PAGE_COMPONENTS } from '@/modules/talent/pages'
import TalentWorkspacePage from '@/modules/talent/components/TalentWorkspacePage.vue'
import PerformanceTemplatesPage from '@/modules/talent/pages/PerformanceTemplatesPage.vue'
import RequisitionListPage from '@/modules/talent/pages/RequisitionListPage.vue'
import RequisitionWorkspacePage from '@/modules/talent/recruiting/pages/RequisitionWorkspacePage.vue'
import TrainingSessionsPage from '@/modules/talent/pages/TrainingSessionsPage.vue'

const pagesDir = resolve(
  dirname(fileURLToPath(import.meta.url)),
  '../../src/modules/talent/pages',
)

const talentKeys = PAGE_REGISTRY
  .filter((page) =>
    ['performance/', 'recruitment/', 'training/'].some((prefix) => page.componentKey.startsWith(prefix)),
  )
  .map((page) => page.componentKey)

function listResponse(items: unknown[] = []) {
  return { items, page: 1, page_size: 20, total: items.length }
}

async function mountPage(component: Component, path: string) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const session = useSessionStore()
  session.capabilities = ['recruitment.requisition.read', 'recruitment.requisition.manage', 'org.unit.read', 'performance.template.read', 'training.session.read']
  session.status = 'authenticated'
  const routePattern = path.replace(/\/\d+(?=\/|$)/g, '/:id')
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: routePattern, name: 'talent-page', component: { template: '<div />' } },
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

describe('Talent module pages', () => {
  beforeEach(() => {
    resource.get.mockReset()
    resource.post.mockReset()
  })

  it('maps every Talent registry key to one distinct .vue page file', async () => {
    // ADR-0035: the requisition create form is a workspace action, not a page.
    expect(talentKeys).toHaveLength(35)
    expect(Object.keys(PAGE_COMPONENTS).sort()).toEqual([...talentKeys].sort())
    for (const key of talentKeys) {
      expect(matureView(key), key).not.toBeNull()
    }

    const pageFiles = readdirSync(pagesDir)
      .filter((name) => name.endsWith('.vue'))
      .sort()
    expect(pageFiles).toHaveLength(35)

    const manifestSource = readFileSync(resolve(pagesDir, 'index.ts'), 'utf8')
    const importedFiles = [...manifestSource.matchAll(/\(\)\s*=>\s*import\('\.\/([^']+\.vue)'\)/g)]
      .map((match) => match[1])
      .filter((name): name is string => name !== undefined)
    expect(importedFiles).toHaveLength(35)
    expect(new Set(importedFiles).size).toBe(35)
    for (const fileName of importedFiles) {
      expect(existsSync(resolve(pagesDir, fileName)), fileName).toBe(true)
    }

    const loaded = await Promise.all(
      Object.values(PAGE_COMPONENTS).map(async (loader) => loader()),
    )
    expect(loaded.every((module) => module.default !== undefined)).toBe(true)
    expect(new Set(loaded.map((module) => module.default)).size).toBe(35)
  })

  it('keeps page identity out of every Talent page component and its shared shell', () => {
    const sources = [
      ...readdirSync(pagesDir)
        .filter((name) => name.endsWith('.vue'))
        .map((fileName) => ({
          fileName,
          source: readFileSync(resolve(pagesDir, fileName), 'utf8'),
        })),
      {
        fileName: 'TalentWorkspacePage.vue',
        source: readFileSync(resolve(pagesDir, '../components/TalentWorkspacePage.vue'), 'utf8'),
      },
    ]
    for (const { fileName, source } of sources) {
      expect(source, fileName).not.toMatch(/\bcomponentKey\b/)
    }
  })

  it('renders the templates page with its own config and endpoint', async () => {
    resource.get.mockResolvedValue(listResponse())
    const wrapper = await mountPage(PerformanceTemplatesPage, '/performance/templates')
    await flushPromises()

    expect(wrapper.text()).toContain('考核模板')
    expect(wrapper.getComponent(TalentWorkspacePage).props('config').key).toBe('performance/templates')
    expect(resource.get).toHaveBeenCalledWith('/v1/performance/templates', expect.any(Object))
  })

  it('renders the requisition list page with the dedicated workspace and endpoint', async () => {
    resource.get.mockResolvedValue(listResponse())
    const wrapper = await mountPage(RequisitionListPage, '/recruitment/requests')
    await flushPromises()

    // ADR-0035: the list route now hosts the requisition workspace, including
    // its create/edit drawers, rather than the config-driven shell.
    expect(wrapper.findComponent(RequisitionWorkspacePage).exists()).toBe(true)
    expect(wrapper.findComponent(TalentWorkspacePage).exists()).toBe(false)
    expect(wrapper.text()).toContain('招聘需求')
    expect(resource.get).toHaveBeenCalledWith('/v1/recruitment/requisitions', expect.any(Object))
  })

  it('renders the training sessions page with its own config and endpoint', async () => {
    resource.get.mockResolvedValue(listResponse())
    const wrapper = await mountPage(TrainingSessionsPage, '/training/sessions')
    await flushPromises()

    expect(wrapper.text()).toContain('培训场次')
    expect(wrapper.getComponent(TalentWorkspacePage).props('config').key).toBe('training/sessions')
    expect(resource.get).toHaveBeenCalledWith('/v1/training/sessions', expect.any(Object))
  })
})
