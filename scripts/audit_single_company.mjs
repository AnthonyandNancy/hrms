#!/usr/bin/env node
/**
 * Single-company runtime gate.
 *
 * ADR-0037 promises one company with no tenant or legal-entity identifiers in
 * runtime code. This gate scans the same roots as
 * `NoTenantNoLegalEntityTest` and reports every matching line as file:line.
 *
 * The only allowlists are:
 * - the forward-only migrations directory, whose job is to name legacy
 *   columns while removing them; and
 * - the exact `CompanyPreflightCommand::buildReport()` lines carrying
 *   `// single-company:preflight-only`.
 *
 * There is deliberately no file-wide or directory-wide runtime exemption. A
 * marker on one line never exempts the next line, another file or a directory.
 */
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { dirname, join, relative, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

export const FORBIDDEN = /tenant_id|legal_entity_id|LEGAL_ENTITY|X-Legal-Entity-Id|activeLegalEntityId/

export const PREFLIGHT_FILE =
  'backend/app/Console/Commands/CompanyPreflightCommand.php'

export const PREFLIGHT_MARKER = '// single-company:preflight-only'

/** Only forward-only migrations may name legacy columns at runtime. */
export const MIGRATIONS_DIRECTORY = 'backend/database/migrations/'

export const SCAN_ROOTS = [
  'backend/app',
  'backend/routes',
  'backend/database/seeders',
  'frontend/src',
  'docs/generated',
]

/** API contract surface scanned in addition to the roots above. */
export const SCAN_FILES = ['docs/openapi/openapi.yaml']

export const SCANNED_EXTENSIONS = new Set([
  'php',
  'ts',
  'tsx',
  'js',
  'mjs',
  'cjs',
  'vue',
  'json',
  'yaml',
  'yml',
  'md',
])

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..')

function toPosix(value) {
  return value.replaceAll('\\', '/')
}

function isMigrationPath(path) {
  return path.startsWith(MIGRATIONS_DIRECTORY)
}

function isExactPreflightMarkerLine(path, line) {
  return path === PREFLIGHT_FILE && line.includes(PREFLIGHT_MARKER)
}

/**
 * Pure line scanner used by both the fixture tests and the repository CLI.
 *
 * @param {{files?: Array<{path: string, source: string}>}} input
 * @returns {{ok: boolean, failures: string[]}}
 */
export function auditSingleCompany({ files = [] } = {}) {
  const failures = []

  for (const file of files) {
    const path = toPosix(file.path)
    if (isMigrationPath(path)) continue

    const lines = String(file.source).split(/\r?\n/)
    for (let index = 0; index < lines.length; index += 1) {
      const line = lines[index]
      if (isExactPreflightMarkerLine(path, line)) continue
      if (FORBIDDEN.test(line)) failures.push(`${path}:${index + 1}`)
    }
  }

  return { ok: failures.length === 0, failures }
}

function walk(directory, files) {
  if (!existsSync(directory)) return
  if (!statSync(directory).isDirectory()) return

  for (const entry of readdirSync(directory, { withFileTypes: true })) {
    const absolute = join(directory, entry.name)
    if (entry.isDirectory()) {
      walk(absolute, files)
      continue
    }
    if (!entry.isFile()) continue

    const extension = entry.name.includes('.') ? entry.name.split('.').pop() : ''
    if (!SCANNED_EXTENSIONS.has(extension)) continue
    files.push({
      path: toPosix(relative(repoRoot, absolute)),
      source: readFileSync(absolute, 'utf8'),
    })
  }
}

/**
 * Scan the real repository. Missing optional roots (for example
 * docs/generated before generation has run) are skipped, not treated as a
 * pass for a directory that contains a violation.
 */
export function auditRepository({ root = repoRoot } = {}) {
  const files = []

  for (const relativeRoot of SCAN_ROOTS) {
    walk(resolve(root, relativeRoot), files)
  }

  for (const relativeFile of SCAN_FILES) {
    const absolute = resolve(root, relativeFile)
    if (!existsSync(absolute) || !statSync(absolute).isFile()) continue
    files.push({
      path: toPosix(relative(root, absolute)),
      source: readFileSync(absolute, 'utf8'),
    })
  }

  files.sort((left, right) => left.path.localeCompare(right.path))
  return auditSingleCompany({ files })
}

function isMainModule() {
  if (process.argv[1] === undefined) return false
  return resolve(process.argv[1]) === fileURLToPath(import.meta.url)
}

function main() {
  let report
  try {
    report = auditRepository()
  } catch (error) {
    report = {
      ok: false,
      failures: [error instanceof Error ? error.message : String(error)],
    }
  }

  process.stdout.write(`${JSON.stringify(report, null, 2)}\n`)
  if (!report.ok) process.exitCode = 1
}

if (isMainModule()) main()
