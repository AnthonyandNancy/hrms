import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { defineComponent } from 'vue'
import { createMemoryHistory, createRouter, RouterView } from 'vue-router'
import { ArrowDown, ArrowRight } from '@element-plus/icons-vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import AppShell from '@/layouts/AppShell.vue'
import type { Principal } from '@/api/contracts'
import { PAGE_REGISTRY } from '@/router/registry'
import { useSessionStore } from '@/stores/session'

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

const NAV_EXPANDED_KEY = 'hrms.nav.expanded'
const ALL_MENU_CAPABILITIES = [
  ...new Set(PAGE_REGISTRY.flatMap((page) => (page.menu === null ? [] : [page.capability]))),
]
const ALL_MENU_PAGES = PAGE_REGISTRY
  .filter((page) => page.menu !== null)
  .map((page) => ({ pageCode: page.name, path: page.path }))

const PRINCIPAL: Principal = {
  userId: 101,
  employmentId: 5001,
  displayName: '林若桐',
  email: 'ruotong.lin@demo.example',
  employmentNo: 'DEMO-0101',
  jobTitle: '人力资源业务伙伴',
  orgName: '人力资源部',
  initials: '林',
  isPrivileged: true,
  hasMfa: true,
  authVersion: 7,
  tenantTimezone: 'Asia/Shanghai',
}

/** Shared test doubles; one file, so the test fixture stays explicit. */
// eslint-disable-next-line vue/one-component-per-file
const RoutePage = defineComponent({
  name: 'RoutePage',
  template: '<div data-testid="route-page">页面</div>',
})

// eslint-disable-next-line vue/one-component-per-file
const ShellHost = defineComponent({
  components: { RouterView },
  template: '<RouterView />',
})

const elementStubs = {
  'el-button': { template: '<button type="button"><slot /></button>' },
  'el-icon': { template: '<span><slot /></span>' },
  'el-badge': { template: '<span><slot /></span>' },
  'el-dropdown': { template: '<div><slot /><slot name="dropdown" /></div>' },
  'el-dropdown-menu': { template: '<div><slot /></div>' },
  'el-dropdown-item': { template: '<div><slot /></div>' },
}

async function mountShell(options: {
  path?: string
  capabilities?: string[]
} = {}): Promise<ReturnType<typeof mount>> {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().$patch({
    status: 'authenticated',
    principal: PRINCIPAL,
    capabilities: options.capabilities ?? ALL_MENU_CAPABILITIES,
    pages: ALL_MENU_PAGES,
    csrfToken: 'test-csrf',
    sessionExpiresAt: null,
    mfaEnrollmentRequired: false,
  })

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      {
        path: '/',
        component: AppShell,
        children: [
          {
            path: 'workspace',
            name: 'workspace',
            component: RoutePage,
            meta: { title: '我的工作台', navKey: 'workspace' },
          },
          {
            path: 'system/roles',
            name: 'system-roles',
            component: RoutePage,
            meta: { title: '角色管理', navKey: 'system-roles' },
          },
          {
            path: 'hr/employees',
            name: 'hr-employees',
            component: RoutePage,
            meta: { title: '员工列表', navKey: 'hr-employees' },
          },
          {
            path: 'payroll',
            name: 'payroll',
            component: RoutePage,
            meta: { title: '薪资概览', navKey: 'payroll' },
          },
        ],
      },
    ],
  })
  await router.push(options.path ?? '/workspace')
  await router.isReady()

  const wrapper = mount(ShellHost, {
    global: {
      plugins: [pinia, router],
      stubs: elementStubs,
    },
  })
  await flushPromises()
  return wrapper
}

function groupButton(wrapper: ReturnType<typeof mount>, label: string) {
  const button = wrapper.findAll('button.nav-link--group').find((candidate) => candidate.text().includes(label))
  expect(button, `${label} menu group`).toBeDefined()
  return button!
}

