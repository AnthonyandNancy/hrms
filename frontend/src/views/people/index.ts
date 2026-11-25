import { defineComponent, h, type Component } from 'vue'
import PeopleWorkspace from './PeopleWorkspace.vue'
import { PEOPLE_COMPONENT_KEYS, PEOPLE_PAGE_CONFIGS, peoplePageConfig } from './catalog'
import type { PeoplePageConfig } from './types'

export { PEOPLE_COMPONENT_KEYS, PEOPLE_PAGE_CONFIGS, peoplePageConfig }
export type { PeoplePageConfig }

function pageComponent(config: PeoplePageConfig): Component {
  return defineComponent({
    name: `PeoplePage_${config.key.replaceAll('/', '_').replaceAll('.', '_')}`,
    setup() {
      return () => h(PeopleWorkspace, { componentKey: config.key })
    },
  })
}

const COMPONENTS = new Map<string, Component>()

for (const key of PEOPLE_COMPONENT_KEYS) {
  const config = peoplePageConfig(key)
  if (config === null) throw new Error(`Missing people page config for ${key}`)
  COMPONENTS.set(key, pageComponent(config))
}

export function peopleComponentFor(key: string): Component | null {
  return COMPONENTS.get(key) ?? null
}
