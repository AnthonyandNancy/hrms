/**
 * Transitional bridge for the TimePay page split.
 *
 * The existing definitions and the shared TimePay layout still live under
 * `views/timepay` while the panel/form logic is migrated into
 * `modules/timepay/components`. This is the only module file allowed to touch
 * that legacy view layer; every dedicated page imports from here so the page
 * layer itself remains independent of `@/views`.
 */
// eslint-disable-next-line no-restricted-imports -- transitional Task 11 bridge; pages never import @/views directly.
import TimePayrollPage from '@/views/timepay/TimePayrollPage.vue'
// eslint-disable-next-line no-restricted-imports -- transitional Task 11 bridge; panels/form logic is still being moved.
import {
  TIME_PAYROLL_DEFINITIONS,
  definitionFor,
} from '@/views/timepay/definitions'
// eslint-disable-next-line no-restricted-imports -- transitional Task 11 bridge; shared definition types stay single-source.
import type { TimePayrollPageDefinition } from '@/views/timepay/types'

export { TimePayrollPage, TIME_PAYROLL_DEFINITIONS, definitionFor }
export type { TimePayrollPageDefinition }

export function timePayDefinition(key: string): TimePayrollPageDefinition {
  const definition = definitionFor(key)
  if (definition === null) {
    throw new Error(`Unknown TimePay page definition: ${key}`)
  }
  return definition
}
