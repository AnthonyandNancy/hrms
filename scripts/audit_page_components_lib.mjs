/**
 * Page component uniqueness gate.
 *
 * Every page registry key must resolve to exactly one existing .vue file.
 * Module-owned pages declare their route targets in
 * frontend/src/modules/<domain>/pages/index.ts via PAGE_COMPONENTS. Base pages
 * live in frontend/src/views and are discovered from BASE_VIEWS in
 * frontend/src/router/pageComponents.ts.
 *
 * Run from the repository root:
 *   node scripts/audit_page_components.mjs
 *
 * The gate intentionally fails until every registry page has a dedicated
 * component. It is wired into audit_route_coverage.mjs as a warning during
 * scaffolding and as a release failure with --require-mature.
 */
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { dirname, isAbsolute, join, relative, resolve, sep } from 'node:path'
import { fileURLToPath } from 'node:url'
import { pathToFileURL } from 'node:url'

const EXPECTED_REGISTRY_COUNT = 161
const accountSecurityView = 'frontend/src/views/AccountSecurityView.vue'

/**
 * The only permitted page-level reuse in the registry.
 *
 * MFA enrollment and session management are two sections of one account
 * security screen. Any other shared page file, or a third page joining this
 * group, fails the gate.
 */
export const DEFAULT_SHARED_ALLOWLIST = [
  {
    keys: ['base/AccountMfaView', 'base/AccountSessionsView'],
    file: accountSecurityView,
    reason:
      'MFA enrollment and session management are two sections of the single ' +
      'AccountSecurityView account-security screen.',
  },
]

function toPosix(path) {
  return path.split(sep).join('/')
}

function stripComments(source) {
  let output = ''
  let index = 0
  let quote = null

  while (index < source.length) {
    const character = source[index]
    const next = source[index + 1]

    if (quote !== null) {
      output += character
      if (character === '\\') {
        output += next ?? ''
        index += 2
        continue
      }
      if (character === quote) quote = null
      index += 1
      continue
    }

    if (character === "'" || character === '"' || character === '`') {
      quote = character
      output += character
      index += 1
      continue
    }

    if (character === '/' && next === '/') {
      output += '  '
      index += 2
      while (index < source.length && source[index] !== '\n') {
        output += ' '
        index += 1
      }
      continue
    }

    if (character === '/' && next === '*') {
      output += '  '
      index += 2
      while (index < source.length && !(source[index] === '*' && source[index + 1] === '/')) {
        output += source[index] === '\n' ? '\n' : ' '
        index += 1
      }
      if (index < source.length) {
        output += '  '
        index += 2
      }
      continue
    }

    output += character
    index += 1
  }

  return output
}

function readStringLiteral(source, start) {
  const quote = source[start]
  if (quote !== "'" && quote !== '"' && quote !== '`') return null

  let value = ''
  let index = start + 1

  while (index < source.length) {
    const character = source[index]

    if (character === '\\') {
      const escaped = source[index + 1]
      const escapes = { n: '\n', r: '\r', t: '\t', b: '\b', f: '\f', v: '\v', 0: '\0' }
      value += escaped !== undefined ? (escapes[escaped] ?? escaped) : ''
      index += 2
      continue
    }

    if (character === quote) {
      return { value, end: index + 1 }
    }

    value += character
    index += 1
  }

  return null
}

function skipWhitespace(source, start) {
  let index = start
  while (index < source.length && /\s/.test(source[index])) index += 1
  return index
}

function findBalancedBlock(source, openIndex, open, close) {
  if (source[openIndex] !== open) return null

  let depth = 0
  let index = openIndex
  let quote = null

  while (index < source.length) {
    const character = source[index]

    if (quote !== null) {
      if (character === '\\') {
        index += 2
        continue
      }
      if (character === quote) quote = null
      index += 1
      continue
    }

    if (character === "'" || character === '"' || character === '`') {
      quote = character
      index += 1
      continue
    }

    if (character === open) depth += 1
    if (character === close) {
      depth -= 1
      if (depth === 0) return { body: source.slice(openIndex + 1, index), end: index + 1 }
    }

    index += 1
  }

  return null
}

function findAssignedBlock(source, name, open) {
  const assignment = new RegExp(`(?:export\\s+)?const\\s+${name}\\b[^=]*=\\s*`)
  const match = assignment.exec(source)
  if (match === null) return null

  const openIndex = skipWhitespace(source, match.index + match[0].length)
  const close = open === '[' ? ']' : '}'
  return findBalancedBlock(source, openIndex, open, close)
}

