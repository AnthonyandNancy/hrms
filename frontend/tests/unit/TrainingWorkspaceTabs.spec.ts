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
  patch: vi.fn(),
}))

vi.mock('@/api/resourceClient', () => ({
  resourceGet: (...args: unknown[]) => resource.get(...args),
  resourcePost: (...args: unknown[]) => resource.post(...args),
  resourcePatch: (...args: unknown[]) => resource.patch(...args),
}))

import TrainingWorkspacePage from '@/modules/talent/training/pages/TrainingWorkspacePage.vue'
import SessionOperationDrawer from '@/modules/talent/training/components/SessionOperationDrawer.vue'
import TrainingExamPanel from '@/modules/talent/training/components/TrainingExamPanel.vue'

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
    program_id: 3,
    code: 'SAFE-2026',
    title: '合成安全培训',
    category: null,
    delivery_mode: 'ONLINE',
    capacity: 10,
    enrolled_count: 1,
    completed_count: 0,
    remaining_seats: 9,
    session_count: 1,
    training_cost_cents: 45600,
    status: 'ACTIVE',
    ...overrides,
  }
}

function sessionRaw(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    session_id: 91,
    program_id: 3,
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

const ALL_CAPABILITIES = [
  'training.program.read',
  'training.program.manage',
  'training.plan.manage',
  'training.course.manage',
  'training.session.manage',
  'training.enrollment.manage',
  'training.completion.manage',
  'training.checkin.manage',
  'training.exam.manage',
  'training.feedback.manage',
  'training.record.read',
  'training.report.read',
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

async function mountAt(path: string, capabilities: string[] = ALL_CAPABILITIES): Promise<VueWrapper> {
  const pinia = piniaWith(capabilities)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/training', name: 'training', component: { template: '<div />' } },
    ],
  })
  await router.push(path)
  await router.isReady()

  const wrapper = mount(TrainingWorkspacePage, {
    global: {
      plugins: [pinia, router, ElementPlus],
      stubs: { ElDrawer: OverlayStub, ElDialog: OverlayStub },
    },
  })
  await flushPromises()
  return wrapper
}

function mockReads(): void {
  resource.get.mockImplementation((path: string, params: Record<string, unknown> = {}) => {
    if (path === '/v1/training/programs') return Promise.resolve(page([programRaw()]))
    if (path === '/v1/training/plans') {
      return Promise.resolve(page([{ plan_id: 1, code: 'PLAN-1', title: '合成年度计划', year: 2027, budget_cents: 1250000, currency: 'CNY', status: 'ACTIVE', recorded_at: null }]))
    }
    if (path === '/v1/training/sessions') return Promise.resolve(page([sessionRaw()]))
    if (path === '/v1/training/enrollments') {
      return Promise.resolve(page([{ enrollment_id: 21, session_id: 91, employment_id: 101, status: 'ENROLLED', attended: false, completion_result: null, score: null, completed_at: null }]))
    }
    if (path === '/v1/training/courses') {
      return Promise.resolve(page([{ course_id: 5, plan_id: null, code: 'COURSE-1', title: '合成课程', instructor: '合成讲师', delivery_mode: 'ONSITE', duration_minutes: 90, cost_cents: 10000, currency: 'CNY', materials: null, status: 'ACTIVE', recorded_at: null }]))
    }
    if (path === '/v1/training/exams') {
      return Promise.resolve(page([{ exam_id: 71, course_id: 5, session_id: 91, code: 'EXAM-1', title: '合成考试', version: 1, passing_score: 60, max_score: 100, status: 'DRAFT', published_at: null, recorded_at: null }]))
    }
    if (path.startsWith('/v1/training/sessions/') && path.endsWith('/checkins')) {
      return Promise.resolve(page([{ checkin_id: 61, session_id: 91, employment_id: 101, checked_in_at: '2026-12-10T01:00:00Z', checked_out_at: '2026-12-10T03:30:00Z', study_minutes: 150, recorded_at: null }]))
    }
    if (path === '/v1/training/feedback') {
      return Promise.resolve(page([{ training_feedback_id: 81, enrollment_id: 21, session_id: 91, employment_id: 101, rating: 5, comments: '合成反馈', questionnaire: null, status: 'RECORDED', recorded_at: null }]))
    }
    if (path === '/v1/training/records') {
      return Promise.resolve(page([{ enrollment_id: 21, employment_id: 101, session_id: 91, course_code: 'COURSE-1', course_title: '合成课程', session_starts_on: '2026-12-10', status: 'COMPLETED', attended: true, completion_result: 'PASS', score: 88, completed_at: null, checkin_count: 1, study_minutes: 150, feedback_count: 1, latest_exam_version: 2 }]))
    }
    if (path === '/v1/training/reports') {
      return Promise.resolve(page([{ plan_id: 1, code: 'PLAN-1', title: '合成年度计划', year: 2027, budget_cents: 1250000, training_cost_cents: 10000, currency: 'CNY', course_count: 1, session_count: 1, enrolled_count: 1, completed_count: 1, completion_rate_bps: 10000, exam_pass_count: 1, exam_pass_rate_bps: 10000, study_minutes_total: 150, feedback_count: 1, average_feedback_rating_bps: 10000 }]))
    }
    if (path === '/v1/employees') {
      return Promise.resolve(page([{ employment_id: 101, person_name: '合成员工', employee_no: 'EMP-101', status: 'ACTIVE' }]))
    }
    void params
    return Promise.resolve(page([]))
  })
}

