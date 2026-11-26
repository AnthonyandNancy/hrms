import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter, type RouteRecordRaw } from 'vue-router'
import ElementPlus from 'element-plus'
import type { Component } from 'vue'
import { ApiError } from '@/api/errors'
import { PAGE_REGISTRY } from '@/router/registry'
import { useSessionStore } from '@/stores/session'

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

import { PAGE_COMPONENTS } from '@/modules/people/pages'
import { PEOPLE_COMPONENT_KEYS } from '@/modules/people/catalog'

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

const contractTemplate = {
  id: 81,
  code: 'CN-FIXED',
  name: 'Synthetic fixed-term template',
  version: 2,
  status: 'PUBLISHED',
  body: 'Synthetic template body',
  published_at: '2026-11-20T08:00:00Z',
}

const positionRequirement = {
  id: 301,
  position_id: 4,
  version: 2,
  requirements_json: { education: 'BACHELOR', years: 3 },
  effective_from: '2026-12-01',
  recorded_at: '2026-11-20T08:00:00Z',
}

const positionGrade = {
  id: 71,
  code: 'P5',
  name: 'Professional 5',
  level: 5,
  active: true,
}

const secondment = {
  assignment_id: 91,
  employment_id: 1,
  employee_no: 'E1001',
  person_name: 'Synthetic Employee',
  host_org_unit_id: 12,
  position_id: null,
  assignment_type: 'SECONDMENT',
  is_primary: false,
  valid_from: '2026-12-01',
  valid_to: '2027-03-31',
}

const positionStaffRow = {
  assignment_id: 11,
  employment_id: 1,
  employee_no: 'E1001',
  person_name: 'Synthetic Employee',
  org_unit_id: 10,
  position_id: 4,
  position_code: 'POS-004',
  position_title: 'Synthetic Engineer',
  assignment_type: 'PRIMARY',
  is_primary: true,
  valid_from: '2026-01-01',
  valid_to: '9999-12-31',
}

function projectionResponse(items: unknown[] = []) {
  return {
    items,
    page: 1,
    page_size: 20,
    total: items.length,
    as_of: '2026-11-25',
    freshness: 'LIVE',
    source_hash: 'a'.repeat(64),
    partial: false,
    partial_reason: null,
    drilldown: null,
  }
}

