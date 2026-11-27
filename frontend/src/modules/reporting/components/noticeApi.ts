import { resourceGet, resourcePost } from '@/api/resourceClient'

/**
 * M18 notice transport boundary.
 *
 * The server owns audience resolution, approval, read evidence, reminder
 * deduplication and template versioning. This module only normalises the
 * transport shapes; it never invents counts, names or success states.
 */

type RawRecord = Record<string, unknown>

export type Notice = {
  id: number
  title: string
  summary: string
  body: string[]
  status: string
  audience: string
  audienceLabel: string
  orgUnitIds: number[]
  roleCodes?: string[]
  publishedAt: string | null
  retractedAt?: string | null
  authorName: string | null
  requiresAck: boolean
  acknowledged: boolean
  acknowledgementCount: number
  audienceCount: number
  approvalCaseId?: number | null
  approvedAt?: string | null
  archivedAt?: string | null
  templateCode?: string | null
  templateVersion?: number | null
}

export type NoticeListResult = {
  items: Notice[]
  total: number
  partial: boolean
  partialReason: string | null
}

export type NoticeAudience = 'ALL' | 'ORG' | 'ROLE'

export type NoticeDetailTab = 'content' | 'approval' | 'audience' | 'reads' | 'templates'

export type PublishNoticeInput = {
  title: string
  body: string
  audience: NoticeAudience
  audienceLabel: string
  orgUnitIds?: number[]
  roleCodes?: string[]
  requiresAck: boolean
  approvalRequired?: boolean
}

export type NoticeAudiencePreview = {
  audienceType: string
  audienceLabel: string
  orgUnitIds: number[]
  roleCodes?: string[]
  recipientCount: number
  resolvedOn: string
}

export type NoticeApprovalResult = {
  notice: Notice
  noticeStatus: string
  applicationStatus: string
  caseId: number | null
}

export type NoticeReadReceipt = {
  userId: number
  acknowledgedAt: string | null
  employmentId: number | null
  employeeNo: string | null
  displayName: string | null
}

export type NoticeReadsResult = {
  items: NoticeReadReceipt[]
  total: number
  page: number
  pageSize: number
  asOf: string
  readUserIds: number[]
  unreadUserIds: number[]
  readCount: number
  unreadCount: number
}

export type NoticeReadExport = {
  fields: string[]
  rows: Array<Record<string, string | number | null>>
  rowCount: number
  asOf: string
}

export type NoticeReminderResult = {
  remindedCount: number
  notificationIds: number[]
}

export type NoticeTemplate = {
  id: number
  code: string
  name: string
  version: number
  bodyTemplate: string
  variables: string[]
  status: string
}

export type NoticeTemplateInput = {
  code: string
  name: string
  bodyTemplate: string
  variables: string[]
}

export type RoleOption = {
  code: string
  name: string
}

export type OrgUnitOption = {
  id: number
  parentId: number | null
  name: string
  label: string
}

const NOTICE_STATUS_LABELS: Record<string, string> = {
  DRAFT: '草稿',
  PENDING_APPROVAL: '待审核',
  APPROVED: '已批准',
  REJECTED: '已驳回',
  PUBLISHED: '已发布',
  RETRACTED: '已撤回',
  ARCHIVED: '已归档',
}

function isRecord(value: unknown): value is RawRecord {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
}

function text(value: unknown, fallback = ''): string {
  if (typeof value === 'string') return value
  if (typeof value === 'number' || typeof value === 'boolean') return String(value)
  return fallback
}

function nullableText(value: unknown): string | null {
  if (value === null || value === undefined || value === '') return null
  return text(value)
}

function integer(value: unknown, fallback = 0): number {
  if (typeof value === 'number' && Number.isSafeInteger(value)) return value
  if (typeof value === 'string' && /^-?\d+$/.test(value.trim())) {
    const parsed = Number(value)
    return Number.isSafeInteger(parsed) ? parsed : fallback
  }
  return fallback
}

function nullableInteger(value: unknown): number | null {
  if (typeof value === 'number' && Number.isSafeInteger(value)) return value
  if (typeof value === 'string' && /^-?\d+$/.test(value.trim())) {
    const parsed = Number(value)
    return Number.isSafeInteger(parsed) ? parsed : null
  }
  return null
}

function stringList(value: unknown): string[] {
  return Array.isArray(value)
    ? value.filter((item): item is string => typeof item === 'string' && item !== '')
    : []
}

function numberList(value: unknown): number[] {
  return Array.isArray(value)
    ? value
        .map((item) => nullableInteger(item))
        .filter((item): item is number => item !== null)
    : []
}