function parseComponentKeyValues(block) {
  const keys = []
  const errors = []
  const source = block
  let index = 0

  while (index < source.length) {
    const character = source[index]

    if (character === "'" || character === '"' || character === '`') {
      const literal = readStringLiteral(source, index)
      if (literal === null) {
        errors.push(`unterminated string while parsing componentKey near offset ${index}`)
        break
      }

      let after = skipWhitespace(source, literal.end)
      if (literal.value === 'componentKey' && source[after] === ':') {
        after = skipWhitespace(source, after + 1)
        const value = readStringLiteral(source, after)
        if (value === null) {
          errors.push(`componentKey must be a string literal (near offset ${after})`)
        } else {
          keys.push(value.value)
          index = value.end
          continue
        }
      }

      index = literal.end
      continue
    }

    if (/[A-Za-z_$]/.test(character)) {
      let end = index + 1
      while (end < source.length && /[A-Za-z0-9_$]/.test(source[end])) end += 1
      const identifier = source.slice(index, end)

      if (identifier === 'componentKey') {
        let after = skipWhitespace(source, end)
        if (source[after] === ':') {
          after = skipWhitespace(source, after + 1)
          const value = readStringLiteral(source, after)
          if (value === null) {
            errors.push(`componentKey must be a string literal (near offset ${after})`)
          } else {
            keys.push(value.value)
            index = value.end
            continue
          }
        }
      }

      index = end
      continue
    }

    index += 1
  }

  return { keys, errors }
}

/**
 * Parse registry componentKey values from the PAGE_REGISTRY array.
 *
 * Only PAGE_REGISTRY is inspected, comments are ignored, and non-string
 * componentKey values fail closed with an error.
 */
export function parseRegistryComponentKeys(source) {
  const stripped = stripComments(source)
  const block = findAssignedBlock(stripped, 'PAGE_REGISTRY', '[')

  if (block === null) {
    return { keys: [], errors: ['PAGE_REGISTRY array not found'] }
  }

  return parseComponentKeyValues(block.body)
}

