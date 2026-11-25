import { describe, expect, it } from 'vitest'
import { normaliseCase, normaliseCasePage } from '@/api/normalise'

/** Shape returned by `GET /v1/cases` (CaseQueryService::caseData). */
const backendCase = {
  id: 8201,
  case_no: 'CASE-2026-08201',
  type: 'LEAVE_REQUEST',
  subject_type: 'employment',
  subject_id: 501,
  title: '年假申请 · 2 天',
  payload: { leave_type_code: 'ANNUAL', from_date: '2026-11-27' },
  application_status: 'IN_APPROVAL',
  effect_status: 'NONE',
  requester_user_id: 10,
  template_id: 3,
  template_version: 2,
  business_date: '2026-11-27',
  submitted_at: '2026-11-22T01:35:00Z',
  closed_at: null,
  current_node_key: 'MANAGER_APPROVAL',
  is_mine: true,
  is_awaiting_me: false,
  effects: [],
}

describe('normaliseCase', () => {
  it('maps snake_case server fields onto the view model', () => {
    const row = normaliseCase(backendCase)

    expect(row.caseNo).toBe('CASE-2026-08201')
    expect(row.title).toBe('年假申请 · 2 天')
    expect(row.applicationStatus).toBe('IN_APPROVAL')
    expect(row.effectStatus).toBe('NONE')
    expect(row.appliedAt).toBe('2026-11-22T01:35:00Z')
    expect(row.currentNode).toBe('MANAGER_APPROVAL')
    expect(row.isMine).toBe(true)
    expect(row.isAwaitingMe).toBe(false)
  })

  it('does not invent an applicant name the payload never carried', () => {
    const row = normaliseCase(backendCase)

    expect(row.applicantName).toBe('—')
    expect(row.applicantEmploymentNo).toBe('—')
    expect(row.applicantOrgName).toBe('—')
  })

  it('uses denormalised payload labels when the case carries them', () => {
    const row = normaliseCase({
      ...backendCase,
      payload: { applicant_name: '陈嘉禾', employee_no: 'E1001', org_name: '数字产品部' },
    })

    expect(row.applicantName).toBe('陈嘉禾')
    expect(row.applicantEmploymentNo).toBe('E1001')
    expect(row.applicantOrgName).toBe('数字产品部')
  })

  it('normalises a paginated envelope without dropping page metadata', () => {
    const page = normaliseCasePage(
      { items: [backendCase], page: 2, page_size: 10, total: 41 },
      1,
      20,
    )

    expect(page.page).toBe(2)
    expect(page.page_size).toBe(10)
    expect(page.total).toBe(41)
    expect(page.items).toHaveLength(1)
  })
})
