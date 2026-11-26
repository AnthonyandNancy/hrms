#!/usr/bin/env node
/**
 * Page-action manifest gate.
 *
 * A page that consumes a write API must declare at least one PageActionSpec
 * whose endpoint exists in OpenAPI and whose capability is both code-owned by
 * CapabilityCatalog and exactly equal to the operation's x-capability.
 *
 * The single public write exception is the login page. /v1/auth/login is in
 * the backend public allowlist and intentionally has no x-capability; inventing
 * a capability for it would make the button/API contract untrue.
 */

import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import YAML from 'yaml'

const WRITE_VERBS = new Set(['POST', 'PUT', 'PATCH', 'DELETE'])
const METHOD_ORDER = ['get', 'post', 'put', 'patch', 'delete']
const CAPABILITY_PATTERN = /^[a-z][a-z0-9]*(\.[a-z0-9-]+)+$/
const ACTION_UI = new Set(['primary', 'secondary', 'danger', 'hidden'])

/**
 * Public allowlist exceptions are deliberately exact. A broad "public pages
 * skip auditing" rule would let a future write page evade this gate.
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

function auditError(message) {
  return `parse error: ${message}`
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
    if (char === "'" || char === '"' || char === '`') {
      if (char === '`') {
        const end = source.indexOf('`', index + 1)
        if (end === -1) throw new Error('unterminated template string')
        index = end + 1
        continue
      }
      index = readQuoted(source, index).end
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

function parseRegistryPages(source) {
  const registryMatch = /export\s+const\s+PAGE_REGISTRY\s*:\s*PageSpec\[\]\s*=\s*\[/.exec(source)
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
    const requiredApis = parseStringArray(
      requiredApisSource.body,
      'requiredApis',
      label,
    )
    let actions = []
    let hasActionsArray = false
    try {
      const actionSource = readArraySource(entry, 'actions', label)
      hasActionsArray = true
      actions = parseActionObjects(actionSource.body, label)
    } catch (error) {
      if (!/missing actions array/.test(error.message)) throw error
    }
    pages.push({
      path: readStringField(entry, 'path', label),
      name,
      capability: readStringField(entry, 'capability', label),
      requiredApis,
      actions,
      hasActionsArray,
    })
    index = end + 1
  }

  if (pages.length === 0) throw new Error('no registry pages found')
  return pages
}

function parseCapabilityCodes(source) {
  const codes = new Set()
  const pattern = /new\s+CapabilityDefinition\s*\(\s*(['"])([^'"]+)\1/g
  for (const match of source.matchAll(pattern)) codes.add(match[2])
  if (codes.size === 0) throw new Error('no CapabilityDefinition codes found')
  return codes
}

function parseOpenApi(source) {
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

function operationFor(document, method, path) {
  const item = document.paths[path]
  if (item === null || typeof item !== 'object') return null
  return item[method] ?? null
}

function validatePublicException(exception, page, writeApis, openapi, failures) {
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
  const operation = operationFor(openapi, method.toLowerCase(), path)
  if (operation === null) {
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

function splitApi(api) {
  const [method, ...pathParts] = api.trim().split(/\s+/)
  return { method: (method ?? '').toUpperCase(), path: pathParts.join(' ') }
}

/**
 * Pure audit entry point used by both the CLI and node:test fixtures.
 */
export function auditActionManifest({
  registrySource,
  catalogSource,
  openapiSource,
  publicWriteExceptions = PUBLIC_WRITE_EXCEPTIONS,
}) {
  const failures = []
  let pages
  let capabilities
  let openapi

  try {
    pages = parseRegistryPages(registrySource)
  } catch (error) {
    failures.push(auditError(error.message))
  }
  try {
    capabilities = parseCapabilityCodes(catalogSource)
  } catch (error) {
    failures.push(auditError(error.message))
  }
  try {
    openapi = parseOpenApi(openapiSource)
  } catch (error) {
    failures.push(auditError(error.message))
  }

  if (pages === undefined || capabilities === undefined || openapi === undefined) {
    return {
      ok: false,
      failures,
      pageCount: pages?.length ?? 0,
      writePageCount: 0,
      pagesWithActions: 0,
      actionCount: 0,
      publicWriteExceptionsUsed: [],
    }
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

    const writeApis = page.requiredApis.filter((api) => WRITE_VERBS.has(splitApi(api).method))
    if (writeApis.length === 0) continue
    writePageCount += 1
    if (page.actions.length > 0) pagesWithActions += 1
    actionCount += page.actions.length

    if (page.actions.length === 0) {
      const exception = exceptionByPage.get(page.name)
      if (exception !== undefined && validatePublicException(exception, page, writeApis, openapi, failures)) {
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
      if (!ACTION_UI.has(action.ui)) {
        failures.push(`${actionLabel}: invalid ui '${action.ui}'`)
      }
      if (action.confirm !== undefined && !['none', 'danger', 'sod'].includes(action.confirm)) {
        failures.push(`${actionLabel}: invalid confirm '${action.confirm}'`)
      }

      const { method, path } = splitApi(action.api)
      if (!WRITE_VERBS.has(method)) {
        failures.push(`${actionLabel}: write page action API must use POST/PUT/PATCH/DELETE, found '${method}'`)
      }
      if (!page.requiredApis.includes(action.api)) {
        failures.push(`${actionLabel}: API ${action.api} is not in requiredApis`)
      }
      coveredApis.add(action.api)

      const operation = operationFor(openapi, method.toLowerCase(), path)
      if (operation === null) {
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
    pageCount: pages.length,
    writePageCount,
    pagesWithActions,
    actionCount,
    publicWriteExceptionsUsed,
  }
}

function read(relativePath) {
  return readFileSync(resolve(repoRoot, relativePath), 'utf8')
}

function main() {
  let result
  try {
    result = auditActionManifest({
      registrySource: read('frontend/src/router/registry.ts'),
      catalogSource: read('backend/app/Modules/IAM/Authorization/CapabilityCatalog.php'),
      openapiSource: read('docs/openapi/openapi.yaml'),
    })
  } catch (error) {
    console.error(`actions:check FAIL: ${error.message}`)
    process.exitCode = 1
    return
  }

  if (!result.ok) {
    console.error('actions:check FAIL')
    for (const failure of result.failures) console.error(`  - ${failure}`)
    process.exitCode = 1
    return
  }

  console.log(
    `actions:check OK (${result.pageCount} pages, ${result.writePageCount} write pages, `
    + `${result.pagesWithActions} pages with actions, ${result.actionCount} actions, `
    + `${result.publicWriteExceptionsUsed.length} public write exceptions)`,
  )
}

if (process.argv[1] !== undefined && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  main()
}
