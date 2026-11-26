import { ApiError } from '@/api/errors'
import { resourceGet, resourcePost } from '@/api/resourceClient'

/**
 * Reporting workspace API boundary.
 *
 * The backend owns authorization, projection scope, field policy, row caps and
 * export job state. This module only normalises transport shapes and formats
 * values for display; it never synthesises rows, metric values or decimal
 * scales. Money travels as integer minor units and rates remain exactly as the
 * API returns them.
 */

type RawRecord = Record<string, unknown>

export type Freshness = 'LIVE' | 'MATERIALIZED' | 'STALE' | string

export type MetricCardMetric = {
  code: string
  label: string
  value: number
  unit: string
  status: 'AVAILABLE' | 'UNAVAILABLE' | string
  reason: string | null
  trend: string | null
  trendDirection: 'UP' | 'DOWN' | 'FLAT' | string
  drilldownLabel: string
  sourceHash: string
  computedAt: string
}

export type DashboardSnapshot = {
  asOf: string
  freshness: string
  metrics: MetricCardMetric[]
}

export type MetricDrilldownColumn = {
  key: string
  label: string
}

export type MetricDrilldownRow = Record<string, string | number | boolean | null>

export type MetricDrilldown = {
  metric: string
  label: string
  asOf: string
  total: number
  unit: string
  status: string
  reason: string | null
  columns: MetricDrilldownColumn[]
  rows: MetricDrilldownRow[]
}

export type ProjectionMeta = {
  page: number
  pageSize: number
  total: number
  asOf: string
  freshness: Freshness
  sourceHash: string
  partial: boolean
  partialReason: string | null
}

export type ReportField = {
  key: string
  label: string
  profile: string
  exportable: boolean
}

export type ReportFilter = {
  key: string
  label: string
  type: 'ORG' | 'DATE_RANGE' | 'SELECT' | string
  options: string[]
}

export type ReportDefinition = {
  id: number
  code: string
  name: string
  description: string
  asOfSupported: boolean
  drilldownRoute: string | null
  fields: ReportField[]
  filters: ReportFilter[]
}

export type ReportDefinitionPage = ProjectionMeta & {
  items: ReportDefinition[]
}

export type ReportColumn = {
  key: string
  label: string
  profile: string
}

export type ReportCell = string | number | null

export type ReportRow = Record<string, ReportCell>

export type ReportResult = {
  runId: number | null
  definitionCode: string
  title: string
  columns: ReportColumn[]
  rows: ReportRow[]
  asOf: string
  freshness: Freshness
  sourceHash: string
  total: number
  partial: boolean
  partialReason: string | null
  generatedAt: string | null
}

export type ReportRunInput = {
  definition_code: string
  filters: Record<string, string>
  as_of: string
}

export type ExportPurpose =
  | 'BUSINESS_OPERATION'
  | 'AUDIT'
  | 'COMPLIANCE'
  | 'PAYROLL_RECONCILIATION'

export type ExportJobStatus = 'QUEUED' | 'RUNNING' | 'COMPLETED' | 'FAILED' | string

export type ExportJob = {
  id: string
  reportCode: string
  reportName: string
  status: ExportJobStatus
  purpose: ExportPurpose | string
  rowCount: number
  createdAt: string | null
  expiresAt: string | null
  downloadCount: number
  maxDownloads: number
  watermark: string
  fileName: string
  downloadUrl: string | null
  downloadToken: string | null
  fields: string[]
  sourceHash: string
}

export type ExportInput = {
  reportCode: string
  purpose: ExportPurpose
  fields: string[]
  asOf: string
  filters?: Record<string, string>
}

export type Notice = {
  id: number
  title: string
  summary: string
  body: string[]
  status: string
  audience: string
  audienceLabel: string
  orgUnitIds: number[]
  publishedAt: string | null
  authorName: string | null
  requiresAck: boolean
  acknowledged: boolean
  acknowledgementCount: number
  audienceCount: number
}

