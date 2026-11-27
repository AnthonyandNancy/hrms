import { ApiError, errorCopy } from '@/api/errors'
import { resourceGet, resourcePost, resourcePut } from '@/api/resourceClient'

/**
 * Platform workspace API boundary.
 *
 * The backend owns authorization, visibility, pagination and state machines.
 * This module only normalises transport shapes and keeps every write body
 * identical to the documented contract. It never synthesises rows, statuses or
 * parameter values.
 */

type Raw = Record<string, unknown>

export type PageResult<T> = {
  items: T[]
  page: number
  pageSize: number
  total: number
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

function text(value: unknown, field: string): string {
  if (typeof value === 'string' && value !== '') return value
  contractError(field)
}

function nullableText(value: unknown): string | null {
  return typeof value === 'string' && value !== '' ? value : null
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
  return integer(value, '数字字段')
}

function boolean(value: unknown): boolean {
  return value === true
}

function rowsOf(value: unknown, field: string): Raw[] {
  if (Array.isArray(value)) return value.filter(isRecord)
  if (isRecord(value) && Array.isArray(value.items)) return value.items.filter(isRecord)
  contractError(field)
}

export function pageFrom(value: unknown): PageResult<Raw> {
  if (Array.isArray(value)) {
    return { items: value.filter(isRecord), page: 1, pageSize: Math.max(1, value.length), total: value.length }
  }
  const payload = record(value, '分页响应')
  const items = Array.isArray(payload.items) ? payload.items.filter(isRecord) : []
  const page = typeof payload.page === 'number' && payload.page > 0 ? payload.page : 1
  const pageSize = typeof payload.page_size === 'number' && payload.page_size > 0
    ? payload.page_size
    : Math.max(1, items.length)
  const total = typeof payload.total === 'number' && payload.total >= 0 ? payload.total : items.length
  return { items, page, pageSize, total }
}

export type WorkflowCaseStatus =
  | 'DRAFT'
  | 'SUBMITTED'
  | 'IN_REVIEW'
  | 'APPROVED'
  | 'REJECTED'
  | 'WITHDRAWN'
  | 'CANCELLED'

export type WorkflowEffectStatus =
  | 'NONE'
  | 'PENDING'
  | 'SCHEDULED'
  | 'APPLIED'
  | 'PARTIAL'
  | 'FAILED'
  | 'REVERSED'

export type WorkflowEffect = {
  effectId: number
  effectType: string
  status: string
  attempts: number
  requiresEscalation: boolean
}

export type WorkflowCaseSummary = {
  id: number
  caseNo: string
  type: string
  subjectType: string
  subjectId: number | null
  title: string
  applicationStatus: WorkflowCaseStatus
  effectStatus: WorkflowEffectStatus
  requesterUserId: number
  templateId: number | null
  templateVersion: number
  businessDate: string
  submittedAt: string | null
  closedAt: string | null
  currentNodeKey: string | null
  isMine: boolean
  isAwaitingMe: boolean
  effects: WorkflowEffect[]
}

export type WorkflowTask = {
  taskId: number
  caseId: number
  caseNo: string | null
  title: string | null
  nodeKey: string | null
  applicationStatus: string | null
  effectStatus: string | null
  dueAt: string | null
  slaState: 'BREACHED' | 'ON_TRACK' | 'NONE' | string
}

export type WorkflowTimelineItem = {
  kind: 'WORKFLOW_EVENT' | 'DECISION' | 'EFFECT'
  eventType: string | null
  action: string | null
  effectType: string | null
  effectStatus: string | null
  nodeKey: string | null
  actorUserId: number | null
  comment: string | null
  payload: Raw | null
  occurredAt: string | null
}

export type WorkflowTimeline = {
  caseId: number
  application: {
    status: WorkflowCaseStatus
    submittedAt: string | null
    closedAt: string | null
  }
  effect: {
    status: WorkflowEffectStatus
    effects: WorkflowEffect[]
  }
  items: WorkflowTimelineItem[]
}

export type WorkflowDecisionAction = 'APPROVE' | 'REJECT' | 'RETURN'

function effectOf(value: unknown): WorkflowEffect {
  const effect = record(value, '生效记录')
  return {
    effectId: integer(effect.effect_id, '生效记录编号'),
    effectType: text(effect.effect_type, '生效类型'),
    status: text(effect.status, '生效状态'),
    attempts: typeof effect.attempts === 'number' ? effect.attempts : 0,
    requiresEscalation: boolean(effect.requires_escalation),
  }
}

export function workflowCaseOf(value: unknown): WorkflowCaseSummary {
  const row = record(value, '审批案例')
  return {
    id: integer(row.id, '案例编号'),
    caseNo: text(row.case_no, '案例编号'),
    type: text(row.type, '案例类型'),
    subjectType: text(row.subject_type, '业务对象类型'),
    subjectId: nullableInteger(row.subject_id),
    title: text(row.title, '案例标题'),
    applicationStatus: text(row.application_status, '申请状态') as WorkflowCaseStatus,
    effectStatus: text(row.effect_status, '生效状态') as WorkflowEffectStatus,
    requesterUserId: integer(row.requester_user_id, '发起人'),
    templateId: nullableInteger(row.template_id),
    templateVersion: typeof row.template_version === 'number' ? row.template_version : 0,
    businessDate: text(row.business_date, '业务日期'),
    submittedAt: nullableText(row.submitted_at),
    closedAt: nullableText(row.closed_at),
    currentNodeKey: nullableText(row.current_node_key),
    isMine: boolean(row.is_mine),
    isAwaitingMe: boolean(row.is_awaiting_me),
    effects: Array.isArray(row.effects) ? row.effects.filter(isRecord).map(effectOf) : [],
  }
}

export type WorkflowCaseView = 'my_approvals' | 'my_decided' | 'my_requests'

export type WorkflowCaseQuery = {
  page: number
  pageSize: number
  view?: WorkflowCaseView
  status?: WorkflowCaseStatus | ''
  effectStatus?: WorkflowEffectStatus | ''
  type?: string
  businessDate?: string
}

/**
 * Closed case-type vocabulary used by the business modules that open cases.
 * The generic workflow API accepts the codes; the center only offers types
 * that a real module emits, so a filter can never return a permanently empty
 * fabricated category.
 */
export const WORKFLOW_CASE_TYPE_OPTIONS: ReadonlyArray<{ value: string; label: string }> = [
  { value: 'LEAVE_REQUEST', label: '请假申请' },
  { value: 'ATTENDANCE_CORRECTION', label: '考勤更正' },
  { value: 'TRAVEL_REQUEST', label: '出差申请' },
  { value: 'ONBOARDING', label: '入职办理' },
  { value: 'PROBATION_CONFIRM', label: '转正办理' },
  { value: 'ASSIGNMENT_CHANGE', label: '调岗/调部门' },
  { value: 'TERMS_CHANGE', label: '雇佣条款变更' },
  { value: 'CONTRACT_RENEW', label: '合同续签' },
  { value: 'OFFBOARD', label: '离职办理' },
  { value: 'REHIRE', label: '返聘办理' },
  { value: 'NOTICE_APPROVAL', label: '公告审批' },
  { value: 'PAYROLL_APPROVAL', label: '薪资审批' },
  { value: 'REQUISITION_APPROVAL', label: '招聘需求审批' },
  { value: 'OFFER_APPROVAL', label: 'Offer 审批' },
  { value: 'PERFORMANCE_APPEAL', label: '绩效申诉' },
]

export async function listWorkflowCases(query: WorkflowCaseQuery): Promise<PageResult<WorkflowCaseSummary>> {
  const params: Record<string, unknown> = { page: query.page, page_size: query.pageSize }
  if (query.view !== undefined) params.view = query.view
  if (query.status !== undefined && query.status !== '') params.status = query.status
  if (query.effectStatus !== undefined && query.effectStatus !== '') params.effect_status = query.effectStatus
  if (query.type !== undefined && query.type !== '') params.type = query.type
  if (query.businessDate !== undefined && query.businessDate !== '') params.business_date = query.businessDate

  const payload = await resourceGet<unknown>('/v1/cases', params)
  const page = pageFrom(payload)
  return { ...page, items: page.items.map(workflowCaseOf) }
}

export async function listMyWorkflowTasks(): Promise<WorkflowTask[]> {
  const payload = await resourceGet<unknown>('/v1/tasks/my')
  const page = pageFrom(payload)
  return page.items.map((value) => {
    const row = record(value, '待办任务')
    return {
      taskId: integer(row.task_id, '任务编号'),
      caseId: integer(row.case_id, '案例编号'),
      caseNo: nullableText(row.case_no),
      title: nullableText(row.title),
      nodeKey: nullableText(row.node_key),
      applicationStatus: nullableText(row.application_status),
      effectStatus: nullableText(row.effect_status),
      dueAt: nullableText(row.due_at),
      slaState: typeof row.sla_state === 'string' ? row.sla_state : 'NONE',
    }
  })
}

export async function getWorkflowCase(id: number): Promise<WorkflowCaseSummary> {
  return workflowCaseOf(await resourceGet<unknown>(`/v1/cases/${id}`))
}

export async function getWorkflowTimeline(id: number): Promise<WorkflowTimeline> {
  const payload = record(await resourceGet<unknown>(`/v1/cases/${id}/timeline`), '流程时间线')
  const application = record(payload.application, '申请状态')
  const effect = record(payload.effect, '生效状态')
  const items = Array.isArray(payload.items)
    ? payload.items.filter(isRecord).map((value): WorkflowTimelineItem => {
      const kind = value.type === 'DECISION' || value.type === 'EFFECT' ? value.type : 'WORKFLOW_EVENT'
      return {
        kind,
        eventType: nullableText(value.event_type),
        action: nullableText(value.action),
        effectType: nullableText(value.effect_type),
        effectStatus: nullableText(value.status),
        nodeKey: nullableText(value.node_key),
        actorUserId: nullableInteger(value.actor_user_id),
        comment: nullableText(value.comment),
        payload: isRecord(value.payload) ? value.payload : null,
        occurredAt: nullableText(value.occurred_at),
      }
    })
    : []

  return {
    caseId: integer(payload.case_id, '案例编号'),
    application: {
      status: text(application.status, '申请状态') as WorkflowCaseStatus,
      submittedAt: nullableText(application.submitted_at),
      closedAt: nullableText(application.closed_at),
    },
    effect: {
      status: text(effect.status, '生效状态') as WorkflowEffectStatus,
      effects: Array.isArray(effect.effects) ? effect.effects.filter(isRecord).map(effectOf) : [],
    },
    items,
  }
}

export async function decideWorkflowCase(
  caseId: number,
  action: WorkflowDecisionAction,
  comment: string,
  idempotencyKey: string,
): Promise<void> {
  await resourcePost(
    `/v1/cases/${caseId}/decisions`,
    { action, comment },
    { idempotencyKey },
  )
}

export type CompanyCalendar = {
  year: number
  timezone: string
  workweek: number[]
  standardDayMinutes: number
  version: number
  holidays: Array<{ date: string; name: string; type: 'PUBLIC' | 'COMPANY' | 'MAKEUP' | string; source: string | null }>
}

function calendarOf(value: unknown): CompanyCalendar {
  const row = record(value, '工作日历')
  if (!Array.isArray(row.workweek)) contractError('工作日规则')
  if (!Array.isArray(row.holidays)) contractError('节假日列表')
  return {
    year: integer(row.year, '年份'),
    timezone: text(row.timezone, '时区'),
    workweek: row.workweek
      .map((day) => integer(day, '工作日'))
      .filter((day) => day >= 1 && day <= 7),
    standardDayMinutes: integer(row.standard_day_minutes, '标准日分钟'),
    version: integer(row.version, '日历版本'),
    holidays: row.holidays.filter(isRecord).map((holiday) => ({
      date: text(holiday.date, '节假日日期'),
      name: text(holiday.name, '节假日名称'),
      type: text(holiday.type, '节假日类型'),
      source: nullableText(holiday.source),
    })),
  }
}

export async function getCompanyCalendar(year: number): Promise<CompanyCalendar> {
  return calendarOf(await resourceGet<unknown>('/v1/time/calendars', { year }))
}

export type CalendarPublishInput = {
  timezone: string
  workweek: number[]
  standardDayMinutes: number
  holidays: Array<{ date: string; name: string; type: string }>
}

export async function publishCompanyCalendar(
  input: CalendarPublishInput,
  idempotencyKey: string,
): Promise<unknown> {
  return resourcePost<unknown>(
    '/v1/system/calendars/publish',
    {
      timezone: input.timezone,
      workweek: input.workweek,
      standard_day_minutes: input.standardDayMinutes,
      holidays: input.holidays,
    },
    { idempotencyKey },
  )
}

export type FeatureFlag = { flag: string; enabled: boolean; risky: boolean }

export async function listFeatureFlags(): Promise<FeatureFlag[]> {
  const payload = record(await resourceGet<unknown>('/v1/platform/features'), '功能开关')
  return rowsOf(payload, '功能开关').map((row) => ({
    flag: text(row.flag, '功能开关'),
    enabled: boolean(row.enabled),
    risky: boolean(row.risky),
  }))
}

export async function updateFeatureFlag(flag: string, enabled: boolean, reason: string): Promise<FeatureFlag> {
  const payload = record(
    await resourcePut<unknown>(`/v1/platform/features/${encodeURIComponent(flag)}`, { enabled, reason }),
    '功能开关更新结果',
  )
  return {
    flag: text(payload.flag, '功能开关'),
    enabled: boolean(payload.enabled),
    risky: false,
  }
}

export type SystemParameter = {
  id: number
  key: string
  scope: string
  value: unknown
  sensitive: boolean
  masked: boolean
}

function parameterOf(value: unknown): SystemParameter {
  const row = record(value, '系统参数')
  return {
    id: integer(row.id, '参数编号'),
    key: text(row.key, '参数名称'),
    scope: text(row.scope, '参数范围'),
    value: row.value,
    sensitive: boolean(row.sensitive),
    masked: boolean(row.masked),
  }
}

export async function listSystemParameters(): Promise<PageResult<SystemParameter>> {
  const payload = await resourceGet<unknown>('/v1/system/parameters', { page: 1, page_size: 100 })
  const page = pageFrom(payload)
  return { ...page, items: page.items.map(parameterOf) }
}

export type ParameterUpdateInput = {
  key: string
  scope: string
  value: unknown
  sensitive: boolean
}

export async function updateSystemParameter(
  input: ParameterUpdateInput,
  idempotencyKey: string,
): Promise<SystemParameter> {
  return parameterOf(await resourcePut<unknown>(
    '/v1/system/parameters',
    { key: input.key, scope: input.scope, value: input.value, sensitive: input.sensitive },
    { idempotencyKey },
  ))
}

export type InboxNotification = {
  id: number
  type: string
  payload: Raw
  read: boolean
  createdAt: string | null
}

function notificationOf(value: unknown): InboxNotification {
  const row = record(value, '站内通知')
  return {
    id: integer(row.id, '通知编号'),
    type: text(row.type, '通知类型'),
    payload: isRecord(row.payload) ? row.payload : {},
    read: boolean(row.read),
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

export type AuthEvent = {
  id: number
  actorUserId: number | null
  eventType: string
  result: string
  requestId: string | null
  metadata: Raw
  occurredAt: string
}

export async function listAuthEvents(): Promise<PageResult<AuthEvent>> {
  // The endpoint currently supports only page/page_size. The page requests one
  // bounded projection and discloses that server-side filters are unavailable
  // instead of pretending to filter a paginated result client-side.
  const payload = pageFrom(await resourceGet<unknown>('/v1/auth/events', { page: 1, page_size: 100 }))
  return {
    ...payload,
    items: payload.items.map((row) => ({
      id: integer(row.id, '事件编号'),
      actorUserId: nullableInteger(row.user_id),
      eventType: text(row.event_type, '事件类型'),
      result: text(row.result, '事件结果'),
      requestId: nullableText(row.request_id),
      metadata: isRecord(row.metadata) ? row.metadata : {},
      occurredAt: text(row.occurred_at, '发生时间'),
    })),
  }
}

export function platformErrorCopy(error: unknown): string {
  return errorCopy(error)
}

export function isForbidden(error: unknown): boolean {
  return error instanceof ApiError && (error.code === 'FORBIDDEN' || error.status === 403)
}
