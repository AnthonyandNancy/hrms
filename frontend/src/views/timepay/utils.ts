export type UnknownRecord = Record<string, unknown>

export function isRecord(value: unknown): value is UnknownRecord {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
}

export function livePath(publicPath: string): string {
  return publicPath.startsWith('/v1/') ? publicPath.slice(3) : publicPath
}

export function recordList(value: unknown): UnknownRecord[] {
  if (Array.isArray(value)) return value.filter(isRecord)
  if (!isRecord(value)) return []
  if (Array.isArray(value.items)) return value.items.filter(isRecord)
  return [value]
}

export { currentMonth, newIdempotencyKey, textValue } from './panelUtils'
