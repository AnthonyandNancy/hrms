import { describe, expect, it } from 'vitest'
import {
  formatBusinessDate,
  formatBusinessDateShort,
  formatDateTimeInTimezone,
  formatMoney,
  formatMonthLabel,
  parseYuanToCents,
  todayBusinessDate,
  weekdayLabel,
} from '@/utils/format'

describe('money formatting from integer cents', () => {
  it('formats positive and negative cents without floating point', () => {
    expect(formatMoney(123456)).toBe('¥1,234.56')
    expect(formatMoney(0)).toBe('¥0.00')
    expect(formatMoney(-500)).toBe('-¥5.00')
    expect(formatMoney(1)).toBe('¥0.01')
  })

  it('rejects non-integer or unsafe money input', () => {
    expect(() => formatMoney(12.5)).toThrow(TypeError)
    expect(() => formatMoney(Number.MAX_SAFE_INTEGER + 2)).toThrow(TypeError)
  })

  it('parses yuan strings to exact cents', () => {
    expect(parseYuanToCents('1,234.5')).toBe(123450)
    expect(parseYuanToCents('0.01')).toBe(1)
    expect(parseYuanToCents('-8')).toBe(-800)
    expect(() => parseYuanToCents('1.234')).toThrow(TypeError)
  })
})

describe('business date and UTC formatting', () => {
  it('formats business dates without local timezone drift', () => {
    expect(formatBusinessDate('2026-11-23')).toBe('2026年11月23日')
    expect(formatBusinessDateShort('2026-01-02')).toBe('1月2日')
    expect(formatMonthLabel('2026-11')).toBe('2026年11月')
    expect(formatBusinessDate('2026-02-30')).toBe('2026年2月30日')
  })

  it('converts UTC recorded timestamps to the tenant timezone', () => {
    expect(formatDateTimeInTimezone('2026-11-23T01:35:00Z', 'Asia/Shanghai')).toBe('2026-11-23 09:35')
    expect(formatDateTimeInTimezone('2026-11-23T16:30:00Z', 'Asia/Shanghai')).toBe('2026-11-24 00:30')
    expect(formatDateTimeInTimezone('2026-11-23T16:30:00Z', 'UTC')).toBe('2026-11-23 16:30')
  })

  it('falls back for absent or malformed dates', () => {
    expect(formatDateTimeInTimezone(null)).toBe('—')
    expect(formatDateTimeInTimezone('not-a-date')).toBe('—')
    expect(formatBusinessDate(undefined)).toBe('—')
    expect(formatBusinessDate('20261123')).toBe('—')
  })

  it('labels weekdays from a calendar date without local shift', () => {
    expect(weekdayLabel('2026-11-23')).toBe('周一')
    expect(weekdayLabel('2026-11-22')).toBe('周日')
  })
})

describe('todayBusinessDate', () => {
  it('renders the date in the requested timezone, not the machine timezone', () => {
    // 2026-11-23T17:30Z is still 2026-11-23 in UTC but already 2026-11-24 in
    // Shanghai. A view that hardcoded the UTC date would query a day that is
    // in the past for the user's business calendar.
    const instant = new Date('2026-11-23T17:30:00Z')
    expect(todayBusinessDate('UTC', instant)).toBe('2026-11-23')
    expect(todayBusinessDate('Asia/Shanghai', instant)).toBe('2026-11-24')
  })

  it('always returns a zero padded ISO date', () => {
    const instant = new Date('2026-01-05T03:00:00Z')
    expect(todayBusinessDate('UTC', instant)).toBe('2026-01-05')
  })
})
