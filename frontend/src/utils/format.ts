const DEFAULT_LOCALE = 'zh-CN'
const DEFAULT_TIMEZONE = 'Asia/Shanghai'

/**
 * Money is always transported as integer minor units (cents). This formatter
 * never accepts a float derived from strings or percentages.
 */
export function formatMoney(cents: number): string {
  if (!Number.isSafeInteger(cents)) {
    throw new TypeError('Money must be an integer number of cents')
  }
  const sign = cents < 0 ? '-' : ''
  const absolute = Math.abs(cents)
  const amount = new Intl.NumberFormat(DEFAULT_LOCALE, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(absolute / 100)
  return `${sign}¥${amount}`
}

export function parseYuanToCents(value: string): number {
  const normalized = value.trim().replace(/[¥,\s]/g, '')
  if (!/^-?\d+(\.\d{0,2})?$/.test(normalized)) {
    throw new TypeError('Amount must have at most two decimal places')
  }
  const negative = normalized.startsWith('-')
  const digits = negative ? normalized.slice(1) : normalized
  const [whole = '0', fraction = ''] = digits.split('.')
  const cents = Number(whole) * 100 + Number(fraction.padEnd(2, '0'))
  return negative ? -cents : cents
}

function partsToRecord(parts: Intl.DateTimeFormatPart[]): Record<string, string> {
  const record: Record<string, string> = {}
  for (const part of parts) {
    if (part.type !== 'literal') {
      record[part.type] = part.value
    }
  }
  return record
}

/**
 * Business DATE values are calendar dates and must not be shifted by a
 * timezone. Format the string itself without constructing a local Date.
 */
export function formatBusinessDate(
  value: string | null | undefined,
  fallback = '—',
): string {
  if (!value) return fallback
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return fallback
  const [year, month, day] = value.split('-')
  return `${year}年${Number(month)}月${Number(day)}日`
}

export function formatBusinessDateShort(
  value: string | null | undefined,
  fallback = '—',
): string {
  if (!value) return fallback
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return fallback
  const [, month, day] = value.split('-')
  return `${Number(month)}月${Number(day)}日`
}

export function formatMonthLabel(value: string | null | undefined): string {
  if (!value || !/^\d{4}-\d{2}/.test(value)) return '—'
  const [year, month] = value.split('-')
  return `${year}年${Number(month)}月`
}

/** Recorded timestamps are UTC ISO-8601; display them in tenant timezone. */
export function formatDateTimeInTimezone(
  iso: string | null | undefined,
  timezone = DEFAULT_TIMEZONE,
  fallback = '—',
): string {
  if (!iso) return fallback
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return fallback
  const formatter = new Intl.DateTimeFormat(DEFAULT_LOCALE, {
    timeZone: timezone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  })
  const parts = partsToRecord(formatter.formatToParts(date))
  return `${parts.year}-${parts.month}-${parts.day} ${parts.hour}:${parts.minute}`
}

export function formatLocalTimeInTimezone(
  iso: string | null | undefined,
  timezone = DEFAULT_TIMEZONE,
  fallback = '—',
): string {
  if (!iso) return fallback
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return fallback
  const formatter = new Intl.DateTimeFormat(DEFAULT_LOCALE, {
    timeZone: timezone,
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  })
  return formatter.format(date)
}

export function formatRelativeTime(
  iso: string | null | undefined,
  now = new Date(),
  timezone = DEFAULT_TIMEZONE,
): string {
  if (!iso) return '—'
  const target = new Date(iso)
  if (Number.isNaN(target.getTime())) return '—'
  const minutes = Math.round((target.getTime() - now.getTime()) / 60000)
  if (Math.abs(minutes) < 1) return '刚刚'
  if (minutes > 0) {
    if (minutes < 60) return `${minutes} 分钟后`
    if (minutes < 1440) return `${Math.round(minutes / 60)} 小时后`
    return `${formatDateTimeInTimezone(iso, timezone)}`
  }
  const abs = Math.abs(minutes)
  if (abs < 60) return `${abs} 分钟前`
  if (abs < 1440) return `${Math.round(abs / 60)} 小时前`
  if (abs < 43200) return `${Math.round(abs / 1440)} 天前`
  return formatDateTimeInTimezone(iso, timezone)
}

export function weekdayLabel(date: string): string {
  const value = new Date(`${date}T00:00:00Z`)
  return ['周日', '周一', '周二', '周三', '周四', '周五', '周六'][value.getUTCDay()] ?? '—'
}

export function addDays(date: string, days: number): string {
  const value = new Date(`${date}T00:00:00Z`)
  value.setUTCDate(value.getUTCDate() + days)
  return value.toISOString().slice(0, 10)
}

export function toUtcDate(date: string): Date {
  return new Date(`${date}T00:00:00Z`)
}
