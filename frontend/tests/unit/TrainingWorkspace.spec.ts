import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import ElementPlus from 'element-plus'
import { defineComponent } from 'vue'
import { ApiError } from '@/api/errors'
import { useSessionStore } from '@/stores/session'

const resource = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
}))

vi.mock('@/api/resourceClient', () => ({
  resourceGet: (...args: unknown[]) => resource.get(...args),
  resourcePost: (...args: unknown[]) => resource.post(...args),
}))

import TrainingWorkspacePage from '@/modules/talent/training/pages/TrainingWorkspacePage.vue'
import TrainingProgramDetailPage from '@/modules/talent/training/pages/TrainingProgramDetailPage.vue'
import TrainingEnrollmentTable from '@/modules/talent/training/components/TrainingEnrollmentTable.vue'

const OverlayStub = defineComponent({
  inheritAttrs: false,
  props: { modelValue: Boolean, title: String },
  emits: ['update:modelValue'],
  template: `<div v-if="modelValue" v-bind="$attrs" class="overlay-stub" role="dialog">
    <h2 v-if="title">{{ title }}</h2>
    <slot />
    <slot name="footer" />
  </div>`,
})

function page(items: unknown[], overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return { items, page: 1, page_size: 20, total: items.length, ...overrides }
}

function programRaw(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    program_id: 1,
    code: 'SAFE-2026',
    title: '合成安全培训',
    category: null,
    delivery_mode: 'ONLINE',
    capacity: 10,
    enrolled_count: 3,
    completed_count: 1,
    remaining_seats: 7,
    session_count: 1,
    training_cost_cents: 45600,
    status: 'ACTIVE',
    ...overrides,
  }
}

function sessionRaw(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    session_id: 11,
    program_id: 1,
    plan_id: null,
    course_id: 5,
    starts_on: '2026-12-10',
    ends_on: '2026-12-10',
    starts_at: '2026-12-10T01:00:00Z',
    ends_at: '2026-12-10T09:00:00Z',
    capacity: 10,
    location: '合成培训室',
    status: 'SCHEDULED',
    ...overrides,
  }
}

function enrollmentRaw(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    enrollment_id: 21,
    session_id: 11,
    employment_id: 101,
    status: 'ENROLLED',
    attended: false,
    completion_result: null,
    score: null,
    completed_at: null,
    ...overrides,
  }
}

function employeePage(): Record<string, unknown> {
  return {
    items: [
      { employment_id: 101, person_name: '合成员工', employee_no: 'EMP-101', status: 'ACTIVE' },
      { employment_id: 102, person_name: '合成学员', employee_no: 'EMP-102', status: 'ACTIVE' },
    ],
    page: 1,
    page_size: 100,
    total: 2,
  }
}

const CAPABILITIES = [
  'training.program.read',
  'training.program.manage',
  'training.session.manage',
  'training.enrollment.manage',
  'training.completion.manage',
  'people.employee.read',
]

function piniaWith(capabilities: string[]): ReturnType<typeof createPinia> {
  const pinia = createPinia()
  setActivePinia(pinia)
  const session = useSessionStore()
  session.capabilities = capabilities
  session.status = 'authenticated'
  return pinia
}

async function mountAt(
  component: typeof TrainingWorkspacePage | typeof TrainingProgramDetailPage,
  path: string,
  capabilities: string[] = CAPABILITIES,
): Promise<VueWrapper> {
  const pinia = piniaWith(capabilities)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/training', name: 'training', component: { template: '<div />' } },
      { path: '/training/programs/:id', name: 'training-program-detail', component: { template: '<div />' } },
      { path: '/hr/employees/:id', name: 'employee-detail', component: { template: '<div />' } },
    ],
  })
  await router.push(path)
  await router.isReady()

  const wrapper = mount(component, {
    global: {
      plugins: [pinia, router, ElementPlus],
      stubs: {
        ElDrawer: OverlayStub,
        ElDialog: OverlayStub,
        teleport: true,
      },
    },
  })
  await flushPromises()
  return wrapper
}

