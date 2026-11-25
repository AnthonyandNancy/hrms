import { describe, expect, it } from 'vitest'
import { NAVIGATION, visibleNavigation } from '@/router/navigation'

describe('registry-driven shell menu', () => {
  it('exposes 17 V1.1 menu groups plus the workspace entry', () => {
    const groups = NAVIGATION.filter((item) => item.key !== 'workspace')
    expect(groups).toHaveLength(17)
    expect(NAVIGATION[0]?.key).toBe('workspace')
  })

  it('keeps every menu child bound to its own capability', () => {
    const children = NAVIGATION.flatMap((item) => item.children)
    expect(children.length).toBe(140 + 14)
    expect(children.every((child) => child.capability.length > 0)).toBe(true)
  })

  it('filters child pages independently and keeps the parent group', () => {
    const people = visibleNavigation(['people.employee.read']).find((item) => item.group === 'people')
    expect(people?.children.every((child) => child.capability === 'people.employee.read')).toBe(true)
    expect(people?.children.length).toBeGreaterThan(0)

    const denied = visibleNavigation(['people.employee.read']).find((item) => item.group === 'payroll')
    expect(denied).toBeUndefined()
  })
})
