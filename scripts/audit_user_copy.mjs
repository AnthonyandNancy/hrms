#!/usr/bin/env node
/**
 * User-copy gate.
 *
 * User-facing Vue templates must not expose API paths, English enum codes,
 * development markers or internal field-profile codes. Technical evidence
 * belongs in audit documents and administrator-only technical detail areas.
 */
import { readFileSync, readdirSync } from 'node:fs'
import { dirname, join, relative, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..')

export const FORBIDDEN = [
  { pattern: /GET \/v1\/|POST \/v1\/|PATCH \/v1\/|DELETE \/v1\//, label: 'API path' },
  { pattern: /\b(ACTIVE|INACTIVE|PROBATION|TERMINATED|FULL_TIME|PART_TIME|MINOR_UNITS)\b/, label: 'English enum' },
  { pattern: /\b(Wave|TODO|TBD)\b/, label: 'development marker' },
  { pattern: /P[0-3]_(PUBLIC|INTERNAL|SENSITIVE|RESTRICTED)/, label: 'field profile code' },
]

function toPosix(value) {
  return value.replaceAll('\\', '/')
}

function lineOf(source, index) {
  return source.slice(0, index).split('\n').length
}

/** Pure copy audit used by the fixture test and the repository CLI. */
export function auditUserCopy({ files = [] } = {}) {
  const failures = []
  for (const file of files) {
    const path = toPosix(file.path)
    if (!path.endsWith('.vue')) continue
    if (path.includes('/tests/') || path.includes('/shared/ui/')) continue
    for (const rule of FORBIDDEN) {
      const match = rule.pattern.exec(file.source)
      if (match !== null) {
        failures.push(`${path}: ${rule.label} '${match[0]}' (line ${lineOf(file.source, match.index)})`)
      }
    }
  }
  return { ok: failures.length === 0, failures }
}

function walkVueFiles(directory) {
  const files = []
  for (const entry of readdirSync(directory, { withFileTypes: true })) {
    const absolute = join(directory, entry.name)
    if (entry.isDirectory()) files.push(...walkVueFiles(absolute))
    else if (entry.isFile() && entry.name.endsWith('.vue')) files.push(absolute)
  }
  return files.sort()
}

export function auditRepository({ root = repoRoot } = {}) {
  const modulesPath = join(root, 'frontend/src/modules')
  const files = walkVueFiles(modulesPath).map((absolute) => ({
    path: toPosix(relative(root, absolute)),
    source: readFileSync(absolute, 'utf8'),
  }))
  return auditUserCopy({ files })
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
    report = { ok: false, failures: [error instanceof Error ? error.message : String(error)] }
  }
  process.stdout.write(`${JSON.stringify(report, null, 2)}\n`)
  if (!report.ok) process.exitCode = 1
}

if (isMainModule()) main()
