import { defineComponent, h, type Component } from 'vue'
import Workspace from '@/modules/platform/components/PlatformWorkspace.vue'
import { PAGE_COMPONENTS } from '@/modules/platform/pages'
import { platformPageConfig } from '@/modules/platform/catalog'

function pageComponent(key: string): Component {
  return defineComponent({
    name: `PlatformPage_${key.replaceAll('/', '_')}`,
    setup() {
      const config = platformPageConfig(key)
      if (config === null) throw new Error(`Missing platform page config for ${key}`)
      return () => h(Workspace, { config })
    },
  })
}

const COMPONENTS = new Map<string, Component>()

for (const key of Object.keys(PAGE_COMPONENTS)) {
  if (platformPageConfig(key) === null) {
    throw new Error(`Missing platform page config for ${key}`)
  }
  COMPONENTS.set(key, pageComponent(key))
}

export const PLATFORM_COMPONENT_KEYS = [...COMPONENTS.keys()]

export function platformComponentFor(key: string): Component | null {
  return COMPONENTS.get(key) ?? null
}

export { PAGE_COMPONENTS } from '@/modules/platform/pages'
export { default as PlatformWorkspace } from '@/modules/platform/components/PlatformWorkspace.vue'
