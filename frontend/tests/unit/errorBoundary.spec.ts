/* eslint-disable vue/one-component-per-file */
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { defineComponent, h, nextTick, onMounted, type PropType } from 'vue'
import { createMemoryHistory, createRouter, RouterView } from 'vue-router'
import { describe, expect, it, vi } from 'vitest'
import App from '@/App.vue'
import { ApiError } from '@/api/errors'
import PageErrorBoundary from '@/components/PageErrorBoundary.vue'
import { useApiFeedback } from '@/composables/useApiFeedback'
import AppShell from '@/layouts/AppShell.vue'
import ForbiddenView from '@/views/ForbiddenView.vue'

vi.mock('@/api', () => ({
  getApi: () => ({
    dashboard: {
      getDashboard: vi.fn().mockResolvedValue({ metrics: [] }),
    },
    notices: {
      listNotices: vi.fn().mockResolvedValue([]),
    },
  }),
}))

const SafePage = defineComponent({
  name: 'SafePage',
  template: '<div data-testid="safe-page">安全页面</div>',
})

const ThrowingRenderPage = defineComponent({
  name: 'ThrowingRenderPage',
  setup() {
    return () => {
      throw new Error('page render exploded')
    }
  },
})

const componentStubs = {
  'el-button': {
    template: '<button type="button"><slot /></button>',
  },
  'el-icon': {
    template: '<span><slot /></span>',
  },
  'el-badge': {
    template: '<span><slot /></span>',
  },
  'el-dropdown': {
    template: '<div><slot /><slot name="dropdown" /></div>',
  },
  'el-dropdown-menu': {
    template: '<div><slot /></div>',
  },
  'el-dropdown-item': {
    template: '<div><slot /></div>',
  },
}

const ShellHost = defineComponent({
  components: { RouterView },
  template: '<RouterView />',
})

async function mountShellRoute(): Promise<{
  wrapper: ReturnType<typeof mount>
  router: ReturnType<typeof createRouter>
}> {
  const pinia = createPinia()
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      {
        path: '/',
        component: AppShell,
        children: [
          { path: 'workspace', name: 'workspace', component: SafePage },
          { path: 'boom', name: 'boom', component: ThrowingRenderPage },
          {
            path: 'lazy',
            name: 'lazy',
            component: () =>
              Promise.reject(new Error('Failed to fetch dynamically imported module: /assets/workspace-old.js')),
          },
          { path: 'forbidden', name: 'forbidden', component: SafePage },
          { path: 'login', name: 'login', component: SafePage },
          { path: 'notices', name: 'notices', component: SafePage },
        ],
      },
    ],
  })
  await router.push('/workspace')
  await router.isReady()
  const wrapper = mount(ShellHost, {
    global: {
      plugins: [pinia, router],
      stubs: componentStubs,
    },
  })
  await flushPromises()

  return { wrapper, router }
}

const FeedbackProbe = defineComponent({
  props: {
    error: {
      type: Object as PropType<unknown>,
      required: true,
    },
  },
  setup(props) {
    const { report, feedback } = useApiFeedback()
    onMounted(() => report(props.error))
    return { feedback }
  },
  template: `
    <div>
      <span data-kind>{{ feedback.kind }}</span>
      <span data-message>{{ feedback.message }}</span>
      <span data-request-id>{{ feedback.requestId ?? '' }}</span>
      <span data-no-active>{{ feedback.noActiveEmployment }}</span>
      <span data-fields>{{ JSON.stringify(feedback.fieldErrors) }}</span>
      <span data-links>{{ feedback.safeLinks.map((link) => link.path).join(',') }}</span>
    </div>
  `,
})

async function mountFeedback(error: unknown): Promise<{
  wrapper: ReturnType<typeof mount>
  router: ReturnType<typeof createRouter>
}> {
  const pinia = createPinia()
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/workspace', name: 'workspace', component: SafePage },
      { path: '/forbidden', name: 'forbidden', component: SafePage },
      { path: '/login', name: 'login', component: SafePage },
    ],
  })
  await router.push('/workspace')
  await router.isReady()
  const wrapper = mount(FeedbackProbe, {
    props: { error },
    global: { plugins: [pinia, router] },
  })
  await flushPromises()
  await nextTick()

  return { wrapper, router }
}

describe('PageErrorBoundary', () => {
  it('catches a descendant render error and shows the request id', async () => {
    const Broken = defineComponent({
      name: 'Broken',
      setup() {
        return () => {
          throw new ApiError('INTERNAL', 'synthetic render failure', {
            status: 500,
            requestId: 'req-render-9f3a',
          })
        }
      },
    })
    const Host = defineComponent({
      components: { PageErrorBoundary, Broken },
      template: '<PageErrorBoundary><Broken /></PageErrorBoundary>',
    })

    const wrapper = mount(Host, { global: { stubs: componentStubs } })
    await nextTick()

    expect(wrapper.text()).toContain('页面暂时无法显示')
    expect(wrapper.text()).toContain('请求编号：req-render-9f3a')
    expect(wrapper.find('.error-state').exists()).toBe(true)
    expect(wrapper.findComponent(PageErrorBoundary).emitted()).toEqual({})
  })

  it('retries by resetting the boundary instead of clearing the shell', async () => {
    let shouldThrow = true
    const FlakyPage = defineComponent({
      name: 'FlakyPage',
      setup() {
        return () => {
          if (shouldThrow) throw new Error('transient render failure')
          return h('div', { 'data-testid': 'recovered-page' }, '页面已恢复')
        }
      },
    })
    const Host = defineComponent({
      components: { PageErrorBoundary, FlakyPage },
      template: '<PageErrorBoundary><FlakyPage /></PageErrorBoundary>',
    })

    const wrapper = mount(Host, { global: { stubs: componentStubs } })
    await nextTick()
    expect(wrapper.text()).toContain('页面暂时无法显示')

    shouldThrow = false
    await wrapper.find('button').trigger('click')
    await nextTick()

    expect(wrapper.text()).toContain('页面已恢复')
    expect(wrapper.text()).not.toContain('页面暂时无法显示')
  })

  it('keeps the shell navigation when a child page throws', async () => {
    const { wrapper, router } = await mountShellRoute()

    await router.push('/boom')
    await flushPromises()
    await nextTick()

    expect(wrapper.find('.sidebar').exists()).toBe(true)
    expect(wrapper.find('.topbar').exists()).toBe(true)
    expect(wrapper.text()).toContain('页面暂时无法显示')
  })

  it('shows a local refresh panel for a lazy chunk load failure', async () => {
    const { wrapper, router } = await mountShellRoute()

    await router.push('/lazy').catch(() => undefined)
    await flushPromises()
    await nextTick()

    expect(wrapper.find('.sidebar').exists()).toBe(true)
    expect(wrapper.text()).toContain('页面版本已更新，请刷新')
    expect(wrapper.text()).toContain('刷新页面')
    expect(wrapper.find('.boundary').exists()).toBe(false)
  })
})

