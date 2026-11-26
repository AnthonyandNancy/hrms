#!/usr/bin/env node
/**
 * Page-model gate.
 *
 * A page is a task workspace, not an action. Create/edit/archive/import/export
 * operations must be declared as actions on a workspace, not registered as
 * routes. Page-internal tabs may only address the current page's own object;
 * module-wide navigation belongs in the global sidebar.
 *
 * The pure `auditPageModel` entry point is used by node:test fixtures. The CLI
 * reads the real registry and module templates and emits one deterministic
 * JSON report.
 */
import { readFileSync, readdirSync } from 'node:fs'
import { dirname, join, relative, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { parseRegistryPages } from './generate_route_disposition.mjs'

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..')

export const ACTION_LIKE = /\/(create|new|edit|editor|archive|restore|import|export)(\/|$)/
export const CROSS_MODULE_LABELS = new Set([
  '部门管理',
  '岗位管理',
  '员工异动',
  '劳动合同',
  '员工档案',
])

function toPosix(value) {
  return value.replaceAll('\\', '/')
}

function stripTags(value) {
  return value.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim()
}

/**
 * Pure page-model audit used by the fixture test and the repository CLI.
 */
export function auditPageModel({ pages = [], tabs = [] } = {}) {
  const failures = []
  for (const page of pages) {
    if (ACTION_LIKE.test(page.path)) failures.push(`${page.name}: action-like path ${page.path}`)
  }
  for (const entry of tabs) {
    for (const label of entry.labels) {
      if (CROSS_MODULE_LABELS.has(label) && label !== entry.page) {
        failures.push(`${entry.page}: cross-module tab ${label}`)
      }
    }
  }
  return { ok: failures.length === 0, failures }
}

/**
 * Extract tab-like navigation from Vue templates without executing Vue.
 *
 * A `<nav>` is considered tab-like when it has role="tablist" or a class/id
 * containing "nav" or "tab". Labels are read from static button/anchor text
 * and Element Plus `el-tab-pane` label attributes. This catches the legacy
 * module-wide shortcut nav while ignoring ordinary in-page links.
 */
export function extractTabEntries(files = []) {
  const entries = []
  for (const file of [...files].sort((left, right) => left.path.localeCompare(right.path))) {
    for (const nav of file.source.matchAll(/<nav\b([^>]*)>([\s\S]*?)<\/nav\s*>/gi)) {
      const attributes = nav[1]
      if (
        !/role\s*=\s*["']tablist["']/i.test(attributes) &&
        !/(?:class|id)\s*=\s*["'][^"']*(?:nav|tabs?)/i.test(attributes)
      ) {
        continue
      }

      const labels = []
      for (const item of nav[2].matchAll(/<(button|a)\b[^>]*>([\s\S]*?)<\/\1\s*>/gi)) {
        const label = stripTags(item[2])
        if (label !== '') labels.push(label)
      }
      for (const pane of nav[2].matchAll(/<el-tab-pane\b[^>]*\blabel\s*=\s*["']([^"']+)["']/gi)) {
        labels.push(pane[1].trim())
      }

      if (labels.length > 0) {
        entries.push({ page: file.path, labels: [...new Set(labels)] })
      }
    }
  }
  return entries
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
  const registryPath = join(root, 'frontend/src/router/registry.ts')
  const modulesPath = join(root, 'frontend/src/modules')
  const pages = parseRegistryPages(readFileSync(registryPath, 'utf8'))
  const files = walkVueFiles(modulesPath).map((absolute) => ({
    path: toPosix(relative(root, absolute)),
    source: readFileSync(absolute, 'utf8'),
  }))
  return auditPageModel({ pages, tabs: extractTabEntries(files) })
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
