import { describe, expect, it, vi } from 'vitest'
import {
  formatMetric,
  getMetricDrilldown,
  metricAvailabilityLabel,
  metricReasonText,
  metricUnitText,
  unavailableMetricReason,
} from '@/modules/reporting/api'
import { resourceGet } from '@/api/resourceClient'

vi.mock('@/api/resourceClient', () => ({
  resourceGet: vi.fn(),
  resourcePost: vi.fn(),
}))

describe('dashboard metric display', () => {
  it('formats integer minor units as yuan and never renders a technical unit', () => {
    expect(formatMetric({
      code: 'payroll_cost_by_entity',
      unit: 'CNY_CENT',
      value: 123456,
      status: 'AVAILABLE',
    })).toEqual({ value: '1,234.56', unit: '元' })
  })

  it('uses the server business unit label for counts', () => {
    expect(metricUnitText({ code: 'headcount', unit: 'PERSON', unitLabel: '人' })).toBe('人')
    expect(metricUnitText({ code: 'contract_expiring', unit: 'CONTRACT' })).toBe('份')
    expect(metricUnitText({ code: 'pending_approvals', unit: 'ITEM' })).toBe('项')
    expect(metricUnitText({ code: 'leave_liability', unit: 'MINUTE' })).toBe('分钟')
    expect(metricUnitText({ code: 'recruitment_funnel', unit: 'PERSON' })).toBe('人')
  })

  it('renders an unavailable metric as a dash with an explicit status', () => {
    expect(formatMetric({
      code: 'payroll_cost_by_entity',
      unit: 'CNY_CENT',
      value: 0,
      status: 'UNCONFIGURED',
    })).toEqual({ value: '—', unit: '' })

    expect(metricAvailabilityLabel('UNCONFIGURED')).toBe('指标尚未配置')
    expect(metricAvailabilityLabel('FORBIDDEN')).toBe('无权查看')
  })

  it('never echoes an English server reason to the UI', () => {
    expect(metricReasonText({
      code: 'payroll_cost_by_entity',
      status: 'UNCONFIGURED',
      reason: 'Payroll run tables exist, but no Payroll-owned Reporting projection publishes finalized company cost yet.',
    })).toContain('薪酬成本')

    expect(unavailableMetricReason('recruitment_funnel')).toContain('招聘漏斗')
    expect(unavailableMetricReason('recruitment_funnel')).not.toContain('projection publishes')

    // A Chinese server reason is authoritative and must be preserved.
    expect(metricReasonText({
      code: 'recruitment_funnel',
      status: 'UNCONFIGURED',
      reason: '招聘模块尚未发布阶段投影。',
    })).toBe('招聘模块尚未发布阶段投影。')
  })

  it('normalises the drilldown unit label and Chinese reason', async () => {
    vi.mocked(resourceGet).mockResolvedValueOnce({
      metric: 'payroll_cost_by_entity',
      label: '薪酬成本',
      asOf: '2026-11-27',
      total: 0,
      unit: 'CNY_CENT',
      unitLabel: '元',
      status: 'UNCONFIGURED',
      reason: '薪酬成本投影尚未配置：仅统计已发布工资批次的雇主成本，当前不提供估算值。',
      columns: [],
      items: [],
    })

    const drilldown = await getMetricDrilldown('payroll_cost_by_entity', '2026-11-27')
    expect(drilldown.unitLabel).toBe('元')
    expect(drilldown.status).toBe('UNCONFIGURED')
    expect(drilldown.reason).toContain('薪酬成本')
  })
})
