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

import PerformanceWorkspacePage from '@/modules/talent/performance/pages/PerformanceWorkspacePage.vue'
import PerformanceCycleDetailPage from '@/modules/talent/performance/pages/PerformanceCycleDetailPage.vue'
import PerformanceGoalTable from '@/modules/talent/performance/components/PerformanceGoalTable.vue'
import PerformanceScoreDrawer from '@/modules/talent/performance/components/PerformanceScoreDrawer.vue'
import {
  goalWeightTotal,
  normalisePerformanceCycle,
  performanceCycleStage,
  ratingLabel,
  reviewStatusLabel,
  validateGoalWeights,
  validateScoreInput,
  type PerformanceGoalDraft,
} from '@/modules/talent/performance/api'

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

function cycleRaw(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    cycle_id: 9,
    name: '2026 年度绩效周期',
    period_from: '2026-01-01',
    period_to: '2026-12-31',
    status: 'ACTIVE',
    participant_count: 3,
    submitted_count: 1,
    draft_count: 2,
    progress: 33,
    ...overrides,
  }
}

function reviewRaw(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    review_id: 31,
    cycle_id: 9,
    employment_id: 21,
    manager_employment_id: 20,
    status: 'DRAFT',
    self_rating: '85',
    manager_rating: null,
    calibrated_rating: null,
    final_rating: null,
    self_assessment: '合成自评',
    manager_assessment: null,
    submitted_at: null,
    goals: [
      {
        goal_id: 1,
        title: '交付质量',
        target_value: '缺陷率低于 1%',
        weight: 60,
        self_score: '85',
        manager_score: null,
        status: 'DRAFT',
      },
      {
        goal_id: 2,
        title: '客户满意度',
        target_value: '90 分以上',
        weight: 40,
        self_score: '90',
        manager_score: null,
        status: 'DRAFT',
      },
    ],
    ...overrides,
  }
}

const ALL_CAPABILITIES = [
  'performance.cycle.read',
  'performance.cycle.manage',
  'performance.review.manage',
  'performance.review.submit',
  'performance.result.publish',
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
  component: typeof PerformanceWorkspacePage | typeof PerformanceCycleDetailPage,
  path: string,
  capabilities: string[] = ALL_CAPABILITIES,
): Promise<VueWrapper> {
  const pinia = piniaWith(capabilities)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/performance', name: 'performance', component: { template: '<div />' } },
      { path: '/performance/cycles/:id', name: 'performance-cycle-detail', component: { template: '<div />' } },
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
      },
    },
  })
  await flushPromises()
  return wrapper
}

async function openDrawer(wrapper: VueWrapper): Promise<void> {
  const button = wrapper.findAll('button').find((item) => item.text() === '评分')
  expect(button, 'a DRAFT review must expose a 评分 action').toBeDefined()
  await button?.trigger('click')
  await flushPromises()
}

beforeEach(() => {
  resource.get.mockReset()
  resource.post.mockReset()
  resource.post.mockResolvedValue({})
})