describe('App last-resort boundary', () => {
  it('clears the boundary when the route changes', async () => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/boom', component: ThrowingRenderPage },
        { path: '/safe', component: SafePage },
      ],
    })
    await router.push('/boom')
    await router.isReady()

    const wrapper = mount(App, {
      global: { plugins: [router], stubs: componentStubs },
    })
    await flushPromises()
    await nextTick()
    expect(wrapper.find('.boundary').exists()).toBe(true)

    await router.push('/safe')
    await flushPromises()
    await nextTick()

    expect(wrapper.find('.boundary').exists()).toBe(false)
    expect(wrapper.find('[data-testid="safe-page"]').exists()).toBe(true)
  })
})

describe('useApiFeedback', () => {
  it('classifies NO_ACTIVE_EMPLOYMENT with safe links and the dedicated empty-state copy', async () => {
    const { wrapper } = await mountFeedback(
      new ApiError('NO_ACTIVE_EMPLOYMENT', 'The current account is not linked to an active employment.', {
        status: 409,
        requestId: 'req-no-employment-1',
      }),
    )

    expect(wrapper.find('[data-kind]').text()).toBe('no_active_employment')
    expect(wrapper.find('[data-message]').text()).toBe('当前账号未绑定员工，员工自助功能不可用')
    expect(wrapper.find('[data-request-id]').text()).toBe('req-no-employment-1')
    expect(wrapper.find('[data-no-active]').text()).toBe('true')
    expect(wrapper.find('[data-links]').text()).toBe('/system/users,/hr/employees')
  })

  it('classifies a 403 with its request id and opens the safe forbidden route', async () => {
    const { wrapper, router } = await mountFeedback(
      new ApiError('FORBIDDEN', 'You are not allowed to perform this action.', {
        status: 403,
        requestId: 'req-forbidden-42',
      }),
    )

    expect(wrapper.find('[data-kind]').text()).toBe('forbidden')
    expect(wrapper.find('[data-request-id]').text()).toBe('req-forbidden-42')
    expect(router.currentRoute.value.name).toBe('forbidden')
    expect(router.currentRoute.value.query.request_id).toBe('req-forbidden-42')
  })

  it('sends a 401 to login exactly once and expires the session', async () => {
    const { wrapper, router } = await mountFeedback(
      new ApiError('UNAUTHENTICATED', 'Authentication is required.', {
        status: 401,
        requestId: 'req-login-401',
      }),
    )

    expect(wrapper.find('[data-kind]').text()).toBe('authentication')
    expect(wrapper.find('[data-request-id]').text()).toBe('req-login-401')
    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query.expired).toBe('1')
  })

  it('classifies 422 field errors without escalating to a global message', async () => {
    const { wrapper } = await mountFeedback(
      new ApiError('VALIDATION_FAILED', '提交内容未通过校验', {
        status: 422,
        requestId: 'req-fields-7',
        fieldErrors: { reason: ['请假事由至少 5 个字'] },
      }),
    )

    expect(wrapper.find('[data-kind]').text()).toBe('validation')
    expect(wrapper.find('[data-request-id]').text()).toBe('req-fields-7')
    expect(JSON.parse(wrapper.find('[data-fields]').text())).toEqual({
      reason: ['请假事由至少 5 个字'],
    })
  })

  it('classifies 5xx failures as server feedback with a request id', async () => {
    const { wrapper } = await mountFeedback(
      new ApiError('INTERNAL', 'An unexpected error occurred.', {
        status: 500,
        requestId: 'req-server-500',
      }),
    )

    expect(wrapper.find('[data-kind]').text()).toBe('server')
    expect(wrapper.find('[data-request-id]').text()).toBe('req-server-500')
  })
})

describe('ForbiddenView safe page', () => {
  it('shows the request id carried from the failed API call', async () => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/forbidden', name: 'forbidden', component: ForbiddenView },
      ],
    })
    await router.push({
      path: '/forbidden',
      query: { from: '/employees', request_id: 'req-forbidden-safe-page' },
    })
    await router.isReady()

    const wrapper = mount(ForbiddenView, {
      global: { plugins: [router], stubs: componentStubs },
    })

    expect(wrapper.text()).toContain('请求编号：req-forbidden-safe-page')
    expect(wrapper.text()).toContain('/employees')
  })
})
