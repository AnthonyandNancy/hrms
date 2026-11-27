import { describe, expect, it } from 'vitest'
import { translate } from '@/shared/display/dictionary'

// 3 dictionary tests + 1 PageShell test = 4 tests below.

describe('dictionary', () => {
  it('translates employee status', () => {
    expect(translate('people', 'employeeStatus', 'ACTIVE').label).toBe('在职')
    expect(translate('people', 'employeeStatus', 'PROBATION').label).toBe('试用')
  })

  it('translates employment type and payroll unit', () => {
    expect(translate('people', 'employmentType', 'FULL_TIME').label).toBe('全职')
    expect(translate('people', 'employmentType', 'FIXED_TERM').label).toBe('固定期限')
    expect(translate('people', 'employmentType', 'CONTRACTOR').label).toBe('外包/顾问')
    expect(translate('payroll', 'amountUnit', 'MINOR_UNITS').label).toBe('最小货币单位')
  })

  it('translates position and department statuses', () => {
    expect(translate('people', 'positionStatus', 'ACTIVE').label).toBe('启用')
    expect(translate('people', 'positionStatus', 'DISABLED').label).toBe('停用')
    expect(translate('people', 'departmentStatus', 'ACTIVE').label).toBe('启用')
    expect(translate('people', 'departmentStatus', 'INACTIVE').label).toBe('停用')
  })

  it('translates contract type and lifecycle statuses', () => {
    expect(translate('people', 'contractType', 'FIXED_TERM').label).toBe('固定期限')
    expect(translate('people', 'contractType', 'OPEN_ENDED').label).toBe('无固定期限')
    expect(translate('people', 'contractStatus', 'ACTIVE').label).toBe('生效中')
    expect(translate('people', 'contractStatus', 'TERMINATED').label).toBe('已终止')
    expect(translate('people', 'applicationStatus', 'ACTIVE').label).toBe('借调中')
    expect(translate('people', 'terminationType', 'RESIGNATION').label).toBe('主动离职')
  })

  it('translates talent statuses and field profiles', () => {
    expect(translate('talent', 'performanceCycleStatus', 'ACTIVE').label).toBe('进行中')
    expect(translate('talent', 'trainingProgramStatus', 'ACTIVE').label).toBe('启用中')
    expect(translate('platform', 'fieldProfile', 'P0_PUBLIC').label).toBe('P0 公开')
    expect(translate('platform', 'fieldProfile', 'P3_RESTRICTED').label).toBe('P3 受限')
    expect(translate('platform', 'userStatus', 'ACTIVE').label).toBe('在职')
  })

  it('returns a safe placeholder and records unknown codes', () => {
    expect(translate('people', 'employeeStatus', 'UNKNOWN_CODE').label).toBe('未知')
  })
})
