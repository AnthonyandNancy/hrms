/**
 * Frozen Task 8A frontend contract.
 *
 * This file mirrors the conventions and domain vocabulary in
 * .superpowers/sdd/artifacts/interface-freeze.md. The backend OpenAPI file is
 * not present in this worktree yet, so this typed port is the adapter boundary.
 * The HTTP adapter and the mock adapter both implement the same ApiAdapter,
 * therefore pages never depend on mock data or transport details.
 */

export type ApiEnvelope<T> = {
  code: string
  message: string
  data: T
  request_id: string
}

export type PageMeta = {
  page: number
  page_size: number
  total: number
}

export type Paged<T> = {
  items: T[]
  page: number
  page_size: number
  total: number
}

/**
 * Stable error codes decoded from the single API envelope. Business-specific
 * codes still carry their envelope code, so callers can give actionable copy.
 */
export type ApiErrorCode =
  | 'UNAUTHENTICATED'
  | 'FORBIDDEN'
  | 'NOT_FOUND'
  | 'METHOD_NOT_ALLOWED'
  | 'CONFLICT'
  | 'NO_ACTIVE_EMPLOYMENT'
  | 'VALIDATION_FAILED'
  | 'RATE_LIMITED'
  | 'INTERNAL'
  | 'SERVICE_UNAVAILABLE'
  | 'NETWORK_ERROR'

export type FieldProfile = 'P0_PUBLIC' | 'P1_INTERNAL' | 'P2_SENSITIVE' | 'P3_RESTRICTED'

/** Server-projected field. P2/P3 values are already masked by the API. */
export type MaskedFieldValue = {
  display: string | null
  masked: boolean
  profile: FieldProfile
  revealable: boolean
  exportable: boolean
}

export type Principal = {
  userId: number
  employmentId: number | null
  displayName: string
  companyName?: string
  email: string
  employmentNo: string
  jobTitle: string
  orgName: string
  initials: string
  isPrivileged: boolean
  hasMfa: boolean
  authVersion: number
  tenantTimezone: string
}

export type SessionBootstrap = {
  authenticated: boolean
  principal: Principal | null
  capabilities: string[]
  csrfToken: string | null
  sessionExpiresAt: string | null
  mfaEnrollmentRequired: boolean
}

export type LoginRequest = {
  /** Backend login name: a username or an email address. */
  username: string
  password: string
}

export type LoginResult =
  | { status: 'MFA_REQUIRED'; challengeId: string; maskedDestination: string }
  | { status: 'AUTHENTICATED'; session: SessionBootstrap }

export type TotpRequest = {
  challengeId: string
  code: string
}

export type MfaEnrollment = {
  secretPreview: string
  otpauthUri: string
  recoveryCodes: string[]
}

export type DemoAccount = {
  role: 'admin' | 'hr' | 'employee' | 'mfa'
  label: string
  username: string
  password: string
  note: string
}

export type EmployeeStatus = 'ACTIVE' | 'PROBATION' | 'ON_LEAVE' | 'TERMINATED'

export type DirectoryEmployee = {
  id: number
  employeeNo: string
  name: string
  initials: string
  orgId: number
  orgName: string
  positionName: string
  status: EmployeeStatus
  employmentType: string
  hireDate: string
  phone: MaskedFieldValue
  email: MaskedFieldValue
  idNumber: MaskedFieldValue
}

export type EmployeeContractSummary = {
  contractNo: string
  type: string
  startDate: string
  endDate: string | null
  daysToExpiry: number | null
  status: 'ACTIVE' | 'EXPIRING' | 'RENEWAL_PENDING'
}

export type EmployeeAssignment = {
  id: number
  orgName: string
  positionName: string
  managerName: string
  validFrom: string
  validTo: string | null
}

export type EmployeeDetail = DirectoryEmployee & {
  managerName: string
  workLocation: string
  personalEmail: MaskedFieldValue
  address: MaskedFieldValue
  bankAccount: MaskedFieldValue
  assignments: EmployeeAssignment[]
  contract: EmployeeContractSummary
  timeline: TimelineEvent[]
  fieldAccessNote: string
}

