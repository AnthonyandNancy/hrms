import axios, { AxiosError, type AxiosInstance } from 'axios'
import type {
  ApiAdapter,
  ApiEnvelope,
  BackendDashboardData,
  DashboardData,
  AuthApi,
  EmployeeDetail,
  ExportJob,
  LoginRequest,
  SessionBootstrap,
  AdminSnapshot,
} from '@/api/contracts'
import { ApiError } from '@/api/errors'

/**
 * The employer (legal entity) the interface is currently acting for.
 *
 * The reference implementation is single-employer, so this stays 1; it exists
 * as one function so a multi-entity switcher only has to change it in one
 * place instead of editing every scattered `legal_entity_id: 1` literal.
 */
export function legalEntityId(): number {
  return 1
}

/** Headers that scope a request to the current employer. */
function legalEntityHeaders(): Record<string, string> {
  return { 'X-Legal-Entity-Id': String(legalEntityId()) }
}

/**
 * Session-cookie HTTP adapter for the real Laravel backend.
 *
 * The backend is the authorization authority. This adapter attaches the
 * in-memory CSRF token to unsafe requests, never stores a bearer token, and
 * normalises the backend's snake_case payloads into the camelCase domain types
 * the views already use.
 */
type BackendUser = {
  id: number
  username: string
  email: string | null
  is_privileged: boolean
  mfa_enrolled: boolean
  auth_version: number
}

type BackendMfaChallenge = {
  status: 'MFA_REQUIRED'
  challenge_id: string
  masked_destination: string
  expires_at: string | null
}

type BackendLogin = {
  user: BackendUser
  csrf_token: string
  session: {
    id: string
    mfa_verified: boolean
    idle_expires_at: string
    absolute_expires_at: string
  }
}

type BackendMe = {
  id: number
  tenant_id: number
  username: string
  email: string | null
  employment_id: number | null
  is_privileged: boolean
  has_mfa: boolean
  mfa_verified: boolean
  auth_version: number
  roles: string[]
  tenant_name?: string
  legal_entity_name?: string
}

type BackendCapabilities = {
  capabilities: Array<{ capability: string }>
  wildcard?: boolean
  mfa_required: boolean
  mfa_verified: boolean
}

function resolveBaseUrl(): string {
  return import.meta.env.VITE_API_BASE_URL ?? '/v1'
}

function mapStatus(status: number): ApiError['code'] {
  if (status === 401) return 'UNAUTHENTICATED'
  if (status === 403) return 'FORBIDDEN'
  if (status === 404) return 'NOT_FOUND'
  if (status === 409) return 'CONFLICT'
  if (status === 422) return 'VALIDATION_FAILED'
  if (status === 429) return 'RATE_LIMITED'
  if (status >= 500) return 'SERVICE_UNAVAILABLE'
  return 'INTERNAL'
}

function actionPath(action: string): string {
  const map: Record<string, string> = {
    LOCK_INPUT: 'lock-inputs',
    CALCULATE: 'calculate',
    REVIEW: 'review',
    APPROVE: 'approve',
    PUBLISH: 'publish',
    CREATE_CORRECTION: 'corrections',
  }
  return map[action] ?? action.toLowerCase()
}

function masked(profile: 'P1_INTERNAL' | 'P2_SENSITIVE' | 'P3_RESTRICTED') {
  return { display: null, masked: true, profile, revealable: false, exportable: false }
}

/**
 * Real dashboard data is composed by Reporting from authorized projection
 * rows. Panels are already bounded by the API; this normaliser only guarantees
 * the empty-list shape so older/partial responses cannot crash the view.
 */
export function normaliseDashboard(data: BackendDashboardData): DashboardData {
  return {
    ...data,
    pendingApprovals: Array.isArray(data.pendingApprovals) ? data.pendingApprovals : [],
    recentNotices: Array.isArray(data.recentNotices) ? data.recentNotices : [],
    contractAlerts: Array.isArray(data.contractAlerts) ? data.contractAlerts : [],
  }
}

