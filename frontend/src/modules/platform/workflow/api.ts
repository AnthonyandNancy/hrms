import { ApiError, errorCopy } from '@/api/errors'
import { resourceGet, resourcePost } from '@/api/resourceClient'

/**
 * Workflow Center transport boundary.
 *
 * The server owns visibility, state transitions, display names, idempotency
 * and effect status. This module only rejects malformed responses; it never
 * invents an approver, decision or successful effect.
 */

type Raw = Record<string, unknown>

export type PageResult<T> = {
  items: T[]
  page: number
  pageSize: number
  total: number
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

export type WorkflowDecisionAction = 'APPROVE' | 'REJECT' | 'RETURN'

export type WorkflowEffect = {
  effectId: number
  effectType: string
  status: string
  attempts: number
  requiresEscalation: boolean
  failureSummary: string | null
  failedAt: string | null
}

export type WorkflowDecisionContext = {
  isAwaitingMe: boolean
  canAdminOverride: boolean
  requiresReason: boolean
}

export type WorkflowCaseSummary = {
  id: number
  caseNo: string
  type: string
  subjectType: string
  subjectId: number | null
  title: string
  payload: Raw
  applicationStatus: WorkflowCaseStatus
  effectStatus: WorkflowEffectStatus
  requesterUserId: number
  requesterDisplayName: string | null
  currentAssigneeUserId: number | null
  currentAssigneeDisplayName: string | null
  decidedByUserId: number | null
  decidedByDisplayName: string | null
  templateId: number | null
  templateVersion: number
  businessDate: string
  submittedAt: string | null
  closedAt: string | null
  currentNodeKey: string | null
  isMine: boolean
  isAwaitingMe: boolean
  decisionContext: WorkflowDecisionContext
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
  slaState: string
}

export type WorkflowTimelineItem = {
  kind: 'WORKFLOW_EVENT' | 'DECISION' | 'EFFECT'
  eventType: string | null
  action: string | null
  effectType: string | null
  effectStatus: string | null
  effectId: number | null
  nodeKey: string | null
  actorUserId: number | null
  actorDisplayName: string | null
  decidedByDisplayName: string | null
  onBehalfOfUserId: number | null
  onBehalfOfDisplayName: string | null
  delegationId: number | null
  adminOverride: boolean
  reason: string | null
  comment: string | null
  attempts: number | null
  requiresEscalation: boolean
  failureSummary: string | null
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

export type WorkflowCaseView = 'my_approvals' | 'my_requests' | 'my_decided' | 'my_cc'

export type WorkflowCaseQuery = {
  page: number
  pageSize: number
  view: WorkflowCaseView
  status?: WorkflowCaseStatus | ''
  type?: string
  businessDate?: string
}

export type WorkflowTemplate = {
  id: number
  code: string
  name: string
  version: number
  status: string
  nodeCount: number
  effectCount: number
  definition: Raw
  createdAt: string | null
}

export type WorkflowTemplateCreateInput = {
  code: string
  name: string
  version: number
  status: string
  definition: Raw
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

function pageFrom(value: unknown): PageResult<Raw> {
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

function effectOf(value: unknown): WorkflowEffect {
  const effect = record(value, '生效记录')
  return {
    effectId: integer(effect.effect_id, '生效记录编号'),
    effectType: text(effect.effect_type, '生效类型'),
    status: text(effect.status, '生效状态'),
    attempts: typeof effect.attempts === 'number' ? effect.attempts : 0,
    requiresEscalation: boolean(effect.requires_escalation),
    failureSummary: nullableText(effect.failure_summary),
    failedAt: nullableText(effect.failed_at),
  }
}

function decisionContextOf(value: unknown): WorkflowDecisionContext {
  const context = isRecord(value) ? value : {}
  return {
    isAwaitingMe: boolean(context.is_awaiting_me),
    canAdminOverride: boolean(context.can_admin_override),
    requiresReason: boolean(context.requires_reason),
  }
}

export function workflowCaseOf(value: unknown): WorkflowCaseSummary {
  const row = record(value, '审批案例')
  const isAwaitingMe = boolean(row.is_awaiting_me)
  return {
    id: integer(row.id, '案例编号'),
    caseNo: text(row.case_no, '案例编号'),
    type: text(row.type, '案例类型'),
    subjectType: text(row.subject_type, '业务对象类型'),
    subjectId: nullableInteger(row.subject_id),
    title: text(row.title, '案例标题'),
    payload: isRecord(row.payload) ? row.payload : {},
    applicationStatus: text(row.application_status, '申请状态') as WorkflowCaseStatus,
    effectStatus: text(row.effect_status, '生效状态') as WorkflowEffectStatus,
    requesterUserId: integer(row.requester_user_id, '申请人'),
    requesterDisplayName: nullableText(row.requester_display_name),
    currentAssigneeUserId: nullableInteger(row.current_assignee_user_id),
    currentAssigneeDisplayName: nullableText(row.current_assignee_display_name),
    decidedByUserId: nullableInteger(row.decided_by_user_id),
    decidedByDisplayName: nullableText(row.decided_by_display_name),
    templateId: nullableInteger(row.template_id),
    templateVersion: typeof row.template_version === 'number' ? row.template_version : 0,
    businessDate: text(row.business_date, '业务日期'),
    submittedAt: nullableText(row.submitted_at),
    closedAt: nullableText(row.closed_at),
    currentNodeKey: nullableText(row.current_node_key),
    isMine: boolean(row.is_mine),
    isAwaitingMe,
    decisionContext: decisionContextOf(row.decision_context),
    effects: Array.isArray(row.effects) ? row.effects.filter(isRecord).map(effectOf) : [],
  }
}

export async function listWorkflowCases(query: WorkflowCaseQuery): Promise<PageResult<WorkflowCaseSummary>> {
  const params: Record<string, unknown> = {
    page: query.page,
    page_size: query.pageSize,
    view: query.view,
  }
  if (query.status !== undefined && query.status !== '') params.status = query.status
  if (query.type !== undefined && query.type !== '') params.type = query.type
  if (query.businessDate !== undefined && query.businessDate !== '') params.business_date = query.businessDate

  const page = pageFrom(await resourceGet<unknown>('/v1/cases', params))
  return { ...page, items: page.items.map(workflowCaseOf) }
}

export async function listMyWorkflowTasks(): Promise<WorkflowTask[]> {
  const page = pageFrom(await resourceGet<unknown>('/v1/tasks/my'))
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
        effectId: nullableInteger(value.effect_id),
        nodeKey: nullableText(value.node_key),
        actorUserId: nullableInteger(value.actor_user_id),
        actorDisplayName: nullableText(value.actor_display_name),
        decidedByDisplayName: nullableText(value.decided_by_display_name),
        onBehalfOfUserId: nullableInteger(value.on_behalf_of_user_id),
        onBehalfOfDisplayName: nullableText(value.on_behalf_of_display_name),
        delegationId: nullableInteger(value.delegation_id),
        adminOverride: boolean(value.admin_override),
        reason: nullableText(value.reason),
        comment: nullableText(value.comment),
        attempts: nullableInteger(value.attempts),
        requiresEscalation: boolean(value.requires_escalation),
        failureSummary: nullableText(value.failure_summary),
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
  reason?: string,
): Promise<void> {
  const body: Record<string, unknown> = { action, comment }
  if (reason !== undefined && reason.trim() !== '') body.reason = reason.trim()

  await resourcePost(
    `/v1/cases/${caseId}/decisions`,
    body,
    { idempotencyKey },
  )
}

export async function withdrawWorkflowCase(
  caseId: number,
  comment: string,
  idempotencyKey: string,
): Promise<void> {
  await resourcePost(`/v1/cases/${caseId}/withdraw`, { comment }, { idempotencyKey })
}

export async function retryWorkflowEffect(
  effectId: number,
  reason: string,
  idempotencyKey: string,
): Promise<{ effectStatus: string; attempts: number; requiresEscalation: boolean }> {
  const data = record(
    await resourcePost<unknown>(`/v1/effects/${effectId}/retry`, { reason }, { idempotencyKey }),
    '效果重试结果',
  )

  return {
    effectStatus: text(data.effect_status, '生效状态'),
    attempts: typeof data.attempts === 'number' ? data.attempts : 0,
    requiresEscalation: boolean(data.requires_escalation),
  }
}

function templateOf(value: unknown): WorkflowTemplate {
  const row = record(value, '流程模板')
  const definition = isRecord(row.definition) ? row.definition : {}
  return {
    id: integer(row.id, '模板编号'),
    code: text(row.code, '模板代码'),
    name: text(row.name, '模板名称'),
    version: integer(row.version, '模板版本'),
    status: text(row.status, '模板状态'),
    nodeCount: typeof row.node_count === 'number'
      ? row.node_count
      : Array.isArray(definition.nodes) ? definition.nodes.length : 0,
    effectCount: typeof row.effect_count === 'number'
      ? row.effect_count
      : Array.isArray(definition.effects) ? definition.effects.length : 0,
    definition,
    createdAt: nullableText(row.created_at),
  }
}

export async function listWorkflowTemplates(): Promise<WorkflowTemplate[]> {
  const page = pageFrom(await resourceGet<unknown>('/v1/workflow/templates'))
  return page.items
    .map(templateOf)
    .sort((left, right) => left.code.localeCompare(right.code) || right.version - left.version)
}

export async function createWorkflowTemplate(
  input: WorkflowTemplateCreateInput,
  idempotencyKey: string,
): Promise<WorkflowTemplate> {
  const data = record(
    await resourcePost<unknown>('/v1/workflow/templates', {
      code: input.code,
      name: input.name,
      version: input.version,
      status: input.status,
      definition: input.definition,
    }, { idempotencyKey }),
    '流程模板创建结果',
  )

  return {
    id: integer(data.id, '模板编号'),
    code: text(data.code, '模板代码'),
    name: text(data.name ?? input.name, '模板名称'),
    version: integer(data.version, '模板版本'),
    status: text(data.status, '模板状态'),
    nodeCount: typeof data.node_count === 'number' ? data.node_count : 0,
    effectCount: typeof data.effect_count === 'number' ? data.effect_count : 0,
    definition: input.definition,
    createdAt: nullableText(data.created_at),
  }
}

export function isForbidden(error: unknown): boolean {
  return error instanceof ApiError && (error.code === 'FORBIDDEN' || error.status === 403)
}

export { ApiError, errorCopy }
