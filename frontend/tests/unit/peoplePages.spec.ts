import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter, type RouteRecordRaw } from 'vue-router'
import ElementPlus from 'element-plus'
import type { Component } from 'vue'
import { ApiError } from '@/api/errors'
import { PAGE_REGISTRY } from '@/router/registry'

const resource = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  patch: vi.fn(),
}))

vi.mock('@/api/resourceClient', () => ({
  resourceGet: resource.get,
  resourcePost: resource.post,
  resourcePatch: resource.patch,
}))

import { PEOPLE_COMPONENT_KEYS, peopleComponentFor } from '@/views/people'

const wave2 = PAGE_REGISTRY.filter((page) => page.wave === 'Wave 2')
const wave2Keys = wave2.map((page) => page.componentKey)

function listResponse(items: unknown[] = []) {
  return { items, page: 1, page_size: 20, total: items.length }
}

const employee = {
  employment_id: 1,
  person_id: 9,
  person_name: 'Synthetic Employee',
  employee_no: 'E1001',
  legal_entity_id: 1,
  status: 'ACTIVE',
  employment_type: 'FULL_TIME',
  valid_from: '2026-01-01',
  valid_to: '9999-12-31',
  current_assignment: {
    id: 11,
    org_unit_id: 10,
    position_id: 4,
    assignment_type: 'PRIMARY',
    is_primary: true,
    valid_from: '2026-01-01',
    valid_to: '9999-12-31',
  },
  manager_employment_id: null,
}

const department = {
  id: 10,
  legal_entity_id: 1,
  parent_id: null,
  code: 'DEP-010',
  name: 'Synthetic Department',
  type: 'DEPARTMENT',
  status: 'ACTIVE',
  valid_from: '2026-01-01',
  valid_to: '9999-12-31',
}

const position = {
  id: 4,
  legal_entity_id: 1,
  org_unit_id: 10,
  code: 'POS-004',
  title: 'Synthetic Engineer',
  grade: 'P5',
  headcount_limit: 3,
  occupancy: 1,
  vacancies: 2,
  status: 'ACTIVE',
  valid_from: '2026-01-01',
  valid_to: '9999-12-31',
}

const lifecycleCase = {
  case_id: 88,
  case_no: 'CASE-2026-0088',
  case_type: 'ASSIGNMENT_CHANGE',
  domain_status: 'SUBMITTED',
  application_status: 'IN_APPROVAL',
  effect_status: 'SCHEDULED',
  subject_type: 'employment',
  subject_id: 1,
  employment_id: 1,
  result_employment_id: null,
  effective_at: '2026-12-01T00:00:00Z',
  recorded_at: '2026-11-20T08:00:00Z',
}

const contract = {
  id: 31,
  employment_id: 1,
  contract_no: 'CON-2026-0031',
  contract_type: 'FIXED_TERM',
  start_date: '2026-01-01',
  end_date: '2026-12-31',
  signed_on: '2025-12-20',
  status: 'ACTIVE',
  version: 1,
  previous_contract_id: null,
  renewed_by_id: null,
  days_to_expiry: 36,
}

async function mountAt(key: string, path: string) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const dynamic = wave2.map<RouteRecordRaw>((page) => ({
    path: page.path,
    name: page.name,
    component: { template: '<div />' },
    meta: { registry: page },
  }))
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      ...dynamic,
      { path: '/login', name: 'login', component: { template: '<div />' } },
      { path: '/forbidden', name: 'forbidden', component: { template: '<div />' } },
    ],
  })
  await router.push(path)
  await router.isReady()

  const component = peopleComponentFor(key)
  if (component === null) throw new Error(`No people component registered for ${key}`)
  return mount(component as Component, {
    global: { plugins: [pinia, router, ElementPlus] },
  })
}

beforeEach(() => {
  resource.get.mockReset()
  resource.post.mockReset()
  resource.patch.mockReset()
})

