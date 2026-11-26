#!/usr/bin/env node
/**
 * V1.1 route/page coverage audit.
 *
 * Checks the registry against the machine-generated inventory and the real
 * component tree. A page listed in the inventory but missing from the
 * registry fails; a registry entry with no component file is reported as
 * missing and fails unless --allow-missing is explicitly passed for Wave 1
 * scaffolding (never for release verification).
 */
import { spawnSync } from 'node:child_process'
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const here = dirname(fileURLToPath(import.meta.url))
const root = resolve(here, '..')
const registryPath = join(root, 'frontend/src/router/registry.ts')
const inventoryPath = join(root, 'docs/audit/2026-11-25-v11-page-inventory.md')
const allowMissing = process.argv.includes('--allow-missing')
const requireCapabilities = process.argv.includes('--require-capabilities')

const registry = readFileSync(registryPath, 'utf8')
const inventory = readFileSync(inventoryPath, 'utf8')

const inventoryRows = [...inventory.matchAll(/^\|\s*(\d+)\s*\|\s*([^|]+?)\s*\|\s*([^|]+?)\s*\|\s*`(\/[^`]+)`\s*\|/gm)]
const inventoryPages = inventoryRows.map((m) => ({
  number: Number(m[1]),
  menu: m[2].trim(),
  name: m[3].trim(),
  path: m[4].trim(),
}))

const inventoryModules = [...inventory.matchAll(/^\|\s*(03B\.\d+)\s*\|\s*([^|]+?)\s*\|\s*`(\/[^`]+)`\s*\|/gm)]
  .filter((m) => !['03B.15', '03B.16'].includes(m[1]))
  .map((m) => ({ code: m[1], name: m[2].trim(), path: m[3].trim() }))

const registryPaths = [...registry.matchAll(/path:\s*'([^']+)'/g)].map((m) => m[1])
const registryNames = [...registry.matchAll(/name:\s*'([^']+)'/g)].map((m) => m[1])
const requiredApiCount = (registry.match(/requiredApis:/g) ?? []).length
const statesCount = (registry.match(/states:\s*\[\.\.\.REQUIRED_STATES\]/g) ?? []).length
const negativeCount = (registry.match(/negativeTests:\s*\[\.\.\.REQUIRED_NEGATIVE_TESTS\]/g) ?? []).length

/**
 * A registry page is renderable when it has either a mature business view in
 * pageComponents.ts or the shared ResourcePage fallback. The audit separately
 * reports mature coverage so fallback pages are never counted as domain-complete.
 */
function matureViewExists(componentKey) {
  const router = readFileSync(join(root, 'frontend/src/router/pageComponents.ts'), 'utf8')
  if (router.includes(`'${componentKey}':`)) return true

  // Module page manifests are the canonical one-file-per-page surface. The
  // loader target must exist on disk; merely naming a key is not enough.
  const modules = [
    { prefixes: ['hr/'], manifest: 'frontend/src/modules/people/pages/index.ts', pages: 'frontend/src/modules/people/pages' },
    { prefixes: ['attendance/', 'leave/', 'overtime/', 'travel/', 'payroll/'], manifest: 'frontend/src/modules/timepay/pages/index.ts', pages: 'frontend/src/modules/timepay/pages' },
    { prefixes: ['performance/', 'recruitment/', 'training/'], manifest: 'frontend/src/modules/talent/pages/index.ts', pages: 'frontend/src/modules/talent/pages' },
    { prefixes: ['dashboard/', 'reports/', 'notices/', 'account', 'system/', 'workflow', 'self-service', 'benefits', 'notifications'], manifest: 'frontend/src/modules/platform/pages/index.ts', pages: 'frontend/src/modules/platform/pages' },
  ]
  const moduleEntry = modules.find((candidate) => candidate.prefixes.some((prefix) => componentKey === prefix || componentKey.startsWith(prefix)))
  if (moduleEntry !== undefined && existsSync(join(root, moduleEntry.manifest))) {
    const manifest = readFileSync(join(root, moduleEntry.manifest), 'utf8')
    const marker = `'${componentKey}': () => import('./`
    const line = manifest.split(String.fromCharCode(10)).find((candidate) => candidate.trim().startsWith(marker))
    if (line === undefined) return false
    const rest = line.trim().slice(marker.length)
    const closing = rest.indexOf(".vue')")
    if (closing < 0) return false
    return existsSync(join(root, moduleEntry.pages, rest.slice(0, closing + 4)))
  }

  // Legacy barrels remain supported for domains that have not moved yet.
  const domains = [
    { prefixes: ['hr/'], dir: 'frontend/src/views/people' },
    { prefixes: ['attendance/', 'leave/', 'overtime/', 'travel/', 'payroll/'], dir: 'frontend/src/views/timepay' },
    { prefixes: ['performance/', 'recruitment/', 'training/'], dir: 'frontend/src/views/talent' },
    { prefixes: ['dashboard/', 'reports/', 'notices/', 'account', 'system/', 'workflow', 'self-service', 'benefits', 'notifications'], dir: 'frontend/src/views/platform' },
  ]
  const domain = domains.find((candidate) => candidate.prefixes.some((prefix) => componentKey === prefix || componentKey.startsWith(prefix)))
  if (domain === undefined) return false

  const directory = join(root, domain.dir)
  if (!existsSync(directory)) return false
  const sources = readdirSync(directory)
    .filter((name) => name.endsWith('.ts'))
    .map((name) => readFileSync(join(directory, name), 'utf8'))
    .join(String.fromCharCode(10))
  return sources.includes(`'${componentKey}'`) || sources.includes(`"${componentKey}"`)
}