async function mountAt(key: string, path: string, capabilities: string[] = []) {
  const pinia = createPinia()
  setActivePinia(pinia)

  // Dedicated workspaces gate on the session capability set (Volume 3).
  useSessionStore().capabilities = capabilities.length > 0
    ? capabilities
    : ['lifecycle.case.read', 'workflow.case.read', 'people.contract.read']
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

  const loader = PAGE_COMPONENTS[key as keyof typeof PAGE_COMPONENTS]
  if (loader === undefined) throw new Error(`No people component registered for ${key}`)
  const module = await loader()
  return mount(module.default as Component, {
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
      expect(PAGE_COMPONENTS[key as keyof typeof PAGE_COMPONENTS], key).toBeTypeOf('function')
    }
    expect(PAGE_COMPONENTS).not.toHaveProperty('hr/employees/not-real')
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
    // The rebuilt workspace presents the Chinese status label, never the raw
    // server enum. The row still proves the status column rendered.
    expect(wrapper.text()).toContain('在职')
    expect(wrapper.text()).not.toContain('ACTIVE')
  })

  it('marks archived employee rows instead of hiding them silently', async () => {
    resource.get.mockResolvedValue(listResponse([
      {
        ...employee,
        employment_id: 2,
        employee_no: 'E1002',
        person_name: 'Archived Synthetic',
        archived_at: '2026-02-01T00:00:00Z',
      },
    ]))

    const wrapper = await mountAt('hr/employees/index', '/hr/employees')
    await flushPromises()

    expect(wrapper.text()).toContain('Archived Synthetic')
    expect(wrapper.text()).toContain('已归档')
    expect(wrapper.find('[data-testid="employee-row-2"]').exists()).toBe(true)
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

    expect(resource.get).toHaveBeenCalledWith('/v1/org-units/tree', expect.objectContaining({ as_of: expect.any(String) }))
    expect(resource.get).toHaveBeenCalledWith('/v1/departments/10/members', expect.any(Object))
    expect(wrapper.text()).toContain('1')
    expect(wrapper.text()).toContain('PRIMARY')
  })

  it('loads position headcount with real occupancy and vacancy values', async () => {
    resource.get.mockResolvedValue(listResponse([position]))

    const wrapper = await mountAt('hr/positions/headcount', '/hr/positions/headcount')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/positions', expect.objectContaining({ as_of: expect.any(String) }))
    expect(wrapper.text()).toContain('POS-004')
    expect(wrapper.text()).toContain('Synthetic Engineer')
    expect(wrapper.text()).toContain('2')
  })

  it('loads movement cases from the live lifecycle collection endpoint', async () => {
    resource.get.mockResolvedValue(listResponse([lifecycleCase]))

    const wrapper = await mountAt('hr/movements/index', '/hr/movements', ['lifecycle.case.read'])
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/lifecycle/cases', expect.objectContaining({
      page: expect.any(Number),
      page_size: expect.any(Number),
    }))
    expect(wrapper.text()).toContain('CASE-2026-0088')
    expect(wrapper.text()).toContain('ASSIGNMENT_CHANGE')
  })

  it('loads the server-side contract ledger projection', async () => {
    resource.get.mockResolvedValue(projectionResponse([contract]))

    const wrapper = await mountAt('hr/contracts/index', '/hr/contracts')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/employments/contracts', expect.objectContaining({
      as_of: expect.any(String),
      page: 1,
      page_size: 20,
    }))
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

    // Volume 3's dedicated detail workspace classifies this 409 through the
    // shared error state rather than the old PeopleWorkspace-only panel. The
    // coverage is unchanged: the Chinese no-active-employment copy and the
    // request id must both survive.
    expect(wrapper.find('.error-state').exists()).toBe(true)
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

  it('loads contract templates from the versioned template endpoint without a gap banner', async () => {
    resource.get.mockResolvedValue(projectionResponse([contractTemplate]))

    const wrapper = await mountAt('hr/contracts/templates', '/hr/contracts/templates')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/contract-templates', expect.objectContaining({ page: 1, page_size: 20 }))
    expect(wrapper.text()).toContain('CN-FIXED')
    expect(wrapper.text()).not.toContain('接口缺口')
  })

  it('creates, publishes, amends and terminates contracts through idempotent write endpoints', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/employments/contracts') return Promise.resolve(projectionResponse([contract]))
      if (path === '/v1/employment-contracts/31/amendments') return Promise.resolve(projectionResponse([]))
      if (path === '/v1/contract-templates') return Promise.resolve(projectionResponse([contractTemplate]))
      return Promise.resolve(projectionResponse())
    })
    resource.post.mockImplementation((path: string) => {
      if (path === '/v1/employment-contracts/31/amendments') {
        return Promise.resolve({ contract, version: { id: 101, change_type: 'AMENDMENT' } })
      }
      if (path === '/v1/employment-contracts/31/terminate') {
        return Promise.resolve({ contract: { ...contract, status: 'TERMINATED' }, version: { id: 102, change_type: 'TERMINATION' } })
      }
      if (path === '/v1/contract-templates') {
        return Promise.resolve({ ...contractTemplate, id: 82, version: 3, status: 'DRAFT', published_at: null })
      }
      if (path === '/v1/contract-templates/82/publish') {
        return Promise.resolve({ ...contractTemplate, id: 82, version: 3, status: 'PUBLISHED' })
      }
      return Promise.resolve({})
    })

    const changes = await mountAt('hr/contracts/changes', '/hr/contracts/changes')
    await flushPromises()

    await changes.get('[data-testid="contract-amend-id"]').setValue('31')
    await changes.get('[data-testid="contract-amend-effective"]').setValue('2026-06-01')
    await changes.get('[data-testid="contract-amend-reason"]').setValue('Synthetic amendment')
    await changes.get('[data-testid="contract-amend-type"]').setValue('OPEN_ENDED')
    await changes.get('[data-testid="contract-amend-submit"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/employment-contracts/31/amendments',
      expect.objectContaining({
        effective_date: '2026-06-01',
        reason: 'Synthetic amendment',
        changes: expect.objectContaining({ contract_type: 'OPEN_ENDED' }),
      }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )

    await changes.get('[data-testid="contract-amend-effective"]').setValue('2026-09-30')
    await changes.get('[data-testid="contract-amend-reason"]').setValue('Synthetic termination')
    await changes.get('[data-testid="contract-terminate-submit"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/employment-contracts/31/terminate',
      { effective_date: '2026-09-30', reason: 'Synthetic termination' },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )

    const templates = await mountAt('hr/contracts/templates', '/hr/contracts/templates')
    await flushPromises()

    await templates.get('[data-testid="contract-template-code"]').setValue('CN-FIXED')
    await templates.get('[data-testid="contract-template-name"]').setValue('Synthetic fixed-term template')
    await templates.get('[data-testid="contract-template-body"]').setValue('Synthetic template body')
    await templates.get('[data-testid="contract-template-submit"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/contract-templates',
      { code: 'CN-FIXED', name: 'Synthetic fixed-term template', body: 'Synthetic template body' },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )

    await templates.get('[data-testid="contract-template-publish-id"]').setValue('82')
    await templates.get('[data-testid="contract-template-publish"]').trigger('click')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/contract-templates/82/publish',
      {},
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
  })
  it('reads position requirements from the versioned sub-resource and appends a new version', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/positions') return Promise.resolve(listResponse([position]))
      if (path === '/v1/positions/4/requirements') return Promise.resolve(projectionResponse([positionRequirement]))
      return Promise.resolve(listResponse())
    })
    resource.post.mockResolvedValue({ ...positionRequirement, id: 302, version: 3 })

    const wrapper = await mountAt('hr/positions/requirements', '/hr/positions/requirements')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/positions/4/requirements', expect.any(Object))
    expect(wrapper.text()).toContain('POS-004')
    expect(wrapper.text()).toContain('BACHELOR')
    expect(wrapper.find('[data-testid="people-api-gap"]').exists()).toBe(false)

    await wrapper.get('[data-testid="position-requirement-position"]').setValue('4')
    await wrapper.get('[data-testid="position-requirement-effective"]').setValue('2027-01-01')
    await wrapper.get('[data-testid="position-requirement-education"]').setValue('MASTER')
    await wrapper.get('[data-testid="position-requirement-years"]').setValue('5')
    await wrapper.get('.people-form').trigger('submit')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/positions/4/requirements',
      expect.objectContaining({
        effective_from: '2027-01-01',
        requirements: expect.objectContaining({ education: 'MASTER', years: 5 }),
      }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
  })

  it('reads and upserts the company grade catalogue without a gap banner', async () => {
    resource.get.mockResolvedValue(projectionResponse([positionGrade]))
    resource.post.mockResolvedValue({ ...positionGrade, level: 6 })

    const wrapper = await mountAt('hr/positions/grades', '/hr/positions/grades')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/positions/grades', expect.objectContaining({ as_of: expect.any(String) }))
    expect(wrapper.text()).toContain('P5')
    expect(wrapper.text()).toContain('Professional 5')
    expect(wrapper.find('[data-testid="people-api-gap"]').exists()).toBe(false)

    await wrapper.get('[data-testid="position-grade-code"]').setValue('P6')
    await wrapper.get('[data-testid="position-grade-name"]').setValue('Professional 6')
    await wrapper.get('[data-testid="position-grade-level"]').setValue('6')
    await wrapper.get('.people-form').trigger('submit')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/positions/grades',
      expect.objectContaining({ code: 'P6', name: 'Professional 6', level: 6, active: true }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
  })

  it('reads position staff from the position sub-resource instead of matching the employee list', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/positions') return Promise.resolve(listResponse([position]))
      if (path === '/v1/positions/4/staff') return Promise.resolve(projectionResponse([positionStaffRow]))
      return Promise.resolve(listResponse())
    })

    const wrapper = await mountAt('hr/positions/staff', '/hr/positions/staff')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/positions/4/staff', expect.any(Object))
    expect(resource.get).not.toHaveBeenCalledWith('/v1/employees', expect.anything())
    expect(wrapper.text()).toContain('E1001')
    expect(wrapper.find('[data-testid="people-api-gap"]').exists()).toBe(false)
  })

  it('reads and starts secondments through the lifecycle command', async () => {
    resource.get.mockImplementation((path: string) => {
      if (path === '/v1/lifecycle/secondments') return Promise.resolve(projectionResponse([secondment]))
      return Promise.resolve(listResponse())
    })
    resource.post.mockResolvedValue({ ...secondment, assignment_id: 92 })

    const wrapper = await mountAt('hr/movements/secondment', '/hr/movements/secondment')
    await flushPromises()

    expect(resource.get).toHaveBeenCalledWith('/v1/lifecycle/secondments', expect.objectContaining({
      as_of: expect.any(String),
      page: 1,
      page_size: 20,
    }))
    expect(wrapper.text()).toContain('SECONDMENT')
    expect(wrapper.find('[data-testid="people-api-gap"]').exists()).toBe(false)

    await wrapper.get('[data-testid="movement-employment-id"]').setValue('1')
    await wrapper.get('[data-testid="movement-host-org"]').setValue('12')
    await wrapper.get('[data-testid="movement-effective-date"]').setValue('2026-12-01')
    await wrapper.get('[data-testid="movement-secondment-to"]').setValue('2027-03-31')
    await wrapper.get('[data-testid="movement-reason"]').setValue('Synthetic secondment')
    await wrapper.get('.people-form').trigger('submit')
    await flushPromises()

    expect(resource.post).toHaveBeenCalledWith(
      '/v1/lifecycle/secondments',
      {
        employment_id: 1,
        host_org_unit_id: 12,
        position_id: null,
        effective_from: '2026-12-01',
        effective_to: '2027-03-31',
        reason: 'Synthetic secondment',
      },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    )
  })
})
