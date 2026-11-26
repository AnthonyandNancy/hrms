import { defineAsyncComponent, type Component } from 'vue'
import { PAGE_COMPONENTS } from '@/modules/people/pages'
import { PEOPLE_COMPONENT_KEYS, PEOPLE_PAGE_CONFIGS, peoplePageConfig } from '@/modules/people/catalog'
import type { PeoplePageConfig } from '@/modules/people/types'

/**
 * Compatibility exports for callers that still use the legacy People barrel.
 *
 * Route resolution must use `@/modules/people/pages` directly. This barrel no
 * longer owns a shared workspace factory; each key points at its dedicated
 * module page component.
 */
export { PAGE_COMPONENTS, PEOPLE_COMPONENT_KEYS, PEOPLE_PAGE_CONFIGS, peoplePageConfig }
export type { PeoplePageConfig }

export function peopleComponentFor(key: string): Component | null {
  if (!(key in PAGE_COMPONENTS)) return null
  const loader = PAGE_COMPONENTS[key as keyof typeof PAGE_COMPONENTS]
  return defineAsyncComponent(loader)
}
