import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@/api/resourceClient', () => ({
  resourceGet: vi.fn(),
  resourcePost: vi.fn(),
  resourcePatch: vi.fn(),
}))

import {
  normaliseCandidate,
  normaliseCandidateApplication,
  type Candidate,
} from '@/modules/talent/recruiting/api'

describe('M16 candidate application contract', () => {
  it('normalises the real application id and stage instead of inventing a requisition', () => {
    const candidate = normaliseCandidate({
      candidate_id: 41,
      display_name: '合成候选人',
      status: 'SCREENING',
      has_email: true,
      has_mobile: false,
      source: 'REFERRAL',
      application_id: 73,
      application_stage: 'SCREENING',
      requisition_id: 12,
      onboarding_case_id: null,
      conversion_status: null,
      offer_id: null,
      offer_status: null,
      base_salary_minor: null,
      salary_currency: null,
      offer_start_date: null,
      next_interview_at: null,
      converted_employment_id: null,
      converted_employee_no: null,
      recorded_at: '2026-11-27T01:00:00Z',
    } satisfies Record<string, unknown>)

    expect(candidate.applicationId).toBe(73)
    expect(candidate.applicationStage).toBe('SCREENING')
    expect(candidate.requisitionId).toBe(12)
    expect(candidate.onboardingCaseId).toBeNull()
  })

  it('keeps unknown contact values absent and never synthesises a requisition', () => {
    const candidate = normaliseCandidate({
      candidate_id: 42,
      display_name: '合成候选人二',
      status: 'NEW',
      has_email: false,
      has_mobile: false,
      source: null,
      application_id: null,
      application_stage: null,
      requisition_id: null,
      offer_id: null,
      offer_status: null,
      base_salary_minor: null,
      salary_currency: null,
      offer_start_date: null,
      next_interview_at: null,
      converted_employment_id: null,
      converted_employee_no: null,
      recorded_at: '2026-11-27T01:00:00Z',
    } satisfies Record<string, unknown>)

    expect(candidate.requisitionId).toBeNull()
    expect(candidate.applicationId).toBeNull()
    expect(candidate.hasEmail).toBe(false)
    expect(candidate.hasMobile).toBe(false)
  })

  it('reads applications from the dedicated endpoint and preserves the requisition title', () => {
    const application = normaliseCandidateApplication({
      application_id: 9,
      candidate_id: 41,
      requisition_id: 12,
      opening_id: 3,
      stage: 'INTERVIEW',
      status: 'ACTIVE',
      source: 'INTERNAL',
      recorded_at: '2026-11-27T01:00:00Z',
      requisition_no: 'REQ-12',
      requisition_title: '合成岗位',
      requisition_status: 'APPROVED',
    })

    expect(application.id).toBe(9)
    expect(application.requisitionId).toBe(12)
    expect(application.openingId).toBe(3)
    expect(application.requisitionTitle).toBe('合成岗位')
    expect(application.requisitionStatus).toBe('APPROVED')
  })

  it('keeps the candidate mapping type explicit for the eight-tab detail view', () => {
    const candidate = {
      applicationId: 9,
      applicationStage: 'INTERVIEW',
      onboardingCaseId: 55,
    } satisfies Partial<Candidate>

    expect(candidate.onboardingCaseId).toBe(55)
  })
})

describe('M16 frontend removal guard', () => {
  it('removes the 13 folded recruitment entries from the Talent barrel', () => {
    const barrel = readFileSync(resolve(process.cwd(), 'src/modules/talent/pages/index.ts'), 'utf8')
    expect(barrel).not.toContain("'recruitment/requests/approval'")
    expect(barrel).not.toContain("'recruitment/requests/openings'")
    expect(barrel).not.toContain("'recruitment/requests/board'")
    expect(barrel).not.toContain("'recruitment/requests/budget'")
    expect(barrel).not.toContain("'recruitment/requests/reports'")
    expect(barrel).not.toContain("'recruitment/candidates/imports'")
    expect(barrel).not.toContain("'recruitment/candidates/pipeline'")
    expect(barrel).not.toContain("'recruitment/candidates/interviews'")
    expect(barrel).not.toContain("'recruitment/candidates/evaluations'")
    expect(barrel).not.toContain("'recruitment/candidates/offers'")
    expect(barrel).not.toContain("'recruitment/candidates/communications'")
    expect(barrel).not.toContain("'recruitment/candidates/talent-pool'")
    expect(barrel).not.toContain("'recruitment/candidates/convert'")
    expect(barrel).toContain("'recruitment/requests/index'")
    expect(barrel).toContain("'recruitment/candidates/index'")
    expect(barrel).toContain("'recruitment/candidates/by-id'")
  })
})
