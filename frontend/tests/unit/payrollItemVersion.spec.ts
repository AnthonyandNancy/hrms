import { describe, expect, it, vi } from 'vitest'

vi.mock('@/api/resourceClient', () => ({
  resourceGet: vi.fn(),
  resourcePost: vi.fn(),
}))

import {
  normalisePayrollItem,
  normalisePayrollReportSummary,
  normalisePayrollScheme,
} from '@/modules/payroll/api'

describe('payroll item version normalisers', () => {
  it('keeps integer cents and decimal rates as their transport values', () => {
    const scheme = normalisePayrollScheme({
      id: 3,
      code: 'SCHEME-A',
      name: '合成方案',
      currency: 'CNY',
      version: 2,
      status: 'ACTIVE',
      proration_divisor: 'ANNUAL_DAYS_365',
      rounding_mode: 'HALF_UP',
      overtime_multiplier: '1.500000',
      base_salary_cents: 800000,
      items: [
        {
          item_code: 'BASE',
          label: '基本工资',
          category: 'EARNING',
          amount_cents: 800000,
          sort_order: 10,
          taxable: true,
        },
      ],
    })

    expect(scheme.baseSalaryCents).toBe(800000)
    expect(scheme.overtimeMultiplier).toBe('1.500000')
    expect(scheme.items[0]?.amountCents).toBe(800000)
    expect(Number.isInteger(scheme.baseSalaryCents)).toBe(true)
  })

  it('rejects a version row without a server version instead of inventing one', () => {
    expect(() =>
      normalisePayrollItem({
        id: 5,
        item_version_id: 9,
        code: 'BONUS',
        status: 'ACTIVE',
        name: '奖金',
        type: 'EARNING',
        calculation: 'FIXED',
        effective_from: '2026-01-01',
      }),
    ).toThrow(/item.version/)
  })

  it('treats a missing money key as absent, never as zero', () => {
    const report = normalisePayrollReportSummary({
      as_of: '2026-11-27',
      source: 'PUBLISHED_PAYROLL_RUNS',
      group_by: 'period',
      currency: 'CNY',
      items: [
        {
          period: '2026-11',
          department_id: null,
          department_name: null,
          run_count: 1,
          employee_count: 1,
          gross_cents: 100000,
          deductions_cents: 0,
          net_cents: 100000,
          employer_cost_cents: 120000,
        },
      ],
      trend: [],
      totals: {
        gross_cents: 100000,
        deductions_cents: 0,
        net_cents: 100000,
        employer_cost_cents: 120000,
      },
    })

    expect(report.items[0]?.grossCents).toBe(100000)
    expect(report.items[0]?.deductionsCents).toBe(0)

    expect(() =>
      normalisePayrollReportSummary({
        as_of: '2026-11-27',
        source: 'PUBLISHED_PAYROLL_RUNS',
        group_by: 'period',
        currency: 'CNY',
        items: [
          {
            period: '2026-11',
            department_id: null,
            department_name: null,
            run_count: 1,
            employee_count: 1,
            gross_cents: 100000,
            net_cents: 100000,
            employer_cost_cents: 120000,
          },
        ],
        trend: [],
        totals: {
          gross_cents: 100000,
          deductions_cents: 0,
          net_cents: 100000,
          employer_cost_cents: 120000,
        },
      }),
    ).toThrow(/deductions_cents/)
  })
})
