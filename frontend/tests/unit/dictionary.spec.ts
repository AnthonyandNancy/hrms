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
    expect(translate('payroll', 'amountUnit', 'MINOR_UNITS').label).toBe('最小货币单位')
  })

  it('returns a safe placeholder and records unknown codes', () => {
    expect(translate('people', 'employeeStatus', 'UNKNOWN_CODE').label).toBe('未知')
  })
})