function hasChinese(value: string): boolean {
  return /[\u3400-\u9fff]/.test(value)
}

function idempotencyKey(prefix: string): string {
  const cryptoApi = globalThis.crypto
  if (cryptoApi !== undefined && typeof cryptoApi.randomUUID === 'function') {
    return `${prefix}-${cryptoApi.randomUUID()}`
  }
  return `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`
}

function normaliseNotice(raw: unknown): Notice | null {
  if (!isRecord(raw)) return null
  const id = nullableInteger(raw.id)
  if (id === null || id <= 0) return null
  const body = Array.isArray(raw.body)
    ? stringList(raw.body)
    : text(raw.body) === ''
      ? []
      : text(raw.body).split(/\r?\n/).map((line) => line.trim()).filter((line) => line !== '')

  return {
    id,
    title: text(raw.title),
    summary: text(raw.summary),
    body,
    status: text(raw.status).toUpperCase(),
    audience: text(raw.audience, 'ALL').toUpperCase(),
    audienceLabel: text(raw.audienceLabel),
    orgUnitIds: numberList(raw.orgUnitIds),
    roleCodes: stringList(raw.roleCodes),
    publishedAt: nullableText(raw.publishedAt),
    retractedAt: nullableText(raw.retractedAt),
    authorName: nullableText(raw.authorName),
    requiresAck: raw.requiresAck === true,
    acknowledged: raw.acknowledged === true,
    acknowledgementCount: integer(raw.acknowledgementCount),
    audienceCount: integer(raw.audienceCount),
    approvalCaseId: nullableInteger(raw.approvalCaseId ?? raw.approval_case_id),
    approvedAt: nullableText(raw.approvedAt),
    archivedAt: nullableText(raw.archivedAt),
    templateCode: nullableText(raw.templateCode),
    templateVersion: nullableInteger(raw.templateVersion),
  }
}

export function noticeStatusText(value: string): string {
  const normalized = value.trim().toUpperCase()
  if (normalized === '') return '—'
  return NOTICE_STATUS_LABELS[normalized] ?? '其他状态'
}

export function audienceText(notice: Pick<Notice, 'audience' | 'audienceLabel'>): string {
  if (notice.audience === 'ALL') return '全体员工'
  if (notice.audience === 'ORG') {
    return notice.audienceLabel !== '' && hasChinese(notice.audienceLabel) ? notice.audienceLabel : '指定组织'
  }
  if (notice.audience === 'ROLE') {
    return notice.audienceLabel !== '' && hasChinese(notice.audienceLabel) ? notice.audienceLabel : '指定角色'
  }
  return notice.audienceLabel !== '' && hasChinese(notice.audienceLabel) ? notice.audienceLabel : '指定范围'
}

export async function listNotices(
  filter: 'all' | 'unread' | 'acknowledged' | 'managed' = 'all',
): Promise<NoticeListResult> {
  const raw = await resourceGet<unknown>('/v1/engagement/notices', { filter })
  const payload = isRecord(raw) ? raw : null
  const rawItems = Array.isArray(raw)
    ? raw
    : payload !== null && Array.isArray(payload.items)
      ? payload.items
      : []
  const items = rawItems
    .map(normaliseNotice)
    .filter((notice): notice is Notice => notice !== null)

  return {
    items,
    total: payload !== null ? integer(payload.total, items.length) : items.length,
    partial: payload !== null && payload.partial === true,
    partialReason: payload !== null ? nullableText(payload.partial_reason) : null,
  }
}

export async function getNotice(id: number): Promise<Notice> {
  const raw = await resourceGet<unknown>(`/v1/engagement/notices/${id}`)
  const notice = normaliseNotice(raw)
  if (notice === null) throw new TypeError('公告详情接口未返回有效记录。')
  return notice
}

export async function acknowledgeNotice(id: number): Promise<Notice> {
  const raw = await resourcePost<unknown>(
    `/v1/engagement/notices/${id}/acknowledge`,
    {},
    { idempotencyKey: idempotencyKey('notice-ack') },
  )
  const notice = normaliseNotice(raw)
  if (notice === null) throw new TypeError('公告确认接口未返回有效记录。')
  return notice
}

export async function archiveNotice(id: number, reason: string): Promise<Notice> {
  const raw = await resourcePost<unknown>(
    `/v1/engagement/notices/${id}/archive`,
    { reason },
    { idempotencyKey: idempotencyKey('notice-archive') },
  )
  const notice = normaliseNotice(raw)
  if (notice === null) throw new TypeError('公告归档接口未返回有效记录。')
  return notice
}

