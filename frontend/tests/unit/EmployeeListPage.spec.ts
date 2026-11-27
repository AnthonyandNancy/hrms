import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter, type RouteRecordRaw } from 'vue-router'
import ElementPlus from 'element-plus'
import { defineComponent } from 'vue'
import { ApiError } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import EmployeeListPage from '@/modules/people/employees/pages/EmployeeListPage.vue'
import EmployeeCreateDrawer from '@/modules/people/employees/components/EmployeeCreateDrawer.vue'

const api = vi.hoisted(() => ({
  listEmployees: vi.fn(),
  listOrgUnits: vi.fn(),
  listPositions: vi.fn(),
  onboardEmployee: vi.fn(),
  archiveEmployee: vi.fn(),
  restoreEmployee: vi.fn(),
}))

const listActions = vi.hoisted(() => ({
  exportEmployees: vi.fn(),
  saveEmployeeExport: vi.fn(),
}))

vi.mock('@/modules/people/employees/api', () => api)
vi.mock('@/modules/people/employees/listActionsApi', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/modules/people/employees/listActionsApi')>()
  return {
    ...actual,
    exportEmployees: listActions.exportEmployees,
    saveEmployeeExport: listActions.saveEmployeeExport,
  }
})

const FormDrawerStub = defineComponent({
  name: 'FormDrawer',
  props: { modelValue: Boolean, title: String, confirmLoading: Boolean },
  emits: ['update:modelValue', 'confirm'],
  template: `<div v-if="modelValue" role="dialog" data-testid="form-drawer">
    <h2>{{ title }}</h2>
    <slot />
    <button type="button" data-testid="employee-drawer-save" :disabled="confirmLoading" @click="$emit('confirm')">保存</button>
  </div>`,
})

type EmployeeRecord = {
  employment_id: number
  employee_no: string
  person_name: string
  status: string
  employment_type?: string
  hire_date?: string
  archived_at?: string | null
  department_name?: string
  position_name?: string
}

function employee(overrides: Partial<EmployeeRecord> = {}): EmployeeRecord {
  return {
    employment_id: 7,
    employee_no: 'E1007',
    person_name: '合成员工',
    status: 'ACTIVE',
    employment_type: 'FULL_TIME',
    hire_date: '2026-01-01',
    archived_at: null,
    department_name: '研发部',
    position_name: '工程师',
    ...overrides,
  }
}

