export function textValue(value: unknown, fallback = '—'): string {
  if (value === null || value === undefined || value === '') return fallback
  if (typeof value === 'string') return value
  if (typeof value === 'number' || typeof value === 'boolean') return String(value)
  return fallback
}

export function integerValue(value: unknown): number | null {
  return typeof value === 'number' && Number.isSafeInteger(value) ? value : null
}

export function isMoneyCents(value: unknown): value is number {
  return typeof value === 'number' && Number.isSafeInteger(value)
}

export function moneyField(record: Record<string, unknown>, key: string): number | null {
  return isMoneyCents(record[key]) ? record[key] : null
}

export function hasAnyMoney(record: Record<string, unknown>, keys: string[]): boolean {
  return keys.some((key) => isMoneyCents(record[key]))
}

export function statusTone(status: string): string {
  if (['CLOSED', 'PAID', 'APPROVED', 'COMPLETED', 'PUBLISHED'].includes(status)) return 'success'
  if (['REJECTED', 'CANCELLED', 'FAILED', 'ABSENT'].includes(status)) return 'danger'
  if (['PENDING', 'PENDING_APPROVAL', 'SUBMITTED', 'REVIEWED', 'CALCULATED'].includes(status)) return 'warning'
  return 'neutral'
}

export function formatMinutes(minutes: unknown): string {
  const value = integerValue(minutes)
  if (value === null || value < 0) return '—'
  const hours = Math.floor(value / 60)
  const rest = value % 60
  if (hours === 0) return `${rest} 分钟`
  return rest === 0 ? `${hours} 小时` : `${hours} 小时 ${rest} 分钟`
}

export function todayIsoDate(): string {
  return new Date().toISOString().slice(0, 10)
}

export function currentMonth(): string {
  return new Date().toISOString().slice(0, 7)
}

export function newIdempotencyKey(prefix: string): string {
  const suffix =
    typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
      ? crypto.randomUUID()
      : `${Date.now()}-${Math.random().toString(36).slice(2)}`
  return `${prefix}-${suffix}`.slice(0, 128)
}
