#!/usr/bin/env node
/**
 * V1.1 production acceptance gate.
 *
 * Runs the eight non-negotiable production standards from the design's §12.1
 * and exits non-zero if ANY of them fails. A gate is never "skipped into a
 * pass": real-stack and migration rehearsals are mandatory unless the caller
 * explicitly opts out with V11_STRICT=0 AND accepts a non-zero exit.
 *
 * Evidence for every run is written to
 *   evidence/v11-production-gate-<stamp>.log
 * so a release can be reconstructed from the repository alone.
 */
import { spawnSync } from 'node:child_process'
import { appendFileSync, existsSync, mkdirSync, writeFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const php = process.env.V11_PHP ?? join(root, '.tools', 'php', 'php.exe')
const strict = process.env.V11_STRICT !== '0'

/**
 * Gates are declared as argv arrays, never as shell strings, so a path with a
 * space or a shell metacharacter can never change which command runs.
 */
const GATES = [
  {
    id: 'gap-zero',
    label: '63 backend gaps closed',
    command: [process.execPath, ['scripts/audit_route_coverage.mjs', '--require-mature', '--require-capabilities']],
  },
  {
    id: 'openapi',
    label: 'OpenAPI 3.1 validates and every operation has a schema',
    command: [process.execPath, ['deploy/scripts/openapi-lint.mjs']],
  },
  {
    id: 'capability',
    label: 'every route is capability-guarded and every code is issued',
    command: [php, ['artisan', 'capability:audit']],
    cwd: join(root, 'backend'),
  },
  {
    id: 'negative-tests',
    label: '12-cluster negative-test matrix',
    command: [php, ['artisan', 'test', '--compact', '--filter=GapCompletionCapabilityMatrixTest']],
    cwd: join(root, 'backend'),
  },
  {
    id: 'migration',
    label: 'forward-only migrations rehearse on an empty database',
    command: ['bash', ['deploy/scripts/migration_rehearsal.sh']],
  },
  {
    id: 'real-stack',
    label: 'real Docker stack serves 155 pages and release traceability',
    command: ['bash', ['deploy/scripts/verify_real_stack.sh']],
  },
  {
    id: 'release-trace',
    label: '/v1/meta reports the deployed commit',
    command: [process.execPath, ['scripts/check_release_traceability.mjs']],
  },
  {
    id: 'coverage',
    label: 'coverage matrix regenerates from the registry',
    command: [process.execPath, ['scripts/generate_coverage_matrix.mjs']],
  },
]

const stamp = new Date().toISOString().replace(/[:.]/g, '-')
const evidenceDir = join(root, 'evidence')
if (!existsSync(evidenceDir)) mkdirSync(evidenceDir, { recursive: true })
const logPath = join(evidenceDir, `v11-production-gate-${stamp}.log`)

const lines = [
  `# V1.1 production gate ${stamp}`,
  `strict=${strict}`,
  `commit=${currentCommit()}`,
  '',
]

function currentCommit() {
  const result = spawnSync('git', ['rev-parse', 'HEAD'], { cwd: root, encoding: 'utf8' })

  return result.status === 0 ? result.stdout.trim() : 'unknown'
}

function record(line) {
  lines.push(line)
  appendFileSync(logPath, `${line}\n`)
}

function run(gate) {
  const [file, args] = gate.command
  const started = Date.now()
  const result = spawnSync(file, args, {
    cwd: gate.cwd ?? root,
    encoding: 'utf8',
    env: { ...process.env, V11_GATE: '1' },
    maxBuffer: 64 * 1024 * 1024,
  })
  const elapsed = ((Date.now() - started) / 1000).toFixed(1)
  const ok = result.status === 0

  record(`[${ok ? 'PASS' : 'FAIL'}] ${gate.id} (${elapsed}s) — ${gate.label}`)
  record(`  $ ${file} ${args.join(' ')}`)

  if (!ok) {
    const output = `${result.stdout ?? ''}\n${result.stderr ?? ''}`.trim()
    record(`  exit=${String(result.status)}`)
    record(
      output
        .split(/\r?\n/)
        .slice(-60)
        .map((line) => `  | ${line}`)
        .join('\n'),
    )
  }

  return { id: gate.id, ok, elapsed }
}

let failed = false
const results = []

for (const gate of GATES) {
  const result = run(gate)
  results.push(result)

  if (!result.ok) {
    failed = true
    // Stop at the first failure so the log points at the root cause instead of
    // a cascade of downstream failures.
    break
  }
}

const notRun = GATES.slice(results.length).map((gate) => gate.id)

record('')
for (const result of results) {
  record(`${result.ok ? 'PASS' : 'FAIL'} ${result.id} ${result.elapsed}s`)
}
for (const id of notRun) {
  record(`SKIP ${id} (not reached)`)
}
record(`RESULT ${failed ? 'FAIL' : 'PASS'}`)

writeFileSync(logPath, lines.join('\n') + '\n', 'utf8')
console.log(`\nV1.1 production gate: ${failed ? 'FAIL' : 'PASS'}`)
console.log(`evidence: ${logPath}`)

if (failed) {
  process.exitCode = 1
}

if (!strict && failed) {
  console.error('V11_STRICT=0 only relaxes optional gates; required gates still failed.')
  process.exitCode = 1
}

if (strict && process.env.V11_SKIP_REAL_STACK === '1') {
  console.error('V11_SKIP_REAL_STACK is not allowed in strict mode.')
  process.exitCode = 1
}