export type DirectoryQuery = {
  search?: string
  orgId?: number | null
  status?: EmployeeStatus | ''
  asOf: string
  page: number
  pageSize: number
}

export type OrgNode = {
  id: number
  parentId: number | null
  code: string
  name: string
  type: 'COMPANY' | 'DIVISION' | 'DEPARTMENT' | 'TEAM' | 'COST_CENTER' | 'SHARED_SERVICE'
  managerName: string | null
  headcount: number | null
  directHeadcount: number | null
  children: OrgNode[]
}

/**
 * The server reports only the impact it can actually compute. Display names
 * and the effective date are supplied by the caller from its own tree, so the
 * adapter never invents them.
 */
export type MoveImpactFacts = {
  affectedEmployees: number
  affectedManagers: number
  affectedPayrollCostCenters: number
  crossEntityWarning: boolean
}

export type MoveImpact = MoveImpactFacts & {
  sourceName: string
  targetName: string
  effectiveDate: string
}

export type MoveOrgResult = {
  moved: boolean
  oldOrgUnitId: number
}

export type TimelineLane = 'APPLICATION' | 'EFFECT' | 'SYSTEM'

export type TimelineEvent = {
  id: string
  lane: TimelineLane
  title: string
  detail: string | null
  actor: string | null
  occurredAt: string
  status: string | null
}

export type ApplicationStatus =
  | 'DRAFT'
  | 'SUBMITTED'
  | 'IN_APPROVAL'
  | 'APPROVED'
  | 'REJECTED'
  | 'WITHDRAWN'

export type EffectStatus = 'SCHEDULED' | 'APPLIED' | 'FAILED' | 'REVERSED'

export type CaseAction = 'APPROVE' | 'REJECT' | 'RETURN' | 'RETRY_EFFECT'

export type CaseSummary = {
  id: number
  caseNo: string
  title: string
  type: string
  applicantName: string
  applicantEmploymentNo: string
  applicantOrgName: string
  appliedAt: string
  applicationStatus: ApplicationStatus
  effectStatus: EffectStatus | null
  currentNode: string | null
  dueAt: string | null
  isMine: boolean
  isAwaitingMe: boolean
}

export type CaseFact = {
  label: string
  value: string
}

export type CaseDetail = CaseSummary & {
  description: string
  facts: CaseFact[]
  applicant: string
  approver: string | null
  effectSummary: string
  effectScheduledAt: string | null
  effectFailedReason: string | null
  timeline: TimelineEvent[]
  availableActions: CaseAction[]
}

export type CaseQuery = {
  scope: 'mine' | 'approvals' | 'all'
  status?: ApplicationStatus | 'ALL'
  page: number
  pageSize: number
}

export type DecisionRequest = {
  action: Exclude<CaseAction, 'RETRY_EFFECT'>
  comment: string
}

export type LeaveBalance = {
  leaveTypeCode: string
  leaveTypeName: string
  unit: 'DAY' | 'HOUR'
  granted: number
  used: number
  pending: number
  available: number
  expiresOn: string | null
}

export type LeaveLedgerEntry = {
  id: string
  occurredAt: string
  businessDate: string
  entryType: 'GRANT' | 'RESERVE' | 'CONSUME' | 'RELEASE' | 'EXPIRE' | 'ADJUST' | 'REVERSE'
  quantity: number
  balanceAfter: number
  reason: string
  sourceCaseNo: string | null
}

export type LeaveLedger = {
  leaveTypeName: string
  unit: 'DAY' | 'HOUR'
  opening: number
  closing: number
  entries: LeaveLedgerEntry[]
}

export type LeaveConflict = {
  date: string
  label: string
  severity: 'BLOCKING' | 'WARNING'
}

export type LeavePreview = {
  workingDays: number
  requestedDays: number
  availableBefore: number
  availableAfter: number
  unit: 'DAY' | 'HOUR'
  conflicts: LeaveConflict[]
  note: string
}