describe('performance goal rules and Chinese display', () => {
  it('totals integer weights and rejects weights outside 1..100 or not totalling 100', () => {
    const valid: PerformanceGoalDraft[] = [
      { title: '交付质量', targetValue: '缺陷率低于 1%', weight: 60, selfScore: '85', managerScore: '' },
      { title: '客户满意度', targetValue: '90 分以上', weight: 40, selfScore: '90', managerScore: '' },
    ]
    expect(goalWeightTotal(valid)).toBe(100)
    expect(validateGoalWeights(valid)).toEqual({ valid: true, total: 100, errors: [] })

    const underTotal = validateGoalWeights([
      { ...valid[0]!, weight: 60 },
      { ...valid[1]!, weight: 30 },
    ])
    expect(underTotal.valid).toBe(false)
    expect(underTotal.total).toBe(90)
    expect(underTotal.errors.join('')).toContain('权重合计必须等于 100')

    const outOfRange = validateGoalWeights([
      { ...valid[0]!, weight: 101 },
      { ...valid[1]!, weight: -1 },
    ])
    expect(outOfRange.valid).toBe(false)
    expect(outOfRange.errors.join('')).toContain('1 到 100')
  })

  it('accepts score strings without ever converting them to floats', () => {
    expect(validateScoreInput('90')).toBeNull()
    expect(validateScoreInput('90.5')).toBeNull()
    expect(validateScoreInput('')).toBeNull()
    expect(validateScoreInput('101')).toContain('0 到 100')
    expect(validateScoreInput('90.1234567')).toContain('最多 6 位小数')
    expect(validateScoreInput('9e1')).toContain('数字')
  })

  it('maps server status codes to Chinese without inventing a rating', () => {
    expect(performanceCycleStage(normalisePerformanceCycle(cycleRaw()))).toBe('评分中')
    expect(performanceCycleStage(normalisePerformanceCycle(cycleRaw({ submitted_count: 0, progress: 0 })))).toBe('进行中')
    expect(performanceCycleStage(normalisePerformanceCycle(cycleRaw({ status: 'DRAFT' })))).toBe('草稿')
    expect(performanceCycleStage(normalisePerformanceCycle(cycleRaw({ status: 'PUBLISHED' })))).toBe('已结束')
    expect(performanceCycleStage(normalisePerformanceCycle(cycleRaw({ status: 'SCORING' })))).toBe('评分中')
    expect(reviewStatusLabel('DRAFT')).toBe('草稿')
    expect(reviewStatusLabel('SUBMITTED')).toBe('已提交')
    expect(reviewStatusLabel('APPEALED')).toBe('申诉中')
    expect(ratingLabel('EXCEEDS')).toBe('超出预期')
    expect(ratingLabel('90.5')).toBe('90.5 分')
    expect(ratingLabel(null)).toBe('待服务端计算')
  })
})

describe('performance goal table', () => {
  it('shows the running weight total and blocks an invalid table', async () => {
    const wrapper = mount(PerformanceGoalTable, {
      props: {
        goals: [
          { title: '交付质量', targetValue: '缺陷率低于 1%', weight: 60, selfScore: '85', managerScore: '' },
          { title: '客户满意度', targetValue: '90 分以上', weight: 30, selfScore: '90', managerScore: '' },
        ],
        readOnly: false,
      },
      global: { plugins: [ElementPlus] },
    })
    await flushPromises()

    expect(wrapper.get('[data-testid="goal-weight-total"]').text()).toContain('90 / 100')
    expect(wrapper.text()).toContain('权重合计必须等于 100')
    expect(wrapper.get('[data-testid="goal-weight-error"]').text()).toContain('权重合计必须等于 100')

    await wrapper.get('[data-testid="goal-weight-1"]').setValue('40')
    await flushPromises()
    expect(wrapper.get('[data-testid="goal-weight-total"]').text()).toContain('100 / 100')
    expect(wrapper.find('[data-testid="goal-weight-error"]').exists()).toBe(false)
  })

  it('renders goal title, weight, target, self score and manager score columns', async () => {
    const wrapper = mount(PerformanceGoalTable, {
      props: {
        goals: [
          { title: '交付质量', targetValue: '缺陷率低于 1%', weight: 100, selfScore: '85', managerScore: '88' },
        ],
        readOnly: true,
      },
      global: { plugins: [ElementPlus] },
    })
    await flushPromises()

    const text = wrapper.text()
    expect(text).toContain('目标')
    expect(text).toContain('权重')
    expect(text).toContain('目标值')
    expect(text).toContain('自评分数')
    expect(text).toContain('主管分数')
    expect(text).toContain('交付质量')
    expect(text).toContain('100%')
    expect(text).toContain('缺陷率低于 1%')
    expect(text).toContain('85')
    expect(text).toContain('88')
    expect(wrapper.get('[data-testid="goal-title-0"]').text()).toContain('交付质量')
    expect(wrapper.find('input[data-testid="goal-title-0"]').exists()).toBe(false)
  })
})

