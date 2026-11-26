#!/usr/bin/env node
/**
 * Page-action manifest gate.
 *
 * Thin CLI over the shared builder/validator in generate_action_manifest.mjs.
 * The production CLI reads the generated manifest and validates it back against
 * the registry, OpenAPI, capability catalog and explicit gap list.
 *
 * The only public write exception is POST /v1/auth/login. It is in the
 * documented backend allowlist and intentionally carries no x-capability.
 */

import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import {
  ACTION_CONFIRM,
  ACTION_UI,
  CAPABILITY_PATTERN,
  GAP_OWNER_PATTERN,
  PUBLIC_WRITE_EXCEPTIONS,
  WRITE_METHODS,
  parseCapabilityCatalog,
  parseCapabilityCodes,
  parseOpenApi,
  parseRegistryPages,
  splitApi,
  validateActionManifest,
} from './generate_action_manifest.mjs'

export { PUBLIC_WRITE_EXCEPTIONS }

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const failure = (message) => `parse error: ${message}`

const canonicalApi = (api) => {
  const { method, path } = splitApi(api)
  return `${method} ${path}`
}

function read(relativePath) {
  return readFileSync(resolve(repoRoot, relativePath), 'utf8')
}

function readJson(relativePath) {
  try {
    return JSON.parse(read(relativePath))
  } catch (error) {
    throw new Error(`${relativePath}: ${error.message}`)
  }
}

function emptyResult(failures, pageCount = 0) {
  return {
    ok: false,
    failures,
    errors: failures,
    pageCount,
    writePageCount: 0,
    pagesWithActions: 0,
    actionCount: 0,
    gapsUsed: 0,
    publicWriteExceptionsUsed: [],
  }
}

function validatePublicException(exception, page, writeApis, openapiDocument, failures) {
  const label = `page ${page.name}`
  if (page.capability !== 'public') {
    failures.push(`${label}: public write exception requires capability 'public', found '${page.capability}'`)
    return false
  }
  if (writeApis.length !== 1 || writeApis[0] !== exception.api) {
    failures.push(`${label}: public write exception does not match write APIs ${writeApis.join(', ') || '(none)'}`)
    return false
  }
  const [method, path] = exception.api.split(' ', 2)
  const operation = openapiDocument.paths?.[path]?.[method.toLowerCase()]
  if (operation === null || operation === undefined) {
    failures.push(`${label}: public write exception ${exception.api} is not documented in OpenAPI`)
    return false
  }
  if (operation.operationId !== exception.operationId) {
    failures.push(`${label}: public write exception operationId '${operation.operationId}' != '${exception.operationId}'`)
    return false
  }
  if (!Array.isArray(operation.security) || operation.security.length > 0) {
    failures.push(`${label}: public write exception ${exception.api} must explicitly declare security: []`)
    return false
  }
  return true
}

/**
 * Pure audit entry point.
 *
 * New signature:
 *   auditActionManifest({ manifest, registry, openapi, gaps = [], catalog })
 *
 * Legacy fixture signature (still used by scripts/tests): pass registrySource,
 * catalogSource and openapiSource instead. Only this fixture bridge reads the
 * registry's hand-written `actions:`; the production gate never does.
 */
export function auditActionManifest(options) {
  if (options?.registrySource !== undefined) return auditLegacyFixture(options)
  return auditGeneratedManifest(options)
}