export type LeaveRequestPayload = {
  leaveTypeCode: string
  startDate: string
  endDate: string
  halfDay: boolean
  reason: string
}

export type LeaveRequestResult = {
  caseId: number
  caseNo: string
  applicationStatus: ApplicationStatus
  effectStatus: EffectStatus | null
}

export type AttendanceStatus =
  | 'PRESENT'
  | 'LATE'
  | 'ABSENT'
  | 'LEAVE'
  | 'REMOTE'
  | 'REST'
  | 'HOLIDAY'

export type AttendanceDay = {
  date: string
  weekday: string
  status: AttendanceStatus
  scheduledStart: string | null
  scheduledEnd: string | null
  firstIn: string | null
  lastOut: string | null
  workedMinutes: number
  exceptionLabel: string | null
  scheduledMinutes: number
}

export type AttendanceMonth = {
  month: string
  timezone: string
  employeeName: string
  days: AttendanceDay[]
  summary: {
    expectedDays: number
    presentDays: number
    lateCount: number
    absentCount: number
    leaveDays: number
    remoteDays: number
  }
  exceptions: AttendanceDay[]
}

export type AttendanceCorrectionPayload = {
  date: string
  correctionType: 'MISSING_PUNCH' | 'WRONG_TIME' | 'WRONG_STATUS'
  firstIn: string
  lastOut: string
  reason: string
}

export type PayrollRunState =
  | 'DRAFT'
  | 'INPUT_LOCKED'
  | 'CALCULATED'
  | 'REVIEWED'
  | 'APPROVED'
  | 'PUBLISHED'
  | 'PAID'
  | 'CLOSED'

export type PayrollRunSummary = {
  id: number
  runNo: string
  period: string
  state: PayrollRunState
  employeeCount: number
  grossCents: number
  netCents: number
  payDate: string
  updatedAt: string
  isCorrection: boolean
  freshAt: string
}

export type PayrollLine = {
  employmentId: number
  employeeName: string
  employeeNo: string
  orgName: string
  grossCents: number
  deductionsCents: number
  netCents: number
  varianceCents: number
  status: 'OK' | 'REVIEW' | 'FAILED'
}

export type PayrollStateEvent = {
  state: PayrollRunState
  occurredAt: string
  actor: string
  note: string | null
}

export type ReconciliationSummary = {
  sourceHeadcount: number
  calculatedHeadcount: number
  grossVarianceCents: number
  netVarianceCents: number
  blockedIssues: number
  note: string
}

export type PayrollRunDetail = PayrollRunSummary & {
  inputLockedAt: string | null
  calculatedAt: string | null
  approvedAt: string | null
  publishedAt: string | null
  /**
   * Null means the run has no reconciliation projection yet, so the view hides
   * the panel instead of rendering an empty object.
   */
  reconciliation: ReconciliationSummary | null
  lines: PayrollLine[]
  history: PayrollStateEvent[]
  availableActions: Array<'LOCK_INPUT' | 'CALCULATE' | 'REVIEW' | 'APPROVE' | 'PUBLISH' | 'CREATE_CORRECTION'>
}

export type Payslip = {
  id: number
  runId: number
  period: string
  employeeName: string
  employeeNo: string
  orgName: string
  payDate: string
  grossCents: number
  earningLines: Array<{ label: string; cents: number }>
  deductionLines: Array<{ label: string; cents: number }>
  netCents: number
  employerCostCents: number
  state: 'PUBLISHED' | 'PAID' | 'CORRECTION'
  note: string | null
}

export type AdminUser = {
  id: number
  name: string
  email: string
  employmentNo: string
  orgName: string
  roles: string[]
  status: 'ACTIVE' | 'SUSPENDED' | 'LOCKED'
  mfaEnabled: boolean
  lastLoginAt: string | null
}