function parseLoaderEntries(source) {
  const entries = []
  const errors = []
  const pattern =
    /(['"])([^'"]+)\1\s*:\s*(?:async\s*)?\(\s*\)\s*=>\s*import\s*\(\s*(['"])([^'"]+)\3\s*\)/g

  for (const match of source.matchAll(pattern)) {
    entries.push({ key: match[2], importPath: match[4] })
  }

  if (entries.length === 0 && /PAGE_COMPONENTS/.test(source)) {
    errors.push('PAGE_COMPONENTS does not contain any direct () => import(...) loaders')
  }

  return { entries, errors }
}

function resolveComponentPath({ root, declaringFile, importPath, moduleManifest }) {
  const frontendRoot = join(root, 'frontend')

  if (importPath.startsWith('@/')) {
    return resolve(frontendRoot, 'src', importPath.slice(2))
  }

  if (importPath.startsWith('./') || importPath.startsWith('../')) {
    const declaringDir = dirname(declaringFile)
    const normalizedImport = moduleManifest ? importPath.replace(/^\.\/pages\//, './') : importPath
    return resolve(declaringDir, normalizedImport)
  }

  if (isAbsolute(importPath)) return resolve(importPath)
  return null
}

function addDiscoveredEntry({
  root,
  manifest,
  existingFiles,
  errors,
  seenKeys,
  key,
  importPath,
  declaringFile,
  moduleManifest,
}) {
  if (seenKeys.has(key)) {
    errors.push(`${key}: duplicate PAGE_COMPONENTS key in ${toPosix(relative(root, declaringFile))}`)
    return
  }
  seenKeys.add(key)

  const absolutePath = resolveComponentPath({ root, declaringFile, importPath, moduleManifest })
  if (absolutePath === null) {
    errors.push(
      `${key}: unsupported component import "${importPath}" in ${toPosix(relative(root, declaringFile))}`,
    )
    return
  }

  const repoRelative = toPosix(relative(root, absolutePath))
  manifest[key] = repoRelative

  if (existsSync(absolutePath) && statSync(absolutePath).isFile()) {
    existingFiles.add(repoRelative)
  }
}

/**
 * Discover page component mappings without importing application code.
 *
 * Module manifests are read from every frontend/src/modules/* /pages/index.ts
 * file, and the six base-* page targets are read from BASE_VIEWS in
 * frontend/src/router/pageComponents.ts. Business/platform entries in
 * BASE_VIEWS are intentionally ignored: those pages must move to module
 * manifests. Returned paths are repository-relative POSIX paths.
 */
export function discoverPageComponentManifest({ root = process.cwd() } = {}) {
  const manifest = {}
  const moduleManifest = {}
  const baseManifest = {}
  const existingFiles = new Set()
  const errors = []
  const seenKeys = new Set()

  const modulesDir = join(root, 'frontend/src/modules')
  if (existsSync(modulesDir) && statSync(modulesDir).isDirectory()) {
    const moduleNames = readdirSync(modulesDir)
      .filter((name) => {
        const moduleDir = join(modulesDir, name)
        return statSync(moduleDir).isDirectory() && existsSync(join(moduleDir, 'pages/index.ts'))
      })
      .sort()

    for (const moduleName of moduleNames) {
      const indexPath = join(modulesDir, moduleName, 'pages/index.ts')
      const source = stripComments(readFileSync(indexPath, 'utf8'))
      const block = findAssignedBlock(source, 'PAGE_COMPONENTS', '{')

      if (block === null) {
        errors.push(`${moduleName}: pages/index.ts must export const PAGE_COMPONENTS = { ... }`)
        continue
      }

      const parsed = parseLoaderEntries(block.body)
      errors.push(...parsed.errors.map((error) => `${moduleName}: ${error}`))

      for (const entry of parsed.entries) {
        const before = manifest[entry.key]
        addDiscoveredEntry({
          root,
          manifest,
          existingFiles,
          errors,
          seenKeys,
          key: entry.key,
          importPath: entry.importPath,
          declaringFile: indexPath,
          moduleManifest: true,
        })
        if (manifest[entry.key] !== undefined) moduleManifest[entry.key] = manifest[entry.key]
        if (before !== undefined) delete moduleManifest[entry.key]
      }
    }
  }

  const pageComponentsPath = join(root, 'frontend/src/router/pageComponents.ts')
  if (!existsSync(pageComponentsPath)) {
    errors.push('frontend/src/router/pageComponents.ts does not exist; base page targets are undiscoverable')
  } else {
    const source = stripComments(readFileSync(pageComponentsPath, 'utf8'))
    const block = findAssignedBlock(source, 'BASE_VIEWS', '{')

    if (block === null) {
      errors.push('frontend/src/router/pageComponents.ts must declare BASE_VIEWS')
    } else {
      const parsed = parseLoaderEntries(block.body)
      errors.push(...parsed.errors.map((error) => `BASE_VIEWS: ${error}`))

      for (const entry of parsed.entries) {
        // Business/platform pages are required to live in module manifests.
        if (!entry.key.startsWith('base/')) continue

        addDiscoveredEntry({
          root,
          manifest,
          existingFiles,
          errors,
          seenKeys,
          key: entry.key,
          importPath: entry.importPath,
          declaringFile: pageComponentsPath,
          moduleManifest: false,
        })
        if (manifest[entry.key] !== undefined) baseManifest[entry.key] = manifest[entry.key]
      }
    }
  }

  return { manifest, moduleManifest, baseManifest, existingFiles, errors }
}

function normalizeManifest(manifest) {
  if (manifest instanceof Map) return Object.fromEntries(manifest.entries())
  return manifest ?? {}
}

function allowlistKeys(entry) {
  if (Array.isArray(entry?.keys)) return entry.keys
  if (entry?.keys instanceof Set) return [...entry.keys]
  return []
}

function sameKeys(left, right) {
  if (left.length !== right.length) return false
  const rightSet = new Set(right)
  return left.every((key) => rightSet.has(key))
}

/**
 * Audit registry keys against a component-key -> repository-relative file map.
 */
export function auditPageComponents({
  registryKeys = [],
  manifest = {},
  existingFiles = new Set(),
  sharedAllowlist = DEFAULT_SHARED_ALLOWLIST,
  expectedRegistryCount,
} = {}) {
  const failures = []
  const missingMappings = []
  const missingFiles = []
  const staleManifestKeys = []
  const allowedSharedGroups = []
  const byFile = new Map()
  const files = existingFiles instanceof Set ? existingFiles : new Set(existingFiles)
  const components = normalizeManifest(manifest)
  const allowlist = Array.isArray(sharedAllowlist) ? sharedAllowlist : []

  if (expectedRegistryCount !== undefined && registryKeys.length !== expectedRegistryCount) {
    failures.push(
      `registry componentKeys=${registryKeys.length}, expected ${expectedRegistryCount}`,
    )
  }

  const counts = new Map()
  for (const key of registryKeys) counts.set(key, (counts.get(key) ?? 0) + 1)
  const duplicateRegistryKeys = [...counts.entries()]
    .filter(([, count]) => count > 1)
    .map(([key]) => key)
  if (duplicateRegistryKeys.length > 0) {
    failures.push(`duplicate registry componentKeys: ${duplicateRegistryKeys.join(', ')}`)
  }

  const registrySet = new Set(registryKeys)
  for (const key of Object.keys(components)) {
    if (!registrySet.has(key)) {
      staleManifestKeys.push(key)
      failures.push(`${key}: manifest key is not in the registry`)
    }
  }

  for (const key of new Set(registryKeys)) {
    const file = components[key]

    if (typeof file !== 'string' || file.trim() === '') {
      missingMappings.push(key)
      failures.push(`${key}: no page component mapping`)
      continue
    }

    if (!file.toLowerCase().endsWith('.vue')) {
      failures.push(`${key}: mapped file must be a .vue file (${file})`)
      continue
    }

    if (!files.has(file)) {
      missingFiles.push(key)
      failures.push(`${key}: mapped file does not exist (${file})`)
      continue
    }

    const users = byFile.get(file) ?? []
    users.push(key)
    byFile.set(file, users)
  }

  for (const [file, keys] of byFile) {
    if (keys.length < 2) continue

    const match = allowlist.find(
      (entry) => entry?.file === file && sameKeys(allowlistKeys(entry), keys),
    )

    if (match === undefined) {
      failures.push(`${file}: shared component used by ${keys.join(', ')}`)
      continue
    }

    if (typeof match.reason !== 'string' || match.reason.trim() === '') {
      failures.push(`${file}: shared component allowlist entry must document a reason`)
      continue
    }

    allowedSharedGroups.push(match)
  }

  return {
    ok: failures.length === 0,
    failures,
    missingMappings,
    missingFiles,
    staleManifestKeys,
    allowedSharedGroups,
    byFile,
  }
}

export function runCli() {
  const scriptDir = dirname(fileURLToPath(import.meta.url))
  const root = resolve(scriptDir, '..')
  const registryPath = join(root, 'frontend/src/router/registry.ts')
  const source = readFileSync(registryPath, 'utf8')
  const parsed = parseRegistryComponentKeys(source)
  const discovered = discoverPageComponentManifest({ root })
  const result = auditPageComponents({
    registryKeys: parsed.keys,
    manifest: discovered.manifest,
    existingFiles: discovered.existingFiles,
    expectedRegistryCount: EXPECTED_REGISTRY_COUNT,
  })

  const registrySet = new Set(parsed.keys)
  const moduleMapped = Object.keys(discovered.moduleManifest).filter((key) =>
    registrySet.has(key),
  ).length
  const baseMapped = Object.keys(discovered.baseManifest).filter((key) =>
    registrySet.has(key),
  ).length
  const failures = [...parsed.errors, ...discovered.errors, ...result.failures]

  const report = {
    ok: failures.length === 0,
    total: parsed.keys.length,
    expected: EXPECTED_REGISTRY_COUNT,
    // The module migration is the work this gate is driving. Base pages are
    // already dedicated views and are reported separately.
    mapped: moduleMapped,
    moduleMapped,
    baseMapped,
    mappedIncludingBase: moduleMapped + baseMapped,
    missingMappings: result.missingMappings,
    missingFiles: result.missingFiles,
    staleManifestKeys: result.staleManifestKeys,
    allowedSharedGroups: result.allowedSharedGroups.map((entry) => ({
      keys: entry.keys,
      file: entry.file,
      reason: entry.reason,
    })),
    failures,
  }

  process.stdout.write(`${JSON.stringify(report, null, 2)}\n`)
  if (!report.ok) process.exitCode = 1
}

const invokedDirectly =
  process.argv[1] !== undefined &&
  pathToFileURL(resolve(process.argv[1])).href === pathToFileURL(fileURLToPath(import.meta.url)).href

if (invokedDirectly) runCli()
