import axios, { AxiosError, type AxiosInstance } from 'axios'
import type {
  ApiAdapter,
  ApiEnvelope,
  AuthApi,
  ExportJob,
  LoginRequest,
  SessionBootstrap,
} from '@/api/contracts'
import { ApiError } from '@/api/errors'

/**
 * Session-cookie HTTP adapter. The CSRF token is acquired from the bootstrap
 * endpoint, kept in memory, and attached to every unsafe request. Access tokens
 * never enter localStorage or sessionStorage.
 */
type BootstrapWithCsrf = SessionBootstrap & {
  demo_accounts?: AuthApi['demoAccounts'] extends () => infer R ? R : never
}

function resolveBaseUrl(): string {
  return import.meta.env.VITE_API_BASE_URL ?? '/v1'
}

export function createHttpAdapter(): ApiAdapter {
  const client: AxiosInstance = axios.create({
    baseURL: resolveBaseUrl(),
    withCredentials: true,
    timeout: 20_000,
    headers: { Accept: 'application/json' },
  })

  let csrfToken: string | null = null

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
      data?: { errors?: Record<string, string[]> }
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
        fieldErrors: body?.data?.errors ?? {},
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

  async function bootstrap(): Promise<BootstrapWithCsrf> {
    const response = await unwrap<BootstrapWithCsrf>(
      client.get<ApiEnvelope<BootstrapWithCsrf>>('/auth/session'),
    )
    csrfToken = response.csrfToken
    return response
  }

  const auth: AuthApi = {
    bootstrap,
    async login(payload: LoginRequest) {
      const result = await unwrap<Awaited<ReturnType<AuthApi['login']>>>(
        client.post<ApiEnvelope<Awaited<ReturnType<AuthApi['login']>>>>('/auth/login', payload),
      )
      if (result.status === 'AUTHENTICATED') {
        csrfToken = result.session.csrfToken
      }
      return result
    },
    async verifyTotp(payload) {
      const session = await unwrap<SessionBootstrap>(
        client.post<ApiEnvelope<SessionBootstrap>>('/auth/mfa/verify', payload),
      )
      csrfToken = session.csrfToken
      return session
    },
    async logout() {
      try {
        await client.post('/auth/logout')
      } catch (error) {
        translateError(error)
      } finally {
        csrfToken = null
      }
    },
    enrollMfa: () => unwrap(client.post<ApiEnvelope<Awaited<ReturnType<AuthApi['enrollMfa']>>>>('/auth/mfa/enroll')),
    confirmMfa: (code) =>
      unwrap(client.post<ApiEnvelope<Awaited<ReturnType<AuthApi['confirmMfa']>>>>('/auth/mfa/confirm', { code })),
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
          client.get('/people/employees', {
            params: {
              as_of: asOf,
              page,
              page_size: pageSize,
              search: search || undefined,
              org_id: orgId ?? undefined,
              status: status || undefined,
            },
          }),
        ),
      getEmployee: (id, asOf) => unwrap(client.get(`/people/employees/${id}`, { params: { as_of: asOf } })),
      revealField: (id, field, purpose) =>
        unwrap(
          client.post(`/people/employees/${id}/fields/${field}/reveal`, { purpose }, {
            headers: { 'Idempotency-Key': crypto.randomUUID() },
          }),
        ),
    },
    organization: {
      getOrgTree: (asOf) => unwrap(client.get('/organization/tree', { params: { as_of: asOf } })),
      previewOrgMove: (nodeId, targetId, effectiveDate) =>
        unwrap(
          client.post('/organization/moves/preview', {
            node_id: nodeId,
            target_id: targetId,
            effective_date: effectiveDate,
          }),
        ),
      moveOrg: (nodeId, targetId, effectiveDate) =>
        unwrap(
          client.post(
            '/organization/moves',
            { node_id: nodeId, target_id: targetId, effective_date: effectiveDate },
            { headers: { 'Idempotency-Key': crypto.randomUUID() } },
          ),
        ),
    },
    cases: {
      listCases: ({ scope, status, page, pageSize }) =>
        unwrap(
          client.get('/workflow/cases', {
            params: { scope, status: status === 'ALL' ? undefined : status, page, page_size: pageSize },
          }),
        ),
      getCase: (id) => unwrap(client.get(`/workflow/cases/${id}`)),
      decideCase: (id, request) =>
        unwrap(
          client.post(
            `/workflow/cases/${id}/decisions`,
            { decision: request.action, comment: request.comment },
            { headers: { 'Idempotency-Key': crypto.randomUUID() } },
          ),
        ),
      retryCaseEffect: (id) =>
        unwrap(
          client.post(
            `/workflow/cases/${id}/effects/retry`,
            {},
            { headers: { 'Idempotency-Key': crypto.randomUUID() } },
          ),
        ),
    },
    leave: {
      getLeaveBalances: () => unwrap(client.get('/time/leave/balances')),
      getLeaveRequests: () => unwrap(client.get('/time/leave/requests')),
      previewLeave: (payload) => unwrap(client.post('/time/leave/preview', payload)),
      requestLeave: (payload) =>
        unwrap(
          client.post('/time/leave/requests', payload, {
            headers: { 'Idempotency-Key': crypto.randomUUID() },
          }),
        ),
      cancelLeave: (caseId) =>
        unwrap(
          client.post(
            `/time/leave/requests/${caseId}/cancel`,
            {},
            { headers: { 'Idempotency-Key': crypto.randomUUID() } },
          ),
        ),
      getLeaveLedger: (leaveTypeCode) =>
        unwrap(client.get('/time/leave/ledger', { params: { leave_type: leaveTypeCode } })),
    },
    attendance: {
      getAttendance: (month) => unwrap(client.get('/time/attendance', { params: { month } })),
      submitCorrection: (payload) =>
        unwrap(
          client.post('/time/attendance/corrections', payload, {
            headers: { 'Idempotency-Key': crypto.randomUUID() },
          }),
        ),
    },
    payroll: {
      listRuns: () => unwrap(client.get('/payroll/runs')),
      getRun: (id) => unwrap(client.get(`/payroll/runs/${id}`)),
      runAction: (id, action) =>
        unwrap(
          client.post(
            `/payroll/runs/${id}/actions`,
            { action },
            { headers: { 'Idempotency-Key': crypto.randomUUID() } },
          ),
        ),
      listPayslips: () => unwrap(client.get('/payroll/payslips')),
      getPayslip: (id) => unwrap(client.get(`/payroll/payslips/${id}`)),
    },
    admin: {
      getAdminSnapshot: (tab) => unwrap(client.get('/admin/access', { params: { tab } })),
      createBinding: (payload) =>
        unwrap(
          client.post('/admin/bindings', payload, {
            headers: { 'Idempotency-Key': crypto.randomUUID() },
          }),
        ),
      reviewAccess: (id, action) =>
        unwrap(
          client.post(
            `/admin/access-review/${id}`,
            { action },
            { headers: { 'Idempotency-Key': crypto.randomUUID() } },
          ),
        ),
    },
    notices: {
      listNotices: (filter = 'all') => unwrap(client.get('/engagement/notices', { params: { filter } })),
      getNotice: (id) => unwrap(client.get(`/engagement/notices/${id}`)),
      acknowledgeNotice: (id) =>
        unwrap(
          client.post(
            `/engagement/notices/${id}/acknowledge`,
            {},
            { headers: { 'Idempotency-Key': crypto.randomUUID() } },
          ),
        ),
      publishNotice: (payload) =>
        unwrap(
          client.post('/engagement/notices', payload, {
            headers: { 'Idempotency-Key': crypto.randomUUID() },
          }),
        ),
      retractNotice: (id) =>
        unwrap(
          client.post(
            `/engagement/notices/${id}/retract`,
            {},
            { headers: { 'Idempotency-Key': crypto.randomUUID() } },
          ),
        ),
    },
    dashboard: {
      getDashboard: () => unwrap(client.get('/reporting/dashboard')),
      getMetricDrilldown: (metric) => unwrap(client.get(`/reporting/dashboard/metrics/${metric}`)),
    },
    reporting: {
      getReportDefinitions: () => unwrap(client.get('/reporting/definitions')),
      runReport: (code, filters) => unwrap(client.post('/reporting/run', { definition_code: code, filters })),
      createExport: (payload) =>
        unwrap(
          client.post<ApiEnvelope<ExportJob>>('/reporting/exports', payload, {
            headers: { 'Idempotency-Key': crypto.randomUUID() },
          }),
        ),
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
