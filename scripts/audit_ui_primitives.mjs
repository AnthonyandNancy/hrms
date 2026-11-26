#!/usr/bin/env node
/**
 * UI primitive gate.
 *
 * Business page templates must use the shared presentation layer
 * (`PageShell`, `DataTable`, Element Plus form controls) instead of raw
 * table/input/select/details elements. Shared UI primitives outside the module
 * tree and module form components are out of scope for this gate.
 */
import { readFileSync, readdirSync } from 'node:fs'
import { dirname, join, relative, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const RAW_TAGS = ['<table', '<input', '<select', '<details']

function toPosix(value) {
  return value.replaceAll('\\', '/')
}

function lineOf(source, index) {
  return source.slice(0, index).split('\n').length
}

/** Pure primitive audit used by the fixture test and the repository CLI. */
export function auditUiPrimitives({ files = [] } = {}) {
  const failures = []
  for (const file of files) {
    const path = toPosix(file.path)
    if (!path.includes('/modules/')) continue
    if (path.includes('/components/') && !path.endsWith('Page.vue')) continue
    for (const tag of RAW_TAGS) {
      const index = file.source.indexOf(tag)
      if (index !== -1) failures.push(`${path}: raw ${tag.slice(1)} (line ${lineOf(file.source, index)})`)
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
  return auditUiPrimitives({ files })
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