export type NoticeListResult = {
  items: Notice[]
  total: number
  partial: boolean
  partialReason: string | null
}

export type PublishNoticeInput = {
  title: string
  body: string
  audience: 'ALL' | 'ORG'
  audienceLabel: string
  orgUnitIds?: number[]
  requiresAck: boolean
}

export type OrgUnitOption = {
  id: number
  parentId: number | null
  name: string
  label: string
}

export const EXPORT_PURPOSE_OPTIONS: ReadonlyArray<{ value: ExportPurpose; label: string }> = [
  { value: 'BUSINESS_OPERATION', label: '业务运营' },
  { value: 'AUDIT', label: '审计' },
  { value: 'COMPLIANCE', label: '合规' },
  { value: 'PAYROLL_RECONCILIATION', label: '薪酬对账' },
]

const METRIC_LABELS: Record<string, string> = {
  headcount: '在职人数',
  probation: '试用期人数',
  turnover: '主动离职率',
  pending_approvals: '待审批',
  contract_expiring: '合同到期',
  leave_liability: '假期负债',
  payroll_cost_by_entity: '薪酬成本',
  recruitment_funnel: '招聘漏斗',
}

const FIELD_LABELS: Record<string, string> = {
  employee: '员工',
  employee_no: '员工编号',
  org_path: '组织',
  org_unit_id: '组织',
  status: '状态',
  end_date: '到期日',
  days_remaining: '剩余天数',
  case_no: '案例编号',
  title: '标题',
  age_days: '已等待天数',
  leave_type: '假期类型',
  balance_minutes: '余额（分钟）',
  stage: '阶段',
  count: '数量',
  gross_cents: '应发（最小货币单位）',
  net_cents: '实发（最小货币单位）',
  employer_cost_cents: '雇主成本（最小货币单位）',
  salary: '受限薪资明细',
  terminated_on: '终止日期',
  reason: '原因',
  primary: '名称',
  secondary: '组织/标识',
  value: '数值',
  window: '到期窗口',
  min_age_days: '最短等待天数',
}

const FILTER_LABELS: Record<string, string> = {
  org_unit_id: '组织',
  window: '到期窗口',
  min_age_days: '最短等待天数',
  leave_type: '假期类型',
}

