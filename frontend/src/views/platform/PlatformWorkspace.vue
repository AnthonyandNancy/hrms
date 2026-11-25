<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { ApiError } from '@/api/errors'
import { resourceDelete, resourceGet, resourcePatch, resourcePost } from '@/api/resourceClient'
import { legalEntityId } from '@/api/http'
import { usePageError } from '@/composables/usePageError'
import { formatBusinessDate, formatDateTimeInTimezone, todayBusinessDate } from '@/utils/format'
import { FIELD_PROFILE_LABEL } from '@/utils/status'
import { useSessionStore } from '@/stores/session'
import LoadingState from '@/components/LoadingState.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import NoActiveEmploymentState from '@/components/NoActiveEmploymentState.vue'
import { platformPageConfig, platformPageConfigForPath } from './catalog'
import type { PlatformPageConfig } from './catalog'
import {
  actionErrorInfo,
  idText,
  isRecord,
  nestedRows,
  nextIdempotencyKey,
  partialFlag,
  recordFrom,
  rowNumber,
  rowText,
  rowsFrom,
  statusText,
  summariseRecord,
  textOf,
  type ActionErrorInfo,
  type PlatformRow,
} from './platformRead'

const props = defineProps<{
  componentKey?: string
}>()

const route = useRoute()
const pageError = usePageError()
const session = useSessionStore()
const entityId = legalEntityId()

const loading = ref(false)
const loaded = ref(false)
const partial = ref(false)
const partialFailure = ref<ActionErrorInfo | null>(null)
const successMessage = ref('')
const actionError = ref<ActionErrorInfo | null>(null)

const dashboardData = ref<PlatformRow | null>(null)
const metricData = ref<PlatformRow | null>(null)
const definitions = ref<PlatformRow[]>([])
const reportResult = ref<PlatformRow | null>(null)
const reportUnavailable = ref('')
const exportJob = ref<PlatformRow | null>(null)
const exportHistory = ref<PlatformRow[]>([])
const subscriptions = ref<PlatformRow[]>([])
const talentPerformance = ref<PlatformRow | null>(null)
const talentTraining = ref<PlatformRow | null>(null)
const notices = ref<PlatformRow[]>([])
const selectedNotice = ref<PlatformRow | null>(null)
const meData = ref<PlatformRow | null>(null)
const mfaEnrollment = ref<PlatformRow | null>(null)
const recoveryCodes = ref<string[]>([])
const users = ref<PlatformRow[]>([])
const roles = ref<PlatformRow[]>([])
const permissionsData = ref<PlatformRow | null>(null)
const capabilities = ref<PlatformRow[]>([])
const tasks = ref<PlatformRow[]>([])
const workflowTemplates = ref<PlatformRow[]>([])
const workspaceData = ref<PlatformRow | null>(null)
const calendarData = ref<PlatformRow | null>(null)
const leaveTypes = ref<PlatformRow[]>([])
const auditEvents = ref<PlatformRow[]>([])
const accessReview = ref<PlatformRow | null>(null)

const noticeFilter = ref<'all' | 'unread' | 'acknowledged' | 'managed'>('all')
const selectedUser = ref<PlatformRow | null>(null)
const bindings = ref<PlatformRow[]>([])
const calendarYear = ref(2026)
const exportReportCode = ref('')
const selectedExportFields = ref<string[]>([])
const exportPurpose = ref('BUSINESS_OPERATION')

const definitionForm = reactive({
  code: '',
  name: '',
  description: '',
  sourceProjection: '',
  fields: [] as string[],
  filters: [] as string[],
})

const subscriptionForm = reactive({
  definitionCode: '',
  recipientUserId: '',
  frequency: 'MONTHLY',
})

const noticeForm = reactive({
  title: '',
  body: '',
  audience: 'ALL' as 'ALL' | 'ORG' | 'ROLE',
  audienceLabel: '全体员工',
  orgUnitIds: '',
  roleCodes: '',
  requiresAck: true,
})

const userForm = reactive({
  username: '',
  email: '',
  password: '',
  isPrivileged: false,
})

const userStatusForm = reactive({
  status: 'ACTIVE',
  reason: '',
})

const roleForm = reactive({
  roleCode: '',
  reason: '',
  validFrom: '',
  validTo: '',
  anchorType: 'LEGAL_ENTITY',
  anchorId: '',
  acknowledgeSodRisk: false,
})

const bindingForm = reactive({
  capabilityCode: '',
  anchorType: 'LEGAL_ENTITY',
  anchorId: '',
  fieldProfile: 'P1_INTERNAL',
  purposeCode: '',
  validFrom: '',
  validTo: '',
  acknowledgeSodRisk: false,
})

const mfaForm = reactive({
  factorId: '',
  code: '',
})

const config = computed<PlatformPageConfig | null>(() => {
  const metaRegistry = route.meta.registry
  const metaKey =
    isRecord(metaRegistry) && typeof metaRegistry.componentKey === 'string'
      ? metaRegistry.componentKey
      : null
  const key = props.componentKey ?? metaKey
  if (key !== null) return platformPageConfig(key)
  return platformPageConfigForPath(route.path)
})

const pageTitle = computed(() => config.value?.title ?? '平台页面')
const pageDescription = computed(() => config.value?.description ?? '当前路由没有登记页面配置。')
const mode = computed(() => config.value?.mode ?? null)
const gap = computed(() => config.value?.gap ?? null)
const gapPath = computed(() => config.value?.gapPath ?? null)

const dashboardMetrics = computed(() => rowsFrom(dashboardData.value, 'metrics'))
const activeMetricKey = computed(() => {
  if (config.value?.metricKey) return config.value.metricKey
  if (config.value?.key === 'dashboard/workforce') return 'probation'
  if (config.value?.key === 'dashboard/tasks') return 'pending_approvals'
  if (config.value?.key === 'dashboard/changes') return 'contract_expiring'
  return null
})
const pendingApprovals = computed(() => rowsFrom(dashboardData.value?.pendingApprovals))
const contractAlerts = computed(() => rowsFrom(dashboardData.value?.contractAlerts))
const recentNotices = computed(() => rowsFrom(dashboardData.value?.recentNotices))
const metricRows = computed(() => rowsFrom(metricData.value?.items))
const metricColumns = computed(() => nestedRows(metricData.value, 'columns'))
const metricUnavailable = computed(() => rowText(metricData.value ?? {}, 'status') === 'UNAVAILABLE')

const departmentGroups = computed(() => {
  const groups = new Map<string, number>()
  for (const row of metricRows.value) {
    const org = rowText(row, 'org_path', '')
    const fallback = rowText(row, 'secondary', '').split(' · ')[0] ?? ''
    const key = org !== '' && org !== '—' ? org : fallback !== '' ? fallback : '未返回组织'
    groups.set(key, (groups.get(key) ?? 0) + (rowNumber(row, 'value') ?? 1))
  }
  return [...groups.entries()].map(([label, count]) => ({ label, count }))
})

const reportDefinition = computed(() =>
  definitions.value.find((definition) => rowText(definition, 'code') === config.value?.reportCode) ?? null,
)
const reportColumns = computed(() => nestedRows(reportResult.value, 'columns'))
const reportRows = computed(() => rowsFrom(reportResult.value?.rows))
const definitionFields = computed(() => nestedRows(reportDefinition.value, 'fields'))
const definitionFilters = computed(() => nestedRows(reportDefinition.value, 'filters'))
const selectedExportDefinition = computed(
  () => definitions.value.find((definition) => rowText(definition, 'code') === exportReportCode.value) ?? null,
)
const selectedExportFieldsAvailable = computed(() =>
  nestedRows(selectedExportDefinition.value, 'fields').filter((field) => field.exportable !== false),
)
const builderFieldsAvailable = computed(() =>
  definitions.value
    .filter((definition) => rowText(definition, 'sourceProjection') === definitionForm.sourceProjection)
    .flatMap((definition) => nestedRows(definition, 'fields')),
)
const builderFiltersAvailable = computed(() =>
  definitions.value
    .filter((definition) => rowText(definition, 'sourceProjection') === definitionForm.sourceProjection)
    .flatMap((definition) => nestedRows(definition, 'filters')),
)
const sourceProjections = computed(() => {
  const seen = new Map<string, string>()
  for (const definition of definitions.value) {
    const source = rowText(definition, 'sourceProjection', '')
    if (source !== '' && source !== '—') seen.set(source, source)
  }
  return [...seen.keys()]
})
const exportDownloadHref = computed(() => {
  if (exportJob.value === null) return ''
  const downloadUrl = rowText(exportJob.value, 'downloadUrl', '')
  if (downloadUrl !== '' && downloadUrl !== '—') return downloadUrl
  const token = rowText(exportJob.value, 'downloadToken', '')
  const id = rowText(exportJob.value, 'id', '')
  return token === '' || token === '—' || id === '' ? '' : `/v1/reporting/exports/${id}/download?token=${encodeURIComponent(token)}`
})

const menus = computed(() => rowsFrom(permissionsData.value, 'menus'))
const permissionActions = computed<string[]>(() => {
  const value = permissionsData.value?.actions
  return Array.isArray(value) ? value.filter((item): item is string => typeof item === 'string') : []
})
const fieldCapabilities = computed<string[]>(() => {
  const value = permissionsData.value?.field_capabilities
  return Array.isArray(value) ? value.filter((item): item is string => typeof item === 'string') : []
})
const scopeLabels = computed(() => rowsFrom(permissionsData.value, 'scope_labels'))
const capabilityRows = computed(() => capabilities.value)
const visiblePages = computed(() => rowsFrom(permissionsData.value, 'pages'))
const bindingsRows = computed(() => bindings.value)
const accessEvents = computed(() => auditEvents.value)
const unusedBindings = computed(() => rowsFrom(accessReview.value, 'unused_high_risk_bindings'))
const sodRisks = computed(() => rowsFrom(accessReview.value, 'sod_risks'))

const profile = computed<PlatformRow | null>(() => recordFrom(workspaceData.value?.profile))
const attendanceSummary = computed<PlatformRow | null>(() => recordFrom(workspaceData.value?.attendanceSummary))
const leaveBalances = computed(() => rowsFrom(workspaceData.value?.leaveBalances))
const payslips = computed(() => rowsFrom(workspaceData.value?.payslips))
const workspaceRequests = computed(() => rowsFrom(workspaceData.value?.myRequests))
const workspaceNotices = computed(() => rowsFrom(workspaceData.value?.notices))
const holidays = computed(() => rowsFrom(calendarData.value, 'holidays'))
const workweek = computed(() => {
  const value = calendarData.value?.workweek
  return Array.isArray(value) ? value.map((item) => textOf(item)).filter((item) => item !== '—') : []
})

const empty = computed(() => {
  switch (mode.value) {
    case 'dashboard':
      return (
        dashboardMetrics.value.length === 0 &&
        pendingApprovals.value.length === 0 &&
        contractAlerts.value.length === 0 &&
        recentNotices.value.length === 0 &&
        metricRows.value.length === 0
      )
    case 'reports-index':
    case 'reports-builder':
      return definitions.value.length === 0
    case 'reports-subscriptions':
      return definitions.value.length === 0 && subscriptions.value.length === 0
    case 'reports-exports':
      return definitions.value.length === 0 && exportHistory.value.length === 0
    case 'reports-detail':
      return definitions.value.length === 0 && reportResult.value === null
    case 'reports-talent':
      return talentPerformance.value === null && talentTraining.value === null && definitions.value.length === 0
    case 'notice-list':
    case 'notice-gap':
      return notices.value.length === 0
    case 'account':
      return meData.value === null
    case 'users':
      return users.value.length === 0
    case 'roles':
      return roles.value.length === 0
    case 'menus':
    case 'permissions':
      return menus.value.length === 0 && permissionActions.value.length === 0 && capabilityRows.value.length === 0
    case 'data-scopes':
    case 'field-policies':
    case 'settings':
      return permissionActions.value.length === 0 && fieldCapabilities.value.length === 0 && scopeLabels.value.length === 0
    case 'workflow':
      return tasks.value.length === 0 && workflowTemplates.value.length === 0
    case 'self-service':
      return workspaceData.value === null
    case 'calendars':
      return calendarData.value === null && leaveTypes.value.length === 0
    case 'notifications':
      return tasks.value.length === 0 && notices.value.length === 0
    case 'audit':
      return auditEvents.value.length === 0 && unusedBindings.value.length === 0 && sodRisks.value.length === 0
    case 'benefits':
      return true
    case 'notice-create':
      return false
    default:
      return true
  }
})

function writeHeaders(extra: Record<string, string> = {}): Record<string, string> {
  return {
    ...extra,
    ...(session.csrfToken ? { 'X-CSRF-Token': session.csrfToken } : {}),
  }
}

function resetFeedback(): void {
  actionError.value = null
  successMessage.value = ''
  partialFailure.value = null
  pageError.clear()
}

function isAuthOrForbidden(error: unknown): boolean {
  return error instanceof ApiError && (error.status === 401 || error.status === 403 || error.code === 'UNAUTHENTICATED' || error.code === 'FORBIDDEN')
}

/**
 * Keep the successful projections visible when a secondary call fails.
 * Authentication/forbidden failures still route through usePageError; server,
 * network and validation failures become an explicit partial state with the
 * request id preserved.
 */
async function settleReads(requests: Array<Promise<unknown>>): Promise<unknown[]> {
  const results = await Promise.allSettled(requests)
  const rejected = results.find((result): result is PromiseRejectedResult => result.status === 'rejected')
  const forbidden = results.find(
    (result): result is PromiseRejectedResult => result.status === 'rejected' && isAuthOrForbidden(result.reason),
  )

  if (forbidden) throw forbidden.reason
  if (rejected && results.every((result) => result.status === 'rejected')) throw rejected.reason
  if (rejected) partialFailure.value = actionErrorInfo(rejected.reason)

  return results.map((result) => (result.status === 'fulfilled' ? result.value : null))
}

function failAction(error: unknown): void {
  actionError.value = actionErrorInfo(error)
}

function paramDate(value: string): string | null {
  return /^\d{4}-\d{2}-\d{2}$/.test(value) ? value : null
}

function parsePositiveIds(value: string): number[] {
  return value
    .split(',')
    .map((item) => item.trim())
    .filter((item) => /^\d+$/.test(item))
    .map((item) => Number(item))
}

function parseRoleCodes(value: string): string[] {
  return value
    .split(',')
    .map((item) => item.trim().toUpperCase())
    .filter((item) => /^[A-Z][A-Z0-9_]{2,63}$/.test(item))
}

function bodyParagraphs(value: unknown): string[] {
  return Array.isArray(value) ? value.filter((item): item is string => typeof item === 'string' && item.trim() !== '') : []
}

function capabilityLabel(code: string): string {
  const capability = capabilityRows.value.find((row) => rowText(row, 'code') === code)
  return capability ? `${rowText(capability, 'code')} · ${rowText(capability, 'description', '')}` : code
}

