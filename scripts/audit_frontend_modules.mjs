#!/usr/bin/env node
/**
 * Frontend module boundary gate.
 *
 * Rules:
 * - pages under frontend/src/modules/<domain>/pages may only depend on their
 *   own module, documented shared layers, Vue packages and Element Plus;
 * - pages may not import the legacy src/views shells, another module's pages,
 *   axios, or call fetch directly;
 * - module components/ and api/ may not import src/views;
 * - shared components may not declare page identity props.
 *
 * Exceptions are documented in BOUNDARY_ALLOWLIST below. Every entry must name
 * one file, one prop or import specifier, and a non-empty reason.
 */
import { existsSync, readFileSync, readdirSync } from 'node:fs'
import { dirname, extname, join, relative, resolve, sep } from 'node:path'
import { fileURLToPath } from 'node:url'

export const BOUNDARY_ALLOWLIST = [
  {
    file: 'frontend/src/components/ActionGate.vue',
    type: 'prop',
    prop: 'mode',
    reason:
      "ActionGate mode selects disabled/denied presentation; it is not a page identity prop and Task 9A explicitly requires mode='disable'.",
  },
]

const PAGE_IDENTITY_PROPS = new Set(['componentKey', 'pageCode', 'mode', 'shellKey'])
const SHARED_LAYERS = new Set(['shared', 'components', 'api', 'stores', 'router', 'utils', 'types'])
const VUE_PACKAGES = new Set(['vue', 'vue-router', 'pinia', 'element-plus'])
const SCRIPT_BLOCK = /<script\b[^>]*>([\s\S]*?)<\/script\s*>/gi
const STRING_PLACEHOLDER = /__FACTORYHR_STRING_(\d+)__/g
const STRING_PLACEHOLDER_SOURCE = '__FACTORYHR_STRING_(\\d+)__'

function toPosix(value) {
  return value.replaceAll('\\', '/')
}

/**
 * auditImports accepts either raw source text or already extracted import
 * specifiers. Both shapes are supported so fixture tests can stay small while
 * the CLI uses the source-aware parser.
 */
export function auditImports(files, sharedComponents = []) {
  const failures = []
  const sharedFailures = []

  for (const file of files ?? []) {
    const filePath = toPosix(file.file ?? '<unknown>')
    const moduleName = moduleDomain(filePath)
    const isPage = isPageFile(filePath)
    const isComponentOrApi = isComponentOrApiFile(filePath)
    const references = collectReferences(file)

    for (const reference of references) {
      if (reference.typeOnly) continue

      if (isPage && isLegacyViewsPath(reference.specifier)) {
        report(failures, filePath, reference.specifier, 'forbidden import')
        continue
      }

      if (isComponentOrApi && isLegacyViewsPath(reference.specifier)) {
        report(failures, filePath, reference.specifier, 'forbidden import')
        continue
      }

      if (!isPage) continue

      if (isAxiosSpecifier(reference.specifier)) {
        report(failures, filePath, reference.specifier, 'forbidden axios import')
        continue
      }

      const aliasModule = aliasModuleDomain(reference.specifier)
      if (aliasModule !== null && aliasModule !== moduleName) {
        if (isPageSpecifier(reference.specifier)) {
          report(failures, filePath, reference.specifier, 'cross-module page import')
        } else {
          report(failures, filePath, reference.specifier, 'cross-module module import')
        }
        continue
      }

      if (reference.specifier.startsWith('.')) {
        const resolvedPath = resolveRelativeSpecifier(reference.specifier, filePath)
        if (isLegacyViewsPath(resolvedPath)) {
          report(failures, filePath, reference.specifier, 'forbidden import')
          continue
        }

        const targetModule = moduleDomain(resolvedPath)
        if (targetModule !== null && targetModule !== moduleName) {
          if (isPagePath(resolvedPath)) {
            report(failures, filePath, reference.specifier, 'cross-module page import')
          } else {
            report(failures, filePath, reference.specifier, 'cross-module module import')
          }
          continue
        }

        if (targetModule === moduleName && targetModule !== null) continue
        if (isAllowedSharedPath(srcRelativePath(resolvedPath))) continue

        failures.push(
          `${filePath}: forbidden import ${reference.specifier} (relative import escapes the page module)`,
        )
        continue
      }

      if (reference.specifier.startsWith('@/')) {
        if (aliasModule !== null) continue
        const aliasPath = reference.specifier.slice(2)
        if (isAllowedSharedPath(aliasPath)) continue
        failures.push(
          `${filePath}: forbidden import ${reference.specifier} (page modules may only use their own module or documented shared layers)`,
        )
        continue
      }

      if (!isAllowedPackage(reference.specifier)) {
        failures.push(
          `${filePath}: forbidden package import ${reference.specifier} (pages may only use Vue packages and Element Plus directly)`,
        )
      }
    }

    if (isPage) {
      for (const fetchLine of findFetchCalls(file)) {
        if (isAllowlisted(filePath, 'fetch', 'call')) continue
        failures.push(
          `${filePath}: forbidden fetch call${fetchLine === null ? '' : ` at line ${fetchLine}`}; use the module api layer`,
        )
      }
    }
  }

  for (const component of sharedComponents ?? []) {
    const componentPath = toPosix(component.file ?? '<unknown>')
    for (const prop of collectProps(component)) {
      if (!PAGE_IDENTITY_PROPS.has(prop)) continue
      if (isAllowlisted(componentPath, prop, 'prop')) continue
      sharedFailures.push(
        `${componentPath}: page identity prop ${prop} in shared component (use data/rows/variant instead)`,
      )
    }
  }

  return [...failures, ...sharedFailures]
}

