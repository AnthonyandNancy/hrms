import { describe, expect, it } from 'vitest'
import {
  PAGE_REGISTRY,
  findPage,
  pagesByCapability,
  pagesByCluster,
} from '@/router/registry'
import { validatePageModel, type PageSpec } from '@/shared/navigation/pageModel'

const base: PageSpec = {
  path: '/hr/employees',
  name: 'hr-employees',
  componentKey: 'hr/employees/index',
  capability: 'people.employee.read',
  kind: 'workspace',
  menu: { group: '员工档案', label: '员工列表', order: 16, icon: 'User' },
  primaryObject: 'employee',
  layer: 'business',
  wave: 'Wave 2',
  requiredApis: ['GET /v1/employees'],
  actions: [],
  states: ['loading', 'empty', 'error', 'forbidden', 'partial'],
  e2e: ['16-hr-employees'],
  negativeTests: ['unauthenticated', 'no_capability', 'cross_anchor', 'field_or_export'],
}

describe('page model', () => {
  it('rejects action-like page paths outside the detail kind', () => {
    const result = validatePageModel([
      { ...base, path: '/hr/employees/create', name: 'hr-employees-create' },
    ])

    expect(result.ok).toBe(false)
    expect(result.errors.join('\n')).toContain('action-like path')
  })

  it('requires detail pages to have menu null and a detailOf that names an existing page', () => {
    const result = validatePageModel([
      base,
      {
        ...base,
        path: '/hr/employees/:id',
        name: 'employee-detail',
        kind: 'detail',
        menu: { group: '员工档案', label: '档案详情', order: 18, icon: 'User' },
        detailOf: 'missing-list',
      },
    ])

    expect(result.ok).toBe(false)
    expect(result.errors.join('\n')).toContain('detail page must have menu: null')
    expect(result.errors.join('\n')).toContain('detailOf missing-list is unknown')
  })

  it('accepts a valid workspace and hidden detail page pair', () => {
    const result = validatePageModel([
      base,
      {
        ...base,
        path: '/hr/employees/:id',
        name: 'employee-detail',
        kind: 'detail',
        menu: null,
        detailOf: 'hr-employees',
      },
    ])

    expect(result).toEqual({ ok: true, errors: [] })
  })

  it('requires workspace menus and forbids base menus', () => {
    const result = validatePageModel([
      { ...base, menu: null },
      {
        ...base,
        path: '/login',
        name: 'login',
        kind: 'base',
        menu: { group: '系统管理', label: '登录', order: 200, icon: 'Lock' },
        layer: 'base',
        wave: 'base',
      },
    ])

    expect(result.ok).toBe(false)
    expect(result.errors.join('\n')).toContain('workspace requires a menu entry')
    expect(result.errors.join('\n')).toContain('base page must have menu: null')
  })

  it('classifies the real 162-entry registry with 13 hidden detail pages', () => {
    expect(PAGE_REGISTRY).toHaveLength(162)
    expect(PAGE_REGISTRY.filter((page) => page.kind === 'detail')).toHaveLength(13)
    expect(PAGE_REGISTRY.filter((page) => page.kind === 'base')).toHaveLength(6)
    expect(PAGE_REGISTRY.filter((page) => page.kind === 'workspace')).toHaveLength(129)
    expect(PAGE_REGISTRY.filter((page) => page.kind === 'governance')).toHaveLength(14)
    expect(PAGE_REGISTRY.filter((page) => page.menu !== null)).toHaveLength(143)
    expect(PAGE_REGISTRY.filter((page) => page.kind === 'detail').every((page) => page.menu === null)).toBe(true)
    expect(PAGE_REGISTRY.filter((page) => page.kind === 'base').every((page) => page.menu === null)).toBe(true)
    expect(PAGE_REGISTRY.filter((page) => page.kind === 'detail').every((page) => page.detailOf !== undefined)).toBe(true)
  })

  it('keeps the frozen registry helper exports available', () => {
    expect(findPage('employee-detail')?.kind).toBe('detail')
    expect(findPage('missing-page')).toBeUndefined()
    expect(pagesByCapability('iam.role.manage').map((page) => page.name)).toEqual(['system-roles'])
    expect(pagesByCluster('K3').map((page) => page.name)).toEqual(['hr-employees-by-id-files'])
  })
})