const RESOURCE_PAGE_EXISTS = existsSync(join(root, 'frontend/src/components/ResourcePage.vue'))

const componentKeys = [...registry.matchAll(/componentKey:\s*'([^']+)'/g)].map((m) => m[1])
const missingComponents = componentKeys.filter((key) => !matureViewExists(key) && !RESOURCE_PAGE_EXISTS)
const matureComponents = componentKeys.filter((key) => matureViewExists(key))

const missingBusiness = inventoryPages.filter((page) => !registryPaths.includes(page.path))
/**
 * Deliberate deep-link additions that are not part of the frozen 03A/03B
 * inventory. Each must be a detail route of an inventory page and is reported
 * here so a new unreviewed path can never slip in silently.
 */
const DELIBERATE_DETAIL_ROUTES = new Set([
  '/payroll/runs/:id',
  '/workflow/cases/:id',
])
const extraBusiness = registryPaths.filter((path) => {
  if (path.startsWith('/account') || ['/login', '/workspace', '/forbidden', '/not-found'].includes(path)) return false
  if (DELIBERATE_DETAIL_ROUTES.has(path)) return false
  return !inventoryPages.some((page) => page.path === path) && !inventoryModules.some((module) => module.path === path)
})
const duplicatePaths = registryPaths.filter((path, index) => registryPaths.indexOf(path) !== index)
const duplicateNames = registryNames.filter((name, index) => registryNames.indexOf(name) !== index)

// Cluster membership comes from the registry; the page count contract is 62
// after ADR-0035 moved the notice archive form route into a workspace action.
const clusterMatches = [...registry.matchAll(/cluster:\s*'(K(?:[1-9]|1[0-2]))'/g)]
const clusterCounts = clusterMatches.reduce((counts, match) => {
  counts[match[1]] = (counts[match[1]] ?? 0) + 1
  return counts
}, {})
const { CLUSTER_MEMBERSHIP, CLUSTER_COUNTS } = await import('./lib/clusterMembership.mjs')
const CLUSTER_CONTRACT = { K1: 4, K2: 2, K3: 1, K4: 4, K5: 4, K6: 3, K7: 2, K8: 6, K9: 10, K10: 7, K11: 14, K12: 5 }
const clusterTotal = clusterMatches.length

// Exact membership, not just counts: recover componentKey -> cluster from the
// registry and compare it with the frozen map in both directions.
const registryClusterByKey = new Map()
for (const match of registry.matchAll(/\{cluster: '(K(?:[1-9]|1[0-2]))', path: '[^']*', name: '[^']*', componentKey: '([^']+)'/g)) {
  registryClusterByKey.set(match[2], match[1])
}
const membershipMismatches = []
for (const [key, cluster] of Object.entries(CLUSTER_MEMBERSHIP)) {
  if (registryClusterByKey.get(key) !== cluster) {
    membershipMismatches.push(`${key}: expected ${cluster}, registry has ${registryClusterByKey.get(key) ?? 'none'}`)
  }
}
for (const key of registryClusterByKey.keys()) {
  if (!(key in CLUSTER_MEMBERSHIP)) {
    membershipMismatches.push(`${key}: clustered in registry but absent from frozen membership`)
  }
}

// Executable "backend endpoint pending" markers, resolved to componentKeys.
const { readGapMarkers } = await import('./lib/gapMarkers.mjs')
const gapMarkers = readGapMarkers(root)
const gappedComponentKeys = [...gapMarkers.keys]

const failures = []
const requireMature = process.argv.includes('--require-mature')

// Page-component uniqueness is a release gate but stays a warning while the
// module split is being completed. Its own JSON report is the source of the
// human-readable counts; only a compact summary is echoed here.
const pageComponentGate = spawnSync(
  process.execPath,
  [join(root, 'scripts/audit_page_components.mjs')],
  { cwd: root, encoding: 'utf8', env: { ...process.env, NO_COLOR: '1' } },
)
let pageComponentReport = null
if (typeof pageComponentGate.stdout === 'string' && pageComponentGate.stdout.trim() !== '') {
  try {
    pageComponentReport = JSON.parse(pageComponentGate.stdout)
  } catch {
    pageComponentReport = null
  }
}
const pageComponentProblem =
  pageComponentGate.error !== undefined
    ? pageComponentGate.error.message
    : pageComponentGate.status === 0
      ? null
      : `total=${pageComponentReport?.total ?? '?'}, mapped=${pageComponentReport?.mapped ?? '?'}, missingMappings=${pageComponentReport?.missingMappings?.length ?? '?'}`
