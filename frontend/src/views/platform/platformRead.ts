import { ApiError, errorCopy } from '@/api/errors'
import { requestIdFromError } from '@/utils/renderErrors'

export type PlatformRow = Record<string, unknown>

export function isRecord(value: unknown): value is PlatformRow {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

/** Accept bare arrays and `{ items }` / `{ roles }` / `{ capabilities }` envelopes. */
export function rowsFrom(value: unknown, key?: string): PlatformRow[] {
  if (Array.isArray(value)) return value.filter(isRecord)
  if (!isRecord(value)) return []

  if (Array.isArray(value.items)) return value.items.filter(isRecord)
  if (key !== undefined && Array.isArray(value[key])) return value[key].filter(isRecord)

  for (const candidate of ['roles', 'capabilities', 'pages', 'menus']) {
    if (Array.isArray(value[candidate])) return value[candidate].filter(isRecord)
  }

  return []
}

export function recordFrom(value: unknown): PlatformRow | null {
  return isRecord(value) ? value : null
}

export function textOf(value: unknown, fallback = '—'): string {
  if (value === null || value === undefined || value === '') return fallback
  if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') {
    return typeof value === 'boolean' ? (value ? '是' : '否') : String(value)
  }
  if (Array.isArray(value)) {
    const parts = value.map((item) => textOf(item, '')).filter((item) => item !== '')
    return parts.length > 0 ? parts.join('、') : fallback
  }
  if (isRecord(value)) {
    if (typeof value.display === 'string' && value.display !== '') return value.display
    if (value.masked === true) return '已遮罩'
    if (typeof value.name === 'string' && value.name !== '') return value.name
    if (typeof value.label === 'string' && value.label !== '') return value.label
    return fallback
  }
  return fallback
}

export function rowText(row: PlatformRow, key: string, fallback = '—'): string {
  return textOf(key.split('.').reduce<unknown>((current, segment) => (isRecord(current) ? current[segment] : undefined), row), fallback)
}

export function numberValue(value: unknown): number | null {
  if (typeof value === 'number' && Number.isFinite(value)) return value
  if (typeof value === 'string' && /^-?\d+(?:\.\d+)?$/.test(value)) return Number(value)
  return null
}

export function rowNumber(row: PlatformRow, key: string): number | null {
  return numberValue(key.split('.').reduce<unknown>((current, segment) => (isRecord(current) ? current[segment] : undefined), row))
}

export function idText(value: unknown): string {
  return typeof value === 'string' || typeof value === 'number' ? String(value) : ''
}

export function nestedRows(row: PlatformRow | null | undefined, key: string): PlatformRow[] {
  if (row === null || row === undefined) return []
  const value = row[key]
  return Array.isArray(value) ? value.filter(isRecord) : []
}

/** Human-readable scalar metadata without turning objects into raw JSON. */
export function summariseRecord(value: unknown): string {
  if (!isRecord(value)) return textOf(value)
  const parts: string[] = []
  for (const [key, entry] of Object.entries(value)) {
    if (entry === null || entry === undefined) continue
    if (typeof entry === 'object') continue
    parts.push(`${key}: ${textOf(entry)}`)
  }
  return parts.length > 0 ? parts.join(' · ') : '—'
}

export function nextIdempotencyKey(prefix: string): string {
  const random = globalThis.crypto?.randomUUID?.() ?? Math.random().toString(36).slice(2)
  return `platform-${prefix}-${random}`
}

export type ActionErrorInfo = {
  message: string
  requestId: string | null
  reason: string | null
}

export function actionErrorInfo(error: unknown): ActionErrorInfo {
  return {
    message: errorCopy(error),
    requestId: requestIdFromError(error),
    reason: error instanceof ApiError ? error.serverReason : null,
  }
}

export function statusText(value: unknown): string {
  const status = textOf(value).toUpperCase()
  const labels: Record<string, string> = {
    ACTIVE: '启用',
    LOCKED: '锁定',
    DISABLED: '停用',
    PUBLISHED: '已发布',
    DRAFT: '草稿',
    RETRACTED: '已撤回',
    SUCCESS: '成功',
    FAILURE: '失败',
    DENIED: '已拒绝',
    AVAILABLE: '可用',
    UNAVAILABLE: '不可用',
    QUEUED: '排队中',
    RUNNING: '生成中',
    COMPLETED: '已完成',
    FAILED: '失败',
    PENDING: '待处理',
    BREACHED: '已超时',
    ON_TRACK: '进行中',
  }
  return labels[status] ?? (status === '—' ? '—' : status)
}

export function partialFlag(...values: unknown[]): boolean {
  return values.some((value) => isRecord(value) && value.partial === true)
}