export async function restoreNotice(id: number, reason: string): Promise<Notice> {
  const raw = await resourcePost<unknown>(
    `/v1/engagement/notices/${id}/restore`,
    { reason },
    { idempotencyKey: idempotencyKey('notice-restore') },
  )
  const notice = normaliseNotice(raw)
  if (notice === null) throw new TypeError('公告恢复接口未返回有效记录。')
  return notice
}

export async function publishNotice(input: PublishNoticeInput): Promise<Notice> {
  const payload: RawRecord = {
    title: input.title,
    body: input.body,
    audience: input.audience,
    audienceLabel: input.audienceLabel,
    requiresAck: input.requiresAck,
  }
  if (input.audience === 'ORG' && input.orgUnitIds !== undefined) {
    payload.orgUnitIds = [...input.orgUnitIds]
  }
  if (input.audience === 'ROLE' && input.roleCodes !== undefined) {
    payload.roleCodes = [...input.roleCodes]
  }
  payload.approvalRequired = input.approvalRequired === true
  if (input.audience === 'ROLE' && input.roleCodes !== undefined) {
    payload.roleCodes = [...input.roleCodes]
  }
  if (input.approvalRequired !== undefined) {
    payload.approvalRequired = input.approvalRequired
  }

  const raw = await resourcePost<unknown>(
    '/v1/engagement/notices',
    payload,
    { idempotencyKey: idempotencyKey('notice-publish') },
  )
  const notice = normaliseNotice(raw)
  if (notice === null) throw new TypeError('公告发布接口未返回有效记录。')
  return notice
}

export async function previewNoticeAudience(input: {
  audience: NoticeAudience
  audienceLabel?: string
  orgUnitIds?: number[]
  roleCodes?: string[]
}): Promise<NoticeAudiencePreview> {
  const raw = await resourcePost<unknown>(
    '/v1/engagement/notices/audience-preview',
    {
      audience: input.audience,
      audienceLabel: input.audienceLabel,
      orgUnitIds: input.orgUnitIds,
      roleCodes: input.roleCodes,
    },
    { idempotencyKey: idempotencyKey('notice-audience-preview') },
  )
  const row = isRecord(raw) ? raw : {}
  return {
    audienceType: text(row.audienceType, input.audience).toUpperCase(),
    audienceLabel: text(row.audienceLabel),
    orgUnitIds: numberList(row.orgUnitIds),
    roleCodes: stringList(row.roleCodes),
    recipientCount: integer(row.recipientCount),
    resolvedOn: text(row.resolvedOn),
  }
}

async function approvalWith(
  path: string,
  body: Record<string, unknown>,
): Promise<NoticeApprovalResult> {
  const raw = await resourcePost<unknown>(
    path,
    body,
    { idempotencyKey: idempotencyKey('notice-approval') },
  )
  const row = isRecord(raw) ? { ...raw } : {}
  const notice = normaliseNotice(row)
  if (notice === null) throw new TypeError('公告审批接口未返回有效记录。')
  const nestedNotice = isRecord(row.notice) ? row.notice : null
  const caseId = nullableInteger(row.case_id ?? row.approvalCaseId ?? row.approval_case_id)
  return {
    notice: nestedNotice !== null
      ? (normaliseNotice(nestedNotice) ?? notice)
      : { ...notice, approvalCaseId: caseId ?? notice.approvalCaseId },
    noticeStatus: text(row.notice_status, notice.status).toUpperCase(),
    applicationStatus: text(row.application_status),
    caseId,
  }
}

export async function submitNotice(id: number): Promise<NoticeApprovalResult> {
  return approvalWith(`/v1/engagement/notices/${id}/submit`, {})
}

export async function decideNotice(
  id: number,
  action: 'APPROVE' | 'REJECT',
  comment: string,
): Promise<NoticeApprovalResult> {
  return approvalWith(`/v1/engagement/notices/${id}/decision`, { action, comment })
}

export async function publishApprovedNotice(id: number): Promise<Notice> {
  const raw = await resourcePost<unknown>(
    `/v1/engagement/notices/${id}/publish`,
    {},
    { idempotencyKey: idempotencyKey('notice-publish-approved') },
  )
  const notice = normaliseNotice(raw)
  if (notice === null) throw new TypeError('公告发布接口未返回有效记录。')
  return notice
}

function normaliseReadReceipt(raw: unknown): NoticeReadReceipt | null {
  if (!isRecord(raw)) return null
  const userId = nullableInteger(raw.user_id ?? raw.userId)
  if (userId === null || userId <= 0) return null
  return {
    userId,
    acknowledgedAt: nullableText(raw.acknowledged_at ?? raw.acknowledgedAt),
    employmentId: nullableInteger(raw.employment_id ?? raw.employmentId),
    employeeNo: nullableText(raw.employee_no ?? raw.employeeNo),
    displayName: nullableText(raw.display_name ?? raw.displayName),
  }
}