describe('performance workspace cycle list', () => {
  it('renders real cycle rows with Chinese status, period, participants and progress', async () => {
    resource.get.mockResolvedValue(
      page([
        cycleRaw({ cycle_id: 1, name: '2026 年度考核', status: 'ACTIVE', participant_count: 3, submitted_count: 0, progress: 0 }),
        cycleRaw({ cycle_id: 2, name: '2026 年中考核', status: 'ACTIVE', participant_count: 3, submitted_count: 1, progress: 33 }),
        cycleRaw({ cycle_id: 3, name: '2026 草稿周期', status: 'DRAFT', participant_count: 0, submitted_count: 0, progress: 0 }),
        cycleRaw({ cycle_id: 4, name: '2025 年度考核', status: 'PUBLISHED', participant_count: 8, submitted_count: 8, progress: 100 }),
      ]),
    )

    const wrapper = await mountAt(PerformanceWorkspacePage, '/performance')

    expect(resource.get).toHaveBeenCalledWith(
      '/v1/performance/cycles',
      expect.objectContaining({ page: 1, page_size: 20 }),
    )
    const text = wrapper.text()
    expect(text).toContain('绩效考核工作台')
    expect(text).toContain('2026 年度考核')
    expect(text).toContain('2026-01-01 至 2026-12-31')
    expect(text).toContain('草稿')
    expect(text).toContain('进行中')
    expect(text).toContain('评分中')
    expect(text).toContain('已结束')
    expect(text).toContain('3 人')
    expect(text).toContain('33%')
    expect(wrapper.findAll('tbody tr')).toHaveLength(4)
  })

  it('sends status and name filters to the API and keeps pagination server-driven', async () => {
    resource.get.mockResolvedValue(page([cycleRaw()], { page: 2, total: 21 }))

    const wrapper = await mountAt(PerformanceWorkspacePage, '/performance')

    const performanceStatus = wrapper.getComponent('[data-testid="performance-status-filter"]') as VueWrapper
    await performanceStatus.setValue('PUBLISHED')
    performanceStatus.vm.$emit('change', 'PUBLISHED')
    await wrapper.get('[data-testid="performance-name-filter"]').setValue('2026')
    await flushPromises()
    await wrapper.get('[data-testid="performance-filter-submit"]').trigger('click')
    await flushPromises()

    expect(resource.get).toHaveBeenLastCalledWith(
      '/v1/performance/cycles',
      expect.objectContaining({ status: 'PUBLISHED', q: '2026', page: 1, page_size: 20 }),
    )
    expect(wrapper.text()).toContain('第 2 / 2 页')
  })

  it('renders empty and forbidden states without inventing rows or issuing a request', async () => {
    resource.get.mockResolvedValue(page([], { total: 0 }))
    const empty = await mountAt(PerformanceWorkspacePage, '/performance')
    expect(empty.text()).toContain('还没有绩效周期')
    expect(empty.findAll('tbody tr')).toHaveLength(0)

    resource.get.mockClear()
    const forbidden = await mountAt(PerformanceWorkspacePage, '/performance', [])
    expect(forbidden.get('[data-testid="performance-forbidden"]').text()).toContain('performance.cycle.read')
    expect(resource.get).not.toHaveBeenCalled()
  })

  it('shows the loading state until the real cycle projection resolves', async () => {
    let resolvePage: (value: unknown) => void = () => {}
    resource.get.mockReturnValue(new Promise((resolve) => {
      resolvePage = resolve
    }))

    const wrapper = await mountAt(PerformanceWorkspacePage, '/performance')
    expect(wrapper.find('.loading-state').exists()).toBe(true)

    resolvePage(page([cycleRaw()]))
    await flushPromises()
    expect(wrapper.find('.loading-state').exists()).toBe(false)
    expect(wrapper.text()).toContain('2026 年度绩效周期')
  })

  it('marks a partial projection without fabricating a cycle count', async () => {
    resource.get.mockResolvedValue(page([cycleRaw()], { total: 4, partial: true }))

    const wrapper = await mountAt(PerformanceWorkspacePage, '/performance')

    expect(wrapper.get('[data-testid="performance-partial"]').text()).toContain('部分数据')
    expect(wrapper.text()).toContain('共 4 个周期')
    expect(wrapper.findAll('tbody tr')).toHaveLength(1)
  })

  it('renders the shared error state and retries the real request', async () => {
    resource.get
      .mockRejectedValueOnce(new ApiError('INTERNAL', '绩效服务暂时不可用', { status: 500 }))
      .mockResolvedValueOnce(page([cycleRaw()]))

    const wrapper = await mountAt(PerformanceWorkspacePage, '/performance')
    expect(wrapper.text()).toContain('绩效服务暂时不可用')

    const retry = wrapper.findAll('button').find((button) => button.text() === '重试')
    expect(retry).toBeDefined()
    await retry?.trigger('click')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledTimes(2)
    expect(wrapper.text()).toContain('2026 年度绩效周期')
  })
})

