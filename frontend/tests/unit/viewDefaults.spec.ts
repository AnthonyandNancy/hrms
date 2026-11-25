import { describe, expect, it } from 'vitest'
import { addDays, todayBusinessDate } from '@/utils/format'

/**
 * Views must derive their default dates from the business calendar. A literal
 * "2026-11-27" looks fine today and is a past date the moment real time moves,
 * which surfaces as a validation error on page load rather than as a stale
 * default.
 */
describe('business date defaults', () => {
  it('derives today from the configured timezone', () => {
    const now = new Date('2026-11-25T01:30:00Z')

    expect(todayBusinessDate('Asia/Shanghai', now)).toBe('2026-11-25')
    expect(todayBusinessDate('UTC', now)).toBe('2026-11-25')
  })

  it('keeps the previous calendar day across the Shanghai boundary', () => {
    const now = new Date('2026-11-25T16:30:00Z')

    expect(todayBusinessDate('Asia/Shanghai', now)).toBe('2026-11-26')
    expect(todayBusinessDate('UTC', now)).toBe('2026-11-25')
  })

  it('advances the default authorization expiry by whole days', () => {
    expect(addDays('2026-11-25', 30)).toBe('2026-12-25')
    expect(addDays('2026-12-31', 1)).toBe('2027-01-01')
  })
})