function byTestId(wrapper: VueWrapper, id: string) {
  return wrapper.get(`[data-testid="${id}"]`)
}

beforeEach(() => {
  resource.get.mockReset()
  resource.post.mockReset()
  resource.post.mockResolvedValue({})
})

describe('training program workspace', () => {
  it('renders live program rows with Chinese delivery mode, capacity, seats and integer-cent cost', async () => {
    resource.get.mockResolvedValue(page([programRaw(), programRaw({ program_id: 2, code: 'ONSITE-1', title: '合成线下培训', delivery_mode: 'ONSITE', status: 'ARCHIVED', enrolled_count: 10, remaining_seats: 0, training_cost_cents: 9900 })]))

    const wrapper = await mountAt(TrainingWorkspacePage, '/training')

    expect(resource.get).toHaveBeenCalledWith('/v1/training/programs', expect.objectContaining({ as_of: expect.any(String) }))
    const text = wrapper.text()
    expect(text).toContain('培训工作台')
    expect(text).toContain('合成安全培训')
    expect(text).toContain('线上')
    expect(text).toContain('线下')
    expect(text).toContain('启用中')
    expect(text).toContain('已归档')
    expect(text).toContain('3 / 10')
    expect(text).toContain('剩余 7 个名额')
    expect(text).toContain('¥456.00')
    expect(text).toContain('¥99.00')
    expect(wrapper.findAll('[data-testid^="training-program-row-"]')).toHaveLength(2)
  })

  it('shows the forbidden state without issuing a program request', async () => {
    const wrapper = await mountAt(TrainingWorkspacePage, '/training', ['people.employee.read'])

    expect(byTestId(wrapper, 'training-forbidden').text()).toContain('training.program.read')
    expect(resource.get).not.toHaveBeenCalled()
  })

  it('renders a real empty state and retries a failed load', async () => {
    resource.get.mockResolvedValueOnce(page([], { total: 0 }))
    const empty = await mountAt(TrainingWorkspacePage, '/training')
    expect(empty.text()).toContain('还没有培训项目')
    expect(empty.findAll('[data-testid^="training-program-row-"]')).toHaveLength(0)

    resource.get
      .mockRejectedValueOnce(new ApiError('INTERNAL', '培训服务暂时不可用', { status: 500 }))
      .mockResolvedValueOnce(page([programRaw()]))

    const wrapper = await mountAt(TrainingWorkspacePage, '/training')
    expect(wrapper.text()).toContain('培训服务暂时不可用')

    const retry = wrapper.findAll('button').find((button) => button.text() === '重试')
    expect(retry).toBeDefined()
    await retry?.trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('合成安全培训')
  })

  it('creates a program through the real command with an idempotency key', async () => {
    resource.get.mockResolvedValue(page([programRaw()]))
    resource.post.mockResolvedValue({ program_id: 3, code: 'NEW-1', title: '新建合成培训', status: 'ACTIVE' })

    const wrapper = await mountAt(TrainingWorkspacePage, '/training')
    await byTestId(wrapper, 'training-program-create').trigger('click')

    await byTestId(wrapper, 'training-program-code').setValue('NEW-1')
    await byTestId(wrapper, 'training-program-title').setValue('新建合成培训')
    await byTestId(wrapper, 'training-program-capacity').setValue('8')
    await byTestId(wrapper, 'training-program-save').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledTimes(1)
    const [path, body, options] = resource.post.mock.calls[0] as [string, Record<string, unknown>, { idempotencyKey?: string }]
    expect(path).toBe('/v1/training/programs')
    expect(body).toEqual({ code: 'NEW-1', title: '新建合成培训', default_capacity: 8 })
    expect(options?.idempotencyKey).toMatch(/^training-program-/)
    expect(wrapper.text()).toContain('培训项目已创建')
  })

  it('keeps publish and close behind a confirmation and never calls a missing route', async () => {
    resource.get.mockResolvedValue(page([programRaw()]))

    const wrapper = await mountAt(TrainingWorkspacePage, '/training')
    await byTestId(wrapper, 'training-program-publish-1').trigger('click')

    const dialog = byTestId(wrapper, 'training-program-confirm')
    expect(dialog.text()).toContain('发布培训项目')
    expect(dialog.text()).toContain('合成安全培训')
    expect(dialog.text()).toContain('确认')

    await byTestId(wrapper, 'training-program-confirm-submit').trigger('click')
    await flushPromises()

    expect(resource.post).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('服务端尚未提供培训项目发布/关闭接口')
    expect(wrapper.text()).toContain('/v1/training/programs/1/publish')

    await byTestId(wrapper, 'training-program-edit-1').trigger('click')
    expect(byTestId(wrapper, 'training-program-unsupported-edit').text()).toContain('更新接口')
  })
})