describe('performance workspace detail entry', () => {
  it('opens the cycle detail through the registered workspace route without a missing router path', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/performance/cycles') return Promise.resolve(page([cycleRaw()]))
      if (path === '/v1/performance/reviews') return Promise.resolve(page([reviewRaw()]))
      return Promise.resolve(page([]))
    })

    const wrapper = await mountAt(PerformanceWorkspacePage, '/performance')
    const open = wrapper.findAll('button').find((button) => button.text() === '查看详情')
    expect(open).toBeDefined()
    await open?.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('参与者评估')
    expect(resource.get).toHaveBeenCalledWith(
      '/v1/performance/reviews',
      expect.objectContaining({ cycle_id: 9 }),
    )
  })
})

describe('performance cycle detail and score drawer', () => {
  beforeEach(() => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/performance/cycles') {
        return Promise.resolve(page([cycleRaw()]))
      }
      if (path === '/v1/performance/reviews') {
        return Promise.resolve(page([reviewRaw()]))
      }
      return Promise.resolve(page([]))
    })
  })

  it('calibrates a submitted review without rewriting the original scores', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/performance/cycles') return Promise.resolve(page([cycleRaw()]))
      if (path === '/v1/performance/reviews') {
        return Promise.resolve(page([reviewRaw({ status: 'SUBMITTED', manager_rating: '88' })]))
      }
      return Promise.resolve(page([]))
    })

    const wrapper = await mountAt(PerformanceCycleDetailPage, '/performance/cycles/9', [
      'performance.cycle.read',
      'performance.review.manage',
      'performance.review.submit',
      'performance.calibration.manage',
    ])

    await wrapper.get('[data-testid="performance-calibrate-31"]').trigger('click')
    await wrapper.get('[data-testid="performance-calibration-rating"]').setValue('EXCEEDS')
    await wrapper.get('[data-testid="performance-calibration-reason"]').setValue('Synthetic calibration evidence')
    await wrapper.get('[data-testid="performance-calibration-submit"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledTimes(1)
    const [path, body, options] = resource.post.mock.calls[0] as [
      string,
      Record<string, unknown>,
      { idempotencyKey?: string },
    ]
    expect(path).toBe('/v1/performance/reviews/31/calibration')
    expect(body).toEqual({ calibrated_rating: 'EXCEEDS', reason: 'Synthetic calibration evidence' })
    expect(body).not.toHaveProperty('manager_rating')
    expect(options.idempotencyKey).toMatch(/^performance-calibration-/)
  })

  it('raises an appeal as a separate workflow Case and never edits the review', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/performance/cycles') return Promise.resolve(page([cycleRaw()]))
      if (path === '/v1/performance/reviews') {
        return Promise.resolve(page([reviewRaw({ status: 'SUBMITTED', manager_rating: '88' })]))
      }
      return Promise.resolve(page([]))
    })

    const wrapper = await mountAt(PerformanceCycleDetailPage, '/performance/cycles/9', [
      'performance.cycle.read',
      'performance.review.manage',
      'performance.appeal.manage',
    ])

    await wrapper.get('[data-testid="performance-appeal-31"]').trigger('click')
    await wrapper.get('[data-testid="performance-appeal-reason"]').setValue('The rating process did not follow the rubric.')
    await wrapper.get('[data-testid="performance-appeal-submit"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledTimes(1)
    const [path, body, options] = resource.post.mock.calls[0] as [
      string,
      Record<string, unknown>,
      { idempotencyKey?: string },
    ]
    expect(path).toBe('/v1/performance/reviews/31/appeals')
    expect(body).toEqual({ reason: 'The rating process did not follow the rubric.' })
    expect(body).not.toHaveProperty('calibrated_rating')
    expect(options.idempotencyKey).toMatch(/^performance-appeal-/)
  })

  it('opens the score drawer with goal rows and enforces the 100 percent total before saving', async () => {
    const wrapper = await mountAt(PerformanceCycleDetailPage, '/performance/cycles/9')

    expect(resource.get).toHaveBeenCalledWith('/v1/performance/cycles', expect.objectContaining({ page: 1 }))
    expect(resource.get).toHaveBeenCalledWith(
      '/v1/performance/reviews',
      expect.objectContaining({ cycle_id: 9, page: 1, page_size: 100 }),
    )
    expect(wrapper.text()).toContain('员工 #21')
    expect(wrapper.text()).toContain('交付质量')
    expect(wrapper.text()).toContain('100% 权重已分配')

    await openDrawer(wrapper)
    expect(wrapper.findAll('[data-testid^="goal-row-"]')).toHaveLength(2)
    expect((wrapper.get('[data-testid="goal-title-0"]').element as HTMLInputElement).value).toBe('交付质量')
    expect(wrapper.get('[data-testid="goal-weight-total"]').text()).toContain('100 / 100')

    await wrapper.get('[data-testid="goal-weight-1"]').setValue('30')
    await flushPromises()
    expect(wrapper.get('[data-testid="goal-weight-total"]').text()).toContain('90 / 100')
    expect(wrapper.get('[data-testid="performance-score-save"]').attributes('disabled')).toBeDefined()

    await wrapper.get('[data-testid="goal-weight-1"]').setValue('40')
    await wrapper.get('[data-testid="performance-self-rating"]').setValue('90')
    await flushPromises()
    expect(wrapper.get('[data-testid="performance-score-save"]').attributes('disabled')).toBeUndefined()

    await wrapper.get('[data-testid="performance-score-save"]').trigger('click')
    await wrapper.get('[data-testid="performance-save-confirm-submit"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledTimes(1)
    const [path, body, options] = resource.post.mock.calls[0] as [
      string,
      Record<string, unknown>,
      { idempotencyKey?: string },
    ]
    expect(path).toBe('/v1/performance/reviews')
    expect(body).toMatchObject({
      cycle_id: 9,
      employment_id: 21,
      self_rating: '90',
      goals: [
        expect.objectContaining({ title: '交付质量', weight: 60, self_score: '85' }),
        expect.objectContaining({ title: '客户满意度', weight: 40, self_score: '90' }),
      ],
    })
    expect(options.idempotencyKey).toMatch(/^performance-save-/)
  })

  it('confirms manager submission and posts the real submit endpoint with manager rating', async () => {
    const wrapper = await mountAt(PerformanceCycleDetailPage, '/performance/cycles/9')
    await openDrawer(wrapper)

    await wrapper.get('[data-testid="performance-manager-rating"]').setValue('92')
    await wrapper.get('[data-testid="performance-manager-assessment"]').setValue('合成主管评语')
    await flushPromises()

    await wrapper.get('[data-testid="performance-score-submit"]').trigger('click')
    const confirm = wrapper.get('[data-testid="performance-submit-confirm"]')
    expect(confirm.text()).toContain('提交主管复核')
    await wrapper.get('[data-testid="performance-submit-confirm-submit"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledTimes(1)
    const [path, body, options] = resource.post.mock.calls[0] as [
      string,
      Record<string, unknown>,
      { idempotencyKey?: string },
    ]
    expect(path).toBe('/v1/performance/reviews/31/submit')
    expect(body).toMatchObject({ manager_rating: '92', manager_assessment: '合成主管评语' })
    expect(options.idempotencyKey).toMatch(/^performance-submit-/)
  })

  it('shows a server final rating and never recomputes it when manager input changes', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/performance/cycles') return Promise.resolve(page([cycleRaw()]))
      if (path === '/v1/performance/reviews') {
        return Promise.resolve(page([reviewRaw({ manager_rating: '88', calibrated_rating: 'EXCEEDS', final_rating: 'EXCEEDS' })]))
      }
      return Promise.resolve(page([]))
    })

    const wrapper = await mountAt(PerformanceCycleDetailPage, '/performance/cycles/9')
    await openDrawer(wrapper)

    const final = wrapper.get('[data-testid="performance-final-rating"]')
    expect(final.text()).toContain('超出预期')
    await wrapper.get('[data-testid="performance-manager-rating"]').setValue('10')
    await flushPromises()
    expect(final.text()).toContain('超出预期')
    expect(final.text()).not.toContain('10 分')
  })

  it('renders a submitted review as read-only and offers no save or submit request', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/performance/cycles') return Promise.resolve(page([cycleRaw()]))
      if (path === '/v1/performance/reviews') {
        return Promise.resolve(page([reviewRaw({ status: 'SUBMITTED', manager_rating: '88', final_rating: '88', submitted_at: '2026-11-20T02:00:00Z' })]))
      }
      return Promise.resolve(page([]))
    })

    const wrapper = await mountAt(PerformanceCycleDetailPage, '/performance/cycles/9')

    expect(wrapper.text()).toContain('已提交（只读）')
    const viewButton = wrapper.findAll('button').find((button) => button.text() === '查看')
    expect(viewButton).toBeDefined()
    await viewButton?.trigger('click')
    await flushPromises()
    expect(wrapper.get('[data-testid="performance-score-drawer"]').text()).toContain('只读')
    expect(wrapper.get('[data-testid="performance-self-rating"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid="performance-score-save"]').attributes('disabled')).toBeDefined()
    expect(resource.post).not.toHaveBeenCalled()
  })

  it('renders a published cycle as read-only and blocks saving even with a draft row', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/performance/cycles') {
        return Promise.resolve(page([cycleRaw({ status: 'PUBLISHED', submitted_count: 3, progress: 100 })]))
      }
      if (path === '/v1/performance/reviews') {
        return Promise.resolve(page([reviewRaw({ status: 'SUBMITTED', final_rating: 'MEETS' })]))
      }
      return Promise.resolve(page([]))
    })

    const wrapper = await mountAt(PerformanceCycleDetailPage, '/performance/cycles/9')

    expect(wrapper.get('[data-testid="performance-readonly"]').text()).toContain('只读')
    const viewButton = wrapper.findAll('button').find((button) => button.text() === '查看')
    expect(viewButton).toBeDefined()
    expect(wrapper.get('[data-testid="performance-publish"]').attributes('disabled')).toBeDefined()
    expect(resource.post).not.toHaveBeenCalled()
  })

  it('shows a capability explanation and does not load review rows without review.manage', async () => {
    const wrapper = await mountAt(PerformanceCycleDetailPage, '/performance/cycles/9', [
      'performance.cycle.read',
    ])

    expect(wrapper.get('[data-testid="performance-review-forbidden"]').text()).toContain(
      'performance.review.manage',
    )
    expect(resource.get).toHaveBeenCalledTimes(1)
    expect(resource.get).toHaveBeenCalledWith('/v1/performance/cycles', expect.anything())
  })
})

