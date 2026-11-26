import { resourceGet } from '@/api/resourceClient'
import { legalEntityId } from '@/api/http'
import type { PeopleListResult, PeopleRow } from './types'

export function isRecord(value: unknown): value is PeopleRow {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

export function rowText(row: PeopleRow, key: string, fallback = '—'): string {
  const value = key.split('.').reduce<unknown>((current, segment) => {
    if (isRecord(current)) return current[segment]
    return undefined
  }, row)

  if (value === null || value === undefined || value === '') return fallback
  if (typeof value === 'boolean') return value ? '是' : '否'
  if (typeof value === 'string' || typeof value === 'number') return String(value)
  if (Array.isArray(value)) return `${value.length} 项`
  if (isRecord(value)) {
    if (typeof value.display === 'string' && value.display !== '') return value.display
    if (value.masked === true) return '已遮罩'
    if (typeof value.name === 'string' && value.name !== '') return value.name
    return '—'
  }
  return fallback
}

export function rowNumber(row: PeopleRow, key: string): number | null {
  const value = key.split('.').reduce<unknown>((current, segment) => {
    if (isRecord(current)) return current[segment]
    return undefined
  }, row)
  return typeof value === 'number' && Number.isFinite(value) ? value : null
}

export function rowId(row: PeopleRow, key = 'id'): string {
  const value = row[key]
  if (typeof value === 'number' || typeof value === 'string') return String(value)
  return ''
}

export function nextIdempotencyKey(prefix: string): string {
  const random = globalThis.crypto?.randomUUID?.() ?? Math.random().toString(36).slice(2)
  return `people-${prefix}-${random}`
}

/**
 * Read an ApiResponse::list projection without inventing rows.
 *
 * A bare array is accepted for older endpoints; any other shape is an empty
 * list so a malformed payload becomes a visible empty state rather than a
 * runtime crash. `partial` is only true when the server says so.
 */
export async function readPeopleList(
  path: string,
  params: Record<string, unknown> = {},
  normalise: (row: PeopleRow) => PeopleRow = (row) => row,
): Promise<PeopleListResult> {
  const data: unknown = await resourceGet(path, params)

  if (Array.isArray(data)) {
    const rows = data.filter(isRecord).map(normalise)
    return { rows, total: rows.length, partial: false }
  }

  if (isRecord(data) && Array.isArray(data.items)) {
    const rows = data.items.filter(isRecord).map(normalise)
    const total = typeof data.total === 'number' ? data.total : rows.length
    return { rows, total, partial: data.partial === true }
  }

  return { rows: [], total: 0, partial: false }
}

export const ENTITY_SCOPE = { legal_entity_id: legalEntityId() }

export function employeeParams(extra: Record<string, unknown> = {}): Record<string, unknown> {
  return { ...ENTITY_SCOPE, ...extra }
}

export function numericParam(value: unknown): number | null {
  if (typeof value === 'number' && Number.isFinite(value)) return value
  if (typeof value === 'string' && /^\d+$/.test(value)) return Number(value)
  return null
}

export function departmentLabel(row: PeopleRow): string {
  const code = rowText(row, 'code')
  const name = rowText(row, 'name')
  return `${name}（${code}）`
}
