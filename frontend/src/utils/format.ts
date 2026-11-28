const DEFAULT_LOCALE = 'zh-CN'
const DEFAULT_TIMEZONE = 'Asia/Shanghai'

/**
 * Money is always transported as integer minor units (cents). Invalid server
 * payloads must be rejected by adapter normalizers; this formatter is the last
 * rendering guard and never throws, so one bad amount cannot blank a page.
 */
const CURRENCY_SYMBOLS: Record<string, string> = {
  CNY: '¥',
  EUR: '€',
  USD: '$',
  GBP: '£',
  JPY: '¥',
}

function moneyCents(value: string | number | bigint): bigint | null {
  if (typeof value === 'bigint') return value
  if (typeof value === 'number') {
    if (!Number.isSafeInteger(value)) return null
    return BigInt(value)
  }
  if (typeof value === 'string' && /^-?\d+$/.test(value)) {
    try {
      return BigInt(value)
    } catch {
      return null
    }
  }
  return null
}

/**
 * Format integer minor units without ever converting through a float. BigInt
 * handles values beyond Number.MAX_SAFE_INTEGER while grouping is still done
 * by Intl with a BigInt argument.
 */
export function formatMoney(
  cents: string | number | bigint | null | undefined,
  currency = 'CNY',
): string {
  if (cents === null || cents === undefined) return '—'
  const value = moneyCents(cents)
  if (value === null) return '—'
  const negative = value < 0n
  const absolute = negative ? -value : value
  const whole = absolute / 100n
  const fraction = (absolute % 100n).toString().padStart(2, '0')
  const grouped = new Intl.NumberFormat(DEFAULT_LOCALE, {
    maximumFractionDigits: 0,
    useGrouping: true,
  }).format(whole)
  const symbol = CURRENCY_SYMBOLS[currency.toUpperCase()] ?? `${currency.toUpperCase()} `
  return `${negative ? '-' : ''}${symbol}${grouped}.${fraction}`
}

export function parseYuanToCents(value: string): number {
  const normalized = value.trim().replace(/[¥€$£,\s]/g, '')
  if (!/^-?\d+(\.\d{0,2})?$/.test(normalized)) {
    throw new TypeError('Amount must have at most two decimal places')
  }
  const negative = normalized.startsWith('-')
  const digits = negative ? normalized.slice(1) : normalized
  const [whole = '0', fraction = ''] = digits.split('.')
  const cents = BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0'))
  const signed = negative ? -cents : cents
  if (signed > BigInt(Number.MAX_SAFE_INTEGER) || signed < BigInt(Number.MIN_SAFE_INTEGER)) {
    throw new TypeError('Amount exceeds the safe integer range')
  }
  return Number(signed)
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

/**
 * Today as a business date (YYYY-MM-DD) in the given IANA timezone.
 *
 * Views must not hardcode demo dates: a fixed date silently becomes invalid as
 * soon as the authorization bindings or data move past it, and the resulting
 * 403/empty list looks like a permissions bug rather than a stale default.
 */
export function todayBusinessDate(timeZone = 'Asia/Shanghai', now: Date = new Date()): string {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(now)

  const get = (type: string): string => parts.find((p) => p.type === type)?.value ?? ''
  return `${get('year')}-${get('month')}-${get('day')}`
}
