import { resourceGet, resourcePost } from '@/api/resourceClient'
import { ApiError } from '@/api/errors'

/**
 * Caller-scoped in-app notifications.
 *
 * The backend exposes an inbox list plus a single-item mark-read command. It
 * deliberately has no bulk-read route and no channel-preference route; the
 * page must not pretend either exists. `valid` and `invalidReason` are live
 * re-authorization facts: an invalid row keeps its inbox identity but carries
 * no payload and cannot be marked read.
 */

type Raw = Record<string, unknown>

export type InboxNotification = {
  id: number
  type: string
  payload: Raw
  read: boolean
  valid: boolean
  invalidReason: string | null
  createdAt: string | null
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

function nullableText(value: unknown): string | null {
  return typeof value === 'string' && value !== '' ? value : null
}

function notificationOf(value: unknown): InboxNotification {
  const row = record(value, '站内通知')
  const valid = row.valid !== false
  return {
    id: integer(row.id, '通知编号'),
    type: String(row.type ?? ''),
    payload: valid && isRecord(row.payload) ? row.payload : {},
    read: row.read === true,
    valid,
    invalidReason: nullableText(row.invalidReason),
    createdAt: nullableText(row.createdAt) ?? nullableText(row.created_at),
  }
}

export async function listInboxNotifications(): Promise<InboxNotification[]> {
  const payload = await resourceGet<unknown>('/v1/engagement/notifications')
  if (!Array.isArray(payload)) contractError('通知列表')
  return payload.filter(isRecord).map(notificationOf)
}

export async function markInboxNotificationRead(id: number): Promise<InboxNotification> {
  return notificationOf(await resourcePost<unknown>(`/v1/engagement/notifications/${id}/read`, {}))
}
