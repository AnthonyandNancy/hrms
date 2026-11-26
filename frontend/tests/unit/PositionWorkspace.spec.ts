import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import ElementPlus from 'element-plus'
import { defineComponent } from 'vue'
import { ApiError } from '@/api/errors'
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

const FULL_CAPABILITIES = [
  'org.position.read',
  'org.position.manage',
  'org.position.grade.manage',
  'org.position.qualification.manage',
  'org.unit.read',
]

type PositionWire = {
  id: number
  org_unit_id: number | null
  code: string
  title: string
  grade: string | null
  headcount_limit: number | null
  occupancy: number | null
  vacancies: number | null
  status: string
  valid_from: string
  valid_to: string
}

const POSITIONS: PositionWire[] = [
  {
    id: 1,
    org_unit_id: 10,
    code: 'ENG-001',
    title: '合成工程师',
    grade: 'P5',
    headcount_limit: 2,
    occupancy: 3,
    vacancies: 0,
    status: 'ACTIVE',
    valid_from: '2026-01-01',
    valid_to: '9999-12-31',
  },
  {
    id: 2,
    org_unit_id: 11,
    code: 'PRD-002',
    title: '合成产品经理',
    grade: 'P4',
    headcount_limit: 3,
    occupancy: 1,
    vacancies: 2,
    status: 'DISABLED',
    valid_from: '2026-01-01',
    valid_to: '9999-12-31',
  },
]

const DEPARTMENTS = [
  { id: 10, code: 'RD', name: '研发部', type: 'DEPARTMENT', status: 'ACTIVE' },
  { id: 11, code: 'PD', name: '产品部', type: 'DEPARTMENT', status: 'ACTIVE' },
]

const GRADES = [
  { id: 1, code: 'P4', name: '专业四级', level: 4, active: true },
  { id: 2, code: 'P5', name: '专业五级', level: 5, active: true },
]

const DrawerStub = defineComponent({
  inheritAttrs: false,
  props: { modelValue: Boolean, title: String },
  emits: ['update:modelValue', 'close'],
  template: `<div v-if="modelValue" v-bind="$attrs" role="dialog">
    <h2>{{ title }}</h2>
    <slot />
    <slot name="footer" />
  </div>`,
})

type ListOverrides = Record<string, unknown>