export type AdminRole = {
  /** Roles are keyed by code; the table has no numeric primary key. */
  id: string
  code: string
  name: string
  template: boolean
  memberCount: number
  capabilityCount: number
  riskLevel: 'LOW' | 'MEDIUM' | 'HIGH'
  owner: string | null
  purpose: string
  expiresOn: string | null
}

export type CapabilityDefinition = {
  code: string
  name: string
  description: string
  anchorTypes: string[]
  maxFieldProfile: FieldProfile
  riskLevel: 'LOW' | 'MEDIUM' | 'HIGH'
  separationRule: string | null
}

export type AuthorityBinding = {
  id: number
  principalName: string
  principalEmploymentNo: string
  capabilityCode: string
  capabilityName: string
  anchorType: string
  anchorName: string
  fieldProfiles: FieldProfile[]
  purpose: string
  validFrom: string
  expiresOn: string | null
  status: 'ACTIVE' | 'EXPIRING' | 'REVOKED' | 'EXPIRED'
  riskLevel: 'LOW' | 'MEDIUM' | 'HIGH'
}

export type AccessReviewItem = {
  id: number
  principalName: string
  employmentNo: string
  roleName: string
  scope: string
  riskLevel: 'LOW' | 'MEDIUM' | 'HIGH'
  reason: string
  lastUsedAt: string | null
  dueOn: string
  status: 'PENDING' | 'KEEP' | 'REVOKE' | 'REVOKED'
}

export type AuditEventItem = {
  id: string
  occurredAt: string
  actorName: string
  action: string
  target: string
  result: 'SUCCESS' | 'DENIED'
  purpose: string | null
  requestId: string
  ipAddress: string
}

export type AuditQuery = {
  search?: string
  result?: 'ALL' | 'SUCCESS' | 'DENIED'
  action?: string
  page: number
  pageSize: number
}

export type NoticeStatus = 'DRAFT' | 'PUBLISHED' | 'RETRACTED'

export type Notice = {
  id: number
  title: string
  summary: string
  body: string[]
  status: NoticeStatus
  audienceLabel: string
  publishedAt: string | null
  retractedAt: string | null
  authorName: string
  requiresAck: boolean
  acknowledged: boolean
  acknowledgementCount: number
  audienceCount: number
}

export type PublishNoticePayload = {
  title: string
  body: string
  audience: 'ALL' | 'ORG' | 'ROLE'
  audienceLabel: string
  /** Required when audience is ORG; the server rejects an empty list. */
  orgUnitIds?: number[]
  /** Required when audience is ROLE; the server rejects an empty list. */
  roleCodes?: string[]
  publishAt: string
  requiresAck: boolean
}

export type DashboardMetricCode =
  | 'headcount'
  | 'probation'
  | 'turnover'
  | 'pending_approvals'
  | 'contract_expiring'
  | 'recruitment_funnel'

export type DashboardMetric = {
  code: DashboardMetricCode
  label: string
  value: number
  unit: string
  status: 'AVAILABLE' | 'UNAVAILABLE'
  reason: string | null
  trend: string | null
  trendDirection: 'UP' | 'DOWN' | 'FLAT'
  drilldownLabel: string
  sourceHash: string
  computedAt: string
}

export type DashboardPendingApproval = {
  id: number
  caseNo: string
  title: string
  status: string
  ageDays: number
  dueAt: string | null
}

export type DashboardNotice = {
  id: number
  title: string
  publishedAt: string | null
  requiresAck: boolean
  acknowledged: boolean
  authorName: string | null
}

export type DashboardContractAlert = {
  employmentId: number
  employeeName: string
  employeeNo: string
  orgName: string
  endDate: string
  daysRemaining: number
}

export type DashboardData = {
  asOf: string
  freshness: string
  greeting: string
  metrics: DashboardMetric[]
  pendingApprovals: DashboardPendingApproval[]
  recentNotices: DashboardNotice[]
  contractAlerts: DashboardContractAlert[]
}

export type BackendDashboardData = DashboardData

export type DrilldownRow = {
  id: string
  primary: string
  secondary: string
  status: string
  detail: string
  link: string | null
}