beforeEach(() => {
  resource.get.mockReset()
  resource.post.mockReset()
  resource.patch.mockReset()
  resource.post.mockResolvedValue({})
  resource.patch.mockResolvedValue({})
})

describe('training nine-tab workspace', () => {
  it('renders one workspace with every required training tab and the sessions query read', async () => {
    mockReads()

    const wrapper = await mountAt('/training?tab=sessions')

    const tabs = wrapper.find('[data-testid="training-workspace-tabs"]').text()
    for (const label of ['计划', '课程', '场次', '报名', '签到', '考试', '反馈', '档案', '报表']) {
      expect(tabs).toContain(label)
    }
    expect(resource.get).toHaveBeenCalledWith('/v1/training/programs', expect.objectContaining({ as_of: expect.any(String) }))
    expect(resource.get).toHaveBeenCalledWith('/v1/training/sessions', expect.objectContaining({ program_id: 3, as_of: expect.any(String) }))
    expect(wrapper.text()).toContain('合成培训室')
  })

  it('opens the session operation drawer from program/session query parameters without a new route', async () => {
    mockReads()

    const wrapper = await mountAt('/training?program=3&session=91')
    await flushPromises()

    expect(wrapper.find('[data-testid="training-session-drawer"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="training-session-tabs"]').text()).toContain('报名')
    expect(resource.get).toHaveBeenCalledWith('/v1/training/sessions/91/checkins', expect.objectContaining({ as_of: expect.any(String) }))
  })

  it('shows unconfigured before a session is selected and never renders an empty enrollment table', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/training/programs') return Promise.resolve(page([]))
      return Promise.resolve(page([]))
    })

    const wrapper = await mountAt('/training?tab=enrollments')
    await flushPromises()

    expect(wrapper.text()).toContain('请选择培训场次')
    expect(wrapper.find('[data-testid="training-enrollment-table"]').exists()).toBe(false)
  })

  it('keeps forbidden honest and issues no training reads', async () => {
    const wrapper = await mountAt('/training', ['people.employee.read'])

    expect(wrapper.find('[data-testid="training-forbidden"]').text()).toContain('training.program.read')
    expect(resource.get).not.toHaveBeenCalled()
  })
})