function list(items: unknown[], overrides: ListOverrides = {}) {
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

function requirementVersion(version: number, requirements: Record<string, unknown>) {
  return {
    id: version,
    position_id: 1,
    version,
    requirements_json: requirements,
    effective_from: '2026-11-01',
    recorded_at: '2026-11-01T00:00:00Z',
  }
}

let positionRows: PositionWire[] = [...POSITIONS]
let requirementRows = [requirementVersion(2, { skills: 'PHP', qualifications: 'PMP' })]
let positionPageOverrides: Record<string, unknown> = {}

function installWorkspaceGet(): void {
  resource.get.mockImplementation((path: string) => {
    if (path === '/v1/positions') return Promise.resolve(list(positionRows, positionPageOverrides))
    if (path === '/v1/departments') return Promise.resolve(list(DEPARTMENTS))
    if (path === '/v1/positions/grades') return Promise.resolve(list(GRADES))
    if (/^\/v1\/positions\/\d+\/requirements$/.test(path)) {
      return Promise.resolve(list(requirementRows))
    }
    return Promise.reject(new Error(`Unexpected GET ${path}`))
  })
}

function useCapabilities(capabilities: string[]): void {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().$patch({ status: 'authenticated', capabilities: [...capabilities] })
}

async function mountWorkspace(capabilities: string[] = FULL_CAPABILITIES) {
  useCapabilities(capabilities)
  const wrapper = mount(PositionWorkspacePage, {
    global: {
      plugins: [ElementPlus],
      stubs: { ElDrawer: DrawerStub },
    },
  })
  return wrapper
}

async function openCreate(wrapper: Awaited<ReturnType<typeof mountWorkspace>>): Promise<void> {
  await wrapper.get('[data-testid="position-create"]').trigger('click')
  await flushPromises()
}

async function selectPosition(
  wrapper: Awaited<ReturnType<typeof mountWorkspace>>,
  id: number,
): Promise<void> {
  await wrapper.get(`[data-testid="position-row-view-${id}"]`).trigger('click')
  await flushPromises()
}

async function fillCreateFields(
  wrapper: Awaited<ReturnType<typeof mountWorkspace>>,
  overrides: Partial<{
    code: string
    title: string
    orgUnitId: string
    grade: string
    headcount: string
    validFrom: string
    validTo: string
  }> = {},
): Promise<void> {
  const values = {
    code: 'NEW-001',
    title: '合成新岗位',
    orgUnitId: '10',
    grade: 'P5',
    headcount: '4',
    validFrom: '2026-12-01',
    validTo: '2027-12-01',
    ...overrides,
  }
  await wrapper.get('[data-testid="position-code"]').setValue(values.code)
  await wrapper.get('[data-testid="position-title"]').setValue(values.title)
  await wrapper.get('[data-testid="position-org-unit"]').setValue(values.orgUnitId)
  await wrapper.get('[data-testid="position-grade"]').setValue(values.grade)
  await wrapper.get('[data-testid="position-headcount-limit"]').setValue(values.headcount)
  await wrapper.get('[data-testid="position-valid-from"]').setValue(values.validFrom)
  await wrapper.get('[data-testid="position-valid-to"]').setValue(values.validTo)
}

beforeEach(() => {
  positionRows = [...POSITIONS]
  requirementRows = [requirementVersion(2, { skills: 'PHP', qualifications: 'PMP' })]
  positionPageOverrides = {}
  resource.get.mockReset()
  resource.post.mockReset()
  resource.patch.mockReset()
  resource.put.mockReset()
  resource.delete.mockReset()
  resource.post.mockResolvedValue({})
  resource.patch.mockResolvedValue({})
  installWorkspaceGet()
})

describe('PositionWorkspacePage', () => {
  it('renders Chinese status and grade labels, org unit and approved/filled/vacancy headcount with a variance warning', async () => {
    const wrapper = await mountWorkspace()
    await flushPromises()

    expect(wrapper.get('[data-testid="position-table"]').text()).toContain('合成工程师')
    expect(wrapper.get('[data-testid="position-org-1"]').text()).toBe('研发部')
    expect(wrapper.get('[data-testid="position-grade-1"]').text()).toContain('专业五级')
    expect(wrapper.get('[data-testid="position-status-1"]').text()).toBe('启用')
    expect(wrapper.get('[data-testid="position-status-2"]').text()).toBe('停用')
    expect(wrapper.get('[data-testid="position-headcount-1"]').text()).toContain('2')
    expect(wrapper.get('[data-testid="position-headcount-1"]').text()).toContain('3')
    expect(wrapper.get('[data-testid="position-headcount-1"]').text()).toContain('0')
    expect(wrapper.get('[data-testid="position-variance-1"]').text()).toContain('超过')
    expect(wrapper.find('[data-testid="position-variance-2"]').exists()).toBe(false)

    const positionCall = resource.get.mock.calls.find(([path]) => path === '/v1/positions')
    expect(positionCall?.[1]).toEqual(expect.objectContaining({ as_of: expect.any(String) }))
    expect(positionCall?.[1]).not.toHaveProperty('page')
    expect(positionCall?.[1]).not.toHaveProperty('page_size')
    expect(positionCall?.[1]).not.toHaveProperty('sort')

    positionPageOverrides = { partial: true, partial_reason: '服务端只返回了部分岗位。' }
    await wrapper.get('[data-testid="position-refresh"]').trigger('click')
    await flushPromises()
    expect(wrapper.get('[data-testid="position-partial"]').text()).toContain('服务端只返回了部分岗位')
  })

  it('applies q and as-of on the server and status and org-unit as explicit client filters', async () => {
    const wrapper = await mountWorkspace()
    await flushPromises()

    await wrapper.findComponent('[data-testid="position-filter-status"]').setValue('DISABLED')
    await wrapper.vm.$nextTick()
    expect(wrapper.find('[data-testid="position-row-1"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="position-row-2"]').exists()).toBe(true)

    await wrapper.findComponent('[data-testid="position-filter-status"]').setValue('ALL')
    await wrapper.findComponent('[data-testid="position-filter-org-unit"]').setValue('10')
    await wrapper.vm.$nextTick()
    expect(wrapper.find('[data-testid="position-row-1"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="position-row-2"]').exists()).toBe(false)

    await wrapper.get('[data-testid="position-filter-q"]').setValue('工程师')
    await wrapper.get('[data-testid="position-filter-as-of"]').setValue('2026-12-01')
    await wrapper.get('[data-testid="position-filter-submit"]').trigger('click')
    await flushPromises()

    const calls = resource.get.mock.calls.filter(([path]) => path === '/v1/positions')
    expect(calls.at(-1)?.[1]).toEqual(
      expect.objectContaining({ as_of: '2026-12-01', q: '工程师' }),
    )
    expect(calls.at(-1)?.[1]).not.toHaveProperty('sort')
  })

  it('paginates only the server-returned rows and resets to page one when filters change', async () => {
    positionRows = Array.from({ length: 12 }, (_, index) => ({
      ...POSITIONS[0]!,
      id: index + 1,
      code: `P-${String(index + 1).padStart(3, '0')}`,
      title: `合成岗位 ${index + 1}`,
    }))
    const wrapper = await mountWorkspace()
    await flushPromises()

    expect(wrapper.find('[data-testid="position-row-10"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="position-row-11"]').exists()).toBe(false)

    await wrapper.get('[data-testid="position-page-next"]').trigger('click')
    expect(wrapper.find('[data-testid="position-row-11"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="position-row-1"]').exists()).toBe(false)

    const statusFilter = wrapper.getComponent('[data-testid="position-filter-status"]') as VueWrapper
    await statusFilter.setValue('DISABLED')
    statusFilter.vm.$emit('change', 'DISABLED')
    await wrapper.vm.$nextTick()
    await statusFilter.setValue('ACTIVE')
    statusFilter.vm.$emit('change', 'ACTIVE')
    await wrapper.vm.$nextTick()
    expect(wrapper.find('[data-testid="position-row-1"]').exists()).toBe(true)
  })

  it('validates the half-open effective range locally and maps create 422 field errors inline', async () => {
    const wrapper = await mountWorkspace()
    await flushPromises()
    await openCreate(wrapper)

    await fillCreateFields(wrapper, {
      code: 'NEW-422',
      title: '校验岗位',
      validFrom: '2026-12-01',
      validTo: '2026-12-01',
    })
    await wrapper.get('[data-testid="position-save"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid="position-date-range-error"]').text()).toContain('晚于')
    expect(resource.post).not.toHaveBeenCalled()

    await wrapper.get('[data-testid="position-valid-to"]').setValue('2027-01-01')
    resource.post.mockRejectedValueOnce(
      new ApiError('VALIDATION_FAILED', '提交内容未通过校验', {
        status: 422,
        fieldErrors: {
          title: ['岗位名称不符合要求。'],
          headcount_limit: ['编制上限不能为负数。'],
        },
      }),
    )
    await wrapper.get('[data-testid="position-save"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/positions',
      {
        org_unit_id: 10,
        code: 'NEW-422',
        title: '校验岗位',
        grade: 'P5',
        headcount_limit: 4,
        valid_from: '2026-12-01',
        valid_to: '2027-01-01',
      },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.get('[data-testid="field-error-title"]').text()).toContain('岗位名称不符合要求')
    expect(wrapper.get('[data-testid="field-error-headcount-limit"]').text()).toContain('编制上限不能为负数')
    expect(wrapper.get('[data-testid="position-form-error"]').text()).toContain('未通过校验')
    expect(wrapper.find('[data-testid="position-form-drawer"]').exists()).toBe(true)
  })

  it('surfaces duplicate code in Chinese, both before transport and from the server conflict reason', async () => {
    const wrapper = await mountWorkspace()
    await flushPromises()
    await openCreate(wrapper)

    await fillCreateFields(wrapper, { code: 'ENG-001' })
    await wrapper.get('[data-testid="position-save"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid="field-error-code"]').text()).toContain('岗位编码「ENG-001」已存在')
    expect(resource.post).not.toHaveBeenCalled()

    await wrapper.get('[data-testid="position-code"]').setValue('ENG-NEW')
    resource.post.mockRejectedValueOnce(
      new ApiError('CONFLICT', 'Conflict', { status: 409 }),
    )
    await wrapper.get('[data-testid="position-save"]').trigger('click')
    await flushPromises()

    const form = wrapper.get('[data-testid="position-form-drawer"]')
    expect(form.text()).toContain('岗位编码「ENG-NEW」已存在，请使用其他编码。')
    expect(form.text()).not.toContain('Conflict')
    expect(wrapper.find('[data-testid="position-form-drawer"]').exists()).toBe(true)
  })

  it('patches only server-supported edit fields and explains the unsupported catalogue changes', async () => {
    const wrapper = await mountWorkspace()
    await flushPromises()
    await selectPosition(wrapper, 1)

    await wrapper.get('[data-testid="position-edit"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid="position-code"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid="position-org-unit"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid="position-valid-from"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid="position-valid-to"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid="position-edit-readonly-note"]').text()).toContain('PATCH')

    await wrapper.get('[data-testid="position-title"]').setValue('高级合成工程师')
    await wrapper.get('[data-testid="position-headcount-limit"]').setValue('5')
    await wrapper.get('[data-testid="position-save"]').trigger('click')
    await flushPromises()

    expect(resource.patch).toHaveBeenCalledWith(
      '/v1/positions/1',
      {
        title: '高级合成工程师',
        grade: 'P5',
        headcount_limit: 5,
        status: 'ACTIVE',
      },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    const body = resource.patch.mock.calls[0]?.[1] as Record<string, unknown>
    expect(body).not.toHaveProperty('code')
    expect(body).not.toHaveProperty('org_unit_id')
    expect(body).not.toHaveProperty('valid_from')
    expect(body).not.toHaveProperty('valid_to')
  })

  it('submits only changed requirement rows and refetches the requirement versions', async () => {
    const wrapper = await mountWorkspace()
    await flushPromises()
    await selectPosition(wrapper, 1)
    await flushPromises()

    expect(wrapper.get('[data-testid="position-requirements"]').text()).toContain('第 2 版')
    expect(
      (wrapper.get('[data-testid="requirement-input-skills"]').element as HTMLInputElement).value,
    ).toBe('PHP')
    expect(
      (wrapper.get('[data-testid="requirement-input-qualifications"]').element as HTMLInputElement)
        .value,
    ).toBe('PMP')

    requirementRows = [requirementVersion(3, { skills: 'TypeScript', qualifications: 'PMP' })]
    await wrapper.get('[data-testid="requirement-input-skills"]').setValue('TypeScript')
    await wrapper.get('[data-testid="position-requirement-effective-from"]').setValue('2026-12-01')
    await wrapper.get('[data-testid="position-requirements-save"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/positions/1/requirements',
      {
        requirements: { skills: 'TypeScript' },
        effective_from: '2026-12-01',
      },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    const requirementCalls = resource.get.mock.calls.filter(
      ([path]) => path === '/v1/positions/1/requirements',
    )
    expect(requirementCalls).toHaveLength(2)
    expect(requirementCalls[1]?.[1]).toEqual(
      expect.objectContaining({ as_of: expect.any(String), page: 1, page_size: 100 }),
    )
    expect(wrapper.get('[data-testid="position-requirements"]').text()).toContain('第 3 版')
    expect(wrapper.get('[data-testid="position-requirements"]').text()).toContain('TypeScript')
  })

  it('warns about over-limit headcount in detail and does not block saving a lower limit', async () => {
    const wrapper = await mountWorkspace()
    await flushPromises()
    await selectPosition(wrapper, 1)

    expect(wrapper.get('[data-testid="position-detail-variance"]').text()).toContain('超过')
    expect(wrapper.get('[data-testid="position-detail-variance"]').text()).toContain('异动')

    await wrapper.get('[data-testid="position-edit"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-testid="position-headcount-limit"]').setValue('1')
    expect(wrapper.get('[data-testid="position-form-variance"]').text()).toContain('超过')
    expect(wrapper.get('[data-testid="position-save"]').attributes('disabled')).toBeUndefined()

    await wrapper.get('[data-testid="position-save"]').trigger('click')
    await flushPromises()
    expect(resource.patch).toHaveBeenCalledWith(
      '/v1/positions/1',
      expect.objectContaining({ headcount_limit: 1 }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
  })

  it('renders a forbidden state without data requests and hides mutating controls from read-only users', async () => {
    const forbidden = await mountWorkspace([])
    await flushPromises()

    expect(forbidden.get('[data-testid="position-forbidden"]').text()).toContain('org.position.read')
    expect(forbidden.find('[data-testid="position-table"]').exists()).toBe(false)
    expect(resource.get).not.toHaveBeenCalled()
    forbidden.unmount()

    installWorkspaceGet()
    const readOnly = await mountWorkspace(['org.position.read'])
    await flushPromises()

    expect(readOnly.find('[data-testid="position-table"]').exists()).toBe(true)
    expect(readOnly.find('[data-testid="position-create"]').exists()).toBe(false)
    await selectPosition(readOnly, 1)
    expect(readOnly.find('[data-testid="position-edit"]').exists()).toBe(false)
    expect(readOnly.get('[data-testid="position-requirements-gap"]').text()).toContain(
      'org.position.qualification.manage',
    )
  })

  it('shows loading, a Chinese retryable error with request id, and then the empty state', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/positions') {
        return Promise.reject(
          new ApiError('INTERNAL', 'Synthetic position outage', {
            status: 500,
            requestId: 'req-position-500',
          }),
        )
      }
      if (path === '/v1/departments') return Promise.resolve(list(DEPARTMENTS))
      if (path === '/v1/positions/grades') return Promise.resolve(list(GRADES))
      return Promise.reject(new Error(`Unexpected GET ${path}`))
    })

    const wrapper = await mountWorkspace()
    expect(wrapper.find('[data-testid="position-loading"]').exists()).toBe(true)
    await flushPromises()

    const error = wrapper.get('[data-testid="position-error"]')
    expect(error.text()).toContain('暂时无法加载')
    expect(error.text()).toContain('req-position-500')
    expect(error.text()).not.toContain('Synthetic position outage')
    expect(wrapper.find('[data-testid="position-table"]').exists()).toBe(false)

    positionRows = []
    installWorkspaceGet()
    await error.get('button').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid="position-empty"]').text()).toContain('暂无岗位')
  })
})
