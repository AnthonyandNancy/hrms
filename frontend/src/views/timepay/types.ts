export type TimePayrollDomain = 'attendance' | 'leave' | 'overtime' | 'travel' | 'payroll'

export type TimePayrollPanel =
  | 'attendance-my-punch'
  | 'attendance-punches'
  | 'attendance-calendar'
  | 'attendance-rules'
  | 'attendance-shifts'
  | 'attendance-schedules'
  | 'attendance-corrections'
  | 'attendance-exceptions'
  | 'attendance-closing'
  | 'attendance-devices'
  | 'leave-apply'
  | 'leave-requests'
  | 'leave-types'
  | 'leave-policies'
  | 'leave-balances'
  | 'leave-calendar'
  | 'leave-cancellations'
  | 'leave-reports'
  | 'overtime-apply'
  | 'overtime-requests'
  | 'overtime-policies'
  | 'overtime-confirm'
  | 'overtime-timeoff'
  | 'overtime-settlement'
  | 'travel-apply'
  | 'travel-requests'
  | 'travel-policies'
  | 'travel-approval'
  | 'travel-changes'
  | 'travel-reports'
  | 'travel-expenses'
  | 'payroll-overview'
  | 'payroll-components'
  | 'payroll-plans'
  | 'payroll-employee-plans'
  | 'payroll-statutory'
  | 'payroll-inputs'
  | 'payroll-batches'
  | 'payroll-reviews'
  | 'payroll-disbursement'
  | 'payroll-payslips'
  | 'payroll-adjustments'
  | 'payroll-reports'

export type BackendGap = {
  method: 'GET' | 'POST'
  path: string
  reason: string
}

export type RelatedEndpoint = {
  key: string
  endpoint: string
  query?: Record<string, unknown>
}

export type TimePayrollPageDefinition = {
  key: string
  path: string
  panel: TimePayrollPanel
  domain: TimePayrollDomain
  title: string
  description: string
  endpoint: string
  query?: Record<string, unknown>
  related?: RelatedEndpoint[]
  gap?: BackendGap
  testId: string
  emptyTitle?: string
  emptyDescription?: string
  showsEmptyState?: boolean
}