function report(failures, filePath, specifier, label) {
  if (isAllowlisted(filePath, specifier, 'import')) return
  failures.push(`${filePath}: ${label} ${specifier}`)
}

function isAllowlisted(filePath, value, kind) {
  return BOUNDARY_ALLOWLIST.some((entry) => {
    if (entry === null || typeof entry !== 'object') return false
    if (typeof entry.reason !== 'string' || entry.reason.trim() === '') return false
    if (toPosix(entry.file ?? '') !== filePath) return false
    if (entry.type !== undefined && entry.type !== kind) return false
    return entry.specifier === value || entry.prop === value || entry.match === value
  })
}

function moduleDomain(filePath) {
  const match = toPosix(filePath).match(/(?:^|\/)modules\/([^/]+)(?:\/|$)/)
  return match?.[1] ?? null
}

function aliasModuleDomain(specifier) {
  const match = specifier.match(/^@\/modules\/([^/]+)(?:\/|$)/)
  return match?.[1] ?? null
}

function isPageFile(filePath) {
  return /(?:^|\/)modules\/[^/]+\/pages(?:\/|$)/.test(toPosix(filePath))
}

function isPagePath(filePath) {
  return /(?:^|\/)pages(?:\/|$)/.test(toPosix(filePath))
}

function isPageSpecifier(specifier) {
  return /(?:^|\/)pages(?:\/|$)/.test(toPosix(specifier))
}

function isComponentOrApiFile(filePath) {
  return /(?:^|\/)modules\/[^/]+\/(?:components|api)(?:\/|$)/.test(toPosix(filePath))
}

function isLegacyViewsPath(value) {
  const normalized = toPosix(value)
  if (normalized === '@/views' || normalized.startsWith('@/views/')) return true
  return /(?:^|\/)views(?:\/|$)/.test(normalized)
}

function isAxiosSpecifier(specifier) {
  return specifier === 'axios' || specifier.startsWith('axios/')
}

