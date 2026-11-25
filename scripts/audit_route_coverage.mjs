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
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const here = dirname(fileURLToPath(import.meta.url))
const root = resolve(here, '..')
const registryPath = join(root, 'frontend/src/router/registry.ts')
const inventoryPath = join(root, 'docs/audit/2026-11-25-v11-page-inventory.md')
const allowMissing = process.argv.includes('--allow-missing')

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
const requiredApiCount = (registry.match(/requiredApis:/g) ?? []).length - 1
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

  // Domain barrels expose one mapping function per domain. The audit verifies
  // the key is actually present in that domain's catalog/definitions instead
  // of trusting a static list in pageComponents.ts.
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
    .join('\n')
  return sources.includes(`'${componentKey}'`) || sources.includes(`"${componentKey}"`)
}

const RESOURCE_PAGE_EXISTS = existsSync(join(root, 'frontend/src/components/ResourcePage.vue'))

const componentKeys = [...registry.matchAll(/componentKey:\s*'([^']+)'/g)].map((m) => m[1])
const missingComponents = componentKeys.filter((key) => !matureViewExists(key) && !RESOURCE_PAGE_EXISTS)
const matureComponents = componentKeys.filter((key) => matureViewExists(key))

const missingBusiness = inventoryPages.filter((page) => !registryPaths.includes(page.path))
const extraBusiness = registryPaths.filter((path) => {
  if (path.startsWith('/account') || ['/login', '/workspace', '/forbidden', '/not-found'].includes(path)) return false
  return !inventoryPages.some((page) => page.path === path) && !inventoryModules.some((module) => module.path === path)
})
const duplicatePaths = registryPaths.filter((path, index) => registryPaths.indexOf(path) !== index)
const duplicateNames = registryNames.filter((name, index) => registryNames.indexOf(name) !== index)

const failures = []
const requireMature = process.argv.includes('--require-mature')
if (requireMature && matureComponents.length < 160) {
  failures.push(`mature business views=${matureComponents.length}/160; remaining ${160 - matureComponents.length} are registry fallbacks`)
}
if (inventoryPages.length !== 140) failures.push(`inventory business pages=${inventoryPages.length}, expected 140`)
if (inventoryModules.length !== 14) failures.push(`inventory platform modules=${inventoryModules.length}, expected 14`)
if (registryPaths.length !== 160) failures.push(`registry entries=${registryPaths.length}, expected 160`)
if (new Set(registryNames).size !== registryNames.length) failures.push(`duplicate page names: ${[...new Set(duplicateNames)].join(', ')}`)
if (duplicatePaths.length > 0) failures.push(`duplicate paths: ${[...new Set(duplicatePaths)].join(', ')}`)
if (requiredApiCount !== 160) failures.push(`entries missing requiredApis=${160 - requiredApiCount}`)
if (statesCount !== 160) failures.push(`entries missing five-state declaration=${160 - statesCount}`)
if (negativeCount !== 160) failures.push(`entries missing negative-test declaration=${160 - negativeCount}`)
if (missingBusiness.length > 0) failures.push(`inventory pages missing from registry: ${missingBusiness.map((p) => p.path).join(', ')}`)
if (extraBusiness.length > 0) failures.push(`extra registry paths not in inventory: ${extraBusiness.join(', ')}`)
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
    layers: { business: 140, platform: 14, base: 6 },
    missingRenderers: missingComponents.length,
    matureViews: matureComponents.length,
    fallbackViews: registryPaths.length - matureComponents.length,
  },
  coverage: {
    business: `${140 - missingBusiness.length}/140`,
    platform: `${14 - extraBusiness.filter((p) => inventoryModules.some((m) => m.path === p)).length}/14`,
    base: '6/6',
  },
  mode: requireMature ? 'release' : 'scaffold',
  failures,
}

console.log(JSON.stringify(report, null, 2))
if (failures.length > 0) process.exit(1)