function nodeSummary(template: PlatformRow): string {
  const definition = recordFrom(template.definition)
  const nodes = definition ? nestedRows(definition, 'nodes') : []
  if (nodes.length === 0) return '模板未返回节点定义'
  return nodes.map((node) => `${rowText(node, 'sequence')}. ${rowText(node, 'name')}（${rowText(node, 'type')}）`).join(' → ')
}

async function loadDashboard(): Promise<void> {
  const asOf = todayBusinessDate()
  const [dashboardPayload, metricPayload] = await settleReads([
    resourceGet<unknown>('/v1/reporting/dashboard', { as_of: asOf }),
    activeMetricKey.value
      ? resourceGet<unknown>(`/v1/reporting/dashboard/metrics/${activeMetricKey.value}`, { as_of: asOf })
      : Promise.resolve(null),
  ])
  dashboardData.value = recordFrom(dashboardPayload)
  metricData.value = recordFrom(metricPayload)
  partial.value = partialFlag(dashboardPayload, metricPayload)
  const returnedAsOf = rowText(dashboardData.value ?? {}, 'asOf', asOf)

  if (activeMetricKey.value === 'contract_expiring') {
    const payload = await resourceGet<unknown>('/v1/reporting/dashboard/metrics/contract_expiring', {
      as_of: returnedAsOf,
      window: 30,
    })
    metricData.value = recordFrom(payload)
    partial.value ||= partialFlag(payload)
  }
}

async function loadDefinitions(): Promise<void> {
  const payload = await resourceGet<unknown>('/v1/reporting/definitions')
  definitions.value = rowsFrom(payload)
  partial.value = partialFlag(payload)
  const first = definitions.value[0]
  exportReportCode.value = rowText(first ?? {}, 'code', '')
  selectedExportFields.value = nestedRows(first, 'fields')
    .filter((field) => field.exportable !== false)
    .map((field) => rowText(field, 'key'))
    .filter((key) => key !== '—')
}

async function loadTalentReports(): Promise<void> {
  const [performance, training, definitionsPayload] = await settleReads([
    resourceGet<unknown>('/v1/performance/reports'),
    resourceGet<unknown>('/v1/training/report'),
    resourceGet<unknown>('/v1/reporting/definitions', { page: 1, page_size: 100 }),
  ])
  talentPerformance.value = recordFrom(performance)
  talentTraining.value = recordFrom(training)
  definitions.value = rowsFrom(definitionsPayload)
  partial.value = partialFlag(performance, training, definitionsPayload)
}

async function loadBuilder(): Promise<void> {
  await loadDefinitions()
  if (definitionForm.sourceProjection === '') definitionForm.sourceProjection = sourceProjections.value[0] ?? ''
}

async function loadSubscriptions(): Promise<void> {
  const [definitionsPayload, subscriptionsPayload] = await settleReads([
    resourceGet<unknown>('/v1/reporting/definitions', { page: 1, page_size: 100 }),
    resourceGet<unknown>('/v1/reporting/subscriptions', { page: 1, page_size: 50 }),
  ])
  definitions.value = rowsFrom(definitionsPayload)
  subscriptions.value = rowsFrom(subscriptionsPayload)
  const first = definitions.value[0]
  subscriptionForm.definitionCode = rowText(first ?? {}, 'code', '')
  partial.value = partialFlag(definitionsPayload, subscriptionsPayload)
}

async function loadReportsDetail(): Promise<void> {
  await loadDefinitions()
  reportUnavailable.value = ''
  const reportCode = config.value?.reportCode
  if (reportCode === undefined || reportDefinition.value === null) return
  try {
    const payload = await resourcePost<unknown>(
      '/v1/reporting/run',
      { definition_code: reportCode, filters: {} },
      { idempotencyKey: nextIdempotencyKey('report-run'), headers: writeHeaders() },
    )
    reportResult.value = recordFrom(payload)
    partial.value ||= partialFlag(payload)
  } catch (reason) {
    // 503 is the Reporting module's explicit "this projection is not
    // published" contract. It is an expected unavailable state, not a crash,
    // and must never be rendered as a generic page failure.
    if (reason instanceof ApiError && reason.code === 'SERVICE_UNAVAILABLE') {
      reportUnavailable.value = reason.message
      reportResult.value = null
      return
    }
    throw reason
  }
}

async function loadNotices(): Promise<void> {
  const payload = await resourceGet<unknown>('/v1/engagement/notices', { filter: noticeFilter.value })
  notices.value = rowsFrom(payload)
  partial.value = partialFlag(payload)
}

async function loadAccount(): Promise<void> {
  const payload = await resourceGet<unknown>('/v1/me')
  meData.value = recordFrom(payload)
  partial.value = partialFlag(payload)
}

async function loadUsers(): Promise<void> {
  const payload = await resourceGet<unknown>('/v1/users', { page: 1, page_size: 50, legal_entity_id: entityId })
  users.value = rowsFrom(payload)
  partial.value = partialFlag(payload)
}

async function loadRoles(): Promise<void> {
  const payload = await resourceGet<unknown>('/v1/roles', { legal_entity_id: entityId })
  roles.value = rowsFrom(payload, 'roles')
  partial.value = partialFlag(payload)
}

async function loadPermissions(withCapabilities: boolean): Promise<void> {
  const [permissionPayload, capabilityPayload] = await settleReads([
    resourceGet<unknown>('/v1/me/permissions'),
    withCapabilities ? resourceGet<unknown>('/v1/capabilities', { legal_entity_id: entityId }) : Promise.resolve(null),
  ])
  permissionsData.value = recordFrom(permissionPayload)
  capabilities.value = rowsFrom(capabilityPayload, 'capabilities')
  partial.value = partialFlag(permissionPayload, capabilityPayload)
}

async function loadWorkflow(): Promise<void> {
  const [taskPayload, templatePayload] = await settleReads([
    resourceGet<unknown>('/v1/tasks/my'),
    resourceGet<unknown>('/v1/workflow/templates'),
  ])
  tasks.value = rowsFrom(taskPayload)
  workflowTemplates.value = rowsFrom(templatePayload)
  partial.value = partialFlag(taskPayload, templatePayload)
}

async function loadSelfService(): Promise<void> {
  const payload = await resourceGet<unknown>('/v1/me/workspace')
  workspaceData.value = recordFrom(payload)
  partial.value = partialFlag(payload)
}

async function loadCalendars(): Promise<void> {
  const [calendarPayload, leaveTypePayload] = await settleReads([
    resourceGet<unknown>(`/v1/time/calendars/${entityId}`, { year: calendarYear.value }),
    resourceGet<unknown>('/v1/time/leave/types'),
  ])
  calendarData.value = recordFrom(calendarPayload)
  leaveTypes.value = rowsFrom(leaveTypePayload)
  partial.value = partialFlag(calendarPayload, leaveTypePayload)
}

async function loadNotifications(): Promise<void> {
  const [taskPayload, noticePayload] = await settleReads([
    resourceGet<unknown>('/v1/tasks/my'),
    resourceGet<unknown>('/v1/engagement/notices', { filter: 'all' }),
  ])
  tasks.value = rowsFrom(taskPayload)
  notices.value = rowsFrom(noticePayload)
  partial.value = partialFlag(taskPayload, noticePayload)
}

async function loadAudit(): Promise<void> {
  const [eventPayload, reviewPayload] = await settleReads([
    resourceGet<unknown>('/v1/auth/events', { page: 1, page_size: 50, legal_entity_id: entityId }),
    resourceGet<unknown>('/v1/access-review', { legal_entity_id: entityId }),
  ])
  auditEvents.value = rowsFrom(eventPayload)
  accessReview.value = recordFrom(reviewPayload)
  partial.value = partialFlag(eventPayload, reviewPayload)
}

async function load(): Promise<void> {
  resetFeedback()
  loading.value = true
  loaded.value = false
  partial.value = false

  try {
    switch (mode.value) {
      case 'dashboard':
        await loadDashboard()
        break
      case 'reports-index':
        await loadDefinitions()
        break
      case 'reports-builder':
        await loadBuilder()
        break
      case 'reports-subscriptions':
        await loadSubscriptions()
        break
      case 'reports-talent':
        await loadTalentReports()
        break
      case 'reports-detail':
        await loadReportsDetail()
        break
      case 'reports-exports':
        await loadDefinitions()
        await loadExportHistory()
        break
      case 'notice-list':
      case 'notice-create':
      case 'notice-gap':
        await loadNotices()
        break
      case 'account':
        await loadAccount()
        break
      case 'users':
        await loadUsers()
        break
      case 'roles':
        await loadRoles()
        break
      case 'menus':
      case 'permissions':
        await loadPermissions(true)
        break
      case 'data-scopes':
      case 'field-policies':
      case 'settings':
        await loadPermissions(false)
        break
      case 'workflow':
        await loadWorkflow()
        break
      case 'self-service':
        await loadSelfService()
        break
      case 'calendars':
        await loadCalendars()
        break
      case 'notifications':
        await loadNotifications()
        break
      case 'audit':
        await loadAudit()
        break
      case 'benefits':
      case null:
        break
      default:
        break
    }
  } catch (reason) {
    pageError.capture(reason)
  } finally {
    loading.value = false
    loaded.value = true
  }
}

async function openNoticeDetail(row: PlatformRow): Promise<void> {
  const id = idText(row.id)
  if (id === '') return
  resetFeedback()
  try {
    const payload = await resourceGet<unknown>(`/v1/engagement/notices/${id}`)
    selectedNotice.value = recordFrom(payload)
  } catch (reason) {
    pageError.capture(reason)
  }
}

async function acknowledgeNotice(row: PlatformRow): Promise<void> {
  const id = idText(row.id)
  if (id === '') return
  resetFeedback()
  try {
    const payload = await resourcePost<unknown>(
      `/v1/engagement/notices/${id}/acknowledge`,
      {},
      { idempotencyKey: nextIdempotencyKey('notice-ack'), headers: writeHeaders() },
    )
    const updated = recordFrom(payload)
    if (updated !== null) {
      row.acknowledged = updated.acknowledged
      row.acknowledgementCount = updated.acknowledgementCount
      if (selectedNotice.value?.id === row.id) selectedNotice.value = updated
    } else {
      row.acknowledged = true
    }
    successMessage.value = '已确认阅读，确认记录已保存。'
  } catch (reason) {
    failAction(reason)
  }
}

async function retractNotice(row: PlatformRow): Promise<void> {
  const id = idText(row.id)
  if (id === '') return
  resetFeedback()
  try {
    await resourcePost<unknown>(
      `/v1/engagement/notices/${id}/retract`,
      {},
      { idempotencyKey: nextIdempotencyKey('notice-retract'), headers: writeHeaders() },
    )
    row.status = 'RETRACTED'
    successMessage.value = '公告已撤回；已确认阅读记录保留。'
  } catch (reason) {
    failAction(reason)
  }
}

async function submitNotice(): Promise<void> {
  resetFeedback()
  const title = noticeForm.title.trim()
  const body = noticeForm.body.trim()
  if (title === '' || body === '') {
    actionError.value = { message: '标题与正文为必填项。', requestId: null, reason: 'NOTICE_CONTENT_REQUIRED' }
    return
  }

  const payload: Record<string, unknown> = {
    title,
    body,
    audience: noticeForm.audience,
    audienceLabel: noticeForm.audienceLabel.trim(),
    requiresAck: noticeForm.requiresAck,
  }

  if (noticeForm.audience === 'ORG') {
    const orgIds = parsePositiveIds(noticeForm.orgUnitIds)
    if (orgIds.length === 0) {
      actionError.value = { message: '按组织发布时至少填写一个真实组织 ID。', requestId: null, reason: 'ORG_AUDIENCE_REQUIRED' }
      return
    }
    payload.orgUnitIds = orgIds
  }

  if (noticeForm.audience === 'ROLE') {
    const roleCodes = parseRoleCodes(noticeForm.roleCodes)
    if (roleCodes.length === 0) {
      actionError.value = { message: '按角色发布时至少填写一个合法角色码。', requestId: null, reason: 'ROLE_AUDIENCE_REQUIRED' }
      return
    }
    payload.roleCodes = roleCodes
  }

  try {
    await resourcePost<unknown>('/v1/engagement/notices', payload, {
      idempotencyKey: nextIdempotencyKey('notice-create'),
      headers: writeHeaders(),
    })
    successMessage.value = '公告已发布；目标受众会在公告中心看到。'
    noticeForm.title = ''
    noticeForm.body = ''
  } catch (reason) {
    failAction(reason)
  }
}

async function enrollMfa(): Promise<void> {
  resetFeedback()
  try {
    const payload = await resourcePost<unknown>(
      '/v1/auth/mfa/totp/enroll',
      {},
      { idempotencyKey: nextIdempotencyKey('mfa-enroll'), headers: writeHeaders() },
    )
    mfaEnrollment.value = recordFrom(payload)
    mfaForm.factorId = rowText(mfaEnrollment.value ?? {}, 'factor_id', '')
    successMessage.value = '已生成 TOTP 注册信息；请在认证器中扫描并输入 6 位验证码。'
  } catch (reason) {
    failAction(reason)
  }
}

async function confirmMfa(): Promise<void> {
  resetFeedback()
  const factorId = Number(mfaForm.factorId)
  if (!Number.isInteger(factorId) || factorId < 1) {
    actionError.value = { message: '必须使用服务端返回的 factor_id。', requestId: null, reason: 'MFA_FACTOR_REQUIRED' }
    return
  }
  if (!/^\d{6}$/.test(mfaForm.code)) {
    actionError.value = { message: '请输入 6 位动态验证码。', requestId: null, reason: 'MFA_CODE_INVALID' }
    return
  }
  try {
    const payload = await resourcePost<unknown>(
      '/v1/auth/mfa/totp/confirm',
      { factor_id: factorId, code: mfaForm.code },
      { idempotencyKey: nextIdempotencyKey('mfa-confirm'), headers: writeHeaders() },
    )
    const record = recordFrom(payload)
    recoveryCodes.value = record && Array.isArray(record.recovery_codes)
      ? record.recovery_codes.filter((item): item is string => typeof item === 'string')
      : []
    if (meData.value !== null) meData.value.has_mfa = true
    successMessage.value = 'MFA 已启用；恢复码只展示一次，请妥善保存。'
  } catch (reason) {
    failAction(reason)
  }
}

async function logoutAll(): Promise<void> {
  resetFeedback()
  try {
    await resourcePost<unknown>(
      '/v1/auth/logout-all',
      {},
      { idempotencyKey: nextIdempotencyKey('logout-all'), headers: writeHeaders() },
    )
    successMessage.value = '已撤销全部会话；其他设备的下一次请求将失效。'
  } catch (reason) {
    failAction(reason)
  }
}

