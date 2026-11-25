import { defineComponent, h, type Component } from 'vue'
import PlatformWorkspace from './PlatformWorkspace.vue'
import { PLATFORM_PAGE_CONFIGS, platformPageConfig } from './catalog'

function pageComponent(key: string): Component {
  return defineComponent({
    name: `PlatformPage_${key.replaceAll('/', '_')}`,
    setup() {
      return () => h(PlatformWorkspace, { componentKey: key })
    },
  })
}

const COMPONENTS = new Map<string, Component>()

for (const page of PLATFORM_PAGE_CONFIGS) {
  if (platformPageConfig(page.key) === null) {
    throw new Error(`Missing platform page config for ${page.key}`)
  }
  COMPONENTS.set(page.key, pageComponent(page.key))
}

export const PLATFORM_COMPONENT_KEYS = [...COMPONENTS.keys()]

export function platformComponentFor(key: string): Component | null {
  return COMPONENTS.get(key) ?? null
}

export { PlatformWorkspace }