describe('training program detail', () => {
  function mockDetail(options: { sessions?: unknown[]; enrollments?: unknown[]; employees?: unknown } = {}) {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/training/programs') return Promise.resolve(page([programRaw()]))
      if (path === '/v1/training/sessions') return Promise.resolve(page(options.sessions ?? [sessionRaw()]))
      if (path === '/v1/training/enrollments') return Promise.resolve(page(options.enrollments ?? [enrollmentRaw()]))
      if (path === '/v1/employees') return Promise.resolve(options.employees ?? employeePage())
      return Promise.resolve(page([]))
    })
  }

  it('renders program, sessions and enrollment rows from the real projections', async () => {
    mockDetail()

    const wrapper = await mountAt(TrainingProgramDetailPage, '/training/programs/1')

    expect(resource.get).toHaveBeenCalledWith('/v1/training/programs', expect.objectContaining({ as_of: expect.any(String) }))
    expect(resource.get).toHaveBeenCalledWith('/v1/training/sessions', expect.objectContaining({ program_id: 1 }))
    expect(resource.get).toHaveBeenCalledWith('/v1/training/enrollments')
    expect(resource.get).toHaveBeenCalledWith('/v1/employees', expect.objectContaining({ page_size: 100 }))

    const text = wrapper.text()
    expect(text).toContain('合成安全培训')
    expect(text).toContain('线上')
    expect(text).toContain('剩余 7 个名额')
    expect(text).toContain('2026年12月10日')
    expect(text).toContain('合成培训室')
    expect(text).toContain('已报名')
    expect(text).toContain('合成员工')
    expect(text).toContain('EMP-101')
    expect(wrapper.get('a[href="/hr/employees/101"]')).toBeTruthy()
  })

  it('maps a server capacity conflict to inline Chinese copy in the enrollment dialog', async () => {
    mockDetail()
    resource.post.mockRejectedValue(
      new ApiError('CONFLICT', 'Training session capacity has been reached.', {
        status: 409,
        details: { reason: 'TRAINING_CAPACITY_REACHED', session_id: 11, capacity: 10 },
      }),
    )

    const wrapper = await mountAt(TrainingProgramDetailPage, '/training/programs/1')
    await byTestId(wrapper, 'training-enroll-open').trigger('click')

    await byTestId(wrapper, 'training-enroll-employee').setValue('102')
    await byTestId(wrapper, 'training-enroll-session').setValue('11')
    await byTestId(wrapper, 'training-enroll-submit').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledTimes(1)
    const [path, body, options] = resource.post.mock.calls[0] as [string, Record<string, unknown>, { idempotencyKey?: string }]
    expect(path).toBe('/v1/training/enrollments')
    expect(body).toEqual({ session_id: 11, employment_id: 102 })
    expect(options?.idempotencyKey).toMatch(/^training-enroll-/)
    expect(byTestId(wrapper, 'training-enroll-error').text()).toContain('名额已满')
    expect(byTestId(wrapper, 'training-enroll-error').text()).not.toContain('Training session capacity')
  })

  it('completes an active enrollment with a completion date and blocks inactive rows', async () => {
    mockDetail({
      enrollments: [
        enrollmentRaw(),
        enrollmentRaw({ enrollment_id: 22, employment_id: 102, status: 'CANCELLED' }),
      ],
    })

    const wrapper = await mountAt(TrainingProgramDetailPage, '/training/programs/1')
    await byTestId(wrapper, 'training-enrollment-complete-21').trigger('click')

    expect(byTestId(wrapper, 'training-complete-dialog').text()).toContain('登记培训完成')
    await byTestId(wrapper, 'training-complete-result').setValue('PASS')
    await byTestId(wrapper, 'training-complete-score').setValue('88')
    await byTestId(wrapper, 'training-complete-submit').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledTimes(1)
    const [path, body, options] = resource.post.mock.calls[0] as [string, Record<string, unknown>, { idempotencyKey?: string }]
    expect(path).toBe('/v1/training/enrollments/21/complete')
    expect(body.completion_result).toBe('PASS')
    expect(body.score).toBe(88)
    expect(body.completed_on).toMatch(/^\d{4}-\d{2}-\d{2}$/)
    expect(options?.idempotencyKey).toMatch(/^training-complete-/)

    const inactive = byTestId(wrapper, 'training-enrollment-complete-22')
    expect(inactive.attributes('disabled')).toBeDefined()
    expect(byTestId(wrapper, 'training-enrollment-reason-22').text()).toContain('已取消')
  })

  it('maps a non-active completion conflict to Chinese and keeps the row unchanged', async () => {
    mockDetail()
    resource.post.mockRejectedValue(
      new ApiError('CONFLICT', 'Only an active enrollment can be completed.', {
        status: 409,
        details: { reason: 'TRAINING_ENROLLMENT_NOT_ACTIVE' },
      }),
    )

    const wrapper = await mountAt(TrainingProgramDetailPage, '/training/programs/1')
    await byTestId(wrapper, 'training-enrollment-complete-21').trigger('click')
    await byTestId(wrapper, 'training-complete-submit').trigger('click')
    await flushPromises()

    expect(byTestId(wrapper, 'training-complete-error').text()).toContain('仅处于“已报名”或“已签到”状态')
    expect(wrapper.text()).not.toContain('Only an active enrollment')
  })

  it('confirms enrollment cancellation and reports the missing real endpoint instead of faking success', async () => {
    mockDetail()

    const wrapper = await mountAt(TrainingProgramDetailPage, '/training/programs/1')
    await byTestId(wrapper, 'training-enrollment-cancel-21').trigger('click')

    const dialog = byTestId(wrapper, 'training-cancel-dialog')
    expect(dialog.text()).toContain('取消报名')
    expect(dialog.text()).toContain('合成员工')

    await byTestId(wrapper, 'training-cancel-submit').trigger('click')
    await flushPromises()
    expect(resource.post).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('服务端尚未提供取消报名接口')
    expect(wrapper.text()).toContain('/v1/training/enrollments/21/cancel')
  })

  it('shows the detail forbidden state without reading any training surface', async () => {
    const wrapper = await mountAt(TrainingProgramDetailPage, '/training/programs/1', ['people.employee.read'])

    expect(byTestId(wrapper, 'training-detail-forbidden').text()).toContain('training.program.read')
    expect(resource.get).not.toHaveBeenCalled()
  })

  it('renders enrollment status labels including cancelled rows in the table component', async () => {
    const wrapper = mount(TrainingEnrollmentTable, {
      props: {
        enrollments: [
          {
            id: 21,
            sessionId: 11,
            employmentId: 101,
            employeeName: '合成员工',
            employeeNo: 'EMP-101',
            status: 'ENROLLED',
            attended: false,
            completionResult: null,
            score: null,
            completedAt: null,
            sessionLabel: '2026年12月10日',
          },
          {
            id: 22,
            sessionId: 11,
            employmentId: 102,
            employeeName: '合成学员',
            employeeNo: 'EMP-102',
            status: 'CANCELLED',
            attended: false,
            completionResult: null,
            score: null,
            completedAt: null,
            sessionLabel: '2026年12月10日',
          },
        ],
        capabilities: CAPABILITIES,
      },
      global: { plugins: [ElementPlus] },
    })

    expect(wrapper.text()).toContain('已报名')
    expect(wrapper.text()).toContain('已取消')
    expect(wrapper.findAll('[data-testid^="training-enrollment-row-"]')).toHaveLength(2)
  })
})