describe('V1.1 expandable shell menu', () => {
  beforeEach(() => {
    window.localStorage.clear()
  })

  it('renders a chevron on every parent and flips aria-expanded/aria-controls when toggled', async () => {
    const wrapper = await mountShell()
    const system = groupButton(wrapper, '系统管理')

    expect(system.findAll('.nav-link__chevron')).toHaveLength(1)
    expect(system.attributes('aria-expanded')).toBe('false')
    expect(system.attributes('aria-controls')).toBe('nav-group-system')
    expect(wrapper.find('#nav-group-system').exists()).toBe(false)
    expect(system.findComponent(ArrowRight).exists()).toBe(true)

    await system.trigger('click')

    expect(system.attributes('aria-expanded')).toBe('true')
    expect(wrapper.find('#nav-group-system').exists()).toBe(true)
    expect(system.findComponent(ArrowDown).exists()).toBe(true)
    expect(system.findComponent(ArrowRight).exists()).toBe(false)

    await system.trigger('click')

    expect(system.attributes('aria-expanded')).toBe('false')
    expect(wrapper.find('#nav-group-system').exists()).toBe(false)
    expect(system.findComponent(ArrowRight).exists()).toBe(true)
  })

  it('renders the chevron and an explicit child icon for every expandable group', async () => {
    window.localStorage.setItem(
      NAV_EXPANDED_KEY,
      JSON.stringify([
        'dashboard',
        'reports',
        'people',
        'organization',
        'lifecycle',
        'contracts',
        'attendance',
        'leave',
        'overtime',
        'travel',
        'payroll',
        'performance',
        'recruitment',
        'candidates',
        'training',
        'engagement',
        'system',
      ]),
    )
    const wrapper = await mountShell()
    const groups = wrapper.findAll('button.nav-link--group')
    const children = wrapper.findAll('.nav-link--child')

    expect(groups).toHaveLength(17)
    expect(groups.every((group) => group.find('.nav-link__chevron').exists())).toBe(true)
    expect(groups.every((group) => group.attributes('aria-expanded') === 'true')).toBe(true)
    expect(groups.every((group) => group.attributes('aria-controls')?.startsWith('nav-group-'))).toBe(true)
    // ADR-0035: the 12 employee/movement/contract/candidate/payroll detail routes are
    // deep links (menu: null), so the sidebar renders menu-bearing pages only.
    // 161 registry keys - 6 base and 12 detail menus = 143.
    expect(children).toHaveLength(ALL_MENU_PAGES.length)
    expect(ALL_MENU_PAGES.length).toBe(143)
    expect(children.every((child) => child.find('.nav-link__child-icon').exists())).toBe(true)
  })

  it('opens the group containing the active route without persisting permission state', async () => {
    const wrapper = await mountShell({ path: '/system/roles', capabilities: ['iam.role.manage'] })
    const system = groupButton(wrapper, '系统管理')
    const active = wrapper.find('.nav-link--child[aria-current="page"]')

    expect(system.attributes('aria-expanded')).toBe('true')
    expect(wrapper.find('#nav-group-system').exists()).toBe(true)
    expect(active.text()).toContain('角色管理')
    expect(active.attributes('href')).toBe('/system/roles')
    expect(window.localStorage.getItem(NAV_EXPANDED_KEY)).toBeNull()
  })

  it('marks the active workspace link with aria-current="page"', async () => {
    const wrapper = await mountShell()
    const workspace = wrapper.find('a.nav-link[href="/workspace"]')

    expect(workspace.attributes('aria-current')).toBe('page')
  })

  it('restores and persists the expanded-group preference under hrms.nav.expanded', async () => {
    window.localStorage.setItem(NAV_EXPANDED_KEY, JSON.stringify(['payroll']))
    const wrapper = await mountShell()
    const payroll = groupButton(wrapper, '薪资管理')

    expect(payroll.attributes('aria-expanded')).toBe('true')
    expect(wrapper.find('#nav-group-payroll').exists()).toBe(true)

    await payroll.trigger('click')

    expect(payroll.attributes('aria-expanded')).toBe('false')
    expect(JSON.parse(window.localStorage.getItem(NAV_EXPANDED_KEY) ?? 'null')).toEqual([])

    await payroll.trigger('click')

    expect(JSON.parse(window.localStorage.getItem(NAV_EXPANDED_KEY) ?? 'null')).toEqual(['payroll'])
  })

  it('never writes capability decisions to storage when a group is toggled', async () => {
    const wrapper = await mountShell({ capabilities: ['iam.role.manage'] })
    const system = groupButton(wrapper, '系统管理')

    await system.trigger('click')

    expect(Object.keys(window.localStorage)).toEqual([NAV_EXPANDED_KEY])
    const stored = window.localStorage.getItem(NAV_EXPANDED_KEY) ?? ''
    expect(JSON.parse(stored)).toEqual(['system'])
    expect(stored).not.toContain('iam.role.manage')
    expect(stored.toLowerCase()).not.toContain('permission')
  })

  it('ignores malformed or non-string persisted expansion values', async () => {
    window.localStorage.setItem(NAV_EXPANDED_KEY, '{"not":"an array"}')
    const wrapper = await mountShell()
    const groups = wrapper.findAll('button.nav-link--group')

    expect(groups.every((group) => group.attributes('aria-expanded') === 'false')).toBe(true)

    window.localStorage.setItem(NAV_EXPANDED_KEY, JSON.stringify(['payroll', 2, null, { key: 'system' }]))
    const rebuilt = await mountShell()
    const payroll = groupButton(rebuilt, '薪资管理')

    expect(payroll.attributes('aria-expanded')).toBe('true')
  })
})
