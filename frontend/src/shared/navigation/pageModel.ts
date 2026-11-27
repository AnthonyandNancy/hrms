// Keep this module dependency-free at runtime. PageSpec is declared here and
// re-exported from @/router/registry; importing the registry type would create
// a cycle once registry imports `kind` from this module.
export type PageKind = 'workspace' | 'detail' | 'governance' | 'base'
export type PageMenu = { group: string; label: string; order: number; icon: string } | null
export type PageState = 'loading' | 'empty' | 'error' | 'forbidden' | 'partial'
export type PageActionSpec = {
  key: string
  label: string
  capability: string
  api: string
  ui: 'primary' | 'secondary' | 'danger' | 'hidden'
  confirm?: 'none' | 'danger' | 'sod'
}

export type PageLayer = 'business' | 'platform' | 'base'
export type PageWave = 'base' | 'Wave 2' | 'Wave 3' | 'Wave 4' | 'Wave 5'
export type ClusterCode = 'K1' | 'K2' | 'K3' | 'K4' | 'K5' | 'K6' | 'K7' | 'K8' | 'K9' | 'K10' | 'K11' | 'K12'

export type PageSpec = {
  path: string
  name: string
  componentKey: string
  capability: string
  menu: PageMenu
  kind: PageKind
  primaryObject?: string
  detailOf?: string
  layer: PageLayer
  wave: PageWave
  requiredApis: string[]
  actions: PageActionSpec[]
  states: PageState[]
  e2e: string[]
  negativeTests: Array<'unauthenticated' | 'no_capability' | 'cross_anchor' | 'field_or_export'>
  cluster?: ClusterCode
}

const ACTION_LIKE = /\/(create|new|edit|editor|archive|restore|import|export|approval|renewal)(\/|$)/

export function validatePageModel(pages: PageSpec[]): { ok: boolean; errors: string[] } {
  const errors: string[] = []
  const byName = new Map(pages.map((page) => [page.name, page]))

  for (const page of pages) {
    if (page.kind !== 'detail' && ACTION_LIKE.test(page.path)) {
      errors.push(`${page.name}: action-like path must be an action, not a page`)
    }
    if (page.kind === 'detail') {
      if (page.menu !== null) errors.push(`${page.name}: detail page must have menu: null`)
      if (page.detailOf === undefined) {
        errors.push(`${page.name}: detail page requires detailOf`)
      } else if (!byName.has(page.detailOf)) {
        errors.push(`${page.name}: detailOf ${page.detailOf} is unknown`)
      }
    }
    if (page.kind === 'workspace' && page.menu === null) {
      errors.push(`${page.name}: workspace requires a menu entry`)
    }
    if (page.kind === 'base' && page.menu !== null) {
      errors.push(`${page.name}: base page must have menu: null`)
    }
  }

  return { ok: errors.length === 0, errors }
}
