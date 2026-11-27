import { resourceGet, resourcePost } from '@/api/resourceClient'
import { ApiError } from '@/api/errors'
import type { PageResult } from './index'

/**
 * Append-only IAM security-event projection.
 *
 * GET /v1/audit/events performs actor/action/result/date filtering on the
 * server. POST /v1/audit/events/export accepts an allow-listed field set and
 * records sensitive access; the client never widens that allow-list.
 */

type Raw = Record<string, unknown>

export type AuthEvent = {
  id: number
  actorUserId: number | null
  eventType: string
  result: string
  requestId: string | null
  metadata: Raw
  occurredAt: string
}

function isRecord(value: unknown): value is Raw {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
}

function contractError(field: string): never {
  throw new ApiError('INTERNAL', `服务端返回的数据不符合契约：${field}。`, { status: 502 })
}

function record(value: unknown, field: string): Raw {
  if (isRecord(value)) return value
  contractError(field)
}

function integer(value: unknown, field: string): number {
  if (typeof value === 'number' && Number.isSafeInteger(value)) return value
  if (typeof value === 'string' && /^-?\d+$/.test(value)) {
    const parsed = Number(value)
    if (Number.isSafeInteger(parsed)) return parsed
  }
  contractError(field)
}

function nullableInteger(value: unknown): number | null {
  if (value === null || value === undefined || value === '') return null
  return integer(value, '操作人编号')
}

function nullableText(value: unknown): string | null {
  return typeof value === 'string' && value !== '' ? value : null
}

function text(value: unknown, field: string): string {
  if (typeof value === 'string' && value !== '') return value
  contractError(field)
}

function eventOf(value: unknown): AuthEvent {
  const row = record(value, '审计事件')
  return {
    id: integer(row.id, '事件编号'),
    actorUserId: nullableInteger(row.user_id),
    eventType: text(row.event_type, '事件类型'),
    result: text(row.result, '事件结果'),
    requestId: nullableText(row.request_id),
    metadata: isRecord(row.metadata) ? row.metadata : {},
    occurredAt: text(row.occurred_at, '发生时间'),
  }
}

export type AuthEventQuery = {
  page: number
  pageSize: number
  actor?: string
  action?: string
  result?: string
  from?: string
  to?: string
}

export async function listAuthEvents(query: AuthEventQuery = { page: 1, pageSize: 20 }): Promise<PageResult<AuthEvent>> {
  const params: Record<string, unknown> = {
    page: query.page,
    page_size: query.pageSize,
  }
  if (query.actor !== undefined && query.actor !== '') params.actor = query.actor
  if (query.action !== undefined && query.action !== '') params.action = query.action
  if (query.result !== undefined && query.result !== '') params.result = query.result
  if (query.from !== undefined && query.from !== '') params.from = query.from
  if (query.to !== undefined && query.to !== '') params.to = query.to

  const payload = record(await resourceGet<unknown>('/v1/audit/events', params), '审计事件分页响应')
  const items = Array.isArray(payload.items) ? payload.items.filter(isRecord) : []
  const page = typeof payload.page === 'number' && payload.page > 0 ? payload.page : query.page
  const pageSize = typeof payload.page_size === 'number' && payload.page_size > 0
    ? payload.page_size
    : query.pageSize
  const total = typeof payload.total === 'number' && payload.total >= 0 ? payload.total : items.length

  return {
    items: items.map(eventOf),
    page,
    pageSize,
    total,
  }
}

export const AUDIT_EXPORT_FIELDS = ['event_type', 'result', 'user_id', 'request_id', 'occurred_at'] as const

export type AuditExportResult = {
  fields: string[]
  rows: Array<Record<string, unknown>>
}

export async function exportAuthEvents(query: AuthEventQuery): Promise<AuditExportResult> {
  const body: Record<string, unknown> = { fields: [...AUDIT_EXPORT_FIELDS] }
  if (query.actor !== undefined && query.actor !== '') body.actor = query.actor
  if (query.action !== undefined && query.action !== '') body.action = query.action
  if (query.result !== undefined && query.result !== '') body.result = query.result
  if (query.from !== undefined && query.from !== '') body.from = query.from
  if (query.to !== undefined && query.to !== '') body.to = query.to

  const payload = record(
    await resourcePost<unknown>('/v1/audit/events/export', body, {
      idempotencyKey: `audit-export-${globalThis.crypto?.randomUUID?.() ?? Date.now()}`,
    }),
    '审计导出结果',
  )

  return {
    fields: Array.isArray(payload.fields)
      ? payload.fields.filter((field): field is string => typeof field === 'string')
      : [],
    rows: Array.isArray(payload.rows) ? payload.rows.filter(isRecord) : [],
  }
}

export type ReadinessCheck = {
  status: string
  version: string
  checks: Record<string, boolean>
  time: string | null
}

export async function getReadiness(): Promise<ReadinessCheck> {
  const payload = record(await resourceGet<unknown>('/v1/health/ready'), '健康检查')
  const checks = isRecord(payload.checks) ? payload.checks : {}

  return {
    status: typeof payload.status === 'string' ? payload.status : 'unknown',
    version: typeof payload.version === 'string' ? payload.version : '',
    checks: Object.fromEntries(
      Object.entries(checks).map(([key, value]) => [key, value === true]),
    ),
    time: nullableText(payload.time),
  }
}
