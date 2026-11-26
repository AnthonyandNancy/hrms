#!/usr/bin/env node
/**
 * Single-source page action manifest generator.
 *
 * Inputs:
 *   - frontend/src/router/registry.ts (path/name/capability/requiredApis only)
 *   - backend/app/Modules/IAM/Authorization/CapabilityCatalog.php
 *   - docs/openapi/openapi.yaml
 *   - docs/generated/action-input.json
 *   - docs/audit/2026-11-26-v2-action-gaps.json (explicit, per-operation gaps)
 *
 * Outputs:
 *   - docs/generated/page-actions.json
 *   - frontend/src/generated/pageActions.ts
 */

import { readFileSync, readdirSync, statSync, writeFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import YAML from 'yaml'

export const WRITE_METHODS = Object.freeze(['POST', 'PUT', 'PATCH', 'DELETE'])
export const WRITE_METHOD_NAMES = Object.freeze(['post', 'put', 'patch', 'delete'])
export const ACTION_UI = Object.freeze(['primary', 'secondary', 'danger', 'hidden'])
export const ACTION_CONFIRM = Object.freeze(['none', 'danger', 'sod'])
export const CAPABILITY_PATTERN = /^[a-z][a-z0-9]*(\.[a-z0-9-]+)+$/
export const GAP_OWNER_PATTERN = /^Volume (?:2|3) Task [1-9][0-9]*$/
export const SERVICE_REFERENCE_PATTERN = /^[A-Za-z_][A-Za-z0-9_]*(?:\\[A-Za-z_][A-Za-z0-9_]*)*::[A-Za-z_][A-Za-z0-9_]*$/

/**
 * Frozen permission-workbench coverage contract. A writable page must expose
 * at least one action once it declares a write API; a read-only page must
 * expose neither. Pages without a registry entry are reported as explicit
 * skips with the owning task rather than failing while those tasks land.
 */
export const WORKBENCH_PAGES = Object.freeze({
  'system-users': Object.freeze({ mode: 'writable', owner: 'Volume 2 Task 6' }),
  'system-roles': Object.freeze({ mode: 'writable', owner: 'Volume 2 Task 7' }),
  'system-menus': Object.freeze({ mode: 'writable', owner: 'Volume 2 Task 8' }),
  'system-permissions': Object.freeze({ mode: 'read-only', owner: 'Volume 2 Task 8' }),
  'system-data-scopes': Object.freeze({ mode: 'writable', owner: 'Volume 2 Task 8' }),
  'system-field-policies': Object.freeze({ mode: 'writable', owner: 'Volume 2 Task 8' }),
})

/** Pages whose actions must declare the backend services they invoke. */
export const SERVICE_MANDATORY_PAGES = Object.freeze([
  'system-menus',
  'system-data-scopes',
  'system-field-policies',
])

/**
 * The only public write exception is the documented backend allowlisted
 * login operation. It carries no capability and therefore no action.
 */
export const PUBLIC_WRITE_EXCEPTIONS = Object.freeze([
  Object.freeze({
    pageCode: 'login',
    api: 'POST /v1/auth/login',
    operationId: 'login',
    reason: '/v1/auth/login is in the documented backend public allowlist and has no x-capability.',
  }),
])

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const isWriteApi = (api) => /^(POST|PUT|PATCH|DELETE) /.test(String(api))

export function splitApi(api) {
  const [method, ...pathParts] = String(api).trim().split(/\s+/)
  return { method: (method ?? '').toUpperCase(), path: pathParts.join(' ') }
}

function skipSpaceAndComments(source, start) {
  let index = start
  while (index < source.length) {
    if (/\s/.test(source[index])) {
      index += 1
      continue
    }
    if (source.startsWith('//', index)) {
      const newline = source.indexOf('\n', index + 2)
      index = newline === -1 ? source.length : newline + 1
      continue
    }
    if (source.startsWith('/*', index)) {
      const end = source.indexOf('*/', index + 2)
      if (end === -1) throw new Error('unterminated block comment')
      index = end + 2
      continue
    }
    break
  }
  return index
}

function readQuoted(source, start) {
  const quote = source[start]
  if (quote !== "'" && quote !== '"') {
    throw new Error(`expected quoted string at offset ${start}`)
  }
  let value = ''
  let index = start + 1
  while (index < source.length) {
    const char = source[index]
    if (char === '\\') {
      const next = source[index + 1]
      if (next === undefined) throw new Error('unterminated string escape')
      value += next === 'n' ? '\n' : next === 't' ? '\t' : next
      index += 2
      continue
    }
    if (char === quote) return { value, end: index + 1 }
    value += char
    index += 1
  }
  throw new Error('unterminated quoted string')
}

function findMatching(source, start) {
  const open = source[start]
  const close = open === '{' ? '}' : open === '[' ? ']' : open === '(' ? ')' : null
  if (close === null) throw new Error(`unsupported delimiter ${open}`)
  let depth = 0
  let index = start
  while (index < source.length) {
    const char = source[index]
    if (char === "'" || char === '"') {
      index = readQuoted(source, index).end
      continue
    }
    if (char === '`') {
      const end = source.indexOf('`', index + 1)
      if (end === -1) throw new Error('unterminated template string')
      index = end + 1
      continue
    }
    if (source.startsWith('//', index)) {
      const newline = source.indexOf('\n', index + 2)
      index = newline === -1 ? source.length : newline + 1
      continue
    }
    if (source.startsWith('/*', index)) {
      const end = source.indexOf('*/', index + 2)
      if (end === -1) throw new Error('unterminated block comment')
      index = end + 2
      continue
    }
    if (char === open) depth += 1
    if (char === close) {
      depth -= 1
      if (depth === 0) return index
    }
    index += 1
  }
  throw new Error(`unterminated ${open}${close} block`)
}

function fieldValueStart(source, field, from = 0) {
  const match = new RegExp(`(?:^|[\\s,{])${field}\\s*:`, 'm').exec(source.slice(from))
  if (match === null) return null
  return skipSpaceAndComments(source, from + match.index + match[0].length)
}

function readStringField(source, field, label) {
  const start = fieldValueStart(source, field)
  if (start === null) throw new Error(`${label}: missing ${field}`)
  try {
    return readQuoted(source, start).value
  } catch (error) {
    throw new Error(`${label}: cannot read ${field}: ${error.message}`)
  }
}

function readArraySource(source, field, label) {
  const start = fieldValueStart(source, field)
  if (start === null || source[start] !== '[') {
    throw new Error(`${label}: missing ${field} array`)
  }
  return {
    body: source.slice(start + 1, findMatching(source, start)),
    start,
  }
}

function parseStringArray(body, field, label) {
  const values = []
  let index = 0
  while (index < body.length) {
    index = skipSpaceAndComments(body, index)
    if (index >= body.length) break
    if (body[index] === ',') {
      index += 1
      continue
    }
    if (body[index] === "'" || body[index] === '"') {
      const quoted = readQuoted(body, index)
      values.push(quoted.value)
      index = quoted.end
      continue
    }
    throw new Error(`${label}: ${field} contains a non-string value`)
  }
  return values
}

function parseActionObjects(body, label) {
  const actions = []
  let index = 0
  while (index < body.length) {
    index = skipSpaceAndComments(body, index)
    if (index >= body.length) break
    if (body[index] === ',') {
      index += 1
      continue
    }
    if (body[index] !== '{') {
      throw new Error(`${label}: actions contains a non-object value`)
    }
    const end = findMatching(body, index)
    const source = body.slice(index, end + 1)
    const actionLabel = `${label} action ${actions.length + 1}`
    actions.push({
      key: readStringField(source, 'key', actionLabel),
      label: readStringField(source, 'label', actionLabel),
      capability: readStringField(source, 'capability', actionLabel),
      api: readStringField(source, 'api', actionLabel),
      ui: readStringField(source, 'ui', actionLabel),
      confirm: fieldValueStart(source, 'confirm') === null
        ? undefined
        : readStringField(source, 'confirm', actionLabel),
    })
    index = end + 1
  }
  return actions
}

/**
 * Parse PAGE_REGISTRY without trusting or consuming hand-written `actions:`
 * unless a legacy caller explicitly asks for transitional compatibility.
 */
export function parseRegistryPages(source, { includeActions = false } = {}) {
  const registryMatch = /export\s+const\s+PAGE_REGISTRY\s*(?::\s*PageSpec\[\])?\s*=\s*\[/.exec(source)
  if (registryMatch === null) throw new Error('PAGE_REGISTRY array not found')
  const arrayStart = registryMatch.index + registryMatch[0].lastIndexOf('[')
  const arrayEnd = findMatching(source, arrayStart)
  const body = source.slice(arrayStart + 1, arrayEnd)
  const pages = []
  let index = 0

  while (index < body.length) {
    index = skipSpaceAndComments(body, index)
    if (index >= body.length) break
    if (body[index] === ',') {
      index += 1
      continue
    }
    if (body[index] !== '{') {
      throw new Error(`PAGE_REGISTRY contains a non-object value at offset ${arrayStart + index + 1}`)
    }
    const end = findMatching(body, index)
    const entry = body.slice(index, end + 1)
    const name = readStringField(entry, 'name', 'registry page')
    const label = `page ${name}`
    const requiredApisSource = readArraySource(entry, 'requiredApis', label)
    const requiredApis = parseStringArray(requiredApisSource.body, 'requiredApis', label)
    const page = {
      path: readStringField(entry, 'path', label),
      name,
      capability: readStringField(entry, 'capability', label),
      requiredApis,
    }
    if (includeActions) {
      try {
        const actionSource = readArraySource(entry, 'actions', label)
        page.actions = parseActionObjects(actionSource.body, label)
        page.hasActionsArray = true
      } catch (error) {
        if (!/missing actions array/.test(error.message)) throw error
        page.actions = []
        page.hasActionsArray = false
      }
    }
    pages.push(page)
    index = end + 1
  }

  if (pages.length === 0) throw new Error('no registry pages found')
  return pages
}

function splitTopLevel(source, delimiter) {
  const parts = []
  let depth = 0
  let start = 0
  let index = 0
  while (index < source.length) {
    const char = source[index]
    if (char === "'" || char === '"') {
      index = readQuoted(source, index).end
      continue
    }
    if (char === '[' || char === '(' || char === '{') depth += 1
    if (char === ']' || char === ')' || char === '}') depth -= 1
    if (char === delimiter && depth === 0) {
      parts.push(source.slice(start, index).trim())
      start = index + 1
    }
    index += 1
  }
  parts.push(source.slice(start).trim())
  return parts
}

/**
 * Parse CapabilityDefinition constructor calls into the catalog projection
 * needed by the manifest: code, anchorTypes and fieldProfileMax.
 */
export function parseCapabilityCatalog(source) {
  const entries = []
  let index = 0
  while (true) {
    const start = source.indexOf('new CapabilityDefinition', index)
    if (start === -1) break
    const open = source.indexOf('(', start)
    if (open === -1) throw new Error('CapabilityDefinition call has no argument list')
    const close = findMatching(source, open)
    const args = splitTopLevel(source.slice(open + 1, close), ',')
    if (args.length < 5) throw new Error('CapabilityDefinition call has too few arguments')
    let code
    try {
      code = readQuoted(args[0], 0).value
    } catch (error) {
      throw new Error(`cannot read CapabilityDefinition code: ${error.message}`)
    }
    const anchorTypes = [...args[3].matchAll(/AnchorType::([A-Z0-9_]+)/g)].map((match) => match[1])
    const profileMatch = /FieldProfile::([A-Z0-9_]+)/.exec(args[4])
    const fieldProfileMax = profileMatch?.[1]
      ?? (/^['"]([^'"]+)['"]$/.exec(args[4])?.[1] ?? undefined)
    entries.push({ code, anchorTypes, fieldProfileMax })
    index = close + 1
  }

  if (entries.length === 0) throw new Error('no CapabilityDefinition codes found')
  return entries
}

export function parseCapabilityCodes(source) {
  return new Set(parseCapabilityCatalog(source).map((entry) => entry.code))
}

function phpFilesUnder(directory) {
  const files = []
  for (const entry of readdirSync(directory, { withFileTypes: true })) {
    const absolute = resolve(directory, entry.name)
    if (entry.isDirectory()) files.push(...phpFilesUnder(absolute))
    else if (entry.isFile() && entry.name.endsWith('.php')) files.push(absolute)
  }
  return files.sort()
}

/**
 * Parse backend PHP classes into short-name and fully-qualified-name service
 * maps. An action may declare `services: ['RolePageService::save']`; the
 * generator rejects references that do not resolve to a real public method.
 * Short names must be unambiguous, so a duplicate service class name can
 * never silently validate the wrong class.
 */
export function buildServiceIndex(root = resolve(repoRoot, 'backend/app')) {
  let directory
  try {
    directory = statSync(root).isDirectory() ? root : null
  } catch {
    throw new Error(`backend/app directory not found at ${root}`)
  }
  if (directory === null) throw new Error(`backend/app is not a directory: ${root}`)

  const entries = []
  for (const file of phpFilesUnder(directory)) {
    const source = readFileSync(file, 'utf8')
    const namespace = /^namespace\s+([A-Za-z0-9_\\]+)\s*;/m.exec(source)?.[1]
    const classMatch = /^[ \t]*(?:final\s+|abstract\s+|readonly\s+)*class\s+([A-Za-z_][A-Za-z0-9_]*)/m.exec(source)
    const interfaceMatch = /^[ \t]*(?:abstract\s+)*interface\s+([A-Za-z_][A-Za-z0-9_]*)/m.exec(source)
    const match = classMatch ?? interfaceMatch
    if (match === null) continue
    const shortName = match[1]
    const methods = new Set()
    for (const methodMatch of source.matchAll(/^[ \t]*public\s+(?:static\s+)?function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/gm)) {
      methods.add(methodMatch[1])
    }
    entries.push({
      shortName,
      fullyQualified: namespace === undefined ? shortName : `${namespace}\\${shortName}`,
      file,
      methods,
    })
  }

  const shortNameCounts = new Map()
  for (const entry of entries) {
    shortNameCounts.set(entry.shortName, (shortNameCounts.get(entry.shortName) ?? 0) + 1)
  }

  const index = new Map()
  for (const entry of entries) {
    const value = Object.freeze({ file: entry.file, methods: entry.methods })
    index.set(entry.fullyQualified, value)
    if (shortNameCounts.get(entry.shortName) === 1) index.set(entry.shortName, value)
    else index.set(entry.shortName, Object.freeze({ ambiguous: true, methods: new Set(), file: entry.file }))
  }
  return index
}

function assertServiceReferences(services, serviceIndex, label) {
  if (services === undefined) return
  if (!Array.isArray(services) || services.length === 0) {
    throw new Error(`${label}: services must be a non-empty array when present`)
  }
  const seen = new Set()
  for (const reference of services) {
    if (typeof reference !== 'string' || !SERVICE_REFERENCE_PATTERN.test(reference)) {
      throw new Error(`${label}: invalid service reference ${String(reference)}; expected Class::method`)
    }
    if (seen.has(reference)) throw new Error(`${label}: duplicate service reference ${reference}`)
    seen.add(reference)
    const [className, method] = reference.split('::')
    const entry = serviceIndex?.get(className)
    if (entry === undefined) throw new Error(`${label}: unknown service class ${className}`)
    if (entry.ambiguous === true) throw new Error(`${label}: ambiguous service class ${className}; use a fully-qualified name`)
    if (!entry.methods.has(method)) throw new Error(`${label}: ${className} has no method ${method}`)
  }
}

export function parseOpenApi(source) {
  let document
  try {
    document = YAML.parse(source)
  } catch (error) {
    throw new Error(`OpenAPI YAML is invalid: ${error.message}`)
  }
  if (document === null || typeof document !== 'object' || document.paths === null || typeof document.paths !== 'object') {
    throw new Error('OpenAPI document has no paths object')
  }
  return document
}

export function operationFor(openapi, method, path) {
  const item = openapi?.paths?.[path]
  if (item === null || typeof item !== 'object') return undefined
  return item[method.toLowerCase()]
}

function actionLabel(api, openapi) {
  const { method, path } = splitApi(api)
  return operationFor(openapi, method, path)?.['x-action-label']
}

/**
 * Build the deterministic action manifest. The `gaps` list is optional in the
 * pure API for fixture use; the CLI always supplies it, so any capability-
 * guarded write outside the explicit gap list must carry x-action-label.
 */
export function buildActionManifest({ pages, catalog, openapi, actions, gaps = [], serviceIndex }) {
  if (!Array.isArray(pages)) throw new Error('pages must be an array')
  if (!Array.isArray(catalog)) throw new Error('catalog must be an array')
  if (!Array.isArray(actions)) throw new Error('actions must be an array')
  if (!Array.isArray(gaps)) throw new Error('gaps must be an array')
  if (serviceIndex !== undefined && !(serviceIndex instanceof Map)) throw new Error('serviceIndex must be a Map')

  const pageNames = new Set(pages.map((page) => page.name))
  const capabilities = new Map(catalog.map((entry) => [entry.code, entry]))
  const gapApis = new Set(gaps.map((gap) => gap.api))
  const result = { version: 1, pages: {} }
  const keys = new Set()
  const pageApis = new Set()

  for (const action of actions) {
    if (typeof action.pageCode !== 'string' || action.pageCode.trim() === '') {
      throw new Error('action pageCode is required')
    }
    if (!pageNames.has(action.pageCode)) throw new Error(`unknown page ${action.pageCode}`)
    if (!keys.has(action.key)) keys.add(action.key)
    else throw new Error(`duplicate action key ${action.key}`)

    const capability = capabilities.get(action.capability)
    if (capability === undefined) throw new Error(`unknown capability ${action.capability}`)
    if (typeof capability.fieldProfileMax !== 'string' || !Array.isArray(capability.anchorTypes)) {
      throw new Error(`capability ${action.capability} is missing anchorTypes or fieldProfileMax`)
    }

    if (!isWriteApi(action.api)) throw new Error(`action ${action.key} must use a write API`)
    const pageApi = JSON.stringify([action.pageCode, action.api])
    if (pageApis.has(pageApi)) {
      throw new Error(`duplicate action API ${action.api} on page ${action.pageCode}`)
    }

    const { method, path } = splitApi(action.api)
    const operation = operationFor(openapi, method, path)
    if (operation === undefined) throw new Error(`missing OpenAPI operation ${action.api}`)
    if (operation['x-capability'] !== action.capability) {
      throw new Error(
        `capability mismatch for ${action.key}: ${String(operation['x-capability'])} != ${action.capability}`,
      )
    }
    if (typeof operation['x-action-label'] !== 'string' || operation['x-action-label'].trim() === '') {
      throw new Error(`missing x-action-label for ${action.api}`)
    }
    if (serviceIndex !== undefined) assertServiceReferences(action.services, serviceIndex, action.key)

    pageApis.add(pageApi)
    result.pages[action.pageCode] ??= []
    const projected = {
      ...action,
      anchorTypes: [...capability.anchorTypes],
      fieldProfileMax: capability.fieldProfileMax,
    }
    if (action.services === undefined) delete projected.services
    else projected.services = [...action.services]
    result.pages[action.pageCode].push(projected)
  }

  // A capability-guarded write outside the explicit gap list is a live page
  // action and must carry its OpenAPI label. Gap exemptions are per-operation.
  for (const [path, operations] of Object.entries(openapi.paths ?? {})) {
    for (const method of WRITE_METHOD_NAMES) {
      const operation = operations?.[method]
      if (operation === undefined || operation['x-capability'] === undefined) continue
      const api = `${method.toUpperCase()} ${path}`
      if (gapApis.has(api)) continue
      if (typeof operation['x-action-label'] !== 'string' || operation['x-action-label'].trim() === '') {
        throw new Error(`missing x-action-label for ${api}`)
      }
    }
  }

  const sortedPages = {}
  for (const pageCode of Object.keys(result.pages).sort()) {
    sortedPages[pageCode] = [...result.pages[pageCode]].sort((left, right) => left.key.localeCompare(right.key))
  }
  result.pages = sortedPages
  return result
}

/**
 * Validate registry -> manifest, manifest -> OpenAPI/registry, and
 * OpenAPI -> manifest with exact, explicit gap matching.
 */
export function validateActionManifest({
  pages,
  manifest,
  openapi,
  gaps = [],
  requireGapReason = false,
  requireLabels = false,
  publicWriteExceptions = [],
  serviceIndex,
}) {
  const errors = []
  const pageByName = new Map(pages.map((page) => [page.name, page]))
  const manifestPages = manifest?.pages ?? {}
  const perPageCounts = new Map()
  const coveredApis = new Set()
  const exceptionsByPage = new Map(publicWriteExceptions.map((exception) => [exception.pageCode, exception]))
  const publicWriteExceptionsUsed = []

  if (manifest?.version !== 1) errors.push('manifest version must be 1')
  if (serviceIndex !== undefined && !(serviceIndex instanceof Map)) errors.push('serviceIndex must be a Map')

  // Registry -> manifest: every declared write API must have an action, except
  // the exact, explicitly documented public write exception.
  for (const page of pages) {
    const declared = new Set((manifestPages[page.name] ?? []).map((action) => action.api))
    const exception = exceptionsByPage.get(page.name)
    for (const api of page.requiredApis.filter(isWriteApi)) {
      if (exception?.api === api) continue
      if (!declared.has(api)) errors.push(`${page.name}: write API ${api} has no action`)
    }
  }

  // Manifest -> registry and OpenAPI: every action must be real in both.
  const actionKeys = new Set()
  for (const [pageCode, actions] of Object.entries(manifestPages)) {
    if (!Array.isArray(actions)) {
      errors.push(`${pageCode}: manifest actions must be an array`)
      continue
    }
    const page = pageByName.get(pageCode)
    if (page === undefined) {
      errors.push(`${pageCode}: action page does not exist in the registry`)
      continue
    }
    for (const action of actions) {
      if (typeof action.key !== 'string' || action.key.trim() === '') {
        errors.push(`${pageCode}: action is missing key`)
        continue
      }
      if (actionKeys.has(action.key)) errors.push(`${action.key}: duplicate action key`)
      actionKeys.add(action.key)
      if (!isWriteApi(action.api)) errors.push(`${pageCode}/${action.key}: action API must be a write`)
      coveredApis.add(action.api)
      const pageApi = JSON.stringify([pageCode, action.api])
      perPageCounts.set(pageApi, (perPageCounts.get(pageApi) ?? 0) + 1)

      const { method, path } = splitApi(action.api)
      const operation = operationFor(openapi, method, path)
      if (operation === undefined) errors.push(`${pageCode}/${action.key}: missing API ${action.api}`)
      else {
        if (operation['x-capability'] !== action.capability) {
          errors.push(`${pageCode}/${action.key}: capability mismatch with OpenAPI`)
        }
        if (requireLabels && (typeof operation['x-action-label'] !== 'string' || operation['x-action-label'].trim() === '')) {
          errors.push(`${pageCode}/${action.key}: missing x-action-label for ${action.api}`)
        }
      }
      if (!page.requiredApis.includes(action.api)) {
        errors.push(`${pageCode}/${action.key}: API ${action.api} is not in requiredApis`)
      }
      if (serviceIndex !== undefined) {
        if (action.services === undefined) {
          if (SERVICE_MANDATORY_PAGES.includes(pageCode)) {
            errors.push(`${pageCode}/${action.key}: must declare at least one backend service`)
          }
        } else {
          try {
            assertServiceReferences(action.services, serviceIndex, `${pageCode}/${action.key}`)
          } catch (error) {
            errors.push(error.message)
          }
        }
      }
    }
  }

  for (const [pageApi, count] of perPageCounts) {
    if (count === 1) continue
    const [pageCode, api] = JSON.parse(pageApi)
    errors.push(`${api} has ${count} actions on ${pageCode}; exactly one per page is required`)
  }

  // OpenAPI -> manifest: every capability-guarded write operation must have
  // exactly one action unless it is named in the explicit gap list.
  const covered = coveredApis
  const gapByApi = new Map()
  const duplicateGaps = new Set()
  for (const gap of gaps) {
    if (gapByApi.has(gap.api)) duplicateGaps.add(gap.api)
    gapByApi.set(gap.api, gap)
  }
  for (const api of duplicateGaps) errors.push(`gap ${api} is duplicated`)

  const pageApiOwners = new Map()
  for (const page of pages) {
    for (const api of page.requiredApis) {
      if (!pageApiOwners.has(api)) pageApiOwners.set(api, [])
      pageApiOwners.get(api).push(page.name)
    }
  }

  for (const [path, operations] of Object.entries(openapi.paths ?? {})) {
    for (const method of WRITE_METHOD_NAMES) {
      const operation = operations?.[method]
      if (operation === undefined || operation['x-capability'] === undefined) continue
      const api = `${method.toUpperCase()} ${path}`
      if (covered.has(api)) continue
      const gap = gapByApi.get(api)
      if (
        gap === undefined
        || typeof gap.owner !== 'string'
        || !GAP_OWNER_PATTERN.test(gap.owner)
        || (requireGapReason && (typeof gap.reason !== 'string' || gap.reason.trim() === ''))
      ) {
        errors.push(`OpenAPI write ${api} has no action`)
      }
    }
  }

  for (const gap of gaps) {
    if (!/^(POST|PUT|PATCH|DELETE) \//.test(String(gap.api))) errors.push(`gap has invalid api: ${gap.api}`)
    const { method, path } = splitApi(gap.api)
    const operation = operationFor(openapi, method, path)
    if (operation === undefined) {
      errors.push(`gap ${gap.api} does not match an OpenAPI operation`)
    } else if (operation['x-capability'] === undefined) {
      errors.push(`gap ${gap.api} does not match a capability-guarded OpenAPI write`)
    }
    if (typeof gap.owner !== 'string' || !GAP_OWNER_PATTERN.test(gap.owner)) {
      errors.push(`gap ${gap.api} has invalid owner: ${String(gap.owner)}`)
    }
    if (gap.reason !== undefined && (typeof gap.reason !== 'string' || gap.reason.trim() === '')) {
      errors.push(`gap ${gap.api} has empty reason`)
    }
    if (requireGapReason && (typeof gap.reason !== 'string' || gap.reason.trim() === '')) {
      errors.push(`gap ${gap.api} is missing reason`)
    }
    if (covered.has(gap.api)) errors.push(`gap ${gap.api} is already covered by an action; remove the gap`)
    const owners = pageApiOwners.get(gap.api)
    if (owners !== undefined && !covered.has(gap.api)) {
      errors.push(`gap ${gap.api} is declared by page ${owners.join(', ')}; it must have an action`)
    }
  }

  for (const exception of publicWriteExceptions) {
    const page = pageByName.get(exception.pageCode)
    if (page === undefined) {
      errors.push(`public write exception references unknown page ${exception.pageCode}`)
      continue
    }
    if (!page.requiredApis.includes(exception.api)) {
      errors.push(`public write exception ${exception.api} is not in ${exception.pageCode} requiredApis`)
      continue
    }
    const { method, path } = splitApi(exception.api)
    const operation = operationFor(openapi, method, path)
    if (operation === undefined) {
      errors.push(`public write exception ${exception.api} is not documented in OpenAPI`)
      continue
    }
    if (operation.operationId !== exception.operationId) {
      errors.push(`public write exception operationId ${String(operation.operationId)} != ${exception.operationId}`)
      continue
    }
    if (!Array.isArray(operation.security) || operation.security.length > 0) {
      errors.push(`public write exception ${exception.api} must explicitly declare security: []`)
      continue
    }
    publicWriteExceptionsUsed.push(`${exception.pageCode}: ${exception.api}`)
  }

  return { ok: errors.length === 0, errors, publicWriteExceptionsUsed }
}

/**
 * Validate the separately tracked page capability gaps. A gap entry is only
 * legitimate when:
 *   - its capability exists in CapabilityCatalog;
 *   - every listed page exists in the registry and actually declares the
 *     capability in PermissionResourceCatalog (`page.actions`);
 *   - no OpenAPI operation carries the exact x-capability yet (otherwise the
 *     gap is stale and the capability must become a real page action);
 *   - it has a one-line reason and an explicit Volume 2/3 owner.
 * This section never exempts a capability-guarded write; it tracks missing
 * read/export/admin contracts only.
 */
export function validateCapabilityGaps({
  capabilityGaps = [],
  pages,
  catalog,
  openapi,
  permissionPages,
  requireReason = true,
}) {
  const errors = []
  if (!Array.isArray(capabilityGaps)) return { ok: false, errors: ['capabilityGaps must be an array'], retained: [], resolved: [] }
  if (!Array.isArray(pages)) errors.push('pages must be an array')
  if (!Array.isArray(catalog)) errors.push('catalog must be an array')

  const catalogCodes = new Set(catalog.map((entry) => (typeof entry === 'string' ? entry : entry.code)))
  const pageNames = new Set((pages ?? []).map((page) => page.name))
  const permissionByName = new Map((permissionPages ?? []).map((page) => [page.page_code, page]))
  const opByCapability = new Map()
  for (const [path, operations] of Object.entries(openapi?.paths ?? {})) {
    for (const method of ['get', 'post', 'put', 'patch', 'delete']) {
      const operation = operations?.[method]
      const capability = operation?.['x-capability']
      if (typeof capability !== 'string') continue
      opByCapability.set(capability, [...(opByCapability.get(capability) ?? []), `${method.toUpperCase()} ${path}`])
    }
  }

  const seen = new Set()
  const retained = []
  const resolved = []
  for (const gap of capabilityGaps) {
    const capability = gap?.capability
    const label = typeof capability === 'string' && capability !== '' ? capability : '(missing capability)'
    if (typeof capability !== 'string' || !CAPABILITY_PATTERN.test(capability)) {
      errors.push(`gap ${label} has an invalid capability`)
      continue
    }
    if (seen.has(capability)) errors.push(`duplicate capability gap ${capability}`)
    seen.add(capability)
    if (!catalogCodes.has(capability)) errors.push(`gap ${capability} is not in CapabilityCatalog`)
    if (typeof gap.owner !== 'string' || !GAP_OWNER_PATTERN.test(gap.owner)) {
      errors.push(`gap ${capability} has invalid owner: ${String(gap.owner)}`)
    }
    if (requireReason && (typeof gap.reason !== 'string' || gap.reason.trim() === '' || /[\r\n]/.test(gap.reason))) {
      errors.push(`gap ${capability} must have a one-line reason`)
    }
    if (!Array.isArray(gap.pages) || gap.pages.length === 0) {
      errors.push(`gap ${capability} must list at least one page`)
      continue
    }
    for (const pageName of gap.pages) {
      if (!pageNames.has(pageName)) errors.push(`gap ${capability} references unknown page ${pageName}`)
      if (permissionPages !== undefined) {
        const permissionPage = permissionByName.get(pageName)
        if (permissionPage !== undefined && !(permissionPage.actions ?? []).includes(capability)) {
          errors.push(`gap ${capability} is not declared by page ${pageName} in PermissionResourceCatalog`)
        }
      }
    }
    const operations = opByCapability.get(capability)
    if (operations !== undefined && operations.length > 0) {
      errors.push(`stale capability gap ${capability}: OpenAPI already carries it on ${operations.join(', ')}; add the page action and remove the gap`)
      continue
    }
    retained.push(gap)
  }

  return { ok: errors.length === 0, errors, retained, resolved }
}

/**
 * Enforce the frozen permission-workbench shape. Writable pages need at least
 * one action and at least one write API; the read-only permission catalogue
 * must have neither. Pages not yet present in the registry are explicit skips
 * owned by the landing task, so this tightens automatically once they land.
 */
export function validateWorkbenchCoverage({ pages, manifest, serviceIndex }) {
  const errors = []
  const manifestPages = manifest?.pages ?? {}
  const pageByName = new Map((pages ?? []).map((page) => [page.name, page]))
  const skipped = []
  let writableChecked = 0
  let readOnlyChecked = 0

  for (const [pageCode, spec] of Object.entries(WORKBENCH_PAGES)) {
    const page = pageByName.get(pageCode)
    if (page === undefined) {
      skipped.push({
        pageCode,
        owner: spec.owner,
        reason: 'page is not yet present in PAGE_REGISTRY; the owning task has not landed it',
      })
      continue
    }
    const actions = manifestPages[pageCode] ?? []
    if (!Array.isArray(actions)) {
      errors.push(`${pageCode}: manifest actions must be an array`)
      continue
    }
    const writeApis = (page.requiredApis ?? []).filter(isWriteApi)

    if (spec.mode === 'read-only') {
      readOnlyChecked += 1
      if (actions.length !== 0) errors.push(`${pageCode} is read-only but declares ${actions.length} action(s)`)
      if (writeApis.length !== 0) errors.push(`${pageCode} is read-only but declares write API ${writeApis.join(', ')}`)
      continue
    }

    if (writeApis.length === 0) {
      skipped.push({
        pageCode,
        owner: spec.owner,
        reason: 'writable page has no declared write API yet; the owning task has not landed its governance write declaration',
      })
      continue
    }
    writableChecked += 1
    if (actions.length === 0) {
      errors.push(`${pageCode} is writable but has no action`)
      continue
    }
    const actionApis = new Set(actions.map((action) => action.api))
    for (const writeApi of writeApis) {
      if (!actionApis.has(writeApi)) errors.push(`${pageCode} is writable but write API ${writeApi} has no action`)
    }
    if (SERVICE_MANDATORY_PAGES.includes(pageCode) && serviceIndex !== undefined) {
      for (const action of actions) {
        if (action.services === undefined) {
          errors.push(`${pageCode}/${action.key}: must declare at least one backend service`)
          continue
        }
        try {
          assertServiceReferences(action.services, serviceIndex, `${pageCode}/${action.key}`)
        } catch (error) {
          errors.push(error.message)
        }
      }
    }
  }

  return { ok: errors.length === 0, errors, skipped, writableChecked, readOnlyChecked }
}

export function serializeManifestJson(manifest) {
  return `${JSON.stringify(manifest, null, 2)}\n`
}

export function renderPageActionsTs(manifest) {
  return [
    '// frontend/src/generated/pageActions.ts — generated by scripts/generate_action_manifest.mjs',
    '// Do not edit by hand. Run `node scripts/generate_action_manifest.mjs`.',
    '',
    'export type GeneratedPageAction = {',
    '  key: string',
    '  pageCode: string',
    '  label: string',
    '  capability: string',
    '  api: string',
    "  ui: 'primary' | 'secondary' | 'danger' | 'hidden'",
    "  confirm?: 'none' | 'danger' | 'sod'",
    '  services?: string[]',
    '  anchorTypes: string[]',
    '  fieldProfileMax: string',
    '}',
    '',
    `export const PAGE_ACTIONS: Record<string, GeneratedPageAction[]> = ${JSON.stringify(manifest.pages, null, 2)}`,
    '',
  ].join('\n')
}

function read(relativePath) {
  return readFileSync(resolve(repoRoot, relativePath), 'utf8')
}

function relative(absolutePath) {
  return resolve(absolutePath).slice(repoRoot.length + 1).replaceAll('\\', '/')
}

export function runCli() {
  const jsonOutput = resolve(repoRoot, 'docs/generated/page-actions.json')
  const tsOutput = resolve(repoRoot, 'frontend/src/generated/pageActions.ts')
  const outputPaths = [relative(jsonOutput), relative(tsOutput)]

  try {
    const pages = parseRegistryPages(read('frontend/src/router/registry.ts'))
    const catalog = parseCapabilityCatalog(read('backend/app/Modules/IAM/Authorization/CapabilityCatalog.php'))
    const openapi = parseOpenApi(read('docs/openapi/openapi.yaml'))
    const actionInput = JSON.parse(read('docs/generated/action-input.json'))
    const gapDocument = JSON.parse(read('docs/audit/2026-11-26-v2-action-gaps.json'))
    const actions = actionInput.actions
    const gaps = gapDocument.gaps
    const capabilityGaps = gapDocument.capabilityGaps ?? []
    if (!Array.isArray(actions)) throw new Error('action-input.json must contain an actions array')
    if (!Array.isArray(gaps)) throw new Error('gap document must contain a gaps array')
    if (!Array.isArray(capabilityGaps)) throw new Error('gap document capabilityGaps must be an array')
    const serviceIndex = buildServiceIndex()

    const manifest = buildActionManifest({ pages, catalog, openapi, actions, gaps, serviceIndex })
    const validation = validateActionManifest({
      pages,
      manifest,
      openapi,
      gaps,
      requireGapReason: true,
      publicWriteExceptions: PUBLIC_WRITE_EXCEPTIONS,
      serviceIndex,
    })
    const capabilityValidation = validateCapabilityGaps({
      capabilityGaps,
      pages,
      catalog,
      openapi,
      requireReason: true,
    })
    const workbenchValidation = validateWorkbenchCoverage({ pages, manifest, serviceIndex })
    const errors = [...validation.errors, ...capabilityValidation.errors, ...workbenchValidation.errors]
    if (errors.length > 0) {
      console.error('action manifest FAIL')
      for (const error of errors) console.error(`  - ${error}`)
      console.error(`not written: ${outputPaths.join(', ')}`)
      process.exitCode = 1
      return { ok: false, errors }
    }

    writeFileSync(jsonOutput, serializeManifestJson(manifest), 'utf8')
    writeFileSync(tsOutput, renderPageActionsTs(manifest), 'utf8')
    console.log(`wrote ${outputPaths[0]}`)
    console.log(`wrote ${outputPaths[1]}`)
    for (const skip of workbenchValidation.skipped) {
      console.log(`workbench skip ${skip.pageCode} (${skip.owner}): ${skip.reason}`)
    }
    console.log(
      `action manifest OK (${Object.keys(manifest.pages).length} pages, `
      + `${Object.values(manifest.pages).flat().length} actions, ${gaps.length} explicit write gaps, `
      + `${capabilityValidation.retained.length} owned capability gaps)`,
    )
    return { ok: true, errors: [], manifest }
  } catch (error) {
    console.error(`action manifest FAIL: ${error.message}`)
    console.error(`not written: ${outputPaths.join(', ')}`)
    process.exitCode = 1
    return { ok: false, errors: [error.message] }
  }
}

if (process.argv[1] !== undefined && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  runCli()
}
