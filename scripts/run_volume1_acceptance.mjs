#!/usr/bin/env node
/**
 * Reusable Volume 1 acceptance recorder.
 *
 * This is intentionally a recorder, not a build orchestrator: `main()` exits 0
 * even when product gates are red, and the generated report states a strict
 * conclusion. Environment-only probes (Docker/real stack) are listed separately
 * and never turn a green product result into a red one.
 */
import { execFileSync } from 'node:child_process'
import { closeSync, mkdirSync, mkdtempSync, openSync, readSync, rmSync, statSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const reportPath = resolve(repoRoot, 'docs/audit/2026-11-26-v2-volume-1-acceptance.md')

const OUTPUT_TAIL_LIMIT = 1500
const DEFAULT_TIMEOUT_MS = 300_000
const LONG_TIMEOUT_MS = 900_000

export const ENVIRONMENT_GATES = new Set(['docker-info'])

const GATES = [
  {
    name: 'backend-phpunit',
    command: 'cd backend && ../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --colors=never',
    cwd: 'backend',
    phpArgs: ['-d', 'memory_limit=1G', 'vendor/bin/phpunit', '--colors=never'],
    timeoutMs: LONG_TIMEOUT_MS,
  },
  {
    name: 'backend-pint',
    command: 'cd backend && ../.tools/php/php.exe -d memory_limit=1G vendor/bin/pint --test',
    cwd: 'backend',
    phpArgs: ['-d', 'memory_limit=1G', 'vendor/bin/pint', '--test'],
  },
  {
    name: 'backend-phpstan',
    command: 'cd backend && ../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpstan analyse --no-progress',
    cwd: 'backend',
    phpArgs: ['-d', 'memory_limit=1G', 'vendor/bin/phpstan', 'analyse', '--no-progress'],
  },
  {
    name: 'capability-audit',
    command: 'cd backend && ../.tools/php/php.exe -d memory_limit=1G artisan capability:audit',
    cwd: 'backend',
    phpArgs: ['-d', 'memory_limit=1G', 'artisan', 'capability:audit'],
  },
  {
    name: 'arch-test',
    command: 'cd backend && ../.tools/php/php.exe -d memory_limit=1G artisan arch:test',
    cwd: 'backend',
    phpArgs: ['-d', 'memory_limit=1G', 'artisan', 'arch:test'],
  },
  {
    name: 'backend-contract',
    command:
      "cd backend && ../.tools/php/php.exe -d memory_limit=1G artisan test --compact --filter='ActionManifestParityTest|PagePermissionCoverageTest|NoTenantNoLegalEntityTest'",
    cwd: 'backend',
    phpArgs: [
      '-d',
      'memory_limit=1G',
      'artisan',
      'test',
      '--compact',
      '--filter=ActionManifestParityTest|PagePermissionCoverageTest|NoTenantNoLegalEntityTest',
    ],
  },
  {
    name: 'frontend-typecheck',
    command: 'pnpm typecheck',
    shell: true,
  },
  {
    name: 'frontend-lint',
    command: 'pnpm lint',
    shell: true,
  },
  {
    name: 'frontend-unit',
    command: 'pnpm test:unit',
    shell: true,
    timeoutMs: LONG_TIMEOUT_MS,
  },
  {
    name: 'audit_page_components',
    command: 'node scripts/audit_page_components.mjs',
    args: ['scripts/audit_page_components.mjs'],
  },
  {
    name: 'audit_frontend_modules',
    command: 'node scripts/audit_frontend_modules.mjs',
    args: ['scripts/audit_frontend_modules.mjs'],
  },
  {
    name: 'audit_action_manifest',
    command: 'node scripts/audit_action_manifest.mjs',
    args: ['scripts/audit_action_manifest.mjs'],
  },
  {
    name: 'audit_page_model',
    command: 'node scripts/audit_page_model.mjs',
    args: ['scripts/audit_page_model.mjs'],
  },
  {
    name: 'audit_ui_primitives',
    command: 'node scripts/audit_ui_primitives.mjs',
    args: ['scripts/audit_ui_primitives.mjs'],
  },
  {
    name: 'audit_user_copy',
    command: 'node scripts/audit_user_copy.mjs',
    args: ['scripts/audit_user_copy.mjs'],
  },
  {
    name: 'openapi-lint',
    command: 'node deploy/scripts/openapi-lint.mjs',
    args: ['deploy/scripts/openapi-lint.mjs'],
  },
  {
    name: 'openapi:validate',
    command: 'pnpm openapi:validate',
    shell: true,
  },
  {
    name: 'node-tests',
    command: 'node --test scripts/tests/*.test.mjs',
    args: ['--test', 'scripts/tests/*.test.mjs'],
  },
  {
    name: 'docker-info',
    command: 'docker info',
    args: ['info'],
    executable: 'docker',
    environment: true,
  },
]

/** @typedef {{ name: string, command: string, exitCode: number | string, stdout: string, stderr: string }} AcceptanceEntry */

export { GATES }

function outputTail(entry) {
  const combined = `${entry.stdout ?? ''}${entry.stderr ?? ''}`.trim()
  if (combined === '') return 'NO OUTPUT'
  const tail = combined.slice(-OUTPUT_TAIL_LIMIT)
  return tail.replaceAll('\r\n', '\n').replaceAll('\r', '\n').replaceAll('|', '\\|').replaceAll(/\n/g, ' ')
}

function outputBlock(entry) {
  const combined = `${entry.stdout ?? ''}${entry.stderr ?? ''}`.trim()
  if (combined === '') return 'NO OUTPUT'
  return combined.slice(-OUTPUT_TAIL_LIMIT)
}

function isEnvironmentEntry(entry) {
  return ENVIRONMENT_GATES.has(entry.name) || entry.environment === true
}

function resultLabel(entry) {
  if (isEnvironmentEntry(entry) && entry.exitCode !== 0) return 'NOT RUN (environment)'
  return entry.exitCode === 0 ? 'PASS' : 'FAIL'
}

function conclusion(entries) {
  const red = entries.filter((entry) => entry.exitCode !== 0 && !isEnvironmentEntry(entry))
  if (red.length === 0) return ['**YES**']
  return [
    '**NO — blocked**',
    '',
    ...red.map(
      (entry) => `- \`${entry.name}\`: exit code ${entry.exitCode}; command \`${entry.command}\`.`,
    ),
  ]
}

/**
 * Builds the deterministic acceptance report. The report deliberately contains
 * no timestamps: identical entries and snapshot commit always yield identical
 * markdown.
 *
 * @param {AcceptanceEntry[]} entries
 * @param {{ snapshotCommit: string }} options
 */
export function buildAcceptanceReport(entries, options = {}) {
  const { snapshotCommit } = typeof options === 'string' ? { snapshotCommit: options } : options
  const product = entries.filter((entry) => !isEnvironmentEntry(entry))
  const environment = entries.filter((entry) => isEnvironmentEntry(entry))
  const commit = String(snapshotCommit ?? 'UNKNOWN')

  const lines = [
    '# 第 1 册验收记录（V2.1 Volume 1）',
    '',
    `- Snapshot commit: \`${commit}\``,
    '- Report mode: recorder. A non-zero product gate does not make this generator fail.',
    '- Evidence tails contain the last 1500 characters of captured stdout+stderr (per gate).',
    '',
    '## Product gates',
    '',
    '| gate | exit code | result | evidence tail |',
    '| --- | --- | --- | --- |',
  ]

  for (const entry of product) {
    lines.push(`| \`${entry.name}\` | ${entry.exitCode} | ${resultLabel(entry)} | ${outputTail(entry)} |`)
  }

  lines.push('', '## Environment probes', '')
  if (environment.length === 0) {
    lines.push('- None recorded.')
  } else {
    lines.push('| gate | exit code | result | evidence tail |', '| --- | --- | --- | --- |')
    for (const entry of environment) {
      lines.push(
        `| \`${entry.name}\` | ${entry.exitCode} | ${resultLabel(entry)} | ${outputTail(entry)} |`,
      )
    }
    lines.push(
      '',
      'Environment probes are recorded separately and do not, by themselves, flip the product conclusion.',
    )
  }

  lines.push('', '## Evidence', '')
  for (const entry of entries) {
    lines.push(
      `### ${entry.name}`,
      '',
      `- command: \`${entry.command}\``,
      `- exit code: ${entry.exitCode}`,
      `- result: ${resultLabel(entry)}`,
      '',
      '```text',
      outputBlock(entry),
      '```',
      '',
    )
  }

  lines.push('## Conclusion', '', ...conclusion(entries), '')

  return `${lines.join('\n')}\n`
}

function gateInvocation(gate, php) {
  if (gate.phpArgs !== undefined) {
    return { command: php, args: gate.phpArgs, shell: false }
  }
  if (gate.shell === true) {
    return { command: gate.command, args: [], shell: true }
  }
  return { command: gate.executable ?? process.execPath, args: gate.args ?? [], shell: false }
}

function exitCodeFor(error) {
  if (error.code === 'ETIMEDOUT') return 'TIMEOUT'
  if (typeof error.status === 'number') return error.status
  return error.code === 'ENOENT' ? 127 : 1
}

function formatExitSignal(error) {
  const status = error.status === null ? 'null' : error.status
  return `exit ${status}${error.signal ? ` (${error.signal})` : ''}${error.code ? `, ${error.code}` : ''}`
}

function runInjected(execFile, command, args, options) {
  try {
    return { exitCode: 0, stdout: String(execFile(command, args, options) ?? ''), stderr: '' }
  } catch (error) {
    const exitCode = exitCodeFor(error)
    const fallbackStderr =
      exitCode === 'TIMEOUT'
        ? `gate timed out after ${options.timeout}ms (${error.signal ?? 'timeout'})`
        : String(error.message ?? '')
    return {
      exitCode,
      stdout: String(error.stdout ?? ''),
      stderr: String(error.stderr || fallbackStderr),
    }
  }
}

function readCapturedTail(path) {
  const maxBytes = 4 * 1024 * 1024
  const file = openSync(path, 'r')
  try {
    const fd = file
    const { size } = statSync(path)
    const length = Math.min(size, maxBytes)
    const buffer = Buffer.alloc(length)
    if (length > 0) readSync(fd, buffer, 0, length, size - length)
    return buffer.toString('utf8')
  } finally {
    closeSync(file)
  }
}

function runCaptured(command, args, options) {
  const captureDir = mkdtempSync(join(tmpdir(), 'factoryhr-volume1-acceptance-'))
  const stdoutPath = join(captureDir, 'stdout.log')
  const stderrPath = join(captureDir, 'stderr.log')
  let stdoutFd
  let stderrFd

  try {
    stdoutFd = openSync(stdoutPath, 'w')
    stderrFd = openSync(stderrPath, 'w')

    let exitCode = 0
    let fallbackStderr = ''
    try {
      execFileSync(command, args, { ...options, stdio: ['ignore', stdoutFd, stderrFd] })
    } catch (error) {
      exitCode = exitCodeFor(error)
      fallbackStderr =
        exitCode === 'TIMEOUT'
          ? `gate timed out after ${options.timeout}ms (${error.signal ?? 'timeout'})`
          : formatExitSignal(error)
    } finally {
      closeSync(stdoutFd)
      closeSync(stderrFd)
      stdoutFd = undefined
      stderrFd = undefined
    }

    const stdout = readCapturedTail(stdoutPath)
    const capturedStderr = readCapturedTail(stderrPath)
    return {
      exitCode,
      stdout,
      stderr: capturedStderr === '' && exitCode !== 0 ? fallbackStderr : capturedStderr,
    }
  } finally {
    if (stdoutFd !== undefined) closeSync(stdoutFd)
    if (stderrFd !== undefined) closeSync(stderrFd)
    rmSync(captureDir, { recursive: true, force: true })
  }
}

/**
 * Executes the selected gates sequentially. Non-zero exits, spawn failures and
 * timeouts are returned as entries; this function never throws for gate
 * failures.
 *
 * @param {{
 *   cwd?: string,
 *   php?: string,
 *   env?: Record<string, string | undefined>,
 *   only?: string[],
 *   execFile?: typeof execFileSync,
 * }} options
 * @returns {AcceptanceEntry[]}
 */
export function runAcceptance({ cwd = repoRoot, php, env = {}, only, execFile = execFileSync } = {}) {
  const phpBinary = php ?? resolve(repoRoot, '.tools/php/php.exe')
  const selected = only === undefined ? GATES : GATES.filter((gate) => only.includes(gate.name))
  const entries = []

  for (const gate of selected) {
    const invocation = gateInvocation(gate, phpBinary)
    const gateCwd = gate.cwd === undefined ? cwd : resolve(cwd, gate.cwd)
    const options = {
      cwd: gateCwd,
      env: { ...process.env, ...env },
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
      maxBuffer: 64 * 1024 * 1024,
      timeout: gate.timeoutMs ?? DEFAULT_TIMEOUT_MS,
      shell: invocation.shell,
    }
    const result =
      execFile === execFileSync
        ? runCaptured(invocation.command, invocation.args, options)
        : runInjected(execFile, invocation.command, invocation.args, options)

    entries.push({ name: gate.name, command: gate.command, ...result })
  }

  return entries
}

function readSnapshotCommit(cwd) {
  try {
    return execFileSync('git', ['rev-parse', 'HEAD'], {
      cwd,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
    }).trim()
  } catch {
    return 'UNKNOWN'
  }
}

function parseOnly(value) {
  const names = String(value)
    .split(',')
    .map((name) => name.trim())
    .filter((name) => name !== '')
  const known = new Set(GATES.map((gate) => gate.name))
  const unknown = names.filter((name) => !known.has(name))
  if (unknown.length > 0) {
    console.error(`Unknown gate(s): ${unknown.join(', ')}`)
    console.error(`Known gates: ${[...known].join(', ')}`)
    process.exitCode = 2
    return undefined
  }
  return names
}

function main(argv = process.argv.slice(2)) {
  const dryRun = argv.includes('--dry-run')
  const onlyArg = argv.find((arg) => arg.startsWith('--only='))
  const only = onlyArg === undefined ? undefined : parseOnly(onlyArg.slice('--only='.length))
  if (onlyArg !== undefined && only === undefined) return 2

  if (dryRun) {
    const selected = only === undefined ? GATES : GATES.filter((gate) => only.includes(gate.name))
    console.log(`dry-run: ${selected.length} gate(s); no report written`)
    selected.forEach((gate, index) => console.log(`${index + 1}. ${gate.name}: ${gate.command}`))
    return 0
  }

  const snapshotCommit = readSnapshotCommit(repoRoot)
  const entries = runAcceptance({ cwd: repoRoot, only })
  const report = buildAcceptanceReport(entries, { snapshotCommit })

  mkdirSync(dirname(reportPath), { recursive: true })
  writeFileSync(reportPath, report)
  console.log(reportPath)
  for (const entry of entries) {
    console.log(`${entry.name}: ${entry.exitCode}`)
  }
  return 0
}

if (process.argv[1] !== undefined && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  process.exitCode = main()
}
