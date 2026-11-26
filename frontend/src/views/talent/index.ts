import { defineAsyncComponent, type Component } from 'vue'
import { PAGE_COMPONENTS } from '@/modules/talent/pages'
import { TALENT_PAGES, talentPageConfig } from '@/modules/talent/catalog'
import type { TalentPageConfig } from '@/modules/talent/types'

export const TALENT_COMPONENT_KEYS = TALENT_PAGES.map((page) => page.key)
export const TALENT_PAGE_CONFIGS = TALENT_PAGES

const COMPONENTS = new Map<string, Component>()

function pageComponent(config: TalentPageConfig): Component {
  const loader = PAGE_COMPONENTS[config.key as keyof typeof PAGE_COMPONENTS]
  if (loader === undefined) throw new Error(`Missing talent page component for ${config.key}`)
  const cached = COMPONENTS.get(config.key)
  if (cached !== undefined) return cached
  const component = defineAsyncComponent(loader)
  COMPONENTS.set(config.key, component)
  return component
}

export function talentComponentFor(key: string): Component | null {
  const config = talentPageConfig(key)
  return config === null ? null : pageComponent(config)
}