function list(items: EmployeeRecord[], overrides: Record<string, unknown> = {}) {
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

function useCapabilities(capabilities: string[]): void {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().$patch({ status: 'authenticated', capabilities })
}

async function mountPage(capabilities = ['people.employee.read']) {
  useCapabilities(capabilities)
  const routes: RouteRecordRaw[] = [
    { path: '/hr/employees', name: 'hr-employees', component: { template: '<div />' } },
    { path: '/hr/employees/:id', name: 'employee-detail', component: { template: '<div />' } },
    { path: '/forbidden', name: 'forbidden', component: { template: '<div />' } },
  ]
  const router = createRouter({ history: createMemoryHistory(), routes })
  await router.push('/hr/employees')
  await router.isReady()
  const wrapper = mount(EmployeeListPage, {
    global: {
      plugins: [ElementPlus, router],
      stubs: { FormDrawer: FormDrawerStub },
    },
  })
  return { wrapper, router }
}

async function waitForDebounce(): Promise<void> {
  await new Promise((resolve) => setTimeout(resolve, 350))
  await flushPromises()
}

async function fillDrawer(wrapper: ReturnType<typeof mount>): Promise<void> {
  await wrapper.get('[aria-label="姓"]').setValue('合成')
  await wrapper.get('[aria-label="名"]').setValue('员工')
  await wrapper.get('[aria-label="员工编号"]').setValue('E0007')
  await wrapper.get('[aria-label="入职日期"]').setValue('2026-12-01')
  await wrapper.get('[aria-label="部门编号"]').setValue('10')
  await wrapper.get('[aria-label="基本工资（分）"]').setValue('100000')
}

function mountDrawer() {
  return mount(EmployeeCreateDrawer, {
    props: { modelValue: true, orgUnits: [{ id: 10, name: '研发部', code: 'DEV' }], positions: [] },
    global: { stubs: { FormDrawer: FormDrawerStub } },
  })
}

beforeEach(() => {
  for (const fn of Object.values(api)) fn.mockReset()
  for (const fn of Object.values(listActions)) fn.mockReset()
  api.listOrgUnits.mockResolvedValue([])
  api.listPositions.mockResolvedValue([])
  api.listEmployees.mockResolvedValue(list([employee()]))
  api.onboardEmployee.mockResolvedValue({
    person_id: 3,
    employment_id: 7,
    employee_no: 'E0007',
    assignment_id: 4,
    terms_id: 5,
    replayed: false,
  })
})

describe('EmployeeListPage', () => {
  it('shows a loading skeleton, Chinese labels, archived rows and a working detail navigation', async () => {
    api.listEmployees.mockResolvedValue(
      list([
        employee(),
        employee({
          employment_id: 8,
          employee_no: 'E1008',
          status: 'TERMINATED',
          employment_type: 'CONTRACT',
          archived_at: '2026-02-01T00:00:00Z',
        }),
      ]),
    )

    const { wrapper, router } = await mountPage()

    expect(wrapper.find('[data-testid="employee-table-loading"]').exists()).toBe(true)
    await flushPromises()

    expect(wrapper.text()).toContain('在职')
    expect(wrapper.text()).toContain('已离职')
    expect(wrapper.text()).toContain('全职')
    expect(wrapper.text()).toContain('已归档')
    expect(wrapper.text()).not.toContain('ACTIVE')
    expect(wrapper.text()).not.toContain('FULL_TIME')
    expect(wrapper.find('a[href="/hr/employees/7"]').exists()).toBe(true)
    expect(api.listEmployees).toHaveBeenCalledWith(
      expect.objectContaining({
        as_of: expect.any(String),
        page: 1,
        page_size: 20,
        include_archived: false,
      }),
    )

    await wrapper.get('[data-testid="employee-row-8"]').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.path).toBe('/hr/employees/8')
  })

  it('debounces filter changes, coalesces keystrokes and resets the request to page 1', async () => {
    api.listEmployees.mockResolvedValue(list([employee()], { total: 40 }))
    const { wrapper } = await mountPage()
    await flushPromises()
    expect(api.listEmployees).toHaveBeenCalledTimes(1)

    const nextPage = wrapper.get('[aria-label="下一页"]')
    await nextPage.trigger('click')
    await flushPromises()
    expect(api.listEmployees).toHaveBeenCalledTimes(2)
    expect(api.listEmployees.mock.calls[1]?.[0]).toMatchObject({ page: 2 })

    const search = wrapper.get('[aria-label="搜索员工"]')
    await search.setValue('Syn')
    await search.setValue('Synthetic')
    expect(api.listEmployees).toHaveBeenCalledTimes(2)
    await waitForDebounce()

    expect(api.listEmployees).toHaveBeenCalledTimes(3)
    expect(api.listEmployees.mock.calls[2]?.[0]).toMatchObject({ page: 1, q: 'Synthetic' })

    await wrapper.get('[aria-label="包含已归档"]').setValue(true)
    await waitForDebounce()
    expect(api.listEmployees.mock.calls[3]?.[0]).toMatchObject({
      page: 1,
      q: 'Synthetic',
      include_archived: true,
    })
  })

  it('shows the empty state and its create call to action', async () => {
    api.listEmployees.mockResolvedValue(list([]))
    const { wrapper } = await mountPage(['people.employee.read', 'people.employee.manage'])
    await flushPromises()

    expect(wrapper.text()).toContain('暂无员工')
    const empty = wrapper.get('.empty-state')
    const cta = empty.findAll('button').find((button) => button.text() === '新建员工')
    expect(cta).toBeDefined()
    await cta?.trigger('click')
    expect(wrapper.find('[data-testid="form-drawer"]').exists()).toBe(true)
  })

  it('shows a retryable Chinese error state with the request id and recovers', async () => {
    api.listEmployees
      .mockRejectedValueOnce(new ApiError('INTERNAL', 'Synthetic outage', { status: 500, requestId: 'req-employee-500' }))
      .mockResolvedValueOnce(list([employee()]))

    const { wrapper } = await mountPage()
    await flushPromises()

    expect(wrapper.text()).toContain('req-employee-500')
    expect(wrapper.text()).toContain('暂时无法加载')
    expect(wrapper.text()).not.toContain('Synthetic outage')
    expect(wrapper.text()).not.toContain('E1007')

    const retry = wrapper.get('.error-state').findAll('button').find((button) => button.text() === '重试')
    expect(retry).toBeDefined()
    await retry?.trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('E1007')
  })

  it('renders a forbidden state without leaking rows or raw codes', async () => {
    api.listEmployees.mockRejectedValue(
      new ApiError('FORBIDDEN', 'Forbidden', { status: 403, requestId: 'req-employee-403' }),
    )
    const { wrapper } = await mountPage()
    await flushPromises()

    const forbidden = wrapper.get('[data-testid="employee-forbidden"]')
    expect(forbidden.text()).toContain('权限')
    expect(forbidden.text()).toContain('req-employee-403')
    expect(wrapper.text()).not.toContain('E1007')
    expect(wrapper.text()).not.toContain('FORBIDDEN')
    expect(wrapper.find('[data-testid="employee-table"]').exists()).toBe(false)
  })

  it('shows a partial-data banner when the API reports partial results', async () => {
    api.listEmployees.mockResolvedValue(
      list([employee()], { partial: true, partial_reason: '部分组织不在可见范围内' }),
    )
    const { wrapper } = await mountPage()
    await flushPromises()

    const banner = wrapper.get('[data-testid="employee-partial"]')
    expect(banner.text()).toContain('部分数据')
    expect(banner.text()).toContain('部分组织不在可见范围内')
    expect(wrapper.text()).toContain('E1007')
  })

  it('shows archive and restore only for their own capabilities', async () => {
    api.listEmployees.mockResolvedValue(
      list([
        employee(),
        employee({ employment_id: 8, employee_no: 'E1008', archived_at: '2026-02-01T00:00:00Z' }),
      ]),
    )

    const reader = await mountPage(['people.employee.read'])
    await flushPromises()
    expect(reader.wrapper.find('[data-testid="employee-archive"]').exists()).toBe(false)
    expect(reader.wrapper.find('[data-testid="employee-restore"]').exists()).toBe(false)

    const archiver = await mountPage(['people.employee.read', 'people.employee.archive'])
    await flushPromises()
    expect(archiver.wrapper.findAll('[data-testid="employee-archive"]')).toHaveLength(1)
    expect(archiver.wrapper.find('[data-testid="employee-restore"]').exists()).toBe(false)

    const restorer = await mountPage(['people.employee.read', 'people.employee.restore'])
    await flushPromises()
    expect(restorer.wrapper.find('[data-testid="employee-archive"]').exists()).toBe(false)
    expect(restorer.wrapper.findAll('[data-testid="employee-restore"]')).toHaveLength(1)
  })

  it('hides the create entry point when people.employee.manage is absent', async () => {
    const { wrapper } = await mountPage(['people.employee.read'])
    await flushPromises()

    expect(wrapper.find('[data-testid="employee-create"]').exists()).toBe(false)
    expect(wrapper.findAll('button').some((button) => button.text() === '新建员工')).toBe(false)
  })

  it('creates an employee, closes the drawer, refetches and shows the new employee number', async () => {
    api.listOrgUnits.mockResolvedValue([{ id: 10, name: '研发部', code: 'DEV' }])
    api.listEmployees
      .mockResolvedValueOnce(list([employee()]))
      .mockResolvedValueOnce(list([employee({ employment_id: 9, employee_no: 'E0007', person_name: '合成新人' }), employee()]))
    const { wrapper } = await mountPage(['people.employee.read', 'people.employee.manage'])
    await flushPromises()

    await wrapper.get('[data-testid="employee-create"]').trigger('click')
    expect(wrapper.find('[data-testid="form-drawer"]').exists()).toBe(true)
    await fillDrawer(wrapper)

    await wrapper.get('[data-testid="employee-drawer-save"]').trigger('click')
    await flushPromises()

    expect(api.onboardEmployee).toHaveBeenCalledWith(
      expect.objectContaining({
        person: expect.objectContaining({ family_name: '合成', given_name: '员工' }),
        employment: expect.objectContaining({ employee_no: 'E0007', hire_date: '2026-12-01' }),
        assignment: expect.objectContaining({ org_unit_id: 10 }),
        terms: expect.objectContaining({ salary_minor: 100000, currency: 'CNY' }),
      }),
      expect.any(String),
    )
    expect(wrapper.find('[data-testid="form-drawer"]').exists()).toBe(false)
    expect(api.listEmployees).toHaveBeenCalledTimes(2)
    expect(api.listEmployees.mock.calls[1]?.[0]).toMatchObject({ page: 1 })
    expect(wrapper.text()).toContain('员工 E0007 已创建')
    expect(wrapper.text()).toContain('E0007')
  })
})