function isAllowedPackage(specifier) {
  if (specifier.startsWith('@vue/')) return true
  if (specifier.startsWith('@element-plus/')) return true
  const packageName = specifier.startsWith('@')
    ? specifier.split('/').slice(0, 2).join('/')
    : specifier.split('/')[0]
  if (VUE_PACKAGES.has(packageName)) return true
  return /^vue(?:-|$)/.test(packageName)
}

function resolveRelativeSpecifier(specifier, filePath) {
  const clean = specifier.split(/[?#]/, 1)[0]
  return toPosix(resolve(dirname(filePath), clean))
}

function srcRelativePath(filePath) {
  const normalized = toPosix(filePath)
  const marker = '/src/'
  if (normalized.includes(marker)) return normalized.slice(normalized.indexOf(marker) + marker.length)
  if (normalized.startsWith('src/')) return normalized.slice('src/'.length)
  return normalized
}

function isAllowedSharedPath(srcRelative) {
  const firstSegment = srcRelative.split('/')[0]
  return SHARED_LAYERS.has(firstSegment)
}

function collectReferences(file) {
  const references = []
  if (Array.isArray(file.imports)) {
    for (const item of file.imports) {
      if (typeof item === 'string') {
        references.push({ specifier: item, typeOnly: false })
      } else if (item !== null && typeof item === 'object' && typeof item.specifier === 'string') {
        references.push({ specifier: item.specifier, typeOnly: item.typeOnly === true })
      }
    }
  }
  if (typeof file.source === 'string') {
    references.push(...extractImports(file.source, file.file))
  }
  return dedupeReferences(references)
}

function dedupeReferences(references) {
  const seen = new Set()
  return references.filter((reference) => {
    const key = `${reference.typeOnly ? 'type' : 'value'}:${reference.specifier}`
    if (seen.has(key)) return false
    seen.add(key)
    return true
  })
}

function extractImports(source, filePath) {
  const script = scriptContent(source, filePath)
  const { code, strings } = maskCommentsAndStrings(script)
  const references = []

  // import type { Foo } from 'pkg' / import { type Foo } from 'pkg'
  for (const match of code.matchAll(
    new RegExp(
      `\\bimport\\s+(type\\s+)?([^;]*?)\\s+from\\s+${STRING_PLACEHOLDER_SOURCE}`,
      'g',
    ),
  )) {
    const explicitType = match[1] !== undefined
    const specifier = strings[Number(match[3])]
    if (specifier === undefined) continue
    references.push({
      specifier,
      typeOnly: explicitType || isTypeOnlyImportClause(match[2]),
    })
  }

  // Side-effect import: import 'pkg'
  for (const match of code.matchAll(
    new RegExp(`\\bimport\\s+(type\\s+)?${STRING_PLACEHOLDER_SOURCE}`, 'g'),
  )) {
    const specifier = strings[Number(match[2])]
    if (specifier !== undefined) {
      references.push({ specifier, typeOnly: match[1] !== undefined })
    }
  }

  // Dynamic import: import('pkg')
  for (const match of code.matchAll(
    new RegExp(`\\bimport\\s*\\(\\s*${STRING_PLACEHOLDER_SOURCE}\\s*\\)`, 'g'),
  )) {
    const specifier = strings[Number(match[1])]
    if (specifier !== undefined) references.push({ specifier, typeOnly: false })
  }

  // Re-exports: export type { Foo } from 'pkg' / export * from 'pkg'
  for (const match of code.matchAll(
    new RegExp(
      `\\bexport\\s+(type\\s+)?(\\*|\\{[\\s\\S]*?\\})\\s+from\\s+${STRING_PLACEHOLDER_SOURCE}`,
      'g',
    ),
  )) {
    const explicitType = match[1] !== undefined
    const specifier = strings[Number(match[3])]
    if (specifier === undefined) continue
    references.push({
      specifier,
      typeOnly: explicitType || isTypeOnlyExportClause(match[2]),
    })
  }

  // CommonJS require, including require('axios').
  for (const match of code.matchAll(
    new RegExp(`\\brequire\\s*\\(\\s*${STRING_PLACEHOLDER_SOURCE}\\s*\\)`, 'g'),
  )) {
    const specifier = strings[Number(match[1])]
    if (specifier !== undefined) references.push({ specifier, typeOnly: false })
  }

  return references
}

function scriptContent(source, filePath) {
  const cleaned = source.replace(/<!--[\s\S]*?-->/g, '')
  if (extname(filePath ?? '') !== '.vue') return cleaned
  const scripts = [...cleaned.matchAll(SCRIPT_BLOCK)].map((match) => match[1] ?? '')
  return scripts.length === 0 ? cleaned : scripts.join('\n')
}

function maskCommentsAndStrings(source) {
  const strings = []
  const output = []
  let index = 0

  while (index < source.length) {
    const char = source[index]
    const next = source[index + 1]

    if (char === '/' && next === '/') {
      index += 2
      while (index < source.length && source[index] !== '\n') index += 1
      output.push('\n')
      continue
    }

    if (char === '/' && next === '*') {
      index += 2
      while (index < source.length && !(source[index] === '*' && source[index + 1] === '/')) {
        if (source[index] === '\n') output.push('\n')
        index += 1
      }
      index += 2
      output.push(' ')
      continue
    }

    if (char === "'" || char === '"') {
      const start = index
      index += 1
      let value = ''
      while (index < source.length) {
        const current = source[index]
        if (current === '\\') {
          value += source[index + 1] ?? ''
          index += 2
          continue
        }
        if (current === char) {
          index += 1
          break
        }
        value += current
        index += 1
      }
      if (index === start + 1) index += 1
      output.push(`__FACTORYHR_STRING_${strings.length}__`)
      strings.push(value)
      continue
    }

    if (char === '`') {
      // Template literals are presentation strings for this gate. Interpolated
      // code is intentionally not treated as a dependency declaration.
      index += 1
      while (index < source.length) {
        if (source[index] === '\\') {
          index += 2
          continue
        }
        if (source[index] === '`') {
          index += 1
          break
        }
        index += 1
      }
      output.push(' ')
      continue
    }

    output.push(char)
    index += 1
  }

  return { code: output.join(''), strings }
}

function isTypeOnlyImportClause(clause) {
  const trimmed = clause.trim()
  if (!trimmed.startsWith('{') || !trimmed.endsWith('}')) return false
  return isTypeOnlySpecifierList(trimmed.slice(1, -1))
}

function isTypeOnlyExportClause(clause) {
  const trimmed = clause.trim()
  if (trimmed === '*') return false
  if (!trimmed.startsWith('{') || !trimmed.endsWith('}')) return false
  return isTypeOnlySpecifierList(trimmed.slice(1, -1))
}

function isTypeOnlySpecifierList(inner) {
  const specifiers = splitTopLevel(inner, ',')
    .map((specifier) => specifier.trim())
    .filter(Boolean)
  return specifiers.length > 0 && specifiers.every((specifier) => /^type\s+/.test(specifier))
}

function splitTopLevel(value, delimiter) {
  const parts = []
  let current = ''
  let brace = 0
  let bracket = 0
  let paren = 0
  for (const char of value) {
    if (char === '{') brace += 1
    else if (char === '}') brace -= 1
    else if (char === '[') bracket += 1
    else if (char === ']') bracket -= 1
    else if (char === '(') paren += 1
    else if (char === ')') paren -= 1
    if (char === delimiter && brace === 0 && bracket === 0 && paren === 0) {
      parts.push(current)
      current = ''
      continue
    }
    current += char
  }
  if (current !== '') parts.push(current)
  return parts
}

function findFetchCalls(file) {
  const source = typeof file.source === 'string' ? scriptContent(file.source, file.file) : ''
  if (source === '') return []
  const { code } = maskCommentsAndStrings(source)
  const lines = []
  for (const match of code.matchAll(/\bfetch\s*(?:\?\.\s*)?(?:<[^>\n]*>\s*)?\(/g)) {
    const before = code.slice(0, match.index)
    if (/function\s+$/.test(before)) continue
    lines.push(before.split('\n').length)
  }
  return lines
}

function collectProps(component) {
  const props = []
  if (Array.isArray(component.props)) {
    for (const prop of component.props) {
      if (typeof prop === 'string') props.push(prop)
    }
  }
  if (typeof component.source === 'string') {
    props.push(...extractDefinedProps(component.source, component.file))
  }
  return [...new Set(props)]
}

function extractDefinedProps(source, filePath) {
  const script = scriptContent(source, filePath)
  const { code, strings } = maskCommentsAndStrings(script)
  const props = []

  for (const match of code.matchAll(/defineProps\s*<\s*\{/g)) {
    const bodyStart = code.indexOf('{', match.index)
    if (bodyStart === -1) continue
    const bodyEnd = findBalancedBraceEnd(code, bodyStart)
    if (bodyEnd === -1) continue
    const body = code.slice(bodyStart + 1, bodyEnd)
    props.push(...parseTopLevelPropNames(body, strings))
  }

  return props
}

function findBalancedBraceEnd(code, openIndex) {
  let depth = 0
  for (let index = openIndex; index < code.length; index += 1) {
    const char = code[index]
    if (char === '{') depth += 1
    else if (char === '}') {
      depth -= 1
      if (depth === 0) return index
    }
  }
  return -1
}

function parseTopLevelPropNames(body, strings) {
  const names = []
  let index = 0
  let atMemberStart = true
  let curly = 0
  let bracket = 0
  let paren = 0

  while (index < body.length) {
    const char = body[index]

    if (char === '{') {
      curly += 1
      index += 1
      atMemberStart = false
      continue
    }
    if (char === '}') {
      curly -= 1
      index += 1
      continue
    }
    if (curly > 0) {
      index += 1
      continue
    }
    if (char === '[') bracket += 1
    else if (char === ']') bracket = Math.max(0, bracket - 1)
    else if (char === '(') paren += 1
    else if (char === ')') paren = Math.max(0, paren - 1)

    if (char === ';' || char === ',') {
      atMemberStart = bracket === 0 && paren === 0
      index += 1
      continue
    }

    if (char === '\n' && bracket === 0 && paren === 0) {
      const candidate = readPropNameAt(body, index + 1, strings)
      if (candidate !== null) {
        names.push(candidate.name)
        atMemberStart = false
        index = candidate.end
        continue
      }
    }

    if (atMemberStart && bracket === 0 && paren === 0) {
      if (/\s/.test(char)) {
        index += 1
        continue
      }
      const candidate = readPropNameAt(body, index, strings)
      if (candidate !== null) {
        names.push(candidate.name)
        atMemberStart = false
        index = candidate.end
        continue
      }
      atMemberStart = false
    }

    index += 1
  }

  return names
}

function readPropNameAt(body, start, strings) {
  let index = start
  while (index < body.length && /[ \t\r\n]/.test(body[index])) index += 1
  if (index >= body.length) return null

  if (body.startsWith('readonly', index) && !/[\w$]/.test(body[index + 'readonly'.length] ?? '')) {
    index += 'readonly'.length
    while (index < body.length && /[ \t\r\n]/.test(body[index])) index += 1
  }

  let name = null
  if (body[index] === '[') {
    let depth = 0
    while (index < body.length) {
      if (body[index] === '[') depth += 1
      else if (body[index] === ']') {
        depth -= 1
        if (depth === 0) {
          index += 1
          break
        }
      }
      index += 1
    }
  } else if (body.startsWith('__FACTORYHR_STRING_', index)) {
    STRING_PLACEHOLDER.lastIndex = 0
    const match = STRING_PLACEHOLDER.exec(body.slice(index))
    if (match === null || match.index !== 0) return null
    name = strings[Number(match[1])] ?? null
    index += match[0].length
  } else {
    const match = /^[A-Za-z_$][\w$-]*/.exec(body.slice(index))
    if (match === null) return null
    name = match[0]
    index += name.length
  }

  if (name === null) return null

  while (index < body.length && /[ \t\r\n]/.test(body[index])) index += 1
  if (body[index] === '?') {
    index += 1
    while (index < body.length && /[ \t\r\n]/.test(body[index])) index += 1
  }

  const suffix = body[index]
  if (suffix !== ':' && suffix !== '(' && suffix !== '<') return null
  return { name, end: index + 1 }
}

/**
 * @param {string} root Repository root (contains frontend/src).
 */
export function scanProject(root) {
  const resolvedRoot = resolve(root)
  const srcRoot = join(resolvedRoot, 'frontend', 'src')
  const modulesDir = join(srcRoot, 'modules')
  const sharedDirs = [join(srcRoot, 'shared'), join(srcRoot, 'components')]

  const moduleFiles = existsSync(modulesDir)
    ? walkFiles(modulesDir).filter((file) => file.endsWith('.ts') || file.endsWith('.vue'))
    : []
  const sharedComponents = sharedDirs.flatMap((directory) =>
    existsSync(directory) ? walkFiles(directory).filter((file) => file.endsWith('.vue')) : [],
  )

  const files = moduleFiles.map((absolutePath) => ({
    file: relative(resolvedRoot, absolutePath).split(sep).join('/'),
    source: readFileSync(absolutePath, 'utf8'),
  }))
  const components = sharedComponents.map((absolutePath) => ({
    file: relative(resolvedRoot, absolutePath).split(sep).join('/'),
    source: readFileSync(absolutePath, 'utf8'),
  }))

  const counts = {
    pagesScanned: files.filter((file) => isPageFile(file.file)).length,
    moduleComponentsScanned: files.filter((file) =>
      /(?:^|\/)modules\/[^/]+\/components(?:\/|$)/.test(file.file),
    ).length,
    moduleApiScanned: files.filter((file) =>
      /(?:^|\/)modules\/[^/]+\/api(?:\/|$)/.test(file.file),
    ).length,
    moduleOtherScanned: 0,
    sharedComponentsScanned: components.length,
  }
  counts.moduleOtherScanned =
    files.length -
    counts.pagesScanned -
    counts.moduleComponentsScanned -
    counts.moduleApiScanned

  const failures = auditImports(files, components)

  return {
    ok: failures.length === 0,
    scanned: files.length,
    ...counts,
    allowlistEntries: BOUNDARY_ALLOWLIST.length,
    failures,
  }
}

function walkFiles(directory) {
  const files = []
  for (const entry of readdirSync(directory, { withFileTypes: true })) {
    const absolute = join(directory, entry.name)
    if (entry.isDirectory()) files.push(...walkFiles(absolute))
    else if (entry.isFile()) files.push(absolute)
  }
  return files.sort()
}

function isMainModule() {
  if (process.argv[1] === undefined) return false
  return resolve(process.argv[1]) === fileURLToPath(import.meta.url)
}

function parseRootArgument(argv) {
  const index = argv.indexOf('--root')
  if (index === -1) return resolve(dirname(fileURLToPath(import.meta.url)), '..')
  const value = argv[index + 1]
  if (value === undefined || value.startsWith('--')) {
    throw new Error('--root requires a directory')
  }
  return resolve(value)
}

if (isMainModule()) {
  try {
    const report = scanProject(parseRootArgument(process.argv.slice(2)))
    console.log(JSON.stringify(report, null, 2))
    if (!report.ok) process.exitCode = 1
  } catch (error) {
    console.log(
      JSON.stringify(
        {
          ok: false,
          scanned: 0,
          failures: [error instanceof Error ? error.message : String(error)],
        },
        null,
        2,
      ),
    )
    process.exitCode = 1
  }
}
