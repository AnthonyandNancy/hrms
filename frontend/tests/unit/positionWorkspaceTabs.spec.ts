import { readFileSync } from 'node:fs'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import ElementPlus from 'element-plus'
import { useSessionStore } from '@/stores/session'

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

import PositionWorkspacePage from '@/modules/people/positions/pages/PositionWorkspacePage.vue'

const PAGE_SOURCE = readFileSync(
  'src/modules/people/positions/pages/PositionWorkspacePage.vue',
  'utf8',
)
const PANEL_SOURCE = readFileSync(
  'src/modules/people/positions/components/PositionRequirementsPanel.vue',
  'utf8',
)

const CAPABILITIES = [
  'org.position.read',
  'org.position.manage',
  'org.position.grade.manage',
  'org.position.qualification.manage',
  'org.position.staff.read',
  'org.unit.read',
]

const POSITION = {
  id: 4,
  org_unit_id: 10,
  code: 'ENG-001',
  title: '合成工程师',
  grade: 'P5',
  headcount_limit: 2,
  occupancy: 1,
  vacancies: 1,
  status: 'ACTIVE',
  valid_from: '2026-01-01',
  valid_to: '9999-12-31',
}

function list(items: unknown[], overrides: Record<string, unknown> = {}) {
  return {
    items,
    page: 1,
    page_size: 20,
    total: items.length,
    partial: false,
    partial_reason: null,
    ...overrides,
  }
}

function installGet(): void {
  resource.get.mockImplementation((path: string) => {
    if (path === '/v1/positions') return Promise.resolve(list([POSITION]))
    if (path === '/v1/departments') {
      return Promise.resolve(list([{ id: 10, code: 'RD', name: '研发部' }]))
    }
    if (path === '/v1/positions/grades') {
      return Promise.resolve(list([{ id: 1, code: 'P5', name: '专业五级', level: 5, active: true }]))
    }
    if (path === '/v1/positions/4/staff') {
      return Promise.resolve(list([
        {
          assignment_id: 91,
          employment_id: 7,
          employee_no: 'E1007',
          person_name: '合成员工',
          org_unit_id: 10,
          position_id: 4,
          position_code: 'ENG-001',
          position_title: '合成工程师',
          assignment_type: 'PRIMARY',
          is_primary: true,
          valid_from: '2026-01-01',
          valid_to: '9999-12-31',
        },
      ]))
    }
    if (path === '/v1/positions/4/requirements') {
      return Promise.resolve(list([
        {
          id: 2,
          position_id: 4,
          version: 2,
          requirements_json: { skills: 'PHP' },
          effective_from: '2026-11-01',
          recorded_at: '2026-11-01T00:00:00Z',
        },
      ]))
    }
    if (path === '/v1/positions/4') return Promise.resolve(POSITION)
    return Promise.reject(new Error(`Unexpected GET ${path}`))
  })
}

async function mountAt(query: Record<string, string> = {}, capabilities = CAPABILITIES) {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().$patch({ status: 'authenticated', capabilities: [...capabilities] })

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/hr/positions', name: 'positions', component: PositionWorkspacePage }],
  })
  await router.push({ path: '/hr/positions', query })
  await router.isReady()

  const wrapper = mount(PositionWorkspacePage, {
    global: { plugins: [pinia, router, ElementPlus] },
  })
  await flushPromises()
  return { wrapper, router }
}

beforeEach(() => {
  resource.get.mockReset()
  resource.post.mockReset()
  resource.patch.mockReset()
  resource.put.mockReset()
  resource.delete.mockReset()
  installGet()
})

