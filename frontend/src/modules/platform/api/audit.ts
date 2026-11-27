import { resourceGet } from '@/api/resourceClient'
import { ApiError } from '@/api/errors'
import type { PageResult } from './index'

/**
 * Append-only IAM security-event projection.
 *
 * GET /v1/auth/events accepts only page/page_size. Date, actor, action and
 * result filters are not server-side, and there is no audit export route; the
 * page states that limitation instead of sending invented query parameters.
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
}

export async function listAuthEvents(query: AuthEventQuery = { page: 1, pageSize: 20 }): Promise<PageResult<AuthEvent>> {
  const payload = record(
    await resourceGet<unknown>('/v1/auth/events', {
      page: query.page,
      page_size: query.pageSize,
    }),
    '审计事件分页响应',
  )
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
