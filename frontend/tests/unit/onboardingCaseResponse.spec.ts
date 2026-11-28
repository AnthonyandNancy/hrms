import { describe, expect, it, vi } from 'vitest'
import { onboardEmployee } from '@/modules/people/employees/api'

const post = vi.fn()

vi.mock('@/api/resourceClient', () => ({
  resourcePost: (...args: unknown[]) => post(...args),
}))

const pendingCase = {
  case_id: 42,
  case_no: 'CASE-ONB-0042',
  case_type: 'ONBOARDING',
  application_status: 'SUBMITTED',
  effect_status: 'NONE',
  canonical_endpoint: '/v1/lifecycle/onboarding',
  replayed: false,
}

const payload = {
  person: {
    given_name: '合成',
    family_name: '员工',
    email: null,
    mobile: null,
    national_id: null,
    id_type: null,
    birth_date: null,
  },
  employment: {
    employee_no: 'E0007',
    hire_date: '2026-12-01',
    employment_type: 'FULL_TIME',
  },
  assignment: {
    org_unit_id: 10,
    position_id: null,
    valid_from: null,
  },
  terms: {
    salary_minor: 100000,
    currency: 'CNY',
    work_location: null,
    work_schedule: null,
    effective_from: null,
  },
}

describe('onboardEmployee', () => {
  it('returns the pending Case and never fabricates an employee id', async () => {
    post.mockResolvedValue(pendingCase)

    const result = await onboardEmployee(payload, 'onboard-key-1')

    expect(post).toHaveBeenCalledWith('/v1/employees/onboard', payload, { idempotencyKey: 'onboard-key-1' })
    expect(result).toEqual(pendingCase)
    expect(result).not.toHaveProperty('employment_id')
    expect(result).not.toHaveProperty('person_id')
  })

  it('rejects a legacy or malformed success body that has no pending case', async () => {
    post.mockResolvedValue({
      person_id: 3,
      employment_id: 7,
      employee_no: 'E0007',
      assignment_id: 4,
      terms_id: 5,
      replayed: false,
    })

    await expect(onboardEmployee(payload, 'onboard-key-2')).rejects.toThrow('未返回待审批入职 Case')
  })
})