describe('EmployeeCreateDrawer', () => {
  it('maps 422 field errors onto inputs and never renders the raw code', async () => {
    api.onboardEmployee.mockRejectedValue(
      new ApiError('VALIDATION_FAILED', 'The given data was invalid.', {
        status: 422,
        fieldErrors: {
          'employment.employee_no': ['员工编号已存在。'],
          'person.family_name': ['姓不能为空。'],
        },
      }),
    )
    const wrapper = mountDrawer()
    await fillDrawer(wrapper)

    await wrapper.get('[data-testid="employee-drawer-save"]').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('员工编号已存在。')
    expect(wrapper.text()).toContain('姓不能为空。')
    expect(wrapper.text()).not.toContain('VALIDATION_FAILED')
    expect(wrapper.get('[aria-label="员工编号"]').attributes('aria-invalid')).toBe('true')
    expect(wrapper.get('[aria-label="姓"]').attributes('aria-invalid')).toBe('true')
  })

  it('keeps one idempotency key for the same failed submission and disables submit while in flight', async () => {
    let rejectFirst: (reason: unknown) => void = () => undefined
    const first = new Promise((_resolve, reject) => {
      rejectFirst = reject
    })
    api.onboardEmployee
      .mockReturnValueOnce(first)
      .mockResolvedValueOnce({
        person_id: 3,
        employment_id: 7,
        employee_no: 'E0007',
        assignment_id: 4,
        terms_id: 5,
        replayed: false,
      })
    const wrapper = mountDrawer()
    await fillDrawer(wrapper)

    await wrapper.get('[data-testid="employee-drawer-save"]').trigger('click')
    await flushPromises()
    expect(wrapper.get('[data-testid="employee-drawer-save"]').attributes('disabled')).toBeDefined()

    rejectFirst(new ApiError('INTERNAL', 'Synthetic outage', { status: 500 }))
    await flushPromises()
    expect(wrapper.get('[data-testid="employee-drawer-save"]').attributes('disabled')).toBeUndefined()

    await wrapper.get('[data-testid="employee-drawer-save"]').trigger('click')
    await flushPromises()

    expect(api.onboardEmployee).toHaveBeenCalledTimes(2)
    const firstKey = api.onboardEmployee.mock.calls[0]?.[1]
    const secondKey = api.onboardEmployee.mock.calls[1]?.[1]
    expect(firstKey).toEqual(expect.any(String))
    expect(secondKey).toBe(firstKey)
  })

  it('shows a duplicate employee number in Chinese for a 409 conflict', async () => {
    api.onboardEmployee.mockRejectedValue(
      new ApiError('CONFLICT', 'Employee number must be unique.', {
        status: 409,
        details: { reason: 'EMPLOYEE_NO_DUPLICATE' },
      }),
    )
    const wrapper = mountDrawer()
    await fillDrawer(wrapper)
    await wrapper.get('[aria-label="员工编号"]').setValue('E-DUP')

    await wrapper.get('[data-testid="employee-drawer-save"]').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('E-DUP')
    expect(wrapper.text()).toContain('已存在')
    expect(wrapper.text()).not.toContain('CONFLICT')
    expect(wrapper.text()).not.toContain('EMPLOYEE_NO_DUPLICATE')
  })
})