describe('position workspace tabs and detail sections', () => {
  it('declares the catalog/headcount/grades tabs and four detail sections', () => {
    expect(PAGE_SOURCE).toContain("type PositionTab = 'catalog' | 'headcount' | 'grades'")
    expect(PAGE_SOURCE).toContain(
      "type PositionDetailTab = 'overview' | 'staff' | 'requirements' | 'headcount'",
    )
    expect(PAGE_SOURCE).toContain('<PositionRequirementsPanel')
    expect(PAGE_SOURCE).toContain('<PositionStaffPanel')
    expect(PAGE_SOURCE).toContain('<PositionGradesPanel')
    expect(PAGE_SOURCE).toContain('route.query.position')
    expect(PAGE_SOURCE).toContain('router.replace')
  })

  it('restores tab, position and detail from the URL query and opens that exact section', async () => {
    const { wrapper } = await mountAt({ tab: 'catalog', position: '4', detail: 'requirements' })

    expect(wrapper.find('[data-testid="position-detail"]').text()).toContain('合成工程师')
    expect(wrapper.find('[data-testid="position-requirements"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="position-staff-panel"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="position-detail-tab-requirements"]').attributes('aria-selected')).toBe('true')
  })

  it('writes tab/position/detail back to the URL when navigating the workspace', async () => {
    const { wrapper, router } = await mountAt()

    await wrapper.find('[data-testid="position-row-view-4"]').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.query).toMatchObject({ position: '4', detail: 'overview' })

    await wrapper.find('[data-testid="position-detail-tab-staff"]').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.query).toMatchObject({ position: '4', detail: 'staff' })

    await wrapper.find('[data-testid="position-tab-grades"]').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.query.tab).toBe('grades')
    expect(router.currentRoute.value.query.position).toBeUndefined()
    expect(wrapper.find('[data-testid="position-grades-panel"]').exists()).toBe(true)
  })

  it('loads incumbents for the selected position only when the staff section is opened', async () => {
    const { wrapper } = await mountAt()

    expect(
      resource.get.mock.calls.filter(([path]) => path === '/v1/positions/4/staff'),
    ).toHaveLength(0)

    await wrapper.find('[data-testid="position-row-view-4"]').trigger('click')
    await flushPromises()
    expect(
      resource.get.mock.calls.filter(([path]) => path === '/v1/positions/4/staff'),
    ).toHaveLength(0)

    await wrapper.find('[data-testid="position-detail-tab-staff"]').trigger('click')
    await flushPromises()

    const staffCalls = resource.get.mock.calls.filter(
      ([path]) => typeof path === 'string' && path.endsWith('/staff'),
    )
    expect(staffCalls).toHaveLength(1)
    expect(staffCalls[0]?.[0]).toBe('/v1/positions/4/staff')
    expect(wrapper.find('[data-testid="position-staff-table"]').text()).toContain('E1007')
    expect(wrapper.find('[data-testid="position-staff-table"]').text()).toContain('主任职')
  })

  it('never falls back to an employee-list scan to build the staff section', () => {
    const source = readFileSync('src/modules/people/positions/components/PositionStaffPanel.vue', 'utf8')
    expect(source).toContain('listPositionStaff')
    expect(source).not.toContain('/v1/employees')
    expect(source).not.toContain('for (')
    expect(source).toContain('assignment')
  })

  it('reuses the requirement panel on the detail section and documents append-only versions', () => {
    expect(PAGE_SOURCE.match(/<PositionRequirementsPanel/g)).toHaveLength(1)
    expect(PANEL_SOURCE).toContain('历史版本')
    expect(PANEL_SOURCE).toContain('versionPositionRequirements')
    expect(PANEL_SOURCE).toContain('保存为新版本')
  })

  it('hides grade maintenance and staff details when their capabilities are absent', async () => {
    const { wrapper } = await mountAt(
      {},
      ['org.position.read', 'org.position.qualification.manage', 'org.unit.read'],
    )

    await wrapper.find('[data-testid="position-tab-grades"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="position-grades-gap"]').text()).toContain(
      '无法查看或维护职级目录',
    )

    await wrapper.find('[data-testid="position-tab-catalog"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-testid="position-row-view-4"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="position-edit"]').exists()).toBe(false)
  })
})
