import { describe, expect, it } from 'vitest'
import router from '@/router'

describe('safe recovery links', () => {
  it('resolves the NO_ACTIVE_EMPLOYMENT safe links to real routes', () => {
    const users = router.resolve('/system/users')
    const employees = router.resolve('/hr/employees')

    expect(users.matched.length).toBeGreaterThan(0)
    expect(employees.matched.length).toBeGreaterThan(0)
    expect(users.matched.some((record) => record.name === 'not-found')).toBe(false)
    expect(employees.matched.some((record) => record.name === 'not-found')).toBe(false)
    expect(users.name).toBe('system-users')
    expect(employees.name).toBe('hr-employees')
  })
})