function auditGeneratedManifest({
  manifest,
  registry,
  openapi,
  gaps = [],
  catalog,
  publicWriteExceptions = PUBLIC_WRITE_EXCEPTIONS,
}) {
  const failures = []
  const pages = registry
  const openapiDocument = openapi
  let capabilities = catalog

  if (!Array.isArray(pages)) failures.push(failure('registry pages must be an array'))
  if (openapiDocument === null || typeof openapiDocument !== 'object') {
    failures.push(failure('OpenAPI document is required'))
  }
  if (capabilities === undefined) failures.push(failure('capability catalog is required'))
  if (failures.length > 0) return emptyResult(failures, pages?.length ?? 0)

  if (!(capabilities instanceof Set)) capabilities = new Set(capabilities)
  const manifestPages = manifest?.pages ?? {}
  const actions = Object.values(manifestPages).flat()

  for (const page of pages) {
    if (new Set(page.requiredApis).size !== page.requiredApis.length) {
      failures.push(`page ${page.name}: requiredApis contains duplicates`)
    }
  }

  const actionKeys = new Set()
  for (const [pageCode, pageActions] of Object.entries(manifestPages)) {
    if (!Array.isArray(pageActions)) {
      failures.push(`${pageCode}: manifest actions must be an array`)
      continue
    }
    for (const action of pageActions) {
      const label = `page ${pageCode} action ${action.key || '(missing key)'}`
      if (action.pageCode !== pageCode) {
        failures.push(`${label}: pageCode ${String(action.pageCode)} != ${pageCode}`)
      }
      if (actionKeys.has(action.key)) failures.push(`${label}: duplicate action key`)
      actionKeys.add(action.key)
      if (typeof action.capability !== 'string' || !CAPABILITY_PATTERN.test(action.capability)) {
        failures.push(`${label}: invalid capability '${String(action.capability)}'`)
      }
      if (typeof action.capability === 'string' && !capabilities.has(action.capability)) {
        failures.push(`${label}: unknown capability ${action.capability}`)
      }
      if (!ACTION_UI.includes(action.ui)) failures.push(`${label}: invalid ui '${String(action.ui)}'`)
      if (action.confirm !== undefined && !ACTION_CONFIRM.includes(action.confirm)) {
        failures.push(`${label}: invalid confirm '${String(action.confirm)}'`)
      }
      const actionMethod = splitApi(action.api).method
      if (!WRITE_METHODS.includes(actionMethod)) {
        failures.push(`${label}: write page action API must use POST/PUT/PATCH/DELETE, found '${actionMethod}'`)
      }
      if (typeof action.label !== 'string' || action.label.trim() === '') {
        failures.push(`${label}: label is required`)
      }
      if (!Array.isArray(action.anchorTypes) || action.anchorTypes.length === 0) {
        failures.push(`${label}: anchorTypes must be a non-empty array`)
      }
      if (typeof action.fieldProfileMax !== 'string' || !/^P[0-3]_[A-Z_]+$/.test(action.fieldProfileMax)) {
        failures.push(`${label}: invalid fieldProfileMax '${String(action.fieldProfileMax)}'`)
      }
    }
  }

  const validation = validateActionManifest({
    pages,
    manifest,
    openapi: openapiDocument,
    gaps,
    requireGapReason: true,
    requireLabels: true,
    publicWriteExceptions,
  })
  failures.push(...validation.errors)

  let writePageCount = 0
  let pagesWithActions = 0
  for (const page of pages) {
    if (page.requiredApis.some((api) => WRITE_METHODS.includes(splitApi(api).method))) writePageCount += 1
    if ((manifestPages[page.name] ?? []).length > 0) pagesWithActions += 1
  }

  const covered = new Set(actions.map((action) => canonicalApi(action.api)))
  const gapsUsed = gaps.filter((gap) => (
    !covered.has(canonicalApi(gap.api)) && GAP_OWNER_PATTERN.test(String(gap.owner))
  )).length

  return {
    ok: failures.length === 0,
    failures,
    errors: failures,
    pageCount: pages.length,
    writePageCount,
    pagesWithActions,
    actionCount: actions.length,
    gapsUsed,
    publicWriteExceptionsUsed: validation.publicWriteExceptionsUsed ?? [],
  }
}

/**
 * Transitional fixture bridge for the pre-manifest unit tests. It reads the
 * fixture registry's hand-written actions solely to reproduce the historical
 * assertion messages; the production CLI never takes this path.
 */