export async function getNoticeReads(id: number, page = 1, pageSize = 20): Promise<NoticeReadsResult> {
  const raw = await resourceGet<unknown>(`/v1/engagement/notices/${id}/reads`, {
    page,
    page_size: pageSize,
  })
  const payload = isRecord(raw) ? raw : {}
  const items = Array.isArray(payload.items)
    ? payload.items.map(normaliseReadReceipt).filter((row): row is NoticeReadReceipt => row !== null)
    : []
  return {
    items,
    total: integer(payload.total, items.length),
    page: integer(payload.page, page),
    pageSize: integer(payload.page_size, pageSize),
    asOf: text(payload.as_of),
    readUserIds: numberList(payload.read_user_ids),
    unreadUserIds: numberList(payload.unread_user_ids),
    readCount: integer(payload.read_count),
    unreadCount: integer(payload.unread_count),
  }
}

export async function exportNoticeReads(id: number, fields: string[]): Promise<NoticeReadExport> {
  const raw = await resourceGet<unknown>(`/v1/engagement/notices/${id}/reads/export`, {
    fields: fields.join(','),
  })
  const payload = isRecord(raw) ? raw : {}
  const rows = Array.isArray(payload.rows)
    ? payload.rows.filter(isRecord).map((row) => {
        const normalised: Record<string, string | number | null> = {}
        for (const [key, value] of Object.entries(row)) {
          if (typeof value === 'string' || typeof value === 'number' || value === null) {
            normalised[key] = value
          }
        }
        return normalised
      })
    : []
  return {
    fields: stringList(payload.fields),
    rows,
    rowCount: integer(payload.row_count, rows.length),
    asOf: text(payload.as_of),
  }
}

export async function remindNotice(id: number): Promise<NoticeReminderResult> {
  const raw = await resourcePost<unknown>(
    `/v1/engagement/notices/${id}/remind`,
    {},
    { idempotencyKey: idempotencyKey('notice-remind') },
  )
  const row = isRecord(raw) ? raw : {}
  return {
    remindedCount: integer(row.reminded_count ?? row.remindedCount),
    notificationIds: numberList(row.notification_ids ?? row.notificationIds),
  }
}

function normaliseNoticeTemplate(raw: unknown): NoticeTemplate | null {
  if (!isRecord(raw)) return null
  const id = nullableInteger(raw.id)
  if (id === null || id <= 0) return null
  return {
    id,
    code: text(raw.code),
    name: text(raw.name),
    version: integer(raw.version),
    bodyTemplate: text(raw.body_template),
    variables: stringList(raw.variables),
    status: text(raw.status).toUpperCase(),
  }
}

export async function listNoticeTemplates(): Promise<NoticeTemplate[]> {
  const raw = await resourceGet<unknown>('/v1/engagement/notice-templates')
  const payload = isRecord(raw) ? raw : {}
  const items = Array.isArray(payload.items) ? payload.items : []
  return items
    .map(normaliseNoticeTemplate)
    .filter((template): template is NoticeTemplate => template !== null)
}

export async function createNoticeTemplate(input: NoticeTemplateInput): Promise<NoticeTemplate> {
  const raw = await resourcePost<unknown>(
    '/v1/engagement/notice-templates',
    {
      code: input.code,
      name: input.name,
      body_template: input.bodyTemplate,
      variables: [...input.variables],
    },
    { idempotencyKey: idempotencyKey('notice-template') },
  )
  const template = normaliseNoticeTemplate(raw)
  if (template === null) throw new TypeError('公告模板接口未返回有效记录。')
  return template
}

export async function listOrgUnits(asOf: string): Promise<OrgUnitOption[]> {
  const raw = await resourceGet<unknown>('/v1/org-units/tree', { as_of: asOf })
  const payload = isRecord(raw) ? raw : {}
  const items = Array.isArray(payload.items) ? payload.items : Array.isArray(raw) ? raw : []
  const units: OrgUnitOption[] = []

  for (const row of items) {
    if (!isRecord(row)) continue
    const id = nullableInteger(row.id)
    if (id === null || id <= 0) continue
    const name = text(row.name, `组织 #${id}`)
    units.push({
      id,
      parentId: nullableInteger(row.parent_id ?? row.parentId),
      name,
      label: text(row.label, name),
    })
  }

  return units
}
