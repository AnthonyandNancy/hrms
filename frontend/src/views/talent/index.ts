import { defineComponent, h, type Component } from 'vue'
import TalentWorkspacePage from './TalentWorkspacePage.vue'
import { TALENT_PAGES, talentPageConfig } from './catalog'
import type { TalentPageConfig } from './types'

function pageComponent(config: TalentPageConfig): Component {
  return defineComponent({
    name: `TalentPage_${config.key.replaceAll('/', '_')}`,
    setup() {
      return () => h(TalentWorkspacePage, { componentKey: config.key })
    },
  })
}

const COMPONENTS = new Map<string, Component>()

for (const key of [
  'performance/index',
  'performance/templates',
  'performance/indicators',
  'performance/goals',
  'performance/reviews',
  'performance/feedback',
  'performance/calibration',
  'performance/results',
  'performance/appeals',
  'performance/reports',
  'recruitment/requests/index',
  'recruitment/requests/create',
  'recruitment/requests/approval',
  'recruitment/requests/openings',
  'recruitment/requests/board',
  'recruitment/requests/budget',
  'recruitment/requests/reports',
  'recruitment/candidates/index',
  'recruitment/candidates/by-id',
  'recruitment/candidates/imports',
  'recruitment/candidates/pipeline',
  'recruitment/candidates/interviews',
  'recruitment/candidates/evaluations',
  'recruitment/candidates/offers',
  'recruitment/candidates/communications',
  'recruitment/candidates/talent-pool',
  'recruitment/candidates/convert',
  'training/index',
  'training/courses',
  'training/sessions',
  'training/enrollments',
  'training/checkins',
  'training/exams',
  'training/feedback',
  'training/records',
  'training/reports',
] as const) {
  const config = talentPageConfig(key)
  if (config === null) throw new Error(`Missing talent page config for ${key}`)
  COMPONENTS.set(key, pageComponent(config))
}

export const TALENT_COMPONENT_KEYS = [...COMPONENTS.keys()]
export const TALENT_PAGE_CONFIGS = TALENT_PAGES

export function talentComponentFor(key: string): Component | null {
  return COMPONENTS.get(key) ?? null
}