describe('session operation center', () => {
  it('cancels an enrollment through the real command and exposes the success evidence', async () => {
    mockReads()
    resource.post.mockResolvedValue({
      enrollment_id: 21,
      session_id: 91,
      employment_id: 101,
      status: 'CANCELLED',
      attended: false,
      completion_result: null,
      score: null,
      completed_at: null,
    })

    const wrapper = await mountAt('/training?program=3&session=91')
    await flushPromises()

    await wrapper.find('[data-testid="training-cancel-enrollment"] button').trigger('click')
    await flushPromises()
    await wrapper.find('[data-testid="training-cancel-submit"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/training/enrollments/21/cancel',
      { reason: null },
      expect.objectContaining({ idempotencyKey: expect.stringMatching(/^training-cancel-enrollment-/) }),
    )
    expect(wrapper.find('[data-testid="training-enrollment-cancelled"]').text()).toContain('名额已释放')
  })

  it('maps a non-cancellable state to Chinese without inventing a local status change', async () => {
    mockReads()
    resource.post.mockRejectedValue(
      new ApiError('CONFLICT', 'Only an active training enrollment can be cancelled.', {
        status: 409,
        details: { reason: 'TRAINING_ENROLLMENT_NOT_CANCELLABLE', status: 'COMPLETED' },
      }),
    )

    const wrapper = await mountAt('/training?program=3&session=91')
    await flushPromises()

    await wrapper.find('[data-testid="training-cancel-enrollment"] button').trigger('click')
    await flushPromises()
    await wrapper.find('[data-testid="training-cancel-submit"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="training-cancel-error"]').text()).toContain('仅处于')
    expect(wrapper.find('[data-testid="training-enrollment-cancelled"]').exists()).toBe(false)
  })

  it('creates a session-scoped exam only when a real session is selected', async () => {
    mockReads()
    resource.post.mockResolvedValue({
      exam_id: 72,
      course_id: 5,
      session_id: 91,
      code: 'EXAM-NEW',
      title: '合成场次考试',
      version: 1,
      passing_score: 60,
      max_score: 100,
      status: 'DRAFT',
      published_at: null,
      recorded_at: null,
    })

    const wrapper = await mountAt('/training?program=3&session=91')
    await flushPromises()

    await wrapper.findAll('[data-testid="training-session-tabs"] .el-tabs__item')[2]?.trigger('click')
    await flushPromises()
    await wrapper.find('[data-testid="training-exam-create"]').trigger('click')
    await wrapper.find('[data-testid="training-exam-code"]').setValue('EXAM-NEW')
    await wrapper.find('[data-testid="training-exam-title"]').setValue('合成场次考试')
    await wrapper.find('[data-testid="training-exam-submit"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/training/exams',
      expect.objectContaining({ session_id: 91, course_id: 5, code: 'EXAM-NEW' }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
  })
})

  it('submits an exam attempt with enrollment and score and shows the source result', async () => {
    resource.get.mockResolvedValue(page([{
      exam_id: 71,
      course_id: 5,
      session_id: 91,
      code: 'EXAM-1',
      title: '合成考试',
      version: 1,
      passing_score: 60,
      max_score: 100,
      status: 'PUBLISHED',
      published_at: '2026-01-01T00:00:00Z',
      recorded_at: null,
    }]))
    resource.post.mockResolvedValue({
      attempt_id: 301,
      exam_id: 71,
      enrollment_id: 21,
      employment_id: 101,
      attempt_no: 1,
      score: 80,
      passed: true,
      source_hash: 'd'.repeat(64),
      submitted_at: '2026-01-01T00:00:00Z',
    })

    const pinia = piniaWith(['training.exam.manage'])
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/training', component: { template: '<div />' } }],
    })
    await router.push('/training')
    await router.isReady()
    const wrapper = mount(TrainingExamPanel, {
      props: { sessionId: 91, courseId: 5 },
      global: { plugins: [pinia, router, ElementPlus], stubs: { ElDialog: OverlayStub } },
    })
    await flushPromises()

    await wrapper.get('[data-testid="training-exam-attempt-71"]').trigger('click')
    await wrapper.get('[data-testid="training-attempt-enrollment"]').setValue('21')
    await wrapper.get('[data-testid="training-attempt-score"]').setValue('80')
    await wrapper.get('[data-testid="training-attempt-submit"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/training/exams/71/attempts',
      expect.objectContaining({ enrollment_id: 21, score: 80 }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
    expect(wrapper.get('[data-testid="training-attempt-result"]').text()).toContain('尝试 #1')
    expect(wrapper.get('[data-testid="training-attempt-result"]').text()).toContain('通过')
  })

describe('SessionOperationDrawer contract seam', () => {
  it('renders a forbidden placeholder instead of issuing hidden panel reads', async () => {
    const pinia = piniaWith(['training.program.read'])
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/training', component: { template: '<div />' } }],
    })
    await router.push('/training')
    await router.isReady()

    const wrapper = mount(SessionOperationDrawer, {
      props: { open: true, programId: 3, sessionId: 91, sessionRow: null },
      global: { plugins: [pinia, router, ElementPlus], stubs: { ElDrawer: OverlayStub } },
    })
    await flushPromises()

    expect(wrapper.text()).toContain('缺少 training.enrollment.manage')
    expect(resource.get).not.toHaveBeenCalled()
  })
})