function auditLegacyFixture({
  registrySource,
  catalogSource,
  openapiSource,
  publicWriteExceptions = PUBLIC_WRITE_EXCEPTIONS,
}) {
  const failures = []
  let pages
  let capabilities
  let openapiDocument

  try {
    pages = parseRegistryPages(registrySource, { includeActions: true })
  } catch (error) {
    failures.push(failure(error.message))
  }
  try {
    capabilities = parseCapabilityCodes(catalogSource)
  } catch (error) {
    failures.push(failure(error.message))
  }
  try {
    openapiDocument = parseOpenApi(openapiSource)
  } catch (error) {
    failures.push(failure(error.message))
  }

  if (pages === undefined || capabilities === undefined || openapiDocument === undefined) {
    return emptyResult(failures, pages?.length ?? 0)
  }

  const exceptionByPage = new Map(publicWriteExceptions.map((exception) => [exception.pageCode, exception]))
  const publicWriteExceptionsUsed = []
  let writePageCount = 0
  let pagesWithActions = 0
  let actionCount = 0

  for (const page of pages) {
    const label = `page ${page.name}`
    if (new Set(page.requiredApis).size !== page.requiredApis.length) {
      failures.push(`${label}: requiredApis contains duplicates`)
    }

    const writeApis = page.requiredApis.filter((api) => WRITE_METHODS.includes(splitApi(api).method))
    if (writeApis.length === 0) continue
    writePageCount += 1
    if (page.actions.length > 0) pagesWithActions += 1
    actionCount += page.actions.length

    if (page.actions.length === 0) {
      const exception = exceptionByPage.get(page.name)
      if (exception !== undefined && validatePublicException(exception, page, writeApis, openapiDocument, failures)) {
        publicWriteExceptionsUsed.push(`${page.name}: ${exception.api}`)
      } else if (exception === undefined) {
        failures.push(`${label}: write page declares no action`)
      }
      continue
    }

    const coveredApis = new Set()
    const actionKeys = new Set()
    for (const action of page.actions) {
      const actionLabel = `${label} action ${action.key || '(missing key)'}`
      if (actionKeys.has(action.key)) failures.push(`${actionLabel}: duplicate action key`)
      actionKeys.add(action.key)

      if (!CAPABILITY_PATTERN.test(action.capability)) {
        failures.push(`${actionLabel}: invalid capability '${action.capability}'`)
      }
      if (!capabilities.has(action.capability)) {
        failures.push(`${actionLabel}: unknown capability ${action.capability}`)
      }
      if (!ACTION_UI.includes(action.ui)) {
        failures.push(`${actionLabel}: invalid ui '${action.ui}'`)
      }
      if (action.confirm !== undefined && !ACTION_CONFIRM.includes(action.confirm)) {
        failures.push(`${actionLabel}: invalid confirm '${action.confirm}'`)
      }

      const { method, path } = splitApi(action.api)
      if (!WRITE_METHODS.includes(method)) {
        failures.push(`${actionLabel}: write page action API must use POST/PUT/PATCH/DELETE, found '${method}'`)
      }
      if (!page.requiredApis.includes(action.api)) {
        failures.push(`${actionLabel}: API ${action.api} is not in requiredApis`)
      }
      coveredApis.add(action.api)

      const operation = openapiDocument.paths?.[path]?.[method.toLowerCase()]
      if (operation === undefined) {
        failures.push(`${actionLabel}: ${action.api} is not documented in OpenAPI`)
        continue
      }
      if (operation['x-capability'] !== action.capability) {
        failures.push(
          `${actionLabel}: OpenAPI x-capability ${String(operation['x-capability'])} != ${action.capability}`,
        )
      }
    }

    for (const writeApi of writeApis) {
      if (!coveredApis.has(writeApi)) failures.push(`${label}: write API ${writeApi} has no declared action`)
    }
  }

  for (const exception of publicWriteExceptions) {
    if (!pages.some((page) => page.name === exception.pageCode)) {
      failures.push(`public write exception references unknown page ${exception.pageCode}`)
    }
  }

  return {
    ok: failures.length === 0,
    failures,
    errors: failures,
    pageCount: pages.length,
    writePageCount,
    pagesWithActions,
    actionCount,
    gapsUsed: 0,
    publicWriteExceptionsUsed,
  }
}

function main() {
  let result
  try {
    const pages = parseRegistryPages(read('frontend/src/router/registry.ts'))
    const openapiDocument = parseOpenApi(read('docs/openapi/openapi.yaml'))
    const catalogEntries = parseCapabilityCatalog(read('backend/app/Modules/IAM/Authorization/CapabilityCatalog.php'))
    const manifestDocument = readJson('docs/generated/page-actions.json')
    const gapDocument = readJson('docs/audit/2026-11-26-v2-action-gaps.json')
    if (!Array.isArray(gapDocument.gaps)) throw new Error('gap document must contain a gaps array')

    result = auditActionManifest({
      manifest: manifestDocument,
      registry: pages,
      openapi: openapiDocument,
      catalog: new Set(catalogEntries.map((entry) => entry.code)),
      gaps: gapDocument.gaps,
    })
  } catch (error) {
    console.error(`actions:check FAIL: ${error.message}`)
    process.exitCode = 1
    return
  }

  if (!result.ok) {
    console.error('actions:check FAIL')
    for (const error of result.errors) console.error(`  - ${error}`)
    process.exitCode = 1
    return
  }

  console.log(
    `actions:check OK (${result.pageCount} pages, ${result.writePageCount} write pages, `
    + `${result.pagesWithActions} pages with actions, ${result.actionCount} actions, `
    + `${result.gapsUsed} explicit gaps, ${result.publicWriteExceptionsUsed.length} public write exceptions)`,
  )

  const generatedJson = read('docs/generated/page-actions.json')
  const generatedTs = read('frontend/src/generated/pageActions.ts')
  if (!generatedJson.endsWith('\n') || !generatedTs.endsWith('\n')) {
    console.error('actions:check FAIL: generated files must end with a newline')
    process.exitCode = 1
  }
}

if (process.argv[1] !== undefined && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  main()
}