const STATUS_LABELS: Record<string, string> = {
  ACTIVE: '在职',
  PROBATION: '试用期',
  LEAVE: '休假中',
  SUSPENDED: '停职',
  TERMINATED: '已离职',
  PENDING: '待处理',
  IN_APPROVAL: '审批中',
  APPROVED: '已批准',
  REJECTED: '已驳回',
  CANCELLED: '已取消',
  COMPLETED: '已完成',
  CLOSED: '已关闭',
  OPEN: '待处理',
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

const EXPORT_STATUS_LABELS: Record<string, string> = {
  QUEUED: '排队中',
  RUNNING: '生成中',
  COMPLETED: '已完成',
  FAILED: '失败',
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

function numberValue(value: unknown): number | null {
  if (typeof value === 'number' && Number.isFinite(value)) return value
  if (typeof value === 'string' && value.trim() !== '' && Number.isFinite(Number(value))) return Number(value)
  return null
}

function integer(value: unknown, fallback = 0): number {
  const parsed = numberValue(value)
  return parsed !== null && Number.isSafeInteger(parsed) ? parsed : fallback
}

function nullableInteger(value: unknown): number | null {
  const parsed = numberValue(value)
  return parsed !== null && Number.isSafeInteger(parsed) ? parsed : null
}

function stringList(value: unknown): string[] {
  if (!Array.isArray(value)) return []
  return value.map((item) => text(item).trim()).filter((item) => item !== '')
}

function numberList(value: unknown): number[] {
  if (!Array.isArray(value)) return []
  return value
    .map((item) => nullableInteger(item))
    .filter((item): item is number => item !== null && item > 0)
}

function idempotencyKey(prefix: string): string {
  const random = globalThis.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(36).slice(2)}`
  return `reporting-${prefix}-${random}`
}

function hasChinese(value: string): boolean {
  return /[\u3400-\u9fff]/.test(value)
}

export function metricLabel(code: string, fallback?: string | null): string {
  const mapped = METRIC_LABELS[code]
  if (mapped !== undefined) return mapped
  return fallback !== null && fallback !== undefined && hasChinese(fallback) ? fallback : '其他指标'
}

export function metricUnitText(metric: Pick<MetricCardMetric, 'code' | 'unit'>): string {
  if (metric.unit === 'COUNT') {
    return metric.code === 'headcount' || metric.code === 'probation' ? '人' : '项'
  }
  if (metric.unit === 'MINUTES') return '分钟'
  if (metric.unit === 'MINOR_UNITS' || metric.unit === 'CENTS') return '最小货币单位'
  if (metric.unit === 'RATIO') return '%'
  return ''
}

export function formatMetricValue(value: number): string {
  if (!Number.isSafeInteger(value)) return String(value)
  return new Intl.NumberFormat('zh-CN', { maximumFractionDigits: 0 }).format(value)
}

export function freshnessLabel(value: string): string {
  if (value === 'LIVE') return '实时'
  if (value === 'MATERIALIZED') return '缓存'
  if (value === 'STALE') return '已过期'
  return '未知状态'
}

export function fieldLabel(key: string, fallback?: string | null): string {
  const mapped = FIELD_LABELS[key]
  if (mapped !== undefined) return mapped
  return fallback !== null && fallback !== undefined && hasChinese(fallback) ? fallback : '其他字段'
}

export function filterLabel(key: string, fallback?: string | null): string {
  const mapped = FILTER_LABELS[key]
  if (mapped !== undefined) return mapped
  return fallback !== null && fallback !== undefined && hasChinese(fallback) ? fallback : '其他筛选'
}

export function statusText(value: string): string {
  const normalized = value.trim().toUpperCase()
  if (normalized === '') return '—'
  return STATUS_LABELS[normalized] ?? '其他状态'
}

export function noticeStatusText(value: string): string {
  const normalized = value.trim().toUpperCase()
  if (normalized === '') return '—'
  return NOTICE_STATUS_LABELS[normalized] ?? '其他状态'
}

export function exportStatusText(value: string): string {
  const normalized = value.trim().toUpperCase()
  if (normalized === '') return '—'
  return EXPORT_STATUS_LABELS[normalized] ?? '其他状态'
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

export function unavailableMetricReason(code: string): string {
  if (code === 'payroll_cost_by_entity') {
    return '薪酬成本投影尚未发布，当前部署不提供估算值。'
  }
  if (code === 'recruitment_funnel') {
    return '招聘漏斗投影尚未发布，当前部署不提供推断值。'
  }
  if (code === 'turnover') {
    return '离职率投影尚未发布，当前部署不提供推断值。'
  }
  return '该指标在当前部署中暂不可用，未以估算值替代。'
}

/**
 * Stable export field-policy denials. The API reason is translated here and
 * must never be rendered directly: it is a machine contract, not user copy.
 */
export function exportDenialCopy(error: unknown): string {
  if (error instanceof ApiError) {
    const reason = error.serverReason
    if (reason === 'EXPORT_FIELD_NOT_ALLOWED') {
      return '所选字段不在该报表的可导出字段白名单内，请移除后重试。'
    }
    if (reason === 'EXPORT_FIELD_HIDDEN') {
      return '所选字段为受限字段，按字段策略不可导出。'
    }
    if (reason === 'EXPORT_FIELDS_REQUIRED') {
      return '请至少选择一个可导出字段。'
    }
    if (reason === 'INVALID_EXPORT_PURPOSE') {
      return '导出目的不受支持，请重新选择。'
    }
    if (reason === 'EXPORT_ROW_CAP_EXCEEDED') {
      return '导出结果超过行数上限，请缩小筛选范围后重试。'
    }
    if (reason === 'EXPORT_NOT_READY') {
      return '导出文件尚未生成完成，请稍后重试。'
    }
    if (reason === 'EXPORT_EXPIRED') {
      return '导出文件已过期，请重新创建导出任务。'
    }
    if (reason === 'EXPORT_DOWNLOAD_LIMIT_REACHED') {
      return '该导出文件已达到下载次数上限。'
    }
    if (error.status === 403 || error.code === 'FORBIDDEN') {
      return '当前账号没有导出该报表的权限。'
    }
    if (error.status === 422 || error.code === 'VALIDATION_FAILED') {
      return '导出请求未通过校验，请检查所选字段与报表条件。'
    }
    if (error.code === 'SERVICE_UNAVAILABLE') {
      return '报表投影暂不可用，导出任务未创建。'
    }
  }
  return '导出任务未创建，请稍后重试。'
}

function normaliseMetric(raw: unknown): MetricCardMetric | null {
  if (!isRecord(raw)) return null
  const code = text(raw.code).trim()
  const value = numberValue(raw.value)
  if (code === '' || value === null) return null

  return {
    code,
    label: text(raw.label),
    value,
    unit: text(raw.unit),
    status: text(raw.status, 'UNAVAILABLE').toUpperCase(),
    reason: nullableText(raw.reason),
    trend: nullableText(raw.trend),
    trendDirection: text(raw.trendDirection, 'FLAT').toUpperCase(),
    drilldownLabel: text(raw.drilldownLabel),
    sourceHash: text(raw.sourceHash),
    computedAt: text(raw.computedAt),
  }
}

export async function getDashboard(asOf: string): Promise<DashboardSnapshot> {
  const raw = await resourceGet<unknown>('/v1/reporting/dashboard', { as_of: asOf })
  if (!isRecord(raw)) return { asOf, freshness: '', metrics: [] }

  return {
    asOf: text(raw.asOf, asOf),
    freshness: text(raw.freshness),
    metrics: Array.isArray(raw.metrics)
      ? raw.metrics.map(normaliseMetric).filter((metric): metric is MetricCardMetric => metric !== null)
      : [],
  }
}

function normaliseDrilldownColumn(raw: unknown): MetricDrilldownColumn | null {
  if (!isRecord(raw)) return null
  const key = text(raw.key).trim()
  if (key === '') return null
  return { key, label: fieldLabel(key, text(raw.label)) }
}

function normaliseDrilldownRow(raw: unknown): MetricDrilldownRow | null {
  if (!isRecord(raw)) return null
  const row: MetricDrilldownRow = {}
  for (const [key, value] of Object.entries(raw)) {
    if (value === null || typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') {
      row[key] = value
    }
  }
  return row
}

export async function getMetricDrilldown(metric: string, asOf: string): Promise<MetricDrilldown> {
  const raw = await resourceGet<unknown>(
    `/v1/reporting/dashboard/metrics/${encodeURIComponent(metric)}`,
    { as_of: asOf },
  )
  if (!isRecord(raw)) {
    return {
      metric,
      label: metricLabel(metric),
      asOf,
      total: 0,
      unit: '',
      status: 'UNAVAILABLE',
      reason: null,
      columns: [],
      rows: [],
    }
  }

  const rawRows = Array.isArray(raw.items) ? raw.items : Array.isArray(raw.rows) ? raw.rows : []

  return {
    metric: text(raw.metric, metric),
    label: metricLabel(text(raw.metric, metric), text(raw.label)),
    asOf: text(raw.asOf, asOf),
    total: integer(raw.total),
    unit: text(raw.unit),
    status: text(raw.status, 'UNAVAILABLE').toUpperCase(),
    reason: nullableText(raw.reason),
    columns: Array.isArray(raw.columns)
      ? raw.columns.map(normaliseDrilldownColumn).filter((column): column is MetricDrilldownColumn => column !== null)
      : [],
    rows: rawRows.map(normaliseDrilldownRow).filter((row): row is MetricDrilldownRow => row !== null),
  }
}

function normaliseField(raw: unknown): ReportField | null {
  if (!isRecord(raw)) return null
  const key = text(raw.key).trim()
  if (key === '') return null
  const profile = text(raw.profile, 'P1_INTERNAL')
  return {
    key,
    label: fieldLabel(key, text(raw.label)),
    profile,
    exportable: raw.exportable === true || (raw.exportable === undefined && profile !== 'P3_RESTRICTED'),
  }
}

function normaliseFilter(raw: unknown): ReportFilter | null {
  if (!isRecord(raw)) return null
  const key = text(raw.key).trim()
  if (key === '') return null
  return {
    key,
    label: filterLabel(key, text(raw.label)),
    type: text(raw.type, 'SELECT').toUpperCase(),
    options: stringList(raw.options),
  }
}

function normaliseDefinition(raw: unknown): ReportDefinition | null {
  if (!isRecord(raw)) return null
  const code = text(raw.code).trim()
  if (code === '') return null

  return {
    id: integer(raw.id),
    code,
    name: text(raw.name, code),
    description: text(raw.description),
    asOfSupported: raw.asOfSupported !== false,
    drilldownRoute: nullableText(raw.drilldownRoute),
    fields: Array.isArray(raw.fields)
      ? raw.fields.map(normaliseField).filter((field): field is ReportField => field !== null)
      : [],
    filters: Array.isArray(raw.filters)
      ? raw.filters.map(normaliseFilter).filter((filter): filter is ReportFilter => filter !== null)
      : [],
  }
}

function normaliseProjection(raw: unknown, fallbackPage = 1, fallbackPageSize = 20): ProjectionMeta {
  const payload = isRecord(raw) ? raw : {}
  return {
    page: integer(payload.page, fallbackPage),
    pageSize: integer(payload.page_size, fallbackPageSize),
    total: integer(payload.total),
    asOf: text(payload.as_of),
    freshness: text(payload.freshness).toUpperCase(),
    sourceHash: text(payload.source_hash),
    partial: payload.partial === true,
    partialReason: nullableText(payload.partial_reason),
  }
}

export async function listReportDefinitions(): Promise<ReportDefinitionPage> {
  const raw = await resourceGet<unknown>('/v1/reporting/definitions', { page: 1, page_size: 100 })
  const meta = normaliseProjection(raw)
  const items = isRecord(raw) && Array.isArray(raw.items)
    ? raw.items.map(normaliseDefinition).filter((definition): definition is ReportDefinition => definition !== null)
    : []

  return {
    ...meta,
    total: meta.total > 0 ? meta.total : items.length,
    items,
  }
}

function normaliseReportColumn(raw: unknown): ReportColumn | null {
  if (!isRecord(raw)) return null
  const key = text(raw.key).trim()
  if (key === '') return null
  return {
    key,
    label: fieldLabel(key, text(raw.label)),
    profile: text(raw.profile, 'P1_INTERNAL'),
  }
}

function normaliseReportRow(raw: unknown): ReportRow | null {
  if (!isRecord(raw)) return null
  const row: ReportRow = {}
  for (const [key, value] of Object.entries(raw)) {
    if (value === null) {
      row[key] = null
    } else if (typeof value === 'string' || typeof value === 'number') {
      row[key] = value
    } else if (typeof value === 'boolean') {
      row[key] = value ? '是' : '否'
    } else {
      row[key] = null
    }
  }
  return row
}

function normaliseReportResult(raw: unknown): ReportResult {
  const payload = isRecord(raw) ? raw : {}
  const rawRows = Array.isArray(payload.rows) ? payload.rows : Array.isArray(payload.items) ? payload.items : []
  const rows = rawRows.map(normaliseReportRow).filter((row): row is ReportRow => row !== null)
  const total = nullableInteger(payload.total) ?? nullableInteger(payload.totalRows) ?? rows.length

  return {
    runId: nullableInteger(payload.runId),
    definitionCode: text(payload.definitionCode),
    title: text(payload.title, '报表结果'),
    columns: Array.isArray(payload.columns)
      ? payload.columns.map(normaliseReportColumn).filter((column): column is ReportColumn => column !== null)
      : [],
    rows,
    asOf: text(payload.as_of ?? payload.asOf),
    freshness: text(payload.freshness).toUpperCase(),
    sourceHash: text(payload.source_hash ?? payload.sourceHash),
    total,
    partial: payload.partial === true,
    partialReason: nullableText(payload.partial_reason ?? payload.partialReason),
    generatedAt: nullableText(payload.generatedAt),
  }
}

export async function runReport(input: ReportRunInput): Promise<ReportResult> {
  const raw = await resourcePost<unknown>('/v1/reporting/run', {
    definition_code: input.definition_code,
    filters: input.filters,
    as_of: input.as_of,
  })
  return normaliseReportResult(raw)
}

function normaliseExportJob(raw: unknown): ExportJob {
  const payload = isRecord(raw) ? raw : {}
  const fields = Array.isArray(payload.fields)
    ? stringList(payload.fields)
    : stringList(payload.fieldWhitelist)

  return {
    id: text(payload.id),
    reportCode: text(payload.reportCode),
    reportName: text(payload.reportName, text(payload.reportCode)),
    status: text(payload.status).toUpperCase(),
    purpose: text(payload.purpose),
    rowCount: integer(payload.rowCount),
    createdAt: nullableText(payload.createdAt),
    expiresAt: nullableText(payload.expiresAt),
    downloadCount: integer(payload.downloadCount),
    maxDownloads: integer(payload.maxDownloads),
    watermark: text(payload.watermark),
    fileName: text(payload.fileName),
    downloadUrl: nullableText(payload.downloadUrl),
    downloadToken: nullableText(payload.downloadToken),
    fields,
    sourceHash: text(payload.source_hash ?? payload.sourceHash),
  }
}

export type ExportHistoryPage = ProjectionMeta & {
  items: ExportJob[]
}

export async function listExportHistory(): Promise<ExportHistoryPage> {
  const raw = await resourceGet<unknown>('/v1/reporting/exports', { page: 1, page_size: 50 })
  const meta = normaliseProjection(raw)
  const items = isRecord(raw) && Array.isArray(raw.items)
    ? raw.items.map(normaliseExportJob)
    : []
  return { ...meta, total: meta.total > 0 ? meta.total : items.length, items }
}

export async function createExport(input: ExportInput): Promise<ExportJob> {
  const raw = await resourcePost<unknown>(
    '/v1/reporting/exports',
    {
      reportCode: input.reportCode,
      purpose: input.purpose,
      fields: [...input.fields],
      asOf: input.asOf,
      filters: input.filters ?? {},
    },
    { idempotencyKey: idempotencyKey('export') },
  )
  return normaliseExportJob(raw)
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
    publishedAt: nullableText(raw.publishedAt),
    authorName: nullableText(raw.authorName),
    requiresAck: raw.requiresAck === true,
    acknowledged: raw.acknowledged === true,
    acknowledgementCount: integer(raw.acknowledgementCount),
    audienceCount: integer(raw.audienceCount),
  }
}

export async function listNotices(filter: 'all' | 'unread' | 'acknowledged' | 'managed' = 'all'): Promise<NoticeListResult> {
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

  const raw = await resourcePost<unknown>(
    '/v1/engagement/notices',
    payload,
    { idempotencyKey: idempotencyKey('notice-publish') },
  )
  const notice = normaliseNotice(raw)
  if (notice === null) throw new TypeError('公告发布接口未返回有效记录。')
  return notice
}

/**
 * Org units come from the real as-of tree endpoint. The endpoint returns a flat
 * projection; indentation is presentation only and never invents a hierarchy.
 */
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