function normaliseEmployee(row: Record<string, unknown>): Record<string, unknown> {
  const assignment = (row.current_assignment ?? {}) as Record<string, unknown>
  const name = typeof row.person_name === 'string' ? row.person_name : String(row.employee_no ?? '')
  return {
    id: Number(row.employment_id ?? 0),
    employeeNo: String(row.employee_no ?? ''),
    name,
    initials: name.slice(0, 2).toUpperCase(),
    orgId: Number(assignment.org_unit_id ?? 0),
    orgName: assignment.org_unit_id ? `组织 ${String(assignment.org_unit_id)}` : '—',
    positionName: '',
    status: String(row.status ?? 'ACTIVE'),
    employmentType: String(row.employment_type ?? 'FULL_TIME'),
    hireDate: String(row.valid_from ?? ''),
    phone: masked('P1_INTERNAL'),
    email: masked('P1_INTERNAL'),
    idNumber: masked('P2_SENSITIVE'),
  }
}

function normaliseEmployeeDetail(row: Record<string, unknown>): EmployeeDetail {
  const base = normaliseEmployee(row) as unknown as EmployeeDetail
  const terms = (row.terms ?? {}) as Record<string, unknown>
  return {
    ...base,
    legalEntity: String(row.legal_entity_id ?? ''),
    managerName: '',
    workLocation: String(terms.work_location ?? ''),
    personalEmail: masked('P1_INTERNAL'),
    address: masked('P1_INTERNAL'),
    bankAccount: masked('P2_SENSITIVE'),
    assignments: [],
    contract: {
      contractNo: '',
      type: String(terms.work_schedule ?? ''),
      startDate: String(terms.effective_from ?? ''),
      endDate: null,
      daysToExpiry: null,
      status: 'ACTIVE',
    },
    timeline: [],
    fieldAccessNote: '敏感字段按字段档位遮蔽展示。',
  }
}