async function createUser(): Promise<void> {
  resetFeedback()
  const username = userForm.username.trim()
  const email = userForm.email.trim()
  if (!/^[a-zA-Z0-9._-]+$/.test(username)) {
    actionError.value = { message: '用户名只能包含字母、数字、点、下划线和连字符。', requestId: null, reason: 'USERNAME_INVALID' }
    return
  }
  if (userForm.password.length < 12) {
    actionError.value = { message: '初始密码至少 12 位。', requestId: null, reason: 'PASSWORD_TOO_SHORT' }
    return
  }
  if (email !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    actionError.value = { message: '邮箱格式不正确。', requestId: null, reason: 'EMAIL_INVALID' }
    return
  }

  const payload: Record<string, unknown> = {
    username,
    password: userForm.password,
    is_privileged: userForm.isPrivileged,
  }
  if (email !== '') payload.email = email

  try {
    const created = recordFrom(
      await resourcePost<unknown>('/v1/users', payload, {
        idempotencyKey: nextIdempotencyKey('user-create'),
        headers: writeHeaders({ 'X-Legal-Entity-Id': String(entityId) }),
      }),
    )
    if (created !== null) users.value = [created, ...users.value]
    successMessage.value = '用户已创建。请分配角色后再交由本人登录。'
    userForm.username = ''
    userForm.email = ''
    userForm.password = ''
    userForm.isPrivileged = false
  } catch (reason) {
    failAction(reason)
  }
}

function selectUser(row: PlatformRow): void {
  selectedUser.value = row
  userStatusForm.status = rowText(row, 'status', 'ACTIVE')
  userStatusForm.reason = ''
  bindings.value = []
  actionError.value = null
  successMessage.value = ''
  void loadBindings()
}

async function loadBindings(): Promise<void> {
  const userId = selectedUser.value ? idText(selectedUser.value.id) : ''
  if (userId === '') return
  try {
    const payload = await resourceGet<unknown>(`/v1/users/${userId}/bindings`, { legal_entity_id: entityId })
    bindings.value = rowsFrom(payload, 'bindings')
  } catch (reason) {
    failAction(reason)
  }
}

async function updateUserStatus(): Promise<void> {
  const user = selectedUser.value
  if (user === null) return
  const userId = idText(user.id)
  if (userId === '' || userStatusForm.reason.trim().length < 3) {
    actionError.value = { message: '状态变更必须填写至少 3 个字的真实原因。', requestId: null, reason: 'STATUS_REASON_REQUIRED' }
    return
  }
  resetFeedback()
  try {
    const updated = recordFrom(
      await resourcePatch<unknown>(
        `/v1/users/${userId}`,
        { status: userStatusForm.status, reason: userStatusForm.reason.trim() },
        {
          idempotencyKey: nextIdempotencyKey('user-status'),
          headers: writeHeaders({ 'X-Legal-Entity-Id': String(entityId) }),
        },
      ),
    )
    if (updated !== null) {
      user.status = updated.status
      user.auth_version = updated.auth_version
    }
    successMessage.value = '用户状态已更新；该用户既有会话已撤销。'
  } catch (reason) {
    failAction(reason)
  }
}

async function assignRole(): Promise<void> {
  const user = selectedUser.value
  if (user === null) return
  const userId = idText(user.id)
  const roleCode = roleForm.roleCode.trim().toUpperCase()
  if (userId === '' || !/^[A-Z][A-Z0-9_]{2,63}$/.test(roleCode)) {
    actionError.value = { message: '角色码必须是结构化大写标识。', requestId: null, reason: 'ROLE_CODE_INVALID' }
    return
  }
  if (roleForm.reason.trim().length < 3) {
    actionError.value = { message: '角色分配必须填写至少 3 个字的业务原因。', requestId: null, reason: 'ROLE_REASON_REQUIRED' }
    return
  }

  const payload: Record<string, unknown> = {
    role_code: roleCode,
    reason: roleForm.reason.trim(),
  }
  const validFrom = paramDate(roleForm.validFrom)
  const validTo = paramDate(roleForm.validTo)
  if (validFrom !== null) payload.valid_from = validFrom
  if (validTo !== null) payload.valid_to = validTo
  if (roleForm.anchorType !== '' && /^\d+$/.test(roleForm.anchorId)) {
    payload.binding_anchors = { [roleForm.anchorType]: Number(roleForm.anchorId) }
  }
  payload.acknowledge_sod_risk = roleForm.acknowledgeSodRisk

  resetFeedback()
  try {
    await resourcePost<unknown>(`/v1/users/${userId}/roles`, payload, {
      idempotencyKey: nextIdempotencyKey('role-assign'),
      headers: writeHeaders({ 'X-Legal-Entity-Id': String(entityId) }),
    })
    successMessage.value = '角色已分配并展开为授权绑定。'
    roleForm.roleCode = ''
    roleForm.reason = ''
  } catch (reason) {
    failAction(reason)
  }
}

function anchorRequiresId(type: string): boolean {
  return ['LEGAL_ENTITY', 'ORG_UNIT', 'ORG_SUBTREE', 'CASE', 'PAYROLL_RUN'].includes(type)
}

async function createBinding(): Promise<void> {
  const user = selectedUser.value
  if (user === null) return
  const userId = idText(user.id)
  if (bindingForm.capabilityCode.trim() === '' || bindingForm.purposeCode.trim() === '') {
    actionError.value = { message: '能力码与目的码为必填项。', requestId: null, reason: 'BINDING_FIELDS_REQUIRED' }
    return
  }
  if (anchorRequiresId(bindingForm.anchorType) && !/^\d+$/.test(bindingForm.anchorId)) {
    actionError.value = { message: '该锚点类型需要填写正整数锚点 ID。', requestId: null, reason: 'ANCHOR_ID_REQUIRED' }
    return
  }

  const payload: Record<string, unknown> = {
    capability_code: bindingForm.capabilityCode.trim(),
    anchor_type: bindingForm.anchorType,
    field_profile: bindingForm.fieldProfile,
    purpose_code: bindingForm.purposeCode.trim(),
  }
  if (anchorRequiresId(bindingForm.anchorType)) payload.anchor_id = Number(bindingForm.anchorId)
  const validFrom = paramDate(bindingForm.validFrom)
  const validTo = paramDate(bindingForm.validTo)
  if (validFrom !== null) payload.valid_from = validFrom
  if (validTo !== null) payload.valid_to = validTo
  if (bindingForm.acknowledgeSodRisk) payload.acknowledge_sod_risk = true

  resetFeedback()
  try {
    await resourcePost<unknown>(`/v1/users/${userId}/bindings`, payload, {
      idempotencyKey: nextIdempotencyKey('binding-create'),
      headers: writeHeaders({ 'X-Legal-Entity-Id': String(entityId) }),
    })
    successMessage.value = '授权绑定已创建，下一次请求即按新绑定校验。'
    bindingForm.capabilityCode = ''
    bindingForm.purposeCode = ''
    await loadBindings()
  } catch (reason) {
    failAction(reason)
  }
}

async function revokeBinding(row: PlatformRow): Promise<void> {
  const bindingId = idText(row.id)
  if (bindingId === '') return
  resetFeedback()
  try {
    await resourcePost<unknown>(
      `/v1/bindings/${bindingId}`,
      {},
      {
        idempotencyKey: nextIdempotencyKey('binding-revoke'),
        headers: writeHeaders({ 'X-HTTP-Method-Override': 'DELETE', 'X-Legal-Entity-Id': String(entityId) }),
      },
    )
    bindings.value = bindings.value.filter((binding) => idText(binding.id) !== bindingId)
    successMessage.value = '授权绑定已回收。'
  } catch (reason) {
    failAction(reason)
  }
}

function changeExportReport(): void {
  selectedExportFields.value = selectedExportFieldsAvailable.value
    .map((field) => rowText(field, 'key'))
    .filter((key) => key !== '—')
}

async function loadExportHistory(): Promise<void> {
  const payload = await resourceGet<unknown>('/v1/reporting/exports', { page: 1, page_size: 50 })
  exportHistory.value = rowsFrom(payload)
  partial.value ||= partialFlag(payload)
}

function selectBuilderSource(): void {
  definitionForm.fields = []
  definitionForm.filters = []
}

async function createDefinition(): Promise<void> {
  resetFeedback()
  if (definitionForm.code === '' || definitionForm.name === '' || definitionForm.fields.length === 0) {
    actionError.value = { message: '请填写定义编码、名称并至少选择一个批准字段。', requestId: null, reason: 'REPORT_FIELD_REQUIRED' }
    return
  }
  try {
    const created = recordFrom(await resourcePost<unknown>(
      '/v1/reporting/definitions',
      {
        code: definitionForm.code.trim().toUpperCase(),
        name: definitionForm.name.trim(),
        description: definitionForm.description.trim(),
        source_projection: definitionForm.sourceProjection,
        fields: [...definitionForm.fields],
        filters: [...definitionForm.filters],
      },
      { idempotencyKey: nextIdempotencyKey('report-definition'), headers: writeHeaders({ 'X-Legal-Entity-Id': String(entityId) }) },
    ))
    if (created !== null) definitions.value = [...definitions.value, created]
    definitionForm.code = ''
    definitionForm.name = ''
    definitionForm.description = ''
    definitionForm.fields = []
    definitionForm.filters = []
    successMessage.value = '自定义报表定义已保存，仅包含批准的白名单字段与筛选。'
  } catch (reason) {
    failAction(reason)
  }
}

async function createSubscription(): Promise<void> {
  resetFeedback()
  const recipient = Number(subscriptionForm.recipientUserId)
  if (subscriptionForm.definitionCode === '' || !Number.isInteger(recipient) || recipient <= 0) {
    actionError.value = { message: '请选择报表定义并填写有效的收件人用户 ID。', requestId: null, reason: 'RECIPIENT_REQUIRED' }
    return
  }
  try {
    const created = recordFrom(await resourcePost<unknown>(
      '/v1/reporting/subscriptions',
      {
        definition_code: subscriptionForm.definitionCode,
        recipient_user_id: recipient,
        frequency: subscriptionForm.frequency,
        filters: {},
      },
      { idempotencyKey: nextIdempotencyKey('report-subscription'), headers: writeHeaders({ 'X-Legal-Entity-Id': String(entityId) }) },
    ))
    if (created !== null) subscriptions.value = [created, ...subscriptions.value]
    subscriptionForm.recipientUserId = ''
    successMessage.value = '报表订阅已创建；执行前会重新校验收件人的报表读取权限。'
  } catch (reason) {
    failAction(reason)
  }
}

async function toggleSubscription(row: PlatformRow): Promise<void> {
  const id = idText(row.id)
  if (id === '') return
  resetFeedback()
  try {
    const updated = recordFrom(await resourcePatch<unknown>(
      `/v1/reporting/subscriptions/${id}`,
      { active: row.active !== true },
      { idempotencyKey: nextIdempotencyKey('report-subscription-toggle'), headers: writeHeaders({ 'X-Legal-Entity-Id': String(entityId) }) },
    ))
    if (updated !== null) {
      subscriptions.value = subscriptions.value.map((subscription) => (idText(subscription.id) === id ? updated : subscription))
    }
    successMessage.value = '报表订阅状态已更新。'
  } catch (reason) {
    failAction(reason)
  }
}

async function removeSubscription(row: PlatformRow): Promise<void> {
  const id = idText(row.id)
  if (id === '') return
  resetFeedback()
  try {
    await resourceDelete<unknown>(
      `/v1/reporting/subscriptions/${id}`,
      {},
      { idempotencyKey: nextIdempotencyKey('report-subscription-delete'), headers: writeHeaders({ 'X-Legal-Entity-Id': String(entityId) }) },
    )
    subscriptions.value = subscriptions.value.filter((subscription) => idText(subscription.id) !== id)
    successMessage.value = '报表订阅已删除。'
  } catch (reason) {
    failAction(reason)
  }
}

async function submitExport(): Promise<void> {
  resetFeedback()
  if (exportReportCode.value === '' || selectedExportFields.value.length === 0) {
    actionError.value = { message: '请选择报表并至少选择一个可导出字段。', requestId: null, reason: 'EXPORT_FIELDS_REQUIRED' }
    return
  }
  try {
    exportJob.value = recordFrom(
      await resourcePost<unknown>(
        '/v1/reporting/exports',
        {
          reportCode: exportReportCode.value,
          purpose: exportPurpose.value,
          fields: [...selectedExportFields.value],
          filters: {},
        },
        { idempotencyKey: nextIdempotencyKey('report-export'), headers: writeHeaders() },
      ),
    )
    await loadExportHistory()
    successMessage.value = '导出任务已创建；下载链接短效签名并记录 source_hash 与审计轨迹。'
  } catch (reason) {
    failAction(reason)
  }
}

async function refreshNoticesFilter(): Promise<void> {
  resetFeedback()
  loading.value = true
  try {
    await loadNotices()
  } catch (reason) {
    pageError.capture(reason)
  } finally {
    loading.value = false
    loaded.value = true
  }
}

async function reloadCalendars(): Promise<void> {
  resetFeedback()
  loading.value = true
  try {
    await loadCalendars()
  } catch (reason) {
    pageError.capture(reason)
  } finally {
    loading.value = false
    loaded.value = true
  }
}

onMounted(() => {
  void load()
})

watch(selectedExportDefinition, () => {
  changeExportReport()
})
</script>