if (pageComponentProblem !== null) {
  const message = `page-component uniqueness gate failed (${pageComponentProblem})`
  if (requireMature) failures.push(message)
  else console.warn(`WARN ${message}`)
}

if (requireMature && matureComponents.length < 155) {
  failures.push(`mature business views=${matureComponents.length}/155; remaining ${155 - matureComponents.length} are registry fallbacks`)
}
if (inventoryPages.length !== 133) failures.push(`inventory business pages=${inventoryPages.length}, expected 133`)
if (inventoryModules.length !== 14) failures.push(`inventory platform modules=${inventoryModules.length}, expected 14`)
if (registryPaths.length !== 155) failures.push(`registry entries=${registryPaths.length}, expected 155`)
if (new Set(registryNames).size !== registryNames.length) failures.push(`duplicate page names: ${[...new Set(duplicateNames)].join(', ')}`)
if (duplicatePaths.length > 0) failures.push(`duplicate paths: ${[...new Set(duplicatePaths)].join(', ')}`)
if (requiredApiCount !== 155) failures.push(`entries missing requiredApis=${155 - requiredApiCount}`)
if (statesCount !== 155) failures.push(`entries missing five-state declaration=${155 - statesCount}`)
if (negativeCount !== 155) failures.push(`entries missing negative-test declaration=${155 - negativeCount}`)
if (missingBusiness.length > 0) failures.push(`inventory pages missing from registry: ${missingBusiness.map((p) => p.path).join(', ')}`)
if (extraBusiness.length > 0) failures.push(`extra registry paths not in inventory: ${extraBusiness.join(', ')}`)
if (clusterTotal !== 62) failures.push(`registry cluster pages=${clusterTotal}, expected 62`)
if (membershipMismatches.length > 0) {
  failures.push(`cluster membership drift: ${membershipMismatches.join('; ')}`)
}
for (const [cluster, expected] of Object.entries(CLUSTER_COUNTS)) {
  if ((clusterCounts[cluster] ?? 0) !== expected) {
    failures.push(`cluster ${cluster} has ${clusterCounts[cluster] ?? 0} pages, frozen map says ${expected}`)
  }
}
for (const [cluster, expected] of Object.entries(CLUSTER_CONTRACT)) {
  if ((clusterCounts[cluster] ?? 0) !== expected) {
    failures.push(`cluster ${cluster} pages=${clusterCounts[cluster] ?? 0}, expected ${expected}`)
  }
}
if (gapMarkers.unparsed.length > 0) {
  failures.push(
    `gap-marker parser could not attribute every marker: ${gapMarkers.unparsed
      .map((entry) => `${entry.file} (raw=${entry.raw}, attributed=${entry.attributed})`)
      .join(', ')}`,
  )
}
if (gapMarkers.hardcoded.length > 0) {
  // Scaffold/release mode reports this as debt; the capability gate rejects it.
  const message = `hardcoded pending panels must be catalog-driven: ${gapMarkers.hardcoded.join(', ')}`
  if (requireCapabilities) failures.push(message)
  else console.warn(`WARN ${message}`)
}
if (requireCapabilities) {
  const stillGapped = gappedComponentKeys.length
  if (stillGapped > 0) {
    failures.push(`--require-capabilities: ${stillGapped} page(s) still declare a backend gap: ${gappedComponentKeys.join(', ')}`)
  }
}
if (missingComponents.length > 0) {
  const message = `registry entries without a component file: ${missingComponents.length}`
  if (allowMissing) {
    console.warn(`WARN ${message} (Wave 1 scaffolding; release gate must not use --allow-missing)`)
  } else {
    failures.push(message)
  }
}

const report = {
  ok: failures.length === 0,
  inventory: { business: inventoryPages.length, platform: inventoryModules.length, total: inventoryPages.length + inventoryModules.length + 6 },
  registry: {
    entries: registryPaths.length,
    layers: { business: 134, platform: 15, base: 6 },
    missingRenderers: missingComponents.length,
    matureViews: matureComponents.length,
    fallbackViews: registryPaths.length - matureComponents.length,
  },
  coverage: {
    business: `${134 - missingBusiness.length}/134`,
    platform: `${14 - extraBusiness.filter((p) => inventoryModules.some((m) => m.path === p)).length}/14`,
    base: '6/6',
  },
  clusters: {
    total: clusterTotal,
    counts: clusterCounts,
    expected: CLUSTER_CONTRACT,
    gapMarkers: gappedComponentKeys.length,
    membershipDrift: membershipMismatches.length,
    unparsedFiles: gapMarkers.unparsed.length,
    hardcodedPanels: gapMarkers.hardcoded.length,
    gapByDomain: Object.fromEntries(Object.entries(gapMarkers.byStyle).map(([domain, keys]) => [domain, keys.length])),
  },
  mode: requireCapabilities ? 'capability-complete' : requireMature ? 'release' : 'scaffold',
  failures,
}

console.log(JSON.stringify(report, null, 2))
if (failures.length > 0) process.exit(1)
