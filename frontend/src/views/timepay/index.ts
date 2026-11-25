import { defineComponent, h, type Component } from 'vue'
import TimePayrollPage from './TimePayrollPage.vue'
import { TIME_PAYROLL_DEFINITIONS, definitionFor } from './definitions'
import type { TimePayrollPageDefinition } from './types'

const CACHE = new Map<string, Component>()

export function componentForDefinition(definition: TimePayrollPageDefinition): Component {
  const existing = CACHE.get(definition.key)
  if (existing !== undefined) return existing

  const component = defineComponent({
    name: `TimePayrollPage_${definition.key.replace(/[^a-zA-Z0-9]+/g, '_')}`,
    setup() {
      return () => h(TimePayrollPage, { definition })
    },
  })
  CACHE.set(definition.key, component)
  return component
}

/** Registry key -> time/payroll workspace page. */
export function timePayrollComponentFor(key: string): Component | null {
  const definition = definitionFor(key)
  return definition === null ? null : componentForDefinition(definition)
}

export const TIME_PAYROLL_COMPONENT_KEYS: string[] = TIME_PAYROLL_DEFINITIONS.map(
  (definition) => definition.key,
)

export { TIME_PAYROLL_API_PATHS, TIME_PAYROLL_DEFINITIONS, definitionFor } from './definitions'
export type { TimePayrollPageDefinition } from './types'
