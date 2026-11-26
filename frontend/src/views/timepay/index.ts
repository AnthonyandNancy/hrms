/**
 * TimePay compatibility exports.
 *
 * Route targets are owned by `modules/timepay/pages`; this barrel keeps the
 * existing definition/API metadata available to tests and callers while the
 * TimePay workspace is split into dedicated page components.
 */
export { PAGE_COMPONENTS as TIME_PAY_COMPONENTS } from '@/modules/timepay/pages'
export { TIME_PAYROLL_API_PATHS, TIME_PAYROLL_DEFINITIONS, definitionFor } from './definitions'
export type { TimePayrollPageDefinition } from './types'