export type MetricDrilldown = {
  metric: DashboardMetricCode
  label: string
  predicate: string
  asOf: string
  total: number
  columns: Array<{ key: string; label: string }>
  rows: DrilldownRow[]
}

export type ReportDefinition = {
  id: number
  code: string
  name: string
  description: string
  asOfSupported: boolean
  drilldownRoute: string | null
  fields: Array<{ key: string; label: string; profile: FieldProfile; exportable: boolean }>
  filters: Array<{
    key: string
    label: string
    type: 'ORG' | 'DATE_RANGE' | 'SELECT'
    options?: string[]
  }>
}

export type ReportResult = {
  definitionCode: string
  title: string
  subtitle: string
  columns: Array<{ key: string; label: string; profile: FieldProfile }>
  rows: Array<Record<string, string | number | null>>
  chart: Array<{ label: string; value: number }>
  totalRows: number
  generatedAt: string
  watermark: string
}

export type ExportPurpose =
  | 'BUSINESS_OPERATION'
  | 'AUDIT'
  | 'COMPLIANCE'
  | 'PAYROLL_RECONCILIATION'

export type ExportRequest = {
  reportCode: string
  purpose: ExportPurpose
  fields: string[]
  filters: Record<string, string>
}

export type ExportJob = {
  id: string
  reportCode: string
  reportName: string
  status: 'QUEUED' | 'RUNNING' | 'COMPLETED' | 'FAILED'
  purpose: ExportPurpose
  rowCount: number
  createdAt: string
  expiresAt: string | null
  downloadCount: number
  maxDownloads: number
  watermark: string
  fileName: string
}

export type PermissionPage = {
  page_code: string
  path: string
  menu_group?: string
  menu_label?: string
  required_capability?: string
}

export type PermissionsProjection = {
  pages: PermissionPage[]
  menus: unknown[]
  actions: string[]
  field_capabilities: string[]
  scope_labels: Array<{ anchor_type: string; label: string }>
  auth_version: number
  wildcard: boolean
}

export type AuthApi = {
  bootstrap(): Promise<SessionBootstrap>
  permissions(): Promise<PermissionsProjection>
  login(payload: LoginRequest): Promise<LoginResult>
  verifyTotp(payload: TotpRequest): Promise<SessionBootstrap>
  logout(): Promise<void>
  enrollMfa(): Promise<MfaEnrollment>
  confirmMfa(code: string): Promise<{ enabled: boolean }>
  demoAccounts(): DemoAccount[]
}

export type WorkspaceContractSummary = {
  contractNo: string
  type: string
  startDate: string
  endDate: string | null
  daysToExpiry: number | null
  status: 'ACTIVE' | 'EXPIRING' | 'RENEWAL_PENDING'
}

export type WorkspacePayslip = {
  id: number
  period: string
  payDate: string | null
  netCents: number
  state: 'PUBLISHED' | 'PAID' | 'CORRECTION'
}

export type WorkspaceRequest = {
  case_id: number
  leave_type_id: number
  from_date: string
  to_date: string
  minutes: number
  state: ApplicationStatus
}

export type WorkspaceNotice = {
  id: number
  title: string
  publishedAt: string | null
  requiresAck: boolean
  acknowledged: boolean
  authorName: string | null
}

export type WorkspaceAttendanceSummary = {
  expectedDays: number
  presentDays: number
  lateCount: number
  absentCount: number
  leaveDays: number
  remoteDays: number
}

export type WorkspaceProjection = {
  profile: {
    employmentId: number | null
    name: string
    initials: string
    employeeNo: string
    jobTitle: string | null
    orgName: string | null
    managerName: string | null
    workLocation: string | null
    email: string | null
    phone: MaskedFieldValue
  }
  contract: WorkspaceContractSummary | null
  businessDate: string
  attendanceSummary: WorkspaceAttendanceSummary
  attendanceMonth: string
  attendanceException: { date: string; label: string } | null
  leaveBalances: LeaveBalance[]
  myRequests: WorkspaceRequest[]
  payslips: WorkspacePayslip[]
  notices: WorkspaceNotice[]
  freshness: string
}

