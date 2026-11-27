import { describe, expect, it } from 'vitest'
import {
  PAGE_REGISTRY,
  REQUIRED_NEGATIVE_TESTS,
  REQUIRED_STATES,
  pageByName,
} from '@/router/registry'

describe('V1.1 controlled page registry', () => {
  it('registers exactly 134 business pages, 15 platform entries and 6 base pages', () => {
    // ADR-0035: seven create/edit/archive form routes became workspace actions.
    expect(PAGE_REGISTRY).toHaveLength(155)
    expect(PAGE_REGISTRY.filter((page) => page.layer === 'business')).toHaveLength(134)
    expect(PAGE_REGISTRY.filter((page) => page.layer === 'platform')).toHaveLength(15)
    expect(PAGE_REGISTRY.filter((page) => page.layer === 'base')).toHaveLength(6)
  })

  it('has a unique path and route name for every page', () => {
    const paths = PAGE_REGISTRY.map((page) => page.path)
    const names = PAGE_REGISTRY.map((page) => page.name)

    expect(new Set(paths).size).toBe(PAGE_REGISTRY.length)
    expect(new Set(names).size).toBe(PAGE_REGISTRY.length)
  })

  it('declares a page capability, component key, required API, five states and four negative tests for every page', () => {
    for (const page of PAGE_REGISTRY) {
      expect(page.capability, `${page.path} capability`).not.toBe('')
      expect(page.componentKey, `${page.path} componentKey`).toMatch(/^[A-Za-z0-9/_-]+$/)
      expect(page.requiredApis, `${page.path} requiredApis`).toBeInstanceOf(Array)
      expect(page.states, `${page.path} states`).toEqual(REQUIRED_STATES)
      expect(page.negativeTests, `${page.path} negativeTests`).toEqual(REQUIRED_NEGATIVE_TESTS)
      expect(page.e2e.length, `${page.path} e2e`).toBeGreaterThan(0)
    }
  })

  it('does not let a parent menu entry substitute for a child page', () => {
    expect(pageByName('hr-employees')?.path).toBe('/hr/employees')
    expect(pageByName('employee-detail')?.path).toBe('/hr/employees/:id')
    expect(pageByName('hr-employees')?.path).not.toBe(pageByName('employee-detail')?.path)
    expect(pageByName('hr-employees')).not.toBe(pageByName('employee-detail'))
  })

  it('covers every of the 17 V1.1 menu groups', () => {
    const groups = new Set(
      PAGE_REGISTRY
        .filter((page) => page.layer === 'business' && page.menu !== null)
        .map((page) => page.menu?.group),
    )

    // ADR-0035: detail routes are deep links with menu: null and must not
    // contribute an `undefined` menu group.
    expect(groups.size).toBe(17)
  })
})
