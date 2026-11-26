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
  // Dedicated workspaces and hidden detail pages are resolved directly by the
  // router from PAGE_COMPONENTS; only remaining config-driven shells belong in
  // this legacy PlatformWorkspace adapter.
  if (platformPageConfig(key) === null) continue
  COMPONENTS.set(key, pageComponent(key))
}

export const PLATFORM_COMPONENT_KEYS = [...COMPONENTS.keys()]

export function platformComponentFor(key: string): Component | null {
  return COMPONENTS.get(key) ?? null
}

export { PAGE_COMPONENTS } from '@/modules/platform/pages'
export { default as PlatformWorkspace } from '@/modules/platform/components/PlatformWorkspace.vue'
