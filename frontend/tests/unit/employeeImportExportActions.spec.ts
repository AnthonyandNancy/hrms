import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import ElementPlus from 'element-plus'
import { ApiError } from '@/api/errors'
import { useSessionStore } from '@/stores/session'

const api = vi.hoisted(() => ({
  listEmployees: vi.fn(),
  listOrgUnits: vi.fn(),
  listPositions: vi.fn(),
  onboardEmployee: vi.fn(),
  archiveEmployee: vi.fn(),
  restoreEmployee: vi.fn(),
}))

const actionsApi = vi.hoisted(() => ({
  exportEmployees: vi.fn(),
  saveEmployeeExport: vi.fn(),
  listEmployeeImportBatches: vi.fn(),
}))

vi.mock('@/modules/people/employees/api', () => api)
vi.mock('@/modules/people/employees/listActionsApi', () => actionsApi)

import EmployeeListPage from '@/modules/people/employees/pages/EmployeeListPage.vue'
import EmployeeImportDrawer from '@/modules/people/employees/components/EmployeeImportDrawer.vue'

const ROW = {
  employment_id: 7,
  person_id: 3,
  employee_no: 'E1007',
  person_name: '合成员工',
  status: 'ACTIVE',
  employment_type: 'FULL_TIME',
  hire_date: '2026-01-01',
  valid_from: '2026-01-01',
  archived_at: null,
  org_unit_id: 10,
  position_id: null,
  department_name: '合成研发部',
  position_name: null,
}

async function mountPage(capabilities: string[]) {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().$patch({ status: 'authenticated', capabilities })
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/hr/employees', name: 'hr-employees', component: { template: '<div />' } },
      { path: '/hr/employees/:id', name: 'employee-detail', component: { template: '<div />' } },
      { path: '/forbidden', component: { template: '<div />' } },
    ],
  })
  await router.push('/hr/employees')
  await router.isReady()
  const wrapper = mount(EmployeeListPage, {
    global: { plugins: [pinia, router, ElementPlus] },
  })
  await flushPromises()
  return { wrapper, router }
}

beforeEach(() => {
  Object.values(api).forEach((fn) => fn.mockReset())
  Object.values(actionsApi).forEach((fn) => fn.mockReset())
  api.listEmployees.mockResolvedValue({
    items: [ROW],
    page: 1,
    page_size: 20,
    total: 1,
    partial: false,
    partial_reason: null,
  })
  api.listOrgUnits.mockResolvedValue([])
  api.listPositions.mockResolvedValue([])
  actionsApi.listEmployeeImportBatches.mockResolvedValue({
    items: [
      {
        id: 5,
        status: 'VALIDATED',
        originalName: 'synthetic-import.xlsx',
        totalRows: 3,
        validRows: 2,
        failedRows: 1,
        errors: [],
        validatedAt: '2026-11-23T01:00:00Z',
        committedAt: null,
        createdAt: '2026-11-23T00:00:00Z',
      },
    ],
    page: 1,
    page_size: 20,
    total: 1,
  })
  actionsApi.exportEmployees.mockResolvedValue({
    filename: 'employees-2026-11-23.csv',
    content: 'employee_no,person_name,status\nE1007,合成员工,ACTIVE\n',
  })
})

describe('employee list import/export actions', () => {
  it('shows import results only with manage and export only with export capability', async () => {
    const reader = await mountPage(['people.employee.read'])
    expect(reader.wrapper.find('[data-testid="employee-imports"]').exists()).toBe(false)
    expect(reader.wrapper.find('[data-testid="employee-export"]').exists()).toBe(false)

    const manager = await mountPage(['people.employee.read', 'people.employee.manage'])
    expect(manager.wrapper.find('[data-testid="employee-imports"]').exists()).toBe(true)
    expect(manager.wrapper.find('[data-testid="employee-export"]').exists()).toBe(false)

    const exporter = await mountPage(['people.employee.read', 'people.employee.export'])
    expect(exporter.wrapper.find('[data-testid="employee-export"]').exists()).toBe(true)
    expect(exporter.wrapper.find('[data-testid="employee-imports"]').exists()).toBe(false)
  })

  it('opens a drawer that renders only persisted import batches', async () => {
    const { wrapper } = await mountPage(['people.employee.read', 'people.employee.manage'])

    await wrapper.get('[data-testid="employee-imports"]').trigger('click')
    await flushPromises()

    expect(actionsApi.listEmployeeImportBatches).toHaveBeenCalledWith(1, 50)
    expect(wrapper.get('[data-testid="import-batch-5"]').text()).toContain('synthetic-import.xlsx')
    expect(wrapper.get('[data-testid="import-batch-5"]').text()).toContain('校验完成')
    expect(wrapper.get('[data-testid="import-batch-5"]').text()).toContain('1')
  })

  it('exports with the current list filters and triggers a real download', async () => {
    const { wrapper } = await mountPage(['people.employee.read', 'people.employee.export'])

    await wrapper.get('[data-testid="employee-export"]').trigger('click')
    await flushPromises()

    expect(actionsApi.exportEmployees).toHaveBeenCalledWith(
      expect.objectContaining({
        as_of: expect.stringMatching(/^\d{4}-\d{2}-\d{2}$/),
        include_archived: false,
      }),
    )
    expect(actionsApi.saveEmployeeExport).toHaveBeenCalledWith(
      expect.objectContaining({ filename: 'employees-2026-11-23.csv' }),
    )
  })

  it('does not download a file when the server rejects the export', async () => {
    actionsApi.exportEmployees.mockRejectedValue(
      new ApiError('FORBIDDEN', 'forbidden', { status: 403, requestId: 'req-export-403' }),
    )
    const { wrapper } = await mountPage(['people.employee.read', 'people.employee.export'])

    await wrapper.get('[data-testid="employee-export"]').trigger('click')
    await flushPromises()

    expect(actionsApi.saveEmployeeExport).not.toHaveBeenCalled()
  })
})

describe('EmployeeImportDrawer', () => {
  it('shows a forbidden state instead of invented rows', async () => {
    actionsApi.listEmployeeImportBatches.mockRejectedValue(
      new ApiError('FORBIDDEN', 'forbidden', { status: 403 }),
    )

    const wrapper = mount(EmployeeImportDrawer, {
      props: { modelValue: true },
      global: { plugins: [ElementPlus] },
    })
    await flushPromises()

    expect(wrapper.get('[data-testid="import-batches-forbidden"]').text()).toContain('无权查看')
    expect(wrapper.find('[data-testid="import-batch-5"]').exists()).toBe(false)
  })
})