export type AdminSnapshot = {
  users: Paged<AdminUser>
  roles: AdminRole[]
  capabilities: CapabilityDefinition[]
  bindings: AuthorityBinding[]
  accessReview: AccessReviewItem[]
  audit: Paged<AuditEventItem>
}

export type EmployeeApi = {
  listEmployees(query: DirectoryQuery): Promise<Paged<DirectoryEmployee>>
  getEmployee(id: number, asOf: string): Promise<EmployeeDetail>
  /** Guarded, purpose-bound reveal; the server records an audit event. */
  revealField(id: number, field: string, purpose: string): Promise<MaskedFieldValue>
}

export type OrganizationApi = {
  getOrgTree(asOf: string): Promise<OrgNode[]>
  previewOrgMove(nodeId: number, targetId: number, effectiveDate: string): Promise<MoveImpactFacts>
  moveOrg(nodeId: number, targetId: number, effectiveDate: string): Promise<MoveOrgResult>
}

export type CaseApi = {
  listCases(query: CaseQuery): Promise<Paged<CaseSummary>>
  getCase(id: number): Promise<CaseDetail>
  decideCase(id: number, request: DecisionRequest): Promise<CaseDetail>
  retryCaseEffect(id: number): Promise<CaseDetail>
}

export type LeaveApi = {
  getLeaveBalances(): Promise<LeaveBalance[]>
  getLeaveRequests(): Promise<CaseSummary[]>
  previewLeave(payload: LeaveRequestPayload): Promise<LeavePreview>
  requestLeave(payload: LeaveRequestPayload): Promise<LeaveRequestResult>
  cancelLeave(caseId: number): Promise<CaseSummary>
  getLeaveLedger(leaveTypeCode: string): Promise<LeaveLedger>
}

export type AttendanceApi = {
  getAttendance(month: string): Promise<AttendanceMonth>
  submitCorrection(payload: AttendanceCorrectionPayload): Promise<{ accepted: boolean; requestNo: string }>
}

export type PayrollApi = {
  listRuns(): Promise<PayrollRunSummary[]>
  getRun(id: number): Promise<PayrollRunDetail>
  runAction(id: number, action: PayrollRunDetail['availableActions'][number]): Promise<PayrollRunDetail>
  listPayslips(): Promise<Payslip[]>
  getPayslip(id: number): Promise<Payslip>
}

export type AdminApi = {
  getAdminSnapshot(tab?: string): Promise<AdminSnapshot>
  loadUserBindings(userId: number): Promise<unknown>
  createBinding(payload: Omit<AuthorityBinding, 'id' | 'status' | 'riskLevel'>): Promise<AuthorityBinding>
}

export type NoticeApi = {
  listNotices(filter?: 'all' | 'unread' | 'managed'): Promise<Notice[]>
  getNotice(id: number): Promise<Notice>
  acknowledgeNotice(id: number): Promise<Notice>
  publishNotice(payload: PublishNoticePayload): Promise<Notice>
  retractNotice(id: number): Promise<Notice>
}

export type DashboardApi = {
  getDashboard(): Promise<DashboardData>
  getMetricDrilldown(metric: DashboardMetricCode): Promise<MetricDrilldown>
}

export type ReportingApi = {
  getReportDefinitions(): Promise<ReportDefinition[]>
  runReport(code: string, filters: Record<string, string>): Promise<ReportResult>
  createExport(payload: ExportRequest): Promise<ExportJob>
  downloadExport(jobId: string): Promise<Blob>
}

export type ApiAdapter = {
  auth: AuthApi
  employees: EmployeeApi
  organization: OrganizationApi
  cases: CaseApi
  leave: LeaveApi
  attendance: AttendanceApi
  payroll: PayrollApi
  admin: AdminApi
  notices: NoticeApi
  dashboard: DashboardApi
  reporting: ReportingApi
  workspace: {
    get(): Promise<WorkspaceProjection>
  }
}
