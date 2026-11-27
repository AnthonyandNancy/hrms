import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ElementPlus from 'element-plus'
import { beforeEach, describe, expect, it } from 'vitest'
import type { ReportDefinition } from '@/modules/reporting/api'
import ReportCatalog from '@/modules/reporting/components/ReportCatalog.vue'

function definition(overrides: Partial<ReportDefinition> = {}): ReportDefinition {
  return {
    id: 1,
    code: 'WORKFORCE_HEADCOUNT',
    name: '人力资源报表',
    domain: 'PEOPLE',
    description: '按统计日与组织范围统计在职员工及其组织、状态分布。',
    asOfSupported: true,
    drilldownRoute: null,
    fields: [],
    filters: [],
    ...overrides,
  }
}

beforeEach(() => {
  const pinia = createPinia()
  setActivePinia(pinia)
})

describe('ReportCatalog', () => {
  it('groups server definitions by business domain without exposing projections', async () => {
    const wrapper = mount(ReportCatalog, {
      props: {
        definitions: [
          definition(),
          definition({
            id: 2,
            code: 'LEAVE_LIABILITY',
            name: '假期负债报表',
            domain: 'ATTENDANCE',
            description: '按员工与假期类型统计剩余假期账本分钟数。',
          }),
          definition({
            id: 3,
            code: 'PAYROLL_COST',
            name: '工资成本报表',
            domain: 'PAYROLL',
            description: '统计工资批次的应发、实发与雇主成本。',
          }),
        ],
        selected: 'LEAVE_LIABILITY',
      },
      global: { plugins: [ElementPlus] },
    })
    await flushPromises()

    expect(wrapper.get('[data-testid="report-domain-PEOPLE"]').text()).toContain('人力资源')
    expect(wrapper.get('[data-testid="report-domain-ATTENDANCE"]').text()).toContain('考勤假勤')
    expect(wrapper.get('[data-testid="report-domain-PAYROLL"]').text()).toContain('薪资')
    expect(wrapper.text()).toContain('假期负债报表')
    expect(wrapper.text()).not.toContain('sourceProjection')
    expect(wrapper.text()).not.toContain('leave_liability')
  })

  it('emits select with the definition code and never invents a report', async () => {
    const wrapper = mount(ReportCatalog, {
      props: { definitions: [definition()], selected: '' },
      global: { plugins: [ElementPlus] },
    })
    await flushPromises()

    expect(wrapper.findAll('[data-testid^="report-definition-"]')).toHaveLength(1)
    await wrapper.get('[data-testid="report-definition-WORKFORCE_HEADCOUNT"]').trigger('click')
    expect(wrapper.emitted('select')).toEqual([['WORKFORCE_HEADCOUNT']])
  })
})