export function createHttpAdapter(): ApiAdapter {
  const client: AxiosInstance = axios.create({
    baseURL: resolveBaseUrl(),
    withCredentials: true,
    timeout: 20_000,
    headers: { Accept: 'application/json' },
  })

  let csrfToken: string | null = null
  let sessionExpiresAt: string | null = null

  client.interceptors.request.use((config) => {
    if (csrfToken && config.method && !['get', 'head', 'options'].includes(config.method.toLowerCase())) {
      config.headers.set('X-CSRF-Token', csrfToken)
    }
    config.headers.set('X-Requested-With', 'XMLHttpRequest')
    return config
  })

  function translateError(error: unknown): never {
    if (error instanceof ApiError) throw error
    const axiosError = error as AxiosError<{
      code?: string
      message?: string
      data?: { reason?: string; fields?: Record<string, string[]> }
      request_id?: string
    }>
    if (!axiosError.response) {
      throw new ApiError('NETWORK_ERROR', '网络连接失败', { status: 0 })
    }
    const body = axiosError.response.data
    throw new ApiError(
      (body?.code as ApiError['code']) || mapStatus(axiosError.response.status),
      body?.message || '请求未完成',
      {
        status: axiosError.response.status,
        requestId: body?.request_id ?? null,
        fieldErrors: body?.data?.fields ?? {},
      },
    )
  }

  async function unwrap<T>(request: Promise<{ data: ApiEnvelope<T> }>): Promise<T> {
    try {
      const response = await request
      return response.data.data
    } catch (error) {
      translateError(error)
    }
  }

  function anonymous(): SessionBootstrap {
    return {
      authenticated: false,
      principal: null,
      capabilities: [],
      csrfToken: null,
      sessionExpiresAt: null,
      mfaEnrollmentRequired: false,
    }
  }

  async function loadMe(): Promise<BackendMe | null> {
    try {
      return await unwrap<BackendMe>(client.get<ApiEnvelope<BackendMe>>('/me'))
    } catch (error) {
      if (error instanceof ApiError && ['UNAUTHENTICATED', 'FORBIDDEN'].includes(error.code)) {
        return null
      }
      throw error
    }
  }

  async function loadIdentity(): Promise<SessionBootstrap> {
    const me = await loadMe()
    if (me === null) return anonymous()

    let capabilities: string[] = []
    try {
      const caps = await unwrap<BackendCapabilities>(
        client.get<ApiEnvelope<BackendCapabilities>>('/me/capabilities'),
      )
      capabilities = caps.capabilities.map((c) => c.capability)
    } catch {
      capabilities = []
    }

    return {
      authenticated: true,
      principal: {
        userId: me.id,
        tenantId: me.tenant_id,
        employmentId: me.employment_id,
        displayName: me.username,
        email: me.email ?? '',
        employmentNo: '',
        jobTitle: '',
        orgName: '',
        initials: me.username.slice(0, 2).toUpperCase(),
        isPrivileged: me.is_privileged,
        hasMfa: me.mfa_verified,
        authVersion: me.auth_version,
        tenantTimezone: 'Asia/Shanghai',
        tenantName: me.tenant_name,
        legalEntityName: me.legal_entity_name,
      },
      capabilities,
      csrfToken,
      sessionExpiresAt,
      mfaEnrollmentRequired: me.is_privileged && !me.has_mfa,
    }
  }

  const auth: AuthApi = {
    bootstrap: loadIdentity,

    async login(payload: LoginRequest) {
      const response = await client.post<ApiEnvelope<BackendLogin | BackendMfaChallenge>>('/auth/login', {
        username: payload.username.trim().toLowerCase(),
        password: payload.password,
      })
      const body = response.data

      if (body.data && 'status' in body.data && body.data.status === 'MFA_REQUIRED') {
        const challenge = body.data as BackendMfaChallenge

        return {
          status: 'MFA_REQUIRED' as const,
          challengeId: challenge.challenge_id,
          maskedDestination: challenge.masked_destination,
        }
      }

      const result = body.data as BackendLogin
      csrfToken = result.csrf_token
      sessionExpiresAt = result.session.absolute_expires_at

      return { status: 'AUTHENTICATED' as const, session: await loadIdentity() }
    },

    async verifyTotp(payload) {
      const response = await client.post<ApiEnvelope<BackendLogin>>('/auth/mfa/challenge', {
        challenge_id: payload.challengeId,
        factor_type: 'TOTP',
        code: payload.code,
      })
      const result = response.data.data as BackendLogin
      csrfToken = result.csrf_token
      sessionExpiresAt = result.session.absolute_expires_at

      return loadIdentity()
    },

    async logout() {
      try {
        await client.post('/auth/logout')
      } catch (error) {
        translateError(error)
      } finally {
        csrfToken = null
        sessionExpiresAt = null
      }
    },

    async enrollMfa() {
      const enrollment = await unwrap<{ secret: string; otpauth_uri: string; factor_id: number }>(
        client.post('/auth/mfa/totp/enroll'),
      )
      return {
        secretPreview: enrollment.secret,
        otpauthUri: enrollment.otpauth_uri,
        recoveryCodes: [],
      }
    },

    async confirmMfa(code) {
      await unwrap(client.post('/auth/mfa/totp/confirm', { factor_id: 0, code }))
      return { enabled: true }
    },

    demoAccounts: () => [],
  }

  return {
    auth,

    workspace: {
      get: () => unwrap(client.get('/me/workspace')),
    },

    employees: {
      listEmployees: ({ asOf, page, pageSize, search, orgId, status }) =>
        unwrap(
          client.get('/employees', {
            params: {
              as_of: asOf,
              page,
              page_size: pageSize,
              q: search || undefined,
              org_unit_id: orgId ?? undefined,
              status: status || undefined,
            },
            headers: legalEntityHeaders(),
          }),
        ).then((data) => {
          const result = data as unknown as {
            items: Array<Record<string, unknown>>
            page: number
            page_size: number
            total: number
          }
          return {
            items: result.items.map(normaliseEmployee),
            page: result.page,
            page_size: result.page_size,
            total: result.total,
          }
        }) as never,
      getEmployee: (id, asOf) =>
        unwrap(
          client.get(`/employees/${id}`, {
            params: { as_of: asOf },
            headers: legalEntityHeaders(),
          }),
        ).then((data) => normaliseEmployeeDetail(data as unknown as Record<string, unknown>)) as never,
      revealField: () => {
        throw new ApiError('FORBIDDEN', '该字段不提供在线明文展示', { status: 403 })
      },
    },

    organization: {
      getOrgTree: async (asOf) => {
        const data = (await unwrap(
          client.get('/org-units/tree', {
            params: { as_of: asOf, legal_entity_id: legalEntityId() },
            headers: legalEntityHeaders(),
          }),
        )) as unknown as { items: Array<Record<string, unknown>> }

        const nodes = new Map<number, Record<string, unknown>>()
        const roots: Array<Record<string, unknown>> = []

        for (const row of data.items) {
          nodes.set(Number(row.id), {
            id: Number(row.id),
            parentId: row.parent_id === null ? null : Number(row.parent_id),
            code: String(row.code ?? ''),
            name: String(row.name ?? ''),
            type: String(row.type ?? 'DEPARTMENT'),
            legalEntityId: Number(row.legal_entity_id ?? 0),
            managerName: null,
            headcount: null,
            directHeadcount: null,
            children: [],
          })
        }

        for (const node of nodes.values()) {
          const parent = node.parentId === null ? null : nodes.get(Number(node.parentId))
          if (parent) {
            ;(parent.children as Array<Record<string, unknown>>).push(node)
          } else {
            roots.push(node)
          }
        }

        return roots as never
      },
      previewOrgMove: (nodeId, targetId, effectiveDate) =>
        unwrap(
          client.post(`/org-units/${nodeId}/impact`, {
            new_parent_id: targetId,
            effective_date: effectiveDate,
          }),
        ) as never,
      moveOrg: (nodeId, targetId, effectiveDate) =>
        unwrap(
          client.post(
            `/org-units/${nodeId}/move`,
            { new_parent_id: targetId, effective_date: effectiveDate },
            { headers: { 'Idempotency-Key': crypto.randomUUID() } },
          ),
        ) as never,
    },

    cases: {
      listCases: ({ status, page, pageSize }) =>
        unwrap(
          client.get('/cases', {
            params: { status: status === 'ALL' ? undefined : status, page, page_size: pageSize },
          }),
        ) as never,
      getCase: (id) => unwrap(client.get(`/cases/${id}`)) as never,
      decideCase: (id, request) =>
        unwrap(
          client.post(
            `/cases/${id}/decisions`,
            { action: request.action, comment: request.comment },
            { headers: { 'Idempotency-Key': crypto.randomUUID() } },
          ),
        ) as never,
      retryCaseEffect: (id) =>
        unwrap(
          client.post(`/effects/${id}/retry`, {}, {
            headers: { 'Idempotency-Key': crypto.randomUUID() },
          }),
        ) as never,
    },

    leave: {
      getLeaveBalances: () => unwrap(client.get('/time/leave/balances')) as never,
      getLeaveRequests: () => unwrap(client.get('/time/leave/requests')) as never,
      previewLeave: (payload) => unwrap(client.post('/time/leave/preview', payload)) as never,
      requestLeave: (payload) =>
        unwrap(
          client.post('/time/leave/requests', payload, {
            headers: { 'Idempotency-Key': crypto.randomUUID() },
          }),
        ) as never,
      cancelLeave: (caseId) =>
        unwrap(
          client.post(`/time/leave/requests/${caseId}/cancel`, {}, {
            headers: { 'Idempotency-Key': crypto.randomUUID() },
          }),
        ) as never,
      getLeaveLedger: (leaveTypeCode) =>
        unwrap(client.get('/time/leave/ledger', { params: { leave_type: leaveTypeCode } })) as never,
    },

    attendance: {
      getAttendance: (month) =>
        unwrap(client.get('/time/attendance', { params: { month } })) as never,
      submitCorrection: (payload) =>
        unwrap(
          client.post('/time/attendance/corrections', payload, {
            headers: { 'Idempotency-Key': crypto.randomUUID() },
          }),
        ) as never,
    },

    payroll: {
      listRuns: () => unwrap(client.get('/payroll/runs')) as never,
      getRun: (id) => unwrap(client.get(`/payroll/runs/${id}`)) as never,
      runAction: (id, action) =>
        unwrap(
          client.post(
            `/payroll/runs/${id}/${actionPath(action)}`,
            {},
            { headers: { 'Idempotency-Key': crypto.randomUUID() } },
          ),
        ) as never,
      listPayslips: () => unwrap(client.get('/payroll/payslips')) as never,
      getPayslip: (id) => unwrap(client.get(`/payroll/payslips/${id}`)) as never,
    },

    admin: {
      // The backend does not expose an aggregated admin snapshot endpoint; the
      // OpenAPI contract has no such path. Compose it from the endpoints that
      // do exist and report the rest as unavailable rather than inventing data.
      // `/me/capabilities` describes the *caller*, so it must never be presented
      // as the tenant capability catalogue.
      getAdminSnapshot: async (tab?: string) => {
        const params = { legal_entity_id: legalEntityId() }
        const headers = { 'X-Legal-Entity-Id': String(legalEntityId()) }

        const [users, roles, capabilities, accessReview] = await Promise.all([
          unwrap(client.get('/users', { params: { ...params, page: 1, page_size: 20 }, headers })).catch(() => null),
          unwrap(client.get('/roles', { params, headers })).catch(() => null),
          unwrap(client.get('/capabilities', { params, headers })).catch(() => null),
          unwrap(client.get('/access-review', { params, headers })).catch(() => null),
        ])

        const audit = tab === 'audit'
          ? await unwrap(client.get('/auth/events', { params: { ...params, page: 1, page_size: 50 }, headers })).catch(() => null)
          : null

        const asItems = <T,>(value: unknown): T[] =>
          Array.isArray(value)
            ? (value as T[])
            : Array.isArray((value as { items?: unknown })?.items)
              ? ((value as { items: T[] }).items)
              : []

        const auditItems = asItems<AdminSnapshot['audit']['items'][number]>(audit)
        const accessReviewData = accessReview as { sod_risks?: unknown[] } | null
        const usersData = users as { page?: number; page_size?: number; total?: number } | null

        return {
          users: {
            items: asItems<AdminSnapshot['users']['items'][number]>(users),
            page: usersData?.page ?? 1,
            page_size: usersData?.page_size ?? 20,
            total: usersData?.total ?? 0,
          },
          roles: asItems<AdminSnapshot['roles'][number]>(roles),
          capabilities: asItems<AdminSnapshot['capabilities'][number]>(capabilities),
          bindings: [],
          accessReview: asItems<AdminSnapshot['accessReview'][number]>(accessReviewData?.sod_risks),
          audit: {
            items: auditItems,
            page: 1,
            page_size: 50,
            total: auditItems.length,
          },
        } as never
      },
      loadUserBindings: async (userId: number) =>
        unwrap(client.get(`/users/${userId}/bindings`, {
          params: { legal_entity_id: legalEntityId() },
          headers: { 'X-Legal-Entity-Id': String(legalEntityId()) },
        })).catch(() => null) as never,
      createBinding: (payload) =>
        unwrap(
          client.post(`/users/${(payload as { userId?: number }).userId ?? 0}/bindings`, payload, {
            headers: { 'Idempotency-Key': crypto.randomUUID() },
          }),
        ) as never,
      reviewAccess: () => unwrap(client.get('/auth/events')) as never,
    },

    notices: {
      listNotices: (filter = 'all') =>
        unwrap(
          client.get('/engagement/notices', {
            params: { filter },
            headers: legalEntityHeaders(),
          }),
        ) as never,
      getNotice: (id) => unwrap(client.get(`/engagement/notices/${id}`)) as never,
      acknowledgeNotice: (id) =>
        unwrap(
          client.post(`/engagement/notices/${id}/acknowledge`, {}, {
            headers: { 'Idempotency-Key': crypto.randomUUID() },
          }),
        ) as never,
      publishNotice: (payload) =>
        unwrap(
          client.post('/engagement/notices', payload, {
            headers: { 'Idempotency-Key': crypto.randomUUID() },
          }),
        ) as never,
      retractNotice: (id) =>
        unwrap(
          client.post(`/engagement/notices/${id}/retract`, {}, {
            headers: { 'Idempotency-Key': crypto.randomUUID() },
          }),
        ) as never,
    },

    dashboard: {
      getDashboard: async () => {
        const data = await unwrap<BackendDashboardData>(
          client.get('/reporting/dashboard', { headers: legalEntityHeaders() }),
        )

        return normaliseDashboard(data)
      },
      getMetricDrilldown: (metric) =>
        unwrap(client.get(`/reporting/dashboard/metrics/${metric}`)) as never,
    },

    reporting: {
      getReportDefinitions: () =>
        unwrap(
          client.get('/reporting/definitions', { headers: legalEntityHeaders() }),
        ) as never,
      runReport: (code, filters) =>
        unwrap(client.post('/reporting/run', { definition_code: code, filters })) as never,
      createExport: (payload) =>
        unwrap(
          client.post<ApiEnvelope<ExportJob>>('/reporting/exports', payload, {
            headers: { 'Idempotency-Key': crypto.randomUUID() },
          }),
        ) as never,
      downloadExport: async (jobId) => {
        try {
          const response = await client.get(`/reporting/exports/${jobId}/download`, {
            responseType: 'blob',
          })
          return response.data as Blob
        } catch (error) {
          translateError(error)
        }
      },
    },
  }
}
