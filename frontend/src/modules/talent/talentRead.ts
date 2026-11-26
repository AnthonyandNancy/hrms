import { resourceGet } from '@/api/resourceClient'
import type { TalentRow } from './types'

export type TalentListResult = {
  rows: TalentRow[]
  total: number
  partial: boolean
}

function isRecord(value: unknown): value is TalentRow {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

export function nextIdempotencyKey(prefix: string): string {
  const random = globalThis.crypto?.randomUUID?.() ?? Math.random().toString(36).slice(2)
  return `talent-${prefix}-${random}`
}

export function rowText(row: TalentRow, key: string): string {
  const value = row[key]
  if (value === null || value === undefined || value === '') return '—'
  if (typeof value === 'boolean') return value ? '是' : '否'
  if (typeof value === 'string' || typeof value === 'number') return String(value)
  if (Array.isArray(value)) return `${value.length} 项`
  if (isRecord(value)) {
    const display = value.display
    if (typeof display === 'string') return display
    const name = value.name
    if (typeof name === 'string') return name
  }
  return '—'
}

export function rowHasEmail(row: TalentRow): boolean {
  return row.has_email === true
}

export function rowId(row: TalentRow, key: string = 'id'): string {
  const value = row[key]
  return typeof value === 'number' || typeof value === 'string' ? String(value) : ''
}

/**
 * Read a registry-backed list without fabricating data.
 *
 * The backend's ApiResponse::list envelope uses {items,total,page,page_size};
 * older projections may return a bare array. Any other shape is an empty list,
 * while an envelope marked partial stays visibly partial in the UI.
 */
export async function readTalentList(
  path: string,
  params: Record<string, unknown> = {},
  normalise: (row: TalentRow) => TalentRow = (row) => row,
): Promise<TalentListResult> {
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