describe('performance score drawer component', () => {
  it('disables all goal and score inputs when read-only', async () => {
    const wrapper = mount(PerformanceScoreDrawer, {
      props: {
        modelValue: true,
        cycleName: '2026 年度绩效周期',
        review: {
          id: 31,
          cycleId: 9,
          employmentId: 21,
          managerEmploymentId: 20,
          status: 'SUBMITTED',
          selfRating: '85',
          managerRating: '88',
          calibratedRating: 'MEETS',
          finalRating: 'MEETS',
          selfAssessment: '',
          managerAssessment: '合成主管评语',
          submittedAt: '2026-11-20T02:00:00Z',
          goals: [
            {
              id: 1,
              title: '交付质量',
              targetValue: '缺陷率低于 1%',
              weight: 100,
              selfScore: '85',
              managerScore: '88',
              status: 'DRAFT',
            },
          ],
        },
        readOnly: true,
        canManage: true,
        canSubmit: true,
      },
      global: {
        plugins: [ElementPlus],
        stubs: { ElDrawer: OverlayStub, ElDialog: OverlayStub, teleport: true },
      },
    })
    await flushPromises()

    expect(wrapper.get('[data-testid="performance-score-save"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid="performance-score-submit"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid="performance-self-rating"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid="goal-weight-0"]').text()).toContain('100%')
  })
})