describe('Wave 2 people domain components', () => {
  it('maps all 39 Wave 2 registry component keys to real components', () => {
    expect(PEOPLE_COMPONENT_KEYS).toHaveLength(39)
    expect(new Set(PEOPLE_COMPONENT_KEYS).size).toBe(39)
    expect([...PEOPLE_COMPONENT_KEYS].sort()).toEqual([...wave2Keys].sort())
    for (const key of wave2Keys) {
      expect(peopleComponentFor(key), key).not.toBeNull()
    }
    expect(peopleComponentFor('hr/employees/not-real')).toBeNull()
  })

  it('loads the employee list from GET /v1/employees', async () => {
    resource.get.mockResolvedValue(listResponse([employee]))

    const wrapper = await mountAt('hr/employees/index', '/hr/employees')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/employees', expect.objectContaining({
      page: expect.any(Number),
      page_size: expect.any(Number),
      as_of: expect.any(String),
    }))
    expect(wrapper.text()).toContain('Synthetic Employee')
    expect(wrapper.text()).toContain('E1001')
    expect(wrapper.text()).toContain('ACTIVE')
  })

  it('loads education for the employment id in the route and submits a real profile row', async () => {
    resource.get.mockResolvedValue(listResponse([
      { id: 51, employment_id: 1, school: 'Synthetic University', degree: 'BACHELOR', major: 'Computer Science', start_date: '2010-09-01', end_date: '2014-06-30', notes: null },
    ]))
    resource.post.mockResolvedValue({ id: 52, employment_id: 1, school: 'Synthetic College', degree: 'MASTER', start_date: '2015-09-01' })

    const wrapper = await mountAt('hr/employees/by-id/education', '/hr/employees/1/education')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/employees/1/education', expect.any(Object))
    expect(wrapper.text()).toContain('Synthetic University')

    await wrapper.get('[data-testid="education-school"]').setValue('Synthetic College')
    await wrapper.get('[data-testid="education-degree"]').setValue('MASTER')
    await wrapper.get('[data-testid="education-start"]').setValue('2015-09-01')
    await wrapper.get('[data-testid="education-submit"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/employees/1/education',
      expect.objectContaining({ school: 'Synthetic College', degree: 'MASTER', start_date: '2015-09-01' }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
  })

  it('loads the organization tree, selects a department, and loads its members', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/org-units/tree') return Promise.resolve(listResponse([department]))
      if (path === '/v1/departments/10/members') {
        return Promise.resolve(listResponse([
          { employment_id: 1, assignment_id: 11, assignment_type: 'PRIMARY', is_primary: true, valid_from: '2026-01-01', valid_to: '9999-12-31' },
        ]))
      }
      return Promise.resolve(listResponse())
    })

    const wrapper = await mountAt('hr/departments/members', '/hr/departments/members')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/org-units/tree', expect.objectContaining({ legal_entity_id: 1 }))
    expect(resource.get).toHaveBeenCalledWith('/v1/departments/10/members', expect.any(Object))
    expect(wrapper.text()).toContain('1')
    expect(wrapper.text()).toContain('PRIMARY')
  })

  it('loads position headcount with real occupancy and vacancy values', async () => {
    resource.get.mockResolvedValue(listResponse([position]))

    const wrapper = await mountAt('hr/positions/headcount', '/hr/positions/headcount')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/positions', expect.objectContaining({ legal_entity_id: 1 }))
    expect(wrapper.text()).toContain('POS-004')
    expect(wrapper.text()).toContain('Synthetic Engineer')
    expect(wrapper.text()).toContain('2')
  })

  it('loads movement cases from the live lifecycle collection endpoint', async () => {
    resource.get.mockResolvedValue(listResponse([lifecycleCase]))

    const wrapper = await mountAt('hr/movements/index', '/hr/movements')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/lifecycle/cases', expect.objectContaining({
      page: expect.any(Number),
      page_size: expect.any(Number),
    }))
    expect(wrapper.text()).toContain('CASE-2026-0088')
    expect(wrapper.text()).toContain('ASSIGNMENT_CHANGE')
  })

  it('loads the contract ledger for real employees from per-employment contract endpoints', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/employees') return Promise.resolve(listResponse([employee]))
      if (path === '/v1/employments/1/contracts') return Promise.resolve(listResponse([contract]))
      return Promise.resolve(listResponse())
    })

    const wrapper = await mountAt('hr/contracts/index', '/hr/contracts')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/employees', expect.any(Object))
    expect(resource.get).toHaveBeenCalledWith('/v1/employments/1/contracts', {})
    expect(wrapper.text()).toContain('CON-2026-0031')
  })

  it('renders contact phones as masked P2 values and never falls back to plaintext', async () => {
    resource.get.mockResolvedValue(listResponse([
      {
        id: 71,
        employment_id: 1,
        name: 'Synthetic Contact',
        relationship: 'SPOUSE',
        phone: { display: null, masked: true, profile: 'P2_SENSITIVE', revealable: false, exportable: false },
        phone_ciphertext: '+8613900000000',
        email: 'synthetic.contact@example.test',
        is_emergency: true,
        is_primary: true,
      },
    ]))

    const wrapper = await mountAt('hr/employees/by-id/contacts', '/hr/employees/1/contacts')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/employees/1/contacts', expect.any(Object))
    expect(wrapper.text()).toContain('Synthetic Contact')
    expect(wrapper.text()).toContain('已遮罩')
    expect(wrapper.text()).toContain('P2')
    expect(wrapper.text()).not.toContain('+8613900000000')
  })

  it('shows the dedicated no-active-employment state with the 409 request id', async () => {
    resource.get.mockRejectedValue(
      new ApiError('NO_ACTIVE_EMPLOYMENT', 'No active employment', { status: 409, requestId: 'req-people-409' }),
    )

    const wrapper = await mountAt('hr/employees/by-id', '/hr/employees/1')
    await flushPromises()

    expect(wrapper.find('[data-testid="no-active-employment"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('当前账号未绑定员工')
    expect(wrapper.text()).toContain('req-people-409')
  })

  it('preserves the request id on a 5xx page failure', async () => {
    resource.get.mockRejectedValue(
      new ApiError('INTERNAL', 'Synthetic outage', { status: 500, requestId: 'req-people-500' }),
    )

    const wrapper = await mountAt('hr/employees/index', '/hr/employees')
    await flushPromises()

    expect(wrapper.get('[data-testid="request-id"]').text()).toContain('req-people-500')
    expect(wrapper.text()).not.toContain('当前账号未绑定员工')
  })

  it('shows an honest API-gap banner for a page whose backend endpoint does not exist', async () => {
    resource.get.mockResolvedValue(listResponse([contract]))

    const wrapper = await mountAt('hr/contracts/templates', '/hr/contracts/templates')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/employments/contracts/expiring', expect.any(Object))
    expect(wrapper.text()).toContain('接口缺口')
    expect(wrapper.text()).toContain('合同模板')
  })
})