<template>
  <div class="page platform-workspace" :data-page="config?.key ?? 'platform-unknown'">
    <header class="page-header">
      <div class="page-header__copy">
        <p class="page-header__breadcrumb">{{ config?.breadcrumb ?? '平台' }}</p>
        <h1 class="page-header__title">{{ pageTitle }}</h1>
        <p class="page-header__description">{{ pageDescription }}</p>
      </div>
      <div class="page-header__actions">
        <el-button :disabled="loading" @click="load">刷新</el-button>
      </div>
    </header>

    <NoActiveEmploymentState
      v-if="pageError.noActiveEmployment.value"
      :request-id="pageError.requestId.value"
      :links="pageError.safeLinks.value"
    />
    <ErrorState
      v-else-if="pageError.message.value"
      :title="`${pageTitle}暂时无法加载`"
      :description="pageError.message.value"
      :code="pageError.code.value"
      :request-id="pageError.requestId.value"
      @retry="load"
    />
    <LoadingState v-else-if="loading" :rows="7" :label="`正在加载${pageTitle}`" />

    <template v-else>
      <el-alert
        v-if="partial || partialFailure"
        data-testid="platform-partial"
        type="warning"
        :closable="false"
        show-icon
        :title="partialFailure ? `部分数据未能加载：${partialFailure.message}` : '部分数据：服务端标记本次响应不完整。请结合请求编号联系管理员后再做导出或授权决策。'"
      >
        <p v-if="partialFailure?.reason">业务原因：{{ partialFailure.reason }}</p>
        <p v-if="partialFailure?.requestId" data-testid="request-id">
          请求编号：<span class="mono">{{ partialFailure.requestId }}</span>
        </p>
      </el-alert>

      <el-alert
        v-if="actionError"
        data-testid="platform-action-error"
        type="error"
        :closable="false"
        show-icon
        :title="actionError.message"
      >
        <p v-if="actionError.reason">业务原因：{{ actionError.reason }}</p>
        <p v-if="actionError.requestId">请求编号：<span class="mono">{{ actionError.requestId }}</span></p>
      </el-alert>

      <p v-if="successMessage" class="platform-success" role="status" data-testid="platform-success">
        {{ successMessage }}
      </p>

      <EmptyState
        v-if="loaded && empty && !pageError.message.value"
        title="当前没有可展示的真实数据"
        description="接口当前返回空集合。页面保留真实操作入口，不使用演示数据补齐。"
      />

      <section v-if="gap" class="platform-gap" data-testid="backend-gap">
        <div>
          <p class="platform-gap__kicker">能力边界</p>
          <h2 class="platform-gap__title">{{ gap }}</h2>
          <p v-if="gapPath" class="mono small">计划接口：{{ gapPath }}</p>
        </div>
        <span class="plain-chip">未提供接口</span>
      </section>

      <!-- Dashboard -->
      <template v-if="mode === 'dashboard'">
        <section v-if="dashboardMetrics.length > 0" class="platform-metric-grid" aria-label="指标卡">
          <article v-for="metric in dashboardMetrics" :key="rowText(metric, 'code')" class="platform-metric">
            <p class="platform-metric__label">{{ rowText(metric, 'label', rowText(metric, 'code')) }}</p>
            <p class="platform-metric__value">
              {{ rowText(metric, 'value') }}
              <span>{{ rowText(metric, 'unit', '') }}</span>
            </p>
            <p class="small muted">
              <span class="plain-chip">{{ statusText(rowText(metric, 'status')) }}</span>
              {{ rowText(metric, 'code') }}
            </p>
            <p v-if="rowText(metric, 'status') === 'UNAVAILABLE'" class="small platform-reason">
              不可用原因：{{ rowText(metric, 'reason') }}
            </p>
          </article>
        </section>

        <section v-if="metricData" class="panel">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">{{ rowText(metricData, 'label', activeMetricKey ?? '指标明细') }}</h2>
              <p class="panel__subtitle">
                {{ rowText(metricData, 'predicate') }} · as-of {{ rowText(metricData, 'asOf') }}
              </p>
            </div>
            <span class="plain-chip">{{ statusText(rowText(metricData, 'status')) }}</span>
          </div>
          <div v-if="metricUnavailable" class="panel__body">
            <p class="platform-reason">指标暂不可用：{{ rowText(metricData, 'reason') }}</p>
          </div>
          <div v-else-if="metricRows.length > 0" class="platform-table-wrap">
            <table class="platform-table">
              <caption class="visually-hidden">{{ rowText(metricData, 'label') }}明细</caption>
              <thead>
                <tr>
                  <th v-for="column in metricColumns" :key="rowText(column, 'key')" scope="col">
                    {{ rowText(column, 'label') }}
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(row, index) in metricRows" :key="`${idText(row.id)}-${index}`">
                  <td v-for="column in metricColumns" :key="rowText(column, 'key')">
                    {{ rowText(row, rowText(column, 'key')) }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-else class="panel__body">
            <p class="empty-inline">该指标当前范围内没有返回明细行。</p>
          </div>
        </section>

        <section v-if="mode === 'dashboard' && config?.key === 'dashboard/departments'" class="panel">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">按组织聚合的实际指标行</h2>
              <p class="panel__subtitle">只对服务端返回行计数，不另行补造部门分母。</p>
            </div>
          </div>
          <div v-if="departmentGroups.length > 0" class="platform-table-wrap">
            <table class="platform-table">
              <caption class="visually-hidden">部门人数聚合</caption>
              <thead>
                <tr><th scope="col">组织</th><th scope="col">人数</th></tr>
              </thead>
              <tbody>
                <tr v-for="group in departmentGroups" :key="group.label">
                  <td>{{ group.label }}</td>
                  <td class="tabular">{{ group.count }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-else class="panel__body"><p class="empty-inline">没有可聚合的部门维度行。</p></div>
        </section>

        <div class="platform-panel-grid">
          <section class="panel">
            <div class="panel__header">
              <div>
                <h2 class="panel__title">待我审批</h2>
                <p class="panel__subtitle">来自 dashboard pendingApprovals，不另算一套口径。</p>
              </div>
              <RouterLink to="/workflow" class="small">流程中心</RouterLink>
            </div>
            <ul v-if="pendingApprovals.length > 0" class="platform-list">
              <li v-for="item in pendingApprovals" :key="idText(item.id)">
                <div>
                  <p class="platform-list__title">{{ rowText(item, 'title') }}</p>
                  <p class="small muted">{{ rowText(item, 'caseNo') }} · 已等待 {{ rowText(item, 'ageDays') }} 天</p>
                </div>
                <span class="plain-chip">{{ statusText(rowText(item, 'status')) }}</span>
              </li>
            </ul>
            <div v-else class="panel__body"><p class="empty-inline">当前没有待审批任务。</p></div>
          </section>

          <section class="panel">
            <div class="panel__header">
              <div>
                <h2 class="panel__title">合同到期提醒</h2>
                <p class="panel__subtitle">仅展示接口返回的提醒行。</p>
              </div>
            </div>
            <ul v-if="contractAlerts.length > 0" class="platform-list">
              <li v-for="alert in contractAlerts" :key="`${idText(alert.employmentId)}-${rowText(alert, 'endDate')}`">
                <div>
                  <p class="platform-list__title">{{ rowText(alert, 'employeeName') }}</p>
                  <p class="small muted">{{ rowText(alert, 'orgName') }} · {{ rowText(alert, 'employeeNo') }}</p>
                </div>
                <span class="plain-chip">{{ rowText(alert, 'daysRemaining') }} 天</span>
              </li>
            </ul>
            <div v-else class="panel__body"><p class="empty-inline">没有合同到期提醒。</p></div>
          </section>

          <section class="panel">
            <div class="panel__header">
              <div>
                <h2 class="panel__title">最近公告</h2>
                <p class="panel__subtitle">需要确认的公告会出现在待办。</p>
              </div>
              <RouterLink to="/notices" class="small">公告中心</RouterLink>
            </div>
            <ul v-if="recentNotices.length > 0" class="platform-list">
              <li v-for="notice in recentNotices" :key="idText(notice.id)">
                <div>
                  <p class="platform-list__title">{{ rowText(notice, 'title') }}</p>
                  <p class="small muted">{{ rowText(notice, 'authorName') }} · {{ formatDateTimeInTimezone(rowText(notice, 'publishedAt', '')) }}</p>
                </div>
                <span v-if="notice.requiresAck && !notice.acknowledged" class="plain-chip">待确认</span>
              </li>
            </ul>
            <div v-else class="panel__body"><p class="empty-inline">当前没有可见公告。</p></div>
          </section>

          <section class="panel">
            <div class="panel__header">
              <div>
                <h2 class="panel__title">口径与缺口</h2>
                <p class="panel__subtitle">数字、明细和导出使用同一授权谓词。</p>
              </div>
            </div>
            <div class="panel__body stack--tight small">
              <p><strong>as-of：</strong>{{ rowText(dashboardData ?? {}, 'asOf') }}</p>
              <p><strong>刷新时间：</strong>{{ formatDateTimeInTimezone(rowText(dashboardData ?? {}, 'freshness', '')) }}</p>
              <p v-if="config?.key === 'dashboard/workforce'">
                <strong>维度缺口：</strong>性别、年龄、用工类型与司龄构成暂无独立接口，页面不推算比例。
              </p>
              <p v-if="config?.key === 'dashboard/changes'">
                <strong>动态缺口：</strong>入转调离事件流暂无 dashboard 聚合接口，仅展示真实待办、合同与公告。
              </p>
              <p v-if="config?.key === 'dashboard/recruitment'">
                <strong>招聘：</strong>只展示 recruitment_funnel 指标实际状态与行；不可用时保留原因。
              </p>
            </div>
          </section>
        </div>
      </template>

      <!-- Reports -->
      <template v-else-if="mode === 'reports-index' || mode === 'reports-exports' || mode === 'reports-builder' || mode === 'reports-subscriptions'">
        <section class="panel">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">可用报表定义</h2>
              <p class="panel__subtitle">定义、字段档位与可导出口径均由 GET /v1/reporting/definitions 返回。</p>
            </div>
          </div>
          <div v-if="definitions.length > 0" class="platform-table-wrap">
            <table class="platform-table">
              <caption class="visually-hidden">报表定义</caption>
              <thead>
                <tr>
                  <th scope="col">编码</th>
                  <th scope="col">名称</th>
                  <th scope="col">说明</th>
                  <th scope="col">字段</th>
                  <th scope="col">筛选</th>
                  <th scope="col">as-of</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="definition in definitions" :key="rowText(definition, 'code')">
                  <td class="mono">{{ rowText(definition, 'code') }}</td>
                  <td>{{ rowText(definition, 'name') }}</td>
                  <td class="small">{{ rowText(definition, 'description') }}</td>
                  <td>{{ nestedRows(definition, 'fields').length }}</td>
                  <td>{{ nestedRows(definition, 'filters').length }}</td>
                  <td>{{ definition.asOfSupported === true ? '支持' : '不支持' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-else class="panel__body"><p class="empty-inline">报表目录没有返回定义。</p></div>
        </section>


        <section v-if="mode === 'reports-builder'" class="panel" data-testid="report-builder">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">自定义报表定义</h2>
              <p class="panel__subtitle">仅可选择服务端定义的投影、批准字段和筛选；SQL 与自由条件由后端拒绝。</p>
            </div>
            <span class="plain-chip">{{ sourceProjections.length }} 个投影</span>
          </div>
          <div class="panel__body platform-form">
            <label class="platform-field">
              <span>来源投影</span>
              <select v-model="definitionForm.sourceProjection" data-testid="definition-source" @change="selectBuilderSource">
                <option v-for="source in sourceProjections" :key="source" :value="source">{{ source }}</option>
              </select>
            </label>
            <label class="platform-field">
              <span>定义编码</span>
              <input v-model="definitionForm.code" data-testid="definition-code" maxlength="80" placeholder="CUSTOM_HEADCOUNT" />
            </label>
            <label class="platform-field">
              <span>名称</span>
              <input v-model="definitionForm.name" data-testid="definition-name" maxlength="160" placeholder="Synthetic custom report" />
            </label>
            <label class="platform-field">
              <span>说明</span>
              <input v-model="definitionForm.description" data-testid="definition-description" maxlength="500" />
            </label>
            <fieldset class="platform-fieldset">
              <legend>批准字段</legend>
              <label v-for="field in builderFieldsAvailable" :key="rowText(field, 'key')" class="platform-check">
                <input v-model="definitionForm.fields" type="checkbox" :value="rowText(field, 'key')" :data-testid="`definition-field-${rowText(field, 'key')}`" />
                {{ rowText(field, 'label') }}
                <span class="mono small">{{ rowText(field, 'profile') }}</span>
              </label>
            </fieldset>
            <fieldset class="platform-fieldset">
              <legend>批准筛选</legend>
              <label v-for="filter in builderFiltersAvailable" :key="rowText(filter, 'key')" class="platform-check">
                <input v-model="definitionForm.filters" type="checkbox" :value="rowText(filter, 'key')" :data-testid="`definition-filter-${rowText(filter, 'key')}`" />
                {{ rowText(filter, 'label') }}
              </label>
            </fieldset>
            <el-button type="primary" data-testid="definition-submit" @click="createDefinition">保存定义</el-button>
            <p v-if="definitionForm.sourceProjection !== '' && builderFieldsAvailable.length === 0" class="small muted">
              该投影没有返回批准字段，无法保存自定义定义。
            </p>
          </div>
        </section>

        <section v-if="mode === 'reports-subscriptions'" class="panel">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">创建报表订阅</h2>
              <p class="panel__subtitle">收件人必须在创建与每次执行时都持有 reporting.report.read。</p>
            </div>
          </div>
          <div class="panel__body platform-form">
            <label class="platform-field">
              <span>报表定义</span>
              <select v-model="subscriptionForm.definitionCode" data-testid="subscription-definition">
                <option v-for="definition in definitions" :key="rowText(definition, 'code')" :value="rowText(definition, 'code')">
                  {{ rowText(definition, 'name') }} · {{ rowText(definition, 'code') }}
                </option>
              </select>
            </label>
            <label class="platform-field">
              <span>收件人用户 ID</span>
              <input v-model="subscriptionForm.recipientUserId" data-testid="subscription-recipient" inputmode="numeric" />
            </label>
            <label class="platform-field">
              <span>频率</span>
              <select v-model="subscriptionForm.frequency" data-testid="subscription-frequency">
                <option value="DAILY">每日</option>
                <option value="WEEKLY">每周</option>
                <option value="MONTHLY">每月</option>
                <option value="QUARTERLY">每季度</option>
              </select>
            </label>
            <el-button type="primary" data-testid="subscription-submit" @click="createSubscription">创建订阅</el-button>
          </div>
        </section>

        <section v-if="mode === 'reports-subscriptions'" class="panel">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">我的报表订阅</h2>
              <p class="panel__subtitle">只返回当前主体拥有的订阅；启停与删除均使用幂等命令。</p>
            </div>
            <span class="plain-chip">{{ subscriptions.length }} 个订阅</span>
          </div>
          <div v-if="subscriptions.length > 0" class="platform-table-wrap">
            <table class="platform-table">
              <caption class="visually-hidden">报表订阅</caption>
              <thead>
                <tr>
                  <th scope="col">定义</th>
                  <th scope="col">收件人</th>
                  <th scope="col">频率</th>
                  <th scope="col">状态</th>
                  <th scope="col">下次执行</th>
                  <th scope="col">操作</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="subscription in subscriptions" :key="idText(subscription.id)">
                  <td class="mono">{{ rowText(subscription, 'definitionCode') }}</td>
                  <td>{{ rowText(subscription, 'recipientUserId') }}</td>
                  <td>{{ rowText(subscription, 'frequency') }}</td>
                  <td>{{ subscription.active === true ? '启用' : '已暂停' }}</td>
                  <td class="small">{{ formatDateTimeInTimezone(rowText(subscription, 'nextRunAt', '')) }}</td>
                  <td>
                    <button type="button" class="platform-link" :data-testid="`subscription-toggle-${idText(subscription.id)}`" @click="toggleSubscription(subscription)">
                      {{ subscription.active === true ? '暂停' : '恢复' }}
                    </button>
                    <button type="button" class="platform-link" @click="removeSubscription(subscription)">删除</button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-else class="panel__body"><p class="empty-inline">当前没有报表订阅。</p></div>
        </section>
        <section v-if="mode === 'reports-exports'" class="panel">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">创建导出任务</h2>
              <p class="panel__subtitle">必须选择服务端定义、导出目的与可导出字段；下载链接短效且限次。</p>
            </div>
          </div>
          <div class="panel__body platform-form">
            <label class="platform-field">
              <span>报表</span>
              <select v-model="exportReportCode" data-testid="export-report-code" @change="changeExportReport">
                <option v-for="definition in definitions" :key="rowText(definition, 'code')" :value="rowText(definition, 'code')">
                  {{ rowText(definition, 'name') }} · {{ rowText(definition, 'code') }}
                </option>
              </select>
            </label>
            <label class="platform-field">
              <span>导出目的</span>
              <select v-model="exportPurpose" data-testid="export-purpose">
                <option value="BUSINESS_OPERATION">业务运营</option>
                <option value="AUDIT">审计检查</option>
                <option value="COMPLIANCE">合规取证</option>
                <option value="PAYROLL_RECONCILIATION">薪酬对账</option>
              </select>
            </label>
            <fieldset class="platform-fieldset">
              <legend>导出字段</legend>
              <label v-for="field in selectedExportFieldsAvailable" :key="rowText(field, 'key')" class="platform-check">
                <input v-model="selectedExportFields" type="checkbox" :value="rowText(field, 'key')" />
                {{ rowText(field, 'label') }}
                <span class="mono small">{{ rowText(field, 'profile') }}</span>
              </label>
            </fieldset>
            <el-button type="primary" data-testid="export-submit" @click="submitExport">创建导出任务</el-button>
          </div>
        </section>

        <section v-if="exportJob" class="panel">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">导出任务结果</h2>
              <p class="panel__subtitle">{{ rowText(exportJob, 'fileName') }}</p>
            </div>
            <span class="plain-chip">{{ statusText(rowText(exportJob, 'status')) }}</span>
          </div>
          <div class="panel__body stack--tight small">
            <p>行数：{{ rowText(exportJob, 'rowCount') }} · 下载次数：{{ rowText(exportJob, 'downloadCount') }} / {{ rowText(exportJob, 'maxDownloads') }}</p>
            <p>水印：{{ rowText(exportJob, 'watermark') }}</p>
            <a v-if="exportDownloadHref" :href="exportDownloadHref" class="platform-link" data-testid="export-download">下载 CSV（短效 token）</a>
            <p v-else class="muted">服务端未返回下载令牌。</p>
          </div>
        </section>
      </template>


        <section v-if="mode === 'reports-exports' && exportHistory.length > 0" class="panel">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">导出历史</h2>
              <p class="panel__subtitle">GET /v1/reporting/exports 返回 source_hash、字段白名单与审计轨迹；链接短效签名。</p>
            </div>
            <span class="plain-chip">{{ exportHistory.length }} 个任务</span>
          </div>
          <div class="platform-table-wrap">
            <table class="platform-table">
              <caption class="visually-hidden">导出任务历史</caption>
              <thead>
                <tr>
                  <th scope="col">任务</th>
                  <th scope="col">报表</th>
                  <th scope="col">目的</th>
                  <th scope="col">状态</th>
                  <th scope="col">字段白名单</th>
                  <th scope="col">source_hash</th>
                  <th scope="col">审计</th>
                  <th scope="col">下载</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="job in exportHistory" :key="rowText(job, 'id')">
                  <td class="mono">{{ rowText(job, 'id') }}</td>
                  <td>{{ rowText(job, 'reportCode') }}</td>
                  <td>{{ rowText(job, 'purpose') }}</td>
                  <td>{{ statusText(rowText(job, 'status')) }}</td>
                  <td class="mono small">{{ textOf(job.fieldWhitelist) }}</td>
                  <td class="mono small">{{ rowText(job, 'source_hash') }}</td>
                  <td class="small">{{ nestedRows(job, 'auditTrail').map((entry) => rowText(entry, 'action')).join('、') || '—' }}</td>
                  <td>
                    <a
                      v-if="rowText(job, 'downloadUrl', '') !== '' && rowText(job, 'downloadUrl', '') !== '—'"
                      :href="rowText(job, 'downloadUrl')"
                      class="platform-link"
                      data-testid="export-history-download"
                    >下载</a>
                    <span v-else class="muted">—</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

      <template v-else-if="mode === 'reports-talent'">
        <section class="panel">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">绩效报表</h2>
              <p class="panel__subtitle">来自 GET /v1/performance/reports 的授权投影；不可用时不生成替代数字。</p>
            </div>
            <span class="plain-chip">{{ talentPerformance ? (talentPerformance.status ?? 'LIVE') : '未返回' }}</span>
          </div>
          <div v-if="talentPerformance && rowsFrom(talentPerformance.items).length > 0" class="platform-table-wrap">
            <table class="platform-table">
              <caption class="visually-hidden">绩效报表</caption>
              <thead>
                <tr>
                  <th scope="col">周期</th>
                  <th scope="col">指标</th>
                  <th scope="col">值</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(row, index) in rowsFrom(talentPerformance.items)" :key="`perf-${index}`">
                  <td>{{ rowText(row, 'cycle_name', rowText(row, 'cycleCode')) }}</td>
                  <td>{{ rowText(row, 'metric', rowText(row, 'name')) }}</td>
                  <td>{{ rowText(row, 'value', rowText(row, 'completion_rate')) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-else class="panel__body"><p class="empty-inline">绩效报表没有返回可见行。</p></div>
        </section>

        <section class="panel">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">培训报表</h2>
              <p class="panel__subtitle">来自 GET /v1/training/report 的完训与考试事实。</p>
            </div>
            <span class="plain-chip">{{ talentTraining ? (talentTraining.status ?? 'LIVE') : '未返回' }}</span>
          </div>
          <div v-if="talentTraining && rowsFrom(talentTraining.items).length > 0" class="platform-table-wrap">
            <table class="platform-table">
              <caption class="visually-hidden">培训报表</caption>
              <thead>
                <tr>
                  <th scope="col">项目</th>
                  <th scope="col">课程</th>
                  <th scope="col">完训人数</th>
                  <th scope="col">通过率</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(row, index) in rowsFrom(talentTraining.items)" :key="`training-${index}`">
                  <td>{{ rowText(row, 'program_name', rowText(row, 'programName')) }}</td>
                  <td>{{ rowText(row, 'course_name', rowText(row, 'courseName')) }}</td>
                  <td>{{ rowText(row, 'completed_count', rowText(row, 'completedCount')) }}</td>
                  <td>{{ rowText(row, 'pass_rate', rowText(row, 'passRate')) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-else class="panel__body"><p class="empty-inline">培训报表没有返回可见行。</p></div>
        </section>
      </template>
      <template v-else-if="mode === 'reports-detail'">
        <section v-if="reportDefinition" class="panel">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">{{ rowText(reportDefinition, 'name') }}</h2>
              <p class="panel__subtitle">{{ rowText(reportDefinition, 'description') }}</p>
            </div>
            <span class="mono small">{{ rowText(reportDefinition, 'code') }}</span>
          </div>
          <div class="panel__body stack--tight small">
            <p><strong>字段：</strong>{{ definitionFields.length }} 个；可导出 {{ definitionFields.filter((field) => field.exportable !== false).length }} 个。</p>
            <p><strong>筛选：</strong>{{ definitionFilters.length > 0 ? definitionFilters.map((filter) => rowText(filter, 'label')).join('、') : '无定义筛选' }}</p>
          </div>
        </section>

        <section v-if="reportResult" class="panel">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">{{ rowText(reportResult, 'title') }}</h2>
              <p class="panel__subtitle">as-of {{ rowText(reportResult, 'subtitle') }} · 共 {{ rowText(reportResult, 'totalRows') }} 行</p>
            </div>
            <span class="plain-chip">服务端投影</span>
          </div>
          <div v-if="reportRows.length > 0" class="platform-table-wrap">
            <table class="platform-table">
              <caption class="visually-hidden">报表运行结果</caption>
              <thead>
                <tr>
                  <th v-for="column in reportColumns" :key="rowText(column, 'key')" scope="col">
                    {{ rowText(column, 'label') }}
                    <span class="platform-profile">{{ rowText(column, 'profile') }}</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(row, index) in reportRows" :key="`report-${index}`">
                  <td v-for="column in reportColumns" :key="rowText(column, 'key')">{{ rowText(row, rowText(column, 'key')) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-else class="panel__body"><p class="empty-inline">报表运行成功，但当前筛选返回 0 行。</p></div>
          <div class="panel__footer small muted">生成于 {{ formatDateTimeInTimezone(rowText(reportResult, 'generatedAt', '')) }}</div>
        </section>

        <section v-else-if="config?.reportCode && reportDefinition" class="panel" data-testid="report-unavailable">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">报表数据尚未发布</h2>
              <p class="panel__subtitle">后端明确拒绝在缺少已发布投影时估算数据。</p>
            </div>
            <span class="plain-chip plain-chip--warning">服务不可用 · 503</span>
          </div>
          <div class="panel__body">
            <p class="small">{{ reportUnavailable || '该报表的投影尚未发布。请联系报表管理员完成数据发布后再运行。' }}</p>
            <p class="small muted">这是数据可用性状态，不代表页面或接口故障。</p>
          </div>
        </section>
      </template>

      <!-- Notices -->
      <template v-else-if="mode === 'notice-list' || mode === 'notice-gap'">
        <div class="platform-toolbar">
          <div class="platform-filters" role="group" aria-label="公告筛选">
            <button
              v-for="option in [
                { key: 'all', label: '全部可见' },
                { key: 'unread', label: '待我确认' },
                { key: 'acknowledged', label: '已确认' },
                { key: 'managed', label: '管理视图' },
              ]"
              :key="option.key"
              type="button"
              :class="{ 'platform-filter--active': noticeFilter === option.key }"
              @click="noticeFilter = option.key as typeof noticeFilter; refreshNoticesFilter()"
            >
              {{ option.label }}
            </button>
          </div>
          <RouterLink to="/notices/editor" class="platform-link">发布公告 →</RouterLink>
        </div>

        <section v-if="notices.length > 0" class="platform-card-list">
          <article v-for="notice in notices" :key="idText(notice.id)" class="platform-card">
            <div class="platform-card__main">
              <div class="platform-card__meta">
                <span class="plain-chip">{{ statusText(rowText(notice, 'status')) }}</span>
                <span v-if="notice.requiresAck" class="plain-chip">{{ notice.acknowledged ? '已确认' : '需确认' }}</span>
                <span>{{ rowText(notice, 'audienceLabel') }}</span>
                <time :datetime="rowText(notice, 'publishedAt', '')">{{ formatDateTimeInTimezone(rowText(notice, 'publishedAt', '')) }}</time>
              </div>
              <h2 class="platform-card__title">{{ rowText(notice, 'title') }}</h2>
              <p class="platform-card__summary">{{ rowText(notice, 'summary') }}</p>
              <p class="small muted">
                已确认 {{ rowText(notice, 'acknowledgementCount') }} / {{ rowText(notice, 'audienceCount') }}
                · 受众类型 {{ rowText(notice, 'audience') }}
              </p>
            </div>
            <div class="platform-card__actions">
              <el-button size="small" @click="openNoticeDetail(notice)">查看全文</el-button>
              <el-button
                v-if="notice.requiresAck && !notice.acknowledged && rowText(notice, 'status') === 'PUBLISHED'"
                type="primary"
                plain
                size="small"
                @click="acknowledgeNotice(notice)"
              >
                确认已读
              </el-button>
              <el-button
                v-if="rowText(notice, 'status') === 'PUBLISHED'"
                text
                size="small"
                @click="retractNotice(notice)"
              >
                撤回
              </el-button>
            </div>
          </article>
        </section>
        <div v-else class="panel"><div class="panel__body"><p class="empty-inline">当前筛选下没有可见公告。</p></div></div>

        <section v-if="selectedNotice" class="panel" data-testid="notice-detail">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">{{ rowText(selectedNotice, 'title') }}</h2>
              <p class="panel__subtitle">
                {{ rowText(selectedNotice, 'audienceLabel') }} ·
                {{ formatDateTimeInTimezone(rowText(selectedNotice, 'publishedAt', '')) }}
              </p>
            </div>
            <el-button text size="small" @click="selectedNotice = null">关闭详情</el-button>
          </div>
          <div class="panel__body notice-body">
            <p v-for="(paragraph, index) in bodyParagraphs(selectedNotice.body)" :key="index">{{ paragraph }}</p>
            <p v-if="bodyParagraphs(selectedNotice.body).length === 0" class="empty-inline">公告正文没有可展示段落。</p>
          </div>
          <div class="panel__footer platform-inline-actions">
            <span class="small muted">
              确认 {{ rowText(selectedNotice, 'acknowledgementCount') }} / {{ rowText(selectedNotice, 'audienceCount') }}
            </span>
            <el-button
              v-if="selectedNotice.requiresAck && !selectedNotice.acknowledged"
              type="primary"
              size="small"
              @click="acknowledgeNotice(selectedNotice)"
            >
              确认已读
            </el-button>
          </div>
        </section>

        <section v-if="mode === 'notice-gap' && config?.key === 'notices/reads'" class="panel">
          <div class="panel__header"><h2 class="panel__title">阅读完成度（真实计数）</h2></div>
          <div class="platform-table-wrap">
            <table class="platform-table">
              <caption class="visually-hidden">公告阅读计数</caption>
              <thead><tr><th scope="col">公告</th><th scope="col">已确认</th><th scope="col">受众</th><th scope="col">完成率</th></tr></thead>
              <tbody>
                <tr v-for="notice in notices" :key="`read-${idText(notice.id)}`">
                  <td>{{ rowText(notice, 'title') }}</td>
                  <td>{{ rowText(notice, 'acknowledgementCount') }}</td>
                  <td>{{ rowText(notice, 'audienceCount') }}</td>
                  <td>
                    {{ rowNumber(notice, 'audienceCount')
                      ? `${Math.round(((rowNumber(notice, 'acknowledgementCount') ?? 0) / (rowNumber(notice, 'audienceCount') ?? 1)) * 100)}%`
                      : '—' }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </template>

      <template v-else-if="mode === 'notice-create'">
        <section class="panel">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">发布真实公告</h2>
              <p class="panel__subtitle">受众类型、组织 ID 与角色码由服务端重新校验；无受众不发布。</p>
            </div>
          </div>
          <div class="panel__body platform-form">
            <label class="platform-field">
              <span>标题</span>
              <input v-model="noticeForm.title" data-testid="notice-title" maxlength="200" placeholder="例：关于 12 月薪酬发放日的安排" />
            </label>
            <label class="platform-field">
              <span>正文</span>
              <textarea v-model="noticeForm.body" data-testid="notice-body" rows="7" maxlength="20000" placeholder="填写员工实际看到的公告正文" />
            </label>
            <div class="platform-form-grid">
              <label class="platform-field">
                <span>受众类型</span>
                <select v-model="noticeForm.audience" data-testid="notice-audience">
                  <option value="ALL">全员</option>
                  <option value="ORG">按组织</option>
                  <option value="ROLE">按角色</option>
                </select>
              </label>
              <label class="platform-field">
                <span>受众说明</span>
                <input v-model="noticeForm.audienceLabel" maxlength="120" data-testid="notice-audience-label" />
              </label>
            </div>
            <label v-if="noticeForm.audience === 'ORG'" class="platform-field">
              <span>组织 ID（逗号分隔）</span>
              <input v-model="noticeForm.orgUnitIds" inputmode="numeric" placeholder="例：10,12" data-testid="notice-org-ids" />
            </label>
            <label v-if="noticeForm.audience === 'ROLE'" class="platform-field">
              <span>角色码（逗号分隔）</span>
              <input v-model="noticeForm.roleCodes" placeholder="例：HR_ADMIN,LINE_MANAGER" data-testid="notice-role-codes" />
            </label>
            <label class="platform-check">
              <input v-model="noticeForm.requiresAck" type="checkbox" data-testid="notice-requires-ack" />
              要求阅读确认并生成待办
            </label>
            <div class="platform-inline-actions">
              <el-button type="primary" data-testid="notice-submit" @click="submitNotice">发布并记录审计</el-button>
              <RouterLink to="/notices" class="platform-link">返回公告列表</RouterLink>
            </div>
          </div>
        </section>

        <section class="panel">
          <div class="panel__header">
            <h2 class="panel__title">已发布公告</h2>
          </div>
          <div class="panel__body">
            <p v-if="notices.length === 0" class="empty-inline">当前列表未返回已发布公告。</p>
            <ul v-else class="platform-list">
              <li v-for="notice in notices" :key="idText(notice.id)">
                <div>
                  <p class="platform-list__title">{{ rowText(notice, 'title') }}</p>
                  <p class="small muted">{{ rowText(notice, 'audienceLabel') }}</p>
                </div>
                <span class="plain-chip">{{ statusText(rowText(notice, 'status')) }}</span>
              </li>
            </ul>
          </div>
        </section>
      </template>

      <!-- Account -->
      <template v-else-if="mode === 'account'">
        <section v-if="meData" class="panel">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">当前账号</h2>
              <p class="panel__subtitle">身份、租户与 MFA 状态来自 GET /v1/me。</p>
            </div>
            <span class="plain-chip">{{ meData.has_mfa ? 'MFA 已启用' : 'MFA 未启用' }}</span>
          </div>
          <dl class="panel__body platform-definition-grid">
            <div><dt>用户名</dt><dd>{{ rowText(meData, 'username') }}</dd></div>
            <div><dt>邮箱</dt><dd>{{ rowText(meData, 'email') }}</dd></div>
            <div><dt>租户</dt><dd>{{ rowText(meData, 'tenant_name') }}</dd></div>
            <div><dt>员工任职</dt><dd>{{ rowText(meData, 'employment_id') }}</dd></div>
            <div><dt>特权账号</dt><dd>{{ meData.is_privileged === true ? '是' : '否' }}</dd></div>
            <div><dt>授权版本</dt><dd>{{ rowText(meData, 'auth_version') }}</dd></div>
            <div><dt>角色</dt><dd>{{ textOf(meData.roles) }}</dd></div>
            <div><dt>MFA 已验证</dt><dd>{{ meData.mfa_verified === true ? '是' : '否' }}</dd></div>
          </dl>
        </section>

        <div class="platform-panel-grid">
          <section class="panel">
            <div class="panel__header">
              <div>
                <h2 class="panel__title">TOTP 注册</h2>
                <p class="panel__subtitle">注册由服务端生成秘密；确认必须使用返回的 factor_id 与 6 位验证码。</p>
              </div>
            </div>
            <div class="panel__body platform-form">
              <el-button type="primary" plain data-testid="mfa-enroll" @click="enrollMfa">生成 TOTP 注册信息</el-button>
              <div v-if="mfaEnrollment" class="platform-code-block">
                <p><strong>factor_id：</strong><span class="mono">{{ rowText(mfaEnrollment, 'factor_id') }}</span></p>
                <p><strong>秘密：</strong><span class="mono">{{ rowText(mfaEnrollment, 'secret') }}</span></p>
                <p><strong>otpauth URI：</strong><span class="mono platform-break">{{ rowText(mfaEnrollment, 'otpauth_uri') }}</span></p>
              </div>
              <div class="platform-form-grid">
                <label class="platform-field">
                  <span>factor_id</span>
                  <input v-model="mfaForm.factorId" inputmode="numeric" data-testid="mfa-factor-id" />
                </label>
                <label class="platform-field">
                  <span>6 位验证码</span>
                  <input v-model="mfaForm.code" inputmode="numeric" maxlength="6" data-testid="mfa-code" />
                </label>
              </div>
              <el-button type="primary" data-testid="mfa-confirm" @click="confirmMfa">确认启用 MFA</el-button>
              <div v-if="recoveryCodes.length > 0" class="platform-code-block" data-testid="mfa-recovery-codes">
                <p><strong>恢复码（只显示一次）</strong></p>
                <ul>
                  <li v-for="code in recoveryCodes" :key="code" class="mono">{{ code }}</li>
                </ul>
              </div>
            </div>
          </section>

          <section class="panel">
            <div class="panel__header">
              <div>
                <h2 class="panel__title">会话安全</h2>
                <p class="panel__subtitle">撤销全部会话会立即让其他设备的下一步请求失效。</p>
              </div>
            </div>
            <div class="panel__body stack">
              <p class="small muted">认证版本：{{ rowText(meData ?? {}, 'auth_version') }}。会话令牌保存在 HttpOnly Cookie 中。</p>
              <el-button type="danger" plain data-testid="logout-all" @click="logoutAll">撤销全部会话</el-button>
            </div>
          </section>
        </div>
      </template>

      <!-- Users -->
      <template v-else-if="mode === 'users'">
        <section class="panel">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">用户账户</h2>
              <p class="panel__subtitle">列表只返回服务端账户字段；角色通过单独接口分配。</p>
            </div>
          </div>
          <div v-if="users.length > 0" class="platform-table-wrap">
            <table class="platform-table">
              <caption class="visually-hidden">用户账户列表</caption>
              <thead>
                <tr>
                  <th scope="col">用户名</th>
                  <th scope="col">邮箱</th>
                  <th scope="col">状态</th>
                  <th scope="col">特权</th>
                  <th scope="col">MFA</th>
                  <th scope="col">授权版本</th>
                  <th scope="col">操作</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="user in users" :key="idText(user.id)">
                  <td class="mono">{{ rowText(user, 'username') }}</td>
                  <td>{{ rowText(user, 'email') }}</td>
                  <td><span class="plain-chip">{{ statusText(rowText(user, 'status')) }}</span></td>
                  <td>{{ user.is_privileged === true ? '是' : '否' }}</td>
                  <td>{{ user.mfa_enrolled === true ? '已启用' : '未启用' }}</td>
                  <td class="mono">{{ rowText(user, 'auth_version') }}</td>
                  <td><el-button text size="small" @click="selectUser(user)">管理</el-button></td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-else class="panel__body"><p class="empty-inline">本租户暂无账户。</p></div>
        </section>

        <section class="panel">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">创建用户</h2>
              <p class="panel__subtitle">后端只接受 username、email、password（至少 12 位）与 is_privileged。</p>
            </div>
          </div>
          <div class="panel__body platform-form">
            <div class="platform-form-grid">
              <label class="platform-field">
                <span>用户名</span>
                <input v-model="userForm.username" data-testid="user-username" autocomplete="off" />
              </label>
              <label class="platform-field">
                <span>邮箱（可选）</span>
                <input v-model="userForm.email" data-testid="user-email" autocomplete="off" />
              </label>
            </div>
            <label class="platform-field">
              <span>初始密码（至少 12 位）</span>
              <input v-model="userForm.password" type="password" data-testid="user-password" autocomplete="new-password" />
            </label>
            <label class="platform-check">
              <input v-model="userForm.isPrivileged" type="checkbox" data-testid="user-privileged" />
              标记为特权账号（后续必须完成 MFA）
            </label>
            <el-button type="primary" data-testid="user-submit" @click="createUser">创建用户</el-button>
          </div>
        </section>

        <template v-if="selectedUser">
          <section class="panel" data-testid="user-management">
            <div class="panel__header">
              <div>
                <h2 class="panel__title">管理 {{ rowText(selectedUser, 'username') }}</h2>
                <p class="panel__subtitle">状态变更使用 PATCH 语义并强制填写原因。</p>
              </div>
              <el-button text size="small" @click="selectedUser = null">关闭</el-button>
            </div>
            <div class="panel__body platform-form">
              <div class="platform-form-grid">
                <label class="platform-field">
                  <span>状态</span>
                  <select v-model="userStatusForm.status" data-testid="user-status">
                    <option value="ACTIVE">启用</option>
                    <option value="LOCKED">锁定</option>
                    <option value="DISABLED">停用</option>
                  </select>
                </label>
                <label class="platform-field">
                  <span>变更原因（至少 3 个字）</span>
                  <input v-model="userStatusForm.reason" data-testid="user-status-reason" />
                </label>
              </div>
              <el-button data-testid="user-status-submit" @click="updateUserStatus">更新状态并撤销旧会话</el-button>
            </div>
          </section>

          <section class="panel">
            <div class="panel__header">
              <div>
                <h2 class="panel__title">分配角色</h2>
                <p class="panel__subtitle">只发送后端校验的 role_code、有效期、锚点、原因与 SoD 确认。</p>
              </div>
            </div>
            <div class="panel__body platform-form">
              <div class="platform-form-grid">
                <label class="platform-field">
                  <span>角色码</span>
                  <input v-model="roleForm.roleCode" placeholder="例：HR_SPECIALIST" data-testid="role-code" />
                </label>
                <label class="platform-field">
                  <span>原因（至少 3 个字）</span>
                  <input v-model="roleForm.reason" data-testid="role-reason" />
                </label>
              </div>
              <div class="platform-form-grid">
                <label class="platform-field">
                  <span>生效日（可选）</span>
                  <input v-model="roleForm.validFrom" type="date" />
                </label>
                <label class="platform-field">
                  <span>到期日（可选）</span>
                  <input v-model="roleForm.validTo" type="date" />
                </label>
                <label class="platform-field">
                  <span>锚点类型</span>
                  <select v-model="roleForm.anchorType">
                    <option value="LEGAL_ENTITY">LEGAL_ENTITY</option>
                    <option value="ORG_UNIT">ORG_UNIT</option>
                    <option value="PAYROLL_RUN">PAYROLL_RUN</option>
                    <option value="CASE">CASE</option>
                  </select>
                </label>
                <label class="platform-field">
                  <span>锚点 ID</span>
                  <input v-model="roleForm.anchorId" inputmode="numeric" placeholder="角色需要时填写" />
                </label>
              </div>
              <label class="platform-check">
                <input v-model="roleForm.acknowledgeSodRisk" type="checkbox" />
                我确认接受职责分离（SoD）风险
              </label>
              <el-button type="primary" data-testid="role-submit" @click="assignRole">分配角色</el-button>
            </div>
          </section>

          <section class="panel">
            <div class="panel__header">
              <div>
                <h2 class="panel__title">授权绑定</h2>
                <p class="panel__subtitle">每个绑定包含能力、锚点、字段档位、目的与有效期。</p>
              </div>
            </div>
            <div v-if="bindingsRows.length > 0" class="platform-table-wrap">
              <table class="platform-table">
                <caption class="visually-hidden">用户授权绑定</caption>
                <thead>
                  <tr><th scope="col">能力</th><th scope="col">锚点</th><th scope="col">字段档位</th><th scope="col">目的</th><th scope="col">有效期</th><th scope="col">操作</th></tr>
                </thead>
                <tbody>
                  <tr v-for="binding in bindingsRows" :key="idText(binding.id)">
                    <td class="mono">{{ rowText(binding, 'capability') }}</td>
                    <td>{{ rowText(binding, 'anchor_type') }} #{{ rowText(binding, 'anchor_id') }}</td>
                    <td>{{ rowText(binding, 'field_profile') }}</td>
                    <td class="mono">{{ rowText(binding, 'purpose_code') }}</td>
                    <td class="small">{{ rowText(binding, 'valid_from') }} → {{ rowText(binding, 'valid_to') }}</td>
                    <td><el-button text size="small" @click="revokeBinding(binding)">回收</el-button></td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div v-else class="panel__body"><p class="empty-inline">当前用户没有已返回的授权绑定。</p></div>
            <div class="panel__body platform-form">
              <div class="platform-form-grid">
                <label class="platform-field">
                  <span>能力码</span>
                  <input v-model="bindingForm.capabilityCode" data-testid="binding-capability" placeholder="例：people.employee.read" />
                </label>
                <label class="platform-field">
                  <span>目的码</span>
                  <input v-model="bindingForm.purposeCode" data-testid="binding-purpose" placeholder="例：HR_OPERATIONS" />
                </label>
                <label class="platform-field">
                  <span>锚点类型</span>
                  <select v-model="bindingForm.anchorType">
                    <option value="LEGAL_ENTITY">LEGAL_ENTITY</option>
                    <option value="ORG_UNIT">ORG_UNIT</option>
                    <option value="ORG_SUBTREE">ORG_SUBTREE</option>
                    <option value="PAYROLL_RUN">PAYROLL_RUN</option>
                    <option value="CASE">CASE</option>
                    <option value="SELF">SELF</option>
                  </select>
                </label>
                <label class="platform-field">
                  <span>锚点 ID（需要时）</span>
                  <input v-model="bindingForm.anchorId" inputmode="numeric" data-testid="binding-anchor-id" />
                </label>
                <label class="platform-field">
                  <span>字段档位</span>
                  <select v-model="bindingForm.fieldProfile">
                    <option value="P0_PUBLIC">P0 公开</option>
                    <option value="P1_INTERNAL">P1 内部</option>
                    <option value="P2_SENSITIVE">P2 敏感</option>
                    <option value="P3_RESTRICTED">P3 受限</option>
                  </select>
                </label>
                <label class="platform-field">
                  <span>生效日（可选）</span>
                  <input v-model="bindingForm.validFrom" type="date" />
                </label>
                <label class="platform-field">
                  <span>到期日（可选）</span>
                  <input v-model="bindingForm.validTo" type="date" />
                </label>
              </div>
              <label class="platform-check">
                <input v-model="bindingForm.acknowledgeSodRisk" type="checkbox" />
                我确认接受职责分离（SoD）风险
              </label>
              <el-button type="primary" data-testid="binding-submit" @click="createBinding">创建授权绑定</el-button>
            </div>
          </section>
        </template>
      </template>

      <!-- Roles -->
      <template v-else-if="mode === 'roles'">
        <section v-if="roles.length > 0" class="platform-card-list">
          <article v-for="role in roles" :key="rowText(role, 'code')" class="platform-card">
            <div class="platform-card__main">
              <div class="platform-card__meta">
                <span class="plain-chip">{{ role.is_template === true ? '模板角色' : '例外角色' }}</span>
                <span class="mono">{{ rowText(role, 'code') }}</span>
              </div>
              <h2 class="platform-card__title">{{ rowText(role, 'name') }}</h2>
              <p class="platform-card__summary">{{ rowText(role, 'purpose') }}</p>
              <p class="small muted">
                到期：{{ role.expires_at ? formatDateTimeInTimezone(rowText(role, 'expires_at', '')) : '未设置' }}
              </p>
              <div class="platform-chip-row">
                <span v-for="grant in nestedRows(role, 'capabilities')" :key="rowText(grant, 'capability')" class="plain-chip mono">
                  {{ rowText(grant, 'capability') }}
                </span>
              </div>
            </div>
            <div class="platform-card__actions">
              <span class="plain-chip">{{ nestedRows(role, 'capabilities').length }} 项能力</span>
            </div>
          </article>
        </section>
        <div v-else class="panel"><div class="panel__body"><p class="empty-inline">角色目录没有返回角色。</p></div></div>

      </template>

      <!-- Menus and permissions -->
      <template v-else-if="mode === 'menus' || mode === 'permissions' || mode === 'data-scopes' || mode === 'field-policies' || mode === 'settings'">
        <section v-if="mode === 'menus' || mode === 'permissions'" class="panel">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">可见菜单与页面</h2>
              <p class="panel__subtitle">来自 GET /v1/me/permissions；页面可见性不会由父菜单隐式授予。</p>
            </div>
            <span class="plain-chip">{{ visiblePages.length }} 个可见页面</span>
          </div>
          <div v-if="menus.length > 0" class="platform-menu-list">
            <article v-for="menu in menus" :key="rowText(menu, 'key')" class="platform-menu-group">
              <h3>{{ rowText(menu, 'label', rowText(menu, 'key')) }}</h3>
              <ul>
                <li v-for="page in nestedRows(menu, 'pages')" :key="rowText(page, 'path')">
                  <span>{{ rowText(page, 'menu_label') }}</span>
                  <span class="mono small">{{ rowText(page, 'path') }}</span>
                </li>
              </ul>
            </article>
          </div>
          <div v-else class="panel__body"><p class="empty-inline">当前主体没有可见菜单。</p></div>
        </section>

        <section class="panel">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">动作权限</h2>
              <p class="panel__subtitle">能力码由代码拥有；此处只读展示当前有效动作。</p>
            </div>
          </div>
          <div v-if="permissionActions.length > 0" class="platform-chip-row platform-panel-body">
            <span v-for="code in permissionActions" :key="code" class="plain-chip mono">{{ capabilityLabel(code) }}</span>
          </div>
          <div v-else class="panel__body"><p class="empty-inline">没有有效动作权限。</p></div>
        </section>

        <section v-if="mode === 'menus' || mode === 'permissions'" class="panel">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">能力目录（只读）</h2>
              <p class="panel__subtitle">来自 GET /v1/capabilities；不提供前端新建能力码。</p>
            </div>
          </div>
          <div v-if="capabilityRows.length > 0" class="platform-table-wrap">
            <table class="platform-table">
              <caption class="visually-hidden">能力目录</caption>
              <thead><tr><th scope="col">能力码</th><th scope="col">资源</th><th scope="col">动作</th><th scope="col">锚点</th><th scope="col">字段上限</th><th scope="col">风险</th><th scope="col">说明</th></tr></thead>
              <tbody>
                <tr v-for="capability in capabilityRows" :key="rowText(capability, 'code')">
                  <td class="mono">{{ rowText(capability, 'code') }}</td>
                  <td>{{ rowText(capability, 'resource') }}</td>
                  <td>{{ textOf(capability.actions) }}</td>
                  <td>{{ textOf(capability.anchor_types) }}</td>
                  <td>{{ rowText(capability, 'field_profile_max') }}</td>
                  <td>{{ rowText(capability, 'risk') }}</td>
                  <td class="small">{{ rowText(capability, 'description') }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-else class="panel__body"><p class="empty-inline">能力目录没有返回记录。</p></div>
        </section>

        <section
          v-if="mode === 'menus' || mode === 'permissions' || mode === 'data-scopes' || mode === 'field-policies' || mode === 'settings'"
          class="platform-panel-grid"
        >
          <div class="panel">
            <div class="panel__header"><h2 class="panel__title">字段档位</h2></div>
            <div class="panel__body">
              <div v-if="fieldCapabilities.length > 0" class="platform-chip-row">
                <span v-for="fieldProfile in fieldCapabilities" :key="fieldProfile" class="plain-chip">
                  {{ FIELD_PROFILE_LABEL[fieldProfile as keyof typeof FIELD_PROFILE_LABEL] ?? fieldProfile }}
                </span>
              </div>
              <p v-else class="empty-inline">没有字段能力记录。</p>
            </div>
          </div>
          <div class="panel">
            <div class="panel__header"><h2 class="panel__title">数据范围</h2></div>
            <div v-if="scopeLabels.length > 0" class="platform-table-wrap">
              <table class="platform-table">
                <caption class="visually-hidden">数据范围标签</caption>
                <thead><tr><th scope="col">锚点类型</th><th scope="col">中文标签</th></tr></thead>
                <tbody>
                  <tr v-for="scope in scopeLabels" :key="rowText(scope, 'anchor_type')">
                    <td class="mono">{{ rowText(scope, 'anchor_type') }}</td>
                    <td>{{ rowText(scope, 'label') }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div v-else class="panel__body"><p class="empty-inline">没有数据范围标签。</p></div>
          </div>
        </section>

      </template>

      <!-- Workflow -->
      <template v-else-if="mode === 'workflow'">
        <section class="panel">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">我的待办</h2>
              <p class="panel__subtitle">GET /v1/tasks/my 只返回指派给当前主体的 PENDING 任务。</p>
            </div>
          </div>
          <div v-if="tasks.length > 0" class="platform-table-wrap">
            <table class="platform-table">
              <caption class="visually-hidden">我的工作流待办</caption>
              <thead><tr><th scope="col">案例</th><th scope="col">标题</th><th scope="col">节点</th><th scope="col">申请状态</th><th scope="col">生效状态</th><th scope="col">SLA</th><th scope="col">截止</th></tr></thead>
              <tbody>
                <tr v-for="task in tasks" :key="idText(task.task_id)">
                  <td class="mono">{{ rowText(task, 'case_no') }}</td>
                  <td>{{ rowText(task, 'title') }}</td>
                  <td class="mono">{{ rowText(task, 'node_key') }}</td>
                  <td>{{ statusText(rowText(task, 'application_status')) }}</td>
                  <td>{{ statusText(rowText(task, 'effect_status')) }}</td>
                  <td>{{ statusText(rowText(task, 'sla_state')) }}</td>
                  <td class="small">{{ formatDateTimeInTimezone(rowText(task, 'due_at', '')) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-else class="panel__body"><p class="empty-inline">当前没有指派给你的待办。</p></div>
        </section>

        <section class="panel">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">流程模板</h2>
              <p class="panel__subtitle">模板版本与节点来自 GET /v1/workflow/templates；不渲染脚本或原始 JSON。</p>
            </div>
          </div>
          <div v-if="workflowTemplates.length > 0" class="platform-card-list">
            <article v-for="template in workflowTemplates" :key="`${rowText(template, 'code')}-${rowText(template, 'version')}`" class="platform-card">
              <div class="platform-card__main">
                <div class="platform-card__meta">
                  <span class="mono">{{ rowText(template, 'code') }}</span>
                  <span class="plain-chip">v{{ rowText(template, 'version') }}</span>
                  <span class="plain-chip">{{ statusText(rowText(template, 'status')) }}</span>
                </div>
                <h2 class="platform-card__title">{{ rowText(template, 'name') }}</h2>
                <p class="small">{{ nodeSummary(template) }}</p>
              </div>
            </article>
          </div>
          <div v-else class="panel__body"><p class="empty-inline">流程模板目录为空。</p></div>
        </section>
      </template>

      <!-- Self service -->
      <template v-else-if="mode === 'self-service'">
        <section v-if="workspaceData" class="panel">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">我的档案</h2>
              <p class="panel__subtitle">GET /v1/me/workspace 只返回当前员工的投影。</p>
            </div>
            <span class="plain-chip">{{ workspaceData.employment_linked === true ? '已关联任职' : '未关联任职' }}</span>
          </div>
          <dl v-if="profile" class="panel__body platform-definition-grid">
            <div><dt>姓名</dt><dd>{{ rowText(profile, 'name') }}</dd></div>
            <div><dt>工号</dt><dd>{{ rowText(profile, 'employeeNo') }}</dd></div>
            <div><dt>岗位</dt><dd>{{ rowText(profile, 'jobTitle') }}</dd></div>
            <div><dt>组织</dt><dd>{{ rowText(profile, 'orgName') }}</dd></div>
            <div><dt>直属经理</dt><dd>{{ rowText(profile, 'managerName') }}</dd></div>
            <div><dt>工作地点</dt><dd>{{ rowText(profile, 'workLocation') }}</dd></div>
            <div><dt>邮箱</dt><dd>{{ rowText(profile, 'email') }}</dd></div>
            <div><dt>业务日期</dt><dd>{{ formatBusinessDate(rowText(workspaceData, 'businessDate', '')) }}</dd></div>
          </dl>
          <div v-else class="panel__body"><p class="empty-inline">自助投影没有返回员工档案。</p></div>
        </section>

        <div class="platform-panel-grid">
          <section class="panel">
            <div class="panel__header"><h2 class="panel__title">本月考勤</h2></div>
            <div v-if="attendanceSummary" class="panel__body platform-stat-grid">
              <div><strong>{{ rowText(attendanceSummary, 'presentDays') }}</strong><span>出勤</span></div>
              <div><strong>{{ rowText(attendanceSummary, 'lateCount') }}</strong><span>迟到</span></div>
              <div><strong>{{ rowText(attendanceSummary, 'absentCount') }}</strong><span>缺勤</span></div>
              <div><strong>{{ rowText(attendanceSummary, 'leaveDays') }}</strong><span>休假</span></div>
              <div><strong>{{ rowText(attendanceSummary, 'remoteDays') }}</strong><span>远程</span></div>
              <div><strong>{{ rowText(attendanceSummary, 'expectedDays') }}</strong><span>应出勤</span></div>
            </div>
          </section>

          <section class="panel">
            <div class="panel__header"><h2 class="panel__title">假期余额</h2></div>
            <ul v-if="leaveBalances.length > 0" class="platform-list">
              <li v-for="balance in leaveBalances" :key="rowText(balance, 'leaveTypeCode')">
                <div>
                  <p class="platform-list__title">{{ rowText(balance, 'leaveTypeName') }}</p>
                  <p class="small muted">发放 {{ rowText(balance, 'granted') }} · 已用 {{ rowText(balance, 'used') }}</p>
                </div>
                <span class="plain-chip">{{ rowText(balance, 'available') }} {{ rowText(balance, 'unit') }}</span>
              </li>
            </ul>
            <div v-else class="panel__body"><p class="empty-inline">没有假期账户。</p></div>
          </section>

          <section class="panel">
            <div class="panel__header"><h2 class="panel__title">我的申请</h2></div>
            <ul v-if="workspaceRequests.length > 0" class="platform-list">
              <li v-for="request in workspaceRequests" :key="idText(request.case_id)">
                <div>
                  <p class="platform-list__title">请假申请</p>
                  <p class="small muted">{{ formatBusinessDate(rowText(request, 'from_date', '')) }} — {{ formatBusinessDate(rowText(request, 'to_date', '')) }}</p>
                </div>
                <span class="plain-chip">{{ statusText(rowText(request, 'state')) }}</span>
              </li>
            </ul>
            <div v-else class="panel__body"><p class="empty-inline">没有本人申请记录。</p></div>
          </section>

          <section class="panel">
            <div class="panel__header"><h2 class="panel__title">最近工资条</h2></div>
            <ul v-if="payslips.length > 0" class="platform-list">
              <li v-for="slip in payslips" :key="idText(slip.id)">
                <div>
                  <p class="platform-list__title">{{ rowText(slip, 'period') }}</p>
                  <p class="small muted">{{ statusText(rowText(slip, 'state')) }} · 发放 {{ formatBusinessDate(rowText(slip, 'payDate', '')) }}</p>
                </div>
                <span class="plain-chip">实发 {{ rowText(slip, 'netCents') }} 分</span>
              </li>
            </ul>
            <div v-else class="panel__body"><p class="empty-inline">没有已发布工资条。</p></div>
          </section>
        </div>

        <section class="panel">
          <div class="panel__header"><h2 class="panel__title">最新公告</h2></div>
          <ul v-if="workspaceNotices.length > 0" class="platform-list">
            <li v-for="notice in workspaceNotices" :key="idText(notice.id)">
              <div>
                <p class="platform-list__title">{{ rowText(notice, 'title') }}</p>
                <p class="small muted">{{ rowText(notice, 'authorName') }} · {{ formatDateTimeInTimezone(rowText(notice, 'publishedAt', '')) }}</p>
              </div>
              <span class="plain-chip">{{ notice.acknowledged ? '已确认' : notice.requiresAck ? '需确认' : '通知' }}</span>
            </li>
          </ul>
          <div v-else class="panel__body"><p class="empty-inline">没有可见公告。</p></div>
        </section>
      </template>

      <!-- Benefits and Calendars -->
      <template v-else-if="mode === 'benefits'">
        <section class="panel">
          <div class="panel__header"><h2 class="panel__title">福利模块</h2></div>
          <div class="panel__body stack--tight">
            <p class="small muted">
              本 checkout 没有基数方案、缴费项、月度增减员、福利项目或发放台账 API。页面不展示任何虚构的缴费金额或员工福利数据。
            </p>
          </div>
        </section>
      </template>

      <template v-else-if="mode === 'calendars'">
        <section class="panel">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">工作日历</h2>
              <p class="panel__subtitle">法人与发布版本来自 Time 模块；默认查询法人 1 的 2026 年版本。</p>
            </div>
            <div class="platform-inline-actions">
              <label class="platform-field platform-field--inline">
                <span>年份</span>
                <input v-model.number="calendarYear" type="number" min="1970" max="9999" data-testid="calendar-year" />
              </label>
              <el-button @click="reloadCalendars">查询</el-button>
            </div>
          </div>
          <dl v-if="calendarData" class="panel__body platform-definition-grid">
            <div><dt>法人</dt><dd>{{ rowText(calendarData, 'legal_entity_id') }}</dd></div>
            <div><dt>年份</dt><dd>{{ rowText(calendarData, 'year') }}</dd></div>
            <div><dt>时区</dt><dd>{{ rowText(calendarData, 'timezone') }}</dd></div>
            <div><dt>标准日分钟</dt><dd>{{ rowText(calendarData, 'standard_day_minutes') }}</dd></div>
            <div><dt>版本</dt><dd>{{ rowText(calendarData, 'version') }}</dd></div>
            <div><dt>工作周</dt><dd>{{ workweek.join('、') }}</dd></div>
          </dl>
          <div v-else class="panel__body"><p class="empty-inline">未返回工作日历。</p></div>
        </section>

        <section class="panel">
          <div class="panel__header"><h2 class="panel__title">节假日</h2></div>
          <div v-if="holidays.length > 0" class="platform-table-wrap">
            <table class="platform-table">
              <caption class="visually-hidden">节假日列表</caption>
              <thead><tr><th scope="col">日期</th><th scope="col">名称</th><th scope="col">类型</th><th scope="col">来源</th></tr></thead>
              <tbody>
                <tr v-for="holiday in holidays" :key="`${rowText(holiday, 'date')}-${rowText(holiday, 'name')}`">
                  <td>{{ formatBusinessDate(rowText(holiday, 'date', '')) }}</td>
                  <td>{{ rowText(holiday, 'name') }}</td>
                  <td>{{ rowText(holiday, 'type') }}</td>
                  <td class="small">{{ rowText(holiday, 'source') }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-else class="panel__body"><p class="empty-inline">该年度没有返回节假日。</p></div>
        </section>

        <section class="panel">
          <div class="panel__header"><h2 class="panel__title">假期类型</h2></div>
          <div v-if="leaveTypes.length > 0" class="platform-table-wrap">
            <table class="platform-table">
              <caption class="visually-hidden">假期类型列表</caption>
              <thead><tr><th scope="col">编码</th><th scope="col">名称</th><th scope="col">单位</th><th scope="col">法定</th><th scope="col">年假</th><th scope="col">年额度</th><th scope="col">需附件</th><th scope="col">允许负余额</th><th scope="col">版本</th></tr></thead>
              <tbody>
                <tr v-for="type in leaveTypes" :key="rowText(type, 'code')">
                  <td class="mono">{{ rowText(type, 'code') }}</td>
                  <td>{{ rowText(type, 'name') }}</td>
                  <td>{{ rowText(type, 'unit') }}</td>
                  <td>{{ type.is_statutory === true ? '是' : '否' }}</td>
                  <td>{{ type.is_annual === true ? '是' : '否' }}</td>
                  <td>{{ rowText(type, 'annual_entitlement_days') }}</td>
                  <td>{{ type.requires_attachment === true ? '是' : '否' }}</td>
                  <td>{{ type.allow_negative === true ? '是' : '否' }}</td>
                  <td>{{ rowText(type, 'version') }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-else class="panel__body"><p class="empty-inline">没有假期类型。</p></div>
        </section>
      </template>

      <!-- Notifications -->
      <template v-else-if="mode === 'notifications'">
        <div class="platform-panel-grid">
          <section class="panel">
            <div class="panel__header">
              <div>
                <h2 class="panel__title">我的待办提醒</h2>
                <p class="panel__subtitle">来自 GET /v1/tasks/my。</p>
              </div>
            </div>
            <div v-if="tasks.length > 0" class="platform-table-wrap">
              <table class="platform-table">
                <caption class="visually-hidden">通知待办</caption>
                <thead><tr><th scope="col">案例</th><th scope="col">标题</th><th scope="col">节点</th><th scope="col">SLA</th></tr></thead>
                <tbody>
                  <tr v-for="task in tasks" :key="idText(task.task_id)">
                    <td class="mono">{{ rowText(task, 'case_no') }}</td>
                    <td>{{ rowText(task, 'title') }}</td>
                    <td>{{ rowText(task, 'node_key') }}</td>
                    <td>{{ statusText(rowText(task, 'sla_state')) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div v-else class="panel__body"><p class="empty-inline">当前没有待办提醒。</p></div>
          </section>

          <section class="panel">
            <div class="panel__header">
              <div>
                <h2 class="panel__title">公告通知</h2>
                <p class="panel__subtitle">来自 GET /v1/engagement/notices。</p>
              </div>
            </div>
            <ul v-if="notices.length > 0" class="platform-list">
              <li v-for="notice in notices" :key="idText(notice.id)">
                <div>
                  <p class="platform-list__title">{{ rowText(notice, 'title') }}</p>
                  <p class="small muted">{{ rowText(notice, 'audienceLabel') }} · {{ formatDateTimeInTimezone(rowText(notice, 'publishedAt', '')) }}</p>
                </div>
                <span class="plain-chip">{{ notice.acknowledged ? '已确认' : notice.requiresAck ? '需确认' : '通知' }}</span>
              </li>
            </ul>
            <div v-else class="panel__body"><p class="empty-inline">当前没有公告通知。</p></div>
          </section>
        </div>

        <section class="panel">
          <div class="panel__body">
            <p class="small muted">站内信投递记录、模板、失败重试与阅读状态管理没有本 checkout 路由，页面不伪造投递结果。</p>
          </div>
        </section>
      </template>

      <!-- Audit -->
      <template v-else-if="mode === 'audit'">
        <section class="panel">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">认证与授权事件</h2>
              <p class="panel__subtitle">GET /v1/auth/events · 保留 request_id 作为支持证据。</p>
            </div>
          </div>
          <div v-if="accessEvents.length > 0" class="platform-table-wrap">
            <table class="platform-table">
              <caption class="visually-hidden">认证事件列表</caption>
              <thead><tr><th scope="col">时间</th><th scope="col">用户</th><th scope="col">事件</th><th scope="col">结果</th><th scope="col">元数据</th><th scope="col">请求编号</th></tr></thead>
              <tbody>
                <tr v-for="event in accessEvents" :key="idText(event.id)">
                  <td class="small">{{ formatDateTimeInTimezone(rowText(event, 'occurred_at', '')) }}</td>
                  <td>{{ rowText(event, 'user_id') }}</td>
                  <td class="mono">{{ rowText(event, 'event_type') }}</td>
                  <td><span class="plain-chip">{{ statusText(rowText(event, 'result')) }}</span></td>
                  <td class="small">{{ summariseRecord(event.metadata) }}</td>
                  <td class="mono small">{{ rowText(event, 'request_id') }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-else class="panel__body"><p class="empty-inline">没有认证事件。</p></div>
        </section>

        <div class="platform-panel-grid">
          <section class="panel">
            <div class="panel__header">
              <div>
                <h2 class="panel__title">访问复核 · 未使用高危绑定</h2>
                <p class="panel__subtitle">GET /v1/access-review 只读投影。</p>
              </div>
            </div>
            <div v-if="unusedBindings.length > 0" class="platform-table-wrap">
              <table class="platform-table">
                <caption class="visually-hidden">未使用高危绑定</caption>
                <thead><tr><th scope="col">绑定</th><th scope="col">主体</th><th scope="col">能力</th><th scope="col">风险</th><th scope="col">创建时间</th></tr></thead>
                <tbody>
                  <tr v-for="binding in unusedBindings" :key="idText(binding.binding_id)">
                    <td class="mono">{{ rowText(binding, 'binding_id') }}</td>
                    <td>{{ rowText(binding, 'principal_id') }}</td>
                    <td class="mono">{{ rowText(binding, 'capability_code') }}</td>
                    <td>{{ rowText(binding, 'risk') }}</td>
                    <td class="small">{{ formatDateTimeInTimezone(rowText(binding, 'created_at', '')) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div v-else class="panel__body"><p class="empty-inline">没有未使用高危绑定。</p></div>
          </section>

          <section class="panel">
            <div class="panel__header">
              <div>
                <h2 class="panel__title">访问复核 · 职责分离风险</h2>
                <p class="panel__subtitle">风险和能力对来自服务端，不推断未返回的主体。</p>
              </div>
            </div>
            <ul v-if="sodRisks.length > 0" class="platform-list">
              <li v-for="(risk, index) in sodRisks" :key="`${rowText(risk, 'principal_id')}-${index}`">
                <div>
                  <p class="platform-list__title">主体 {{ rowText(risk, 'principal_id') }} · {{ rowText(risk, 'kind') }}</p>
                  <p class="small muted mono">{{ textOf(risk.capabilities) }}</p>
                </div>
                <span class="plain-chip">SoD</span>
              </li>
            </ul>
            <div v-else class="panel__body"><p class="empty-inline">没有职责分离风险。</p></div>
          </section>
        </div>

      </template>

      <section v-if="config === null" class="panel">
        <div class="panel__body">
          <p class="platform-gap__title">页面配置缺失</p>
          <p class="small muted">当前路由没有对应的 PlatformWorkspace 配置，请检查页面注册表。</p>
        </div>
      </section>
    </template>
  </div>
</template>

<style scoped>
.platform-workspace {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.platform-success {
  padding: 9px 12px;
  color: var(--color-success);
  font-size: 12px;
  font-weight: 600;
  background: var(--color-success-soft);
  border: 1px solid #b7dcc7;
  border-radius: var(--radius-md);
}

.platform-gap {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 18px;
  padding: 13px 16px;
  background: #fffaf0;
  border: 1px solid #ead49f;
  border-left: 3px solid var(--color-warning);
  border-radius: var(--radius-md);
}

.platform-gap__kicker {
  color: var(--color-warning);
  font-size: 9.5px;
  font-weight: 750;
  letter-spacing: 0.12em;
}

.platform-gap__title {
  color: var(--color-ink);
  font-size: 13px;
  font-weight: 650;
  line-height: 1.55;
}

.platform-metric-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 10px;
}

.platform-metric {
  padding: 13px 15px;
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
}

.platform-metric__label {
  color: var(--color-ink-muted);
  font-size: 10.5px;
  font-weight: 650;
}

.platform-metric__value {
  margin: 4px 0 6px;
  color: var(--color-ink);
  font-size: 25px;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}

.platform-metric__value span {
  color: var(--color-ink-muted);
  font-size: 11px;
  font-weight: 500;
}

.platform-reason {
  color: var(--color-danger);
}

.platform-panel-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 14px;
}

.platform-list {
  margin: 0;
  padding: 0;
  list-style: none;
}

.platform-list li {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 16px;
  border-bottom: 1px solid #edf1ef;
}

.platform-list li:last-child {
  border-bottom: 0;
}

.platform-list__title {
  font-size: 12.5px;
  font-weight: 650;
}

.platform-table-wrap {
  overflow-x: auto;
}

.platform-table {
  width: 100%;
  min-width: 720px;
  border-collapse: collapse;
  font-size: 11.5px;
}

.platform-table th {
  padding: 9px 12px;
  color: var(--color-ink-muted);
  font-size: 10px;
  font-weight: 700;
  text-align: left;
  background: #f8faf9;
  border-bottom: 1px solid var(--color-line);
}

.platform-table td {
  padding: 10px 12px;
  vertical-align: top;
  border-bottom: 1px solid #edf1ef;
}

.platform-profile {
  display: block;
  color: var(--color-ink-muted);
  font-family: "Cascadia Code", Consolas, monospace;
  font-size: 8.5px;
  font-weight: 500;
}

.platform-toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}

.platform-filters {
  display: flex;
  flex-wrap: wrap;
  gap: 3px;
  padding: 3px;
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: 7px;
}

.platform-filters button {
  padding: 5px 11px;
  color: var(--color-ink-muted);
  font-size: 11px;
  font-weight: 650;
  background: transparent;
  border: 0;
  border-radius: 5px;
  cursor: pointer;
}

.platform-filter--active {
  color: var(--color-brand);
  background: var(--color-brand-soft);
}

.platform-card-list {
  display: flex;
  flex-direction: column;
  gap: 9px;
}

.platform-card {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 18px;
  padding: 14px 16px;
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
}

.platform-card__main {
  min-width: 0;
  flex: 1;
}

.platform-card__meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
  color: var(--color-ink-muted);
  font-size: 10px;
}

.platform-card__title {
  margin: 5px 0 3px;
  color: var(--color-ink);
  font-size: 14px;
  font-weight: 660;
}

.platform-card__summary {
  color: var(--color-ink-secondary);
  font-size: 12px;
  line-height: 1.6;
}

.platform-card__actions {
  display: flex;
  flex: 0 0 auto;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
}

.notice-body {
  color: var(--color-ink-secondary);
  font-size: 13px;
  line-height: 1.85;
}

.notice-body p {
  margin-bottom: 10px;
  white-space: pre-wrap;
}

.platform-form {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.platform-form-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px 12px;
}

.platform-field {
  display: flex;
  flex-direction: column;
  gap: 4px;
  color: var(--color-ink-muted);
  font-size: 10.5px;
  font-weight: 650;
}

.platform-field--inline {
  flex-direction: row;
  align-items: center;
}

.platform-field input,
.platform-field select,
.platform-field textarea {
  width: 100%;
  padding: 7px 9px;
  color: var(--color-ink);
  font: inherit;
  font-size: 12px;
  background: #fff;
  border: 1px solid var(--color-line-strong);
  border-radius: 5px;
}

.platform-field textarea {
  resize: vertical;
}

.platform-check {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  color: var(--color-ink-secondary);
  font-size: 11.5px;
}

.platform-fieldset {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 14px;
  padding: 10px;
  border: 1px solid var(--color-line);
  border-radius: 6px;
}

.platform-fieldset legend {
  padding: 0 5px;
  color: var(--color-ink-muted);
  font-size: 10.5px;
  font-weight: 650;
}

.platform-inline-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}

.platform-link {
  color: var(--color-brand);
  font-size: 11.5px;
  font-weight: 600;
}

.platform-definition-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 10px;
}

.platform-definition-grid > div {
  padding: 9px 10px;
  background: var(--color-canvas);
  border-radius: 5px;
}

.platform-definition-grid dt {
  color: var(--color-ink-muted);
  font-size: 9.5px;
  font-weight: 650;
}

.platform-definition-grid dd {
  margin: 2px 0 0;
  color: var(--color-ink);
  font-size: 12px;
  font-weight: 600;
  overflow-wrap: anywhere;
}

.platform-code-block {
  padding: 10px 12px;
  background: var(--color-canvas);
  border: 1px solid var(--color-line);
  border-radius: 6px;
  font-size: 11px;
  line-height: 1.7;
}

.platform-code-block ul {
  margin: 5px 0 0;
  padding-left: 18px;
}

.platform-break {
  overflow-wrap: anywhere;
}

.platform-stat-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 8px;
}

.platform-stat-grid > div {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 9px;
  background: var(--color-canvas);
  border-radius: 5px;
}

.platform-stat-grid strong {
  font-size: 20px;
  font-variant-numeric: tabular-nums;
}

.platform-stat-grid span {
  color: var(--color-ink-muted);
  font-size: 9.5px;
}

.platform-menu-list {
  display: flex;
  flex-direction: column;
}

.platform-menu-group {
  padding: 10px 14px;
  border-bottom: 1px solid #edf1ef;
}

.platform-menu-group:last-child {
  border-bottom: 0;
}

.platform-menu-group h3 {
  margin-bottom: 5px;
  font-size: 12.5px;
  font-weight: 660;
}

.platform-menu-group ul {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 4px 12px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.platform-menu-group li {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  color: var(--color-ink-secondary);
  font-size: 11px;
}

.platform-chip-row {
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
}

.platform-panel-body {
  padding: 14px 16px;
}

@media (max-width: 1080px) {
  .platform-metric-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .platform-panel-grid {
    grid-template-columns: minmax(0, 1fr);
  }

  .platform-definition-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 680px) {
  .platform-metric-grid,
  .platform-form-grid,
  .platform-definition-grid,
  .platform-menu-group ul {
    grid-template-columns: minmax(0, 1fr);
  }

  .platform-card {
    flex-direction: column;
  }
}
</style>
